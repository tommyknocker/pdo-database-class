<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;
use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxPublisherLoader;
use tommyknocker\pdodb\outbox\OutboxStatuses;
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
        return ['work', 'purge', 'help'];
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

        if ($batchSize === null || $lockSeconds === null || $maxAttempts === null || $sleepSeconds === null) {
            return 1;
        }

        $worker = $outbox->worker($workerId, $batchSize, $lockSeconds, $maxAttempts);
        $this->registerStopSignals();

        while (true) {
            if ($this->stopRequested) {
                break;
            }

            $result = $worker->runOnce($publisher);
            $this->writeln(sprintf(
                'Outbox: claimed=%d published=%d retry=%d failed=%d',
                $result->claimed,
                $result->published,
                $result->scheduledRetry,
                $result->markedFailed,
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
        $this->writeln("  help              Show this help message\n");
        $this->writeln('Work options:');
        $this->writeln('  --publisher=PATH  PHP file returning callable(OutboxMessage): void (or PDODB_OUTBOX_PUBLISHER)');
        $this->writeln('  --once            Process one batch and exit (cron-friendly)');
        $this->writeln('  --batch=N         Messages per batch (default 10)');
        $this->writeln('  --lock=N          Lock TTL in seconds (default 120)');
        $this->writeln('  --max-attempts=N  Max publish attempts before failed (default 25)');
        $this->writeln('  --sleep=N         Idle sleep seconds when queue is empty (default 2)');
        $this->writeln('  --worker-id=ID    Value stored in locked_by prefix (default hostname)');
        $this->writeln('  --table=NAME      Outbox table (default pdodb_outbox or PDODB_OUTBOX_TABLE)');
        $this->writeln('  --ensure-schema   Create outbox table when missing (local bootstrap only)');
        $this->writeln('');
        $this->writeln('Purge options:');
        $this->writeln('  --older-than=SPEC Required retention window (7d, 24h, 30m, …)');
        $this->writeln('  --status=NAME     published (default) or failed');
        $this->writeln('  --dry-run         Count rows without deleting');
        $this->writeln('');
        $this->writeln('Examples:');
        $this->writeln('  pdodb outbox work --once --publisher=config/outbox-publisher.php');
        $this->writeln('  pdodb outbox purge --older-than=7d');
        $this->writeln('  pdodb outbox purge --older-than=30d --status=failed --dry-run');

        return 0;
    }
}
