<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use Throwable;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;
use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxPublisherLoader;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\outbox\OutboxTransientError;
use tommyknocker\pdodb\PdoDb;

/**
 * Process and maintain the transactional outbox table.
 */
class OutboxCommand extends Command
{
    use SubcommandSuggestionTrait;

    protected bool $stopRequested = false;

    public function __construct()
    {
        parent::__construct('outbox', 'Process and purge transactional outbox messages');
    }

    /**
     * @return list<string>
     */
    protected function getAvailableSubcommands(): array
    {
        return ['work', 'purge', 'status', 'list', 'retry', 'help'];
    }

    public function execute(): int
    {
        if ($this->wantsHelp()) {
            return $this->showHelp();
        }

        $subcommand = $this->getArgument(0);
        if ($subcommand === null) {
            return $this->showHelp();
        }

        $subcommandStr = is_string($subcommand) ? $subcommand : (string) $subcommand;

        return match ($subcommandStr) {
            'work' => $this->work(),
            'purge' => $this->purge(),
            'status' => $this->status(),
            'list' => $this->listMessages(),
            'retry' => $this->retry(),
            '--help', 'help' => $this->showHelp(),
            default => $this->showSubcommandError($subcommandStr),
        };
    }

    protected function work(): int
    {
        $publisherPath = $this->resolvePublisherPath();
        if ($publisherPath === null) {
            return $this->fail(
                'Missing publisher. Pass --publisher=/path/to/publisher.php or set PDODB_OUTBOX_PUBLISHER.'
            );
        }

        try {
            $publisher = OutboxPublisherLoader::load($publisherPath);
        } catch (OutboxException $e) {
            return $this->fail($e->getMessage());
        }

        $db = $this->getDb();
        $outbox = $this->createOutbox($db);

        if (!$this->prepareOutboxTable($outbox)) {
            return 1;
        }

        $workerId = $this->resolveWorkerId();
        $batchSize = $this->parsePositiveIntOption(['batch', 'batch-size'], 10);
        $lockSeconds = $this->parsePositiveIntOption(['lock', 'lock-seconds'], 120);
        $maxAttempts = $this->parsePositiveIntOption(['max-attempts'], 25);
        $sleepSeconds = $this->parsePositiveIntOption(['sleep'], 2);
        $once = (bool) $this->getOption('once', false);
        $maxRuntime = $this->getOption('max-runtime') === null ? 0 : $this->parsePositiveIntOption(['max-runtime'], 0);

        if ($maxRuntime === null || $batchSize === null || $lockSeconds === null || $maxAttempts === null || $sleepSeconds === null) {
            return 1;
        }

        $worker = $outbox->worker($workerId, $batchSize, $lockSeconds, $maxAttempts);
        $this->registerStopSignals();
        $transientFailures = 0;
        $deadline = $maxRuntime > 0 ? time() + $maxRuntime : null;

        while (true) {
            if ($this->stopRequested || ($deadline !== null && time() >= $deadline)) {
                break;
            }

            try {
                $result = $worker->runOnce($publisher);
                $transientFailures = 0;
            } catch (Throwable $e) {
                if (!OutboxTransientError::isRetryable($e)) {
                    return $this->fail($e->getMessage());
                }

                $transientFailures++;
                $this->writeln(sprintf(
                    'Outbox: transient database error, retrying (%d): %s',
                    $transientFailures,
                    $e->getMessage(),
                ));
                if ($transientFailures >= 5) {
                    if ($once) {
                        return $this->fail('Outbox stopped after repeated transient database errors.');
                    }
                    $transientFailures = 0;
                    sleep($sleepSeconds);
                    continue;
                }
                usleep(100000 * $transientFailures);
                continue;
            }

            $this->writeln(sprintf(
                'Outbox: claimed=%d published=%d retry=%d failed=%d unconfirmed=%d',
                $result->claimed,
                $result->published,
                $result->scheduledRetry,
                $result->markedFailed,
                $result->unconfirmed,
            ));

            if ($once) {
                break;
            }

            if ($result->claimed === 0) {
                sleep($sleepSeconds);
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return 0;
    }

    protected function status(): int
    {
        $db = $this->getDb();
        $outbox = $this->createOutbox($db);
        if (!$this->requireOutboxTable($outbox)) {
            return 1;
        }

        try {
            $counts = $outbox->countByStatus();
        } catch (OutboxException $e) {
            return $this->fail($e->getMessage());
        }

        $format = $this->resolveOutputFormat();
        if ($format === 'json') {
            $this->writeln((string) json_encode($counts->toMap(), JSON_THROW_ON_ERROR));

            return 0;
        }

        foreach ($counts->toMap() as $name => $count) {
            $this->writeln(sprintf('%s: %d', $name, $count));
        }
        $this->writeln(sprintf('total: %d', $counts->total()));

        return 0;
    }

    protected function listMessages(): int
    {
        $statusOption = $this->getOption('status');
        if (!is_string($statusOption) || $statusOption === '') {
            return $this->fail('Missing --status (pending, processing, published, or failed).');
        }

        $limit = $this->parsePositiveIntOption(['limit'], 20);
        if ($limit === null) {
            return 1;
        }

        $db = $this->getDb();
        $outbox = $this->createOutbox($db);
        if (!$this->requireOutboxTable($outbox)) {
            return 1;
        }

        try {
            $items = $outbox->listMessages($statusOption, $limit);
        } catch (OutboxException $e) {
            return $this->fail($e->getMessage());
        }

        $format = $this->resolveOutputFormat();
        if ($format === 'json') {
            $payload = array_map(static fn ($item) => $item->toArray(), $items);
            $this->writeln((string) json_encode($payload, JSON_THROW_ON_ERROR));

            return 0;
        }

        if ($items === []) {
            $this->writeln('No rows.');

            return 0;
        }

        foreach ($items as $item) {
            $error = $item->lastError ?? '';
            $this->writeln(sprintf(
                '%s  type=%s  attempts=%d  created=%s  error=%s',
                $item->id,
                $item->eventType,
                $item->attempts,
                $item->createdAt,
                $error === '' ? '-' : $error,
            ));
        }

        return 0;
    }

    protected function retry(): int
    {
        $db = $this->getDb();
        $outbox = $this->createOutbox($db);
        if (!$this->requireOutboxTable($outbox)) {
            return 1;
        }

        $idOption = $this->getOption('id');
        $allFailed = (bool) $this->getOption('all-failed', false);
        $resetAttempts = !(bool) $this->getOption('keep-attempts', false);
        $dryRun = $this->isDryRunMode();

        if ($allFailed) {
            $limit = $this->parsePositiveIntOption(['limit'], 100);
            if ($limit === null) {
                return 1;
            }

            try {
                $result = $outbox->requeueAllFailed($limit, $resetAttempts, $dryRun);
            } catch (OutboxException $e) {
                return $this->fail($e->getMessage());
            }

            if ($dryRun) {
                $this->writeln(sprintf(
                    'Dry run: would requeue %d failed row(s) (limit %d).',
                    $result->matched,
                    $limit,
                ));
            } else {
                $this->writeln(sprintf(
                    'Requeued %d failed row(s) (matched %d, limit %d).',
                    $result->requeued,
                    $result->matched,
                    $limit,
                ));
            }

            return 0;
        }

        if (!is_string($idOption) || $idOption === '') {
            return $this->fail('Pass --id=<uuid> or --all-failed to requeue failed messages.');
        }

        if ($dryRun) {
            $rows = $outbox->listMessages(OutboxStatuses::FAILED, 1000);
            $would = false;
            foreach ($rows as $item) {
                if ($item->id === $idOption) {
                    $would = true;
                    break;
                }
            }
            $this->writeln($would
                ? 'Dry run: would requeue failed message ' . $idOption . '.'
                : 'Dry run: no failed message with id ' . $idOption . '.');

            return 0;
        }

        try {
            $requeued = $outbox->requeueFailed($idOption, $resetAttempts);
        } catch (OutboxException $e) {
            return $this->fail($e->getMessage());
        }

        if (!$requeued) {
            return $this->fail('No failed outbox message with id "' . $idOption . '".');
        }

        $this->writeln('Requeued failed message ' . $idOption . '.');

        return 0;
    }

    protected function requireOutboxTable(Outbox $outbox): bool
    {
        if ($this->getDb()->schema()->tableExists($outbox->getTableName())) {
            return true;
        }

        $this->fail('Outbox table "' . $outbox->getTableName() . '" does not exist.');

        return false;
    }

    protected function resolveOutputFormat(): string
    {
        $format = $this->getOption('format', 'text');
        if (!is_string($format) || $format === '') {
            return 'text';
        }

        if (!in_array($format, ['text', 'json'], true)) {
            $this->fail('Invalid --format. Allowed: text, json.');

            return 'text';
        }

        return $format;
    }

    protected function purge(): int
    {
        $olderThan = $this->getOption('older-than');
        if (!is_string($olderThan) || $olderThan === '') {
            return $this->fail('Missing --older-than (example: 7d, 24h, 30m).');
        }

        $statusOption = $this->getOption('status', OutboxStatuses::PUBLISHED);
        $status = is_string($statusOption) ? $statusOption : OutboxStatuses::PUBLISHED;
        if (!in_array($status, [OutboxStatuses::PUBLISHED, OutboxStatuses::FAILED], true)) {
            return $this->fail(
                'Invalid --status. Allowed values: ' . OutboxStatuses::PUBLISHED . ', ' . OutboxStatuses::FAILED . '.'
            );
        }

        $db = $this->getDb();
        $outbox = $this->createOutbox($db);
        if (!$db->schema()->tableExists($outbox->getTableName())) {
            return $this->fail('Outbox table "' . $outbox->getTableName() . '" does not exist.');
        }

        try {
            $result = $outbox->purge($olderThan, $status, $this->isDryRunMode());
        } catch (OutboxException $e) {
            return $this->fail($e->getMessage());
        }

        if ($result->dryRun) {
            $this->writeln(sprintf(
                'Dry run: would delete %d row(s) with status=%s older than %s (cutoff %s).',
                $result->matched,
                $result->status,
                $olderThan,
                $result->cutoffIso,
            ));
        } else {
            $this->writeln(sprintf(
                'Deleted %d row(s) with status=%s (matched %d, cutoff %s).',
                $result->deleted,
                $result->status,
                $result->matched,
                $result->cutoffIso,
            ));
        }

        return 0;
    }

    protected function createOutbox(PdoDb $db): Outbox
    {
        $table = getenv('PDODB_OUTBOX_TABLE');
        if ($table === false || $table === '') {
            $tableOption = $this->getOption('table');
            if (is_string($tableOption) && $tableOption !== '') {
                $table = $tableOption;
            } else {
                $table = 'pdodb_outbox';
            }
        }

        return new Outbox($db, $table);
    }

    protected function prepareOutboxTable(Outbox $outbox): bool
    {
        $db = $this->getDb();
        if ($db->schema()->tableExists($outbox->getTableName())) {
            return true;
        }

        if ((bool) $this->getOption('ensure-schema', false)) {
            $outbox->ensureSchema();
            $this->writeln('Created outbox table "' . $outbox->getTableName() . '" (--ensure-schema).');

            return true;
        }

        $this->fail(
            'Outbox table "' . $outbox->getTableName() . '" does not exist. '
            . 'Create it in a migration or pass --ensure-schema for local bootstrap.'
        );

        return false;
    }

    protected function resolvePublisherPath(): ?string
    {
        $option = $this->getOption('publisher');
        if (is_string($option) && $option !== '') {
            return $this->normalizePath($option);
        }

        $env = getenv('PDODB_OUTBOX_PUBLISHER');
        if (is_string($env) && $env !== '') {
            return $this->normalizePath($env);
        }

        return null;
    }

    protected function resolveWorkerId(): string
    {
        $option = $this->getOption('worker-id');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        $hostname = gethostname();
        if (is_string($hostname) && $hostname !== '') {
            return $hostname;
        }

        return 'pdodb-outbox';
    }

    protected function normalizePath(string $path): string
    {
        if ($path[0] === DIRECTORY_SEPARATOR || str_starts_with($path, './') || str_starts_with($path, '../')) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return $path;
        }

        return $cwd . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param list<string> $names
     */
    protected function parsePositiveIntOption(array $names, int $default): ?int
    {
        $value = null;
        foreach ($names as $name) {
            $candidate = $this->getOption($name);
            if ($candidate !== null && $candidate !== false && $candidate !== true) {
                $value = $candidate;
                break;
            }
        }

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            if ($value < 1) {
                $this->fail('Option value must be a positive integer.');

                return null;
            }

            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
            if ($int < 1) {
                $this->fail('Option value must be a positive integer.');

                return null;
            }

            return $int;
        }

        $this->fail('Option value must be a positive integer.');

        return null;
    }

    protected function registerStopSignals(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    protected function showHelp(): int
    {
        $this->writeln("Transactional outbox\n");
        $this->writeln("Usage: pdodb outbox <subcommand> [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  work              Claim pending rows and invoke your publisher');
        $this->writeln('  purge             Delete old published (or failed) rows');
        $this->writeln('  status            Show row counts by status');
        $this->writeln('  list              List rows for one status');
        $this->writeln('  retry             Move failed rows back to pending');
        $this->writeln("  help              Show this help message\n");
        $this->writeln('Work options:');
        $this->writeln('  --publisher=PATH  PHP file returning callable(OutboxMessage): void (or PDODB_OUTBOX_PUBLISHER)');
        $this->writeln('  --once            Process one batch and exit (cron-friendly)');
        $this->writeln('  --batch=N         Messages per batch (default 10)');
        $this->writeln('  --lock=N          Lock TTL in seconds (default 120)');
        $this->writeln('  --max-attempts=N  Max publish attempts before failed (default 25)');
        $this->writeln('  --sleep=N         Idle sleep seconds when queue is empty (default 2)');
        $this->writeln('  --max-runtime=N   Stop after N seconds (lets a supervisor restart the worker; default: run until stopped)');
        $this->writeln('  --worker-id=ID    Value stored in locked_by prefix (default hostname)');
        $this->writeln('  --table=NAME      Outbox table (default pdodb_outbox or PDODB_OUTBOX_TABLE)');
        $this->writeln('  --ensure-schema   Create outbox table when missing (local bootstrap only)');
        $this->writeln('');
        $this->writeln('Purge options:');
        $this->writeln('  --older-than=SPEC Required retention window (7d, 24h, 30m, …)');
        $this->writeln('  --status=NAME     published (default) or failed');
        $this->writeln('  --dry-run         Count rows without deleting');
        $this->writeln('');
        $this->writeln('Status / list options:');
        $this->writeln('  --table=NAME      Outbox table (default pdodb_outbox or PDODB_OUTBOX_TABLE)');
        $this->writeln('  --format=text|json Output format (default text)');
        $this->writeln('  --status=NAME     Required for list (pending, processing, published, failed)');
        $this->writeln('  --limit=N         Max rows for list (default 20)');
        $this->writeln('');
        $this->writeln('Retry options:');
        $this->writeln('  --id=UUID         Requeue one failed message');
        $this->writeln('  --all-failed      Requeue up to --limit failed messages (default 100)');
        $this->writeln('  --keep-attempts   Do not reset attempts when requeueing');
        $this->writeln('  --dry-run         Preview without updating rows');
        $this->writeln('');
        $this->writeln('Examples:');
        $this->writeln('  pdodb outbox work --once --publisher=config/outbox-publisher.php');
        $this->writeln('  pdodb outbox status');
        $this->writeln('  pdodb outbox list --status=failed --limit=10');
        $this->writeln('  pdodb outbox retry --id=11111111-1111-4111-8111-111111111111');
        $this->writeln('  pdodb outbox retry --all-failed --limit=50');
        $this->writeln('  pdodb outbox purge --older-than=7d');
        $this->writeln('  pdodb outbox purge --older-than=30d --status=failed --dry-run');

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'all-failed',
            'batch',
            'batch-size',
            'dry-run',
            'ensure-schema',
            'format',
            'id',
            'keep-attempts',
            'limit',
            'lock',
            'lock-seconds',
            'max-attempts',
            'max-runtime',
            'older-than',
            'once',
            'publisher',
            'sleep',
            'status',
            'table',
            'worker-id',
        ];
    }
}
