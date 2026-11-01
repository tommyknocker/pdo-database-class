<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;
use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\inbox\Inbox;

/**
 * Maintain the consumer-side inbox table.
 */
class InboxCommand extends Command
{
    use SubcommandSuggestionTrait;

    public function __construct()
    {
        parent::__construct('inbox', 'Maintain the consumer inbox table');
    }

    /**
     * @return list<string>
     */
    protected function getAvailableSubcommands(): array
    {
        return ['purge', 'help'];
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
            'purge' => $this->purge(),
            '--help', 'help' => $this->showHelp(),
            default => $this->showSubcommandError($subcommandStr),
        };
    }

    protected function purge(): int
    {
        $olderThan = $this->getOption('older-than');
        if (!is_string($olderThan) || $olderThan === '') {
            return $this->fail('Missing --older-than (example: 30d, 24h, 90m).');
        }

        $db = $this->getDb();
        $table = $this->tableName();
        if (!$db->schema()->tableExists($table)) {
            return $this->fail('Inbox table "' . $table . '" does not exist.');
        }

        $dryRun = $this->isDryRunMode();

        try {
            $count = (new Inbox($db, 'default', $table))->purge($olderThan, $dryRun);
        } catch (OutboxException $e) {
            return $this->fail($e->getMessage());
        }

        $this->writeln($dryRun
            ? sprintf('Dry run: would delete %d processed id(s) older than %s.', $count, $olderThan)
            : sprintf('Deleted %d processed id(s) older than %s.', $count, $olderThan));

        return 0;
    }

    protected function tableName(): string
    {
        $env = getenv('PDODB_INBOX_TABLE');
        if ($env !== false && $env !== '') {
            return $env;
        }

        $option = $this->getOption('table');

        return is_string($option) && $option !== '' ? $option : 'pdodb_inbox';
    }

    protected function showHelp(): int
    {
        $this->writeln("Consumer inbox\n");
        $this->writeln("Usage: pdodb inbox <subcommand> [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  purge             Forget processed message ids older than a given age');
        $this->writeln("  help              Show this help message\n");
        $this->writeln('Purge options:');
        $this->writeln('  --older-than=SPEC Required retention window (30d, 24h, 90m, …)');
        $this->writeln('  --table=NAME      Inbox table (default pdodb_inbox or PDODB_INBOX_TABLE)');
        $this->writeln('  --dry-run         Count rows without deleting');
        $this->writeln('');
        $this->writeln('Keep the window longer than the longest time your broker can redeliver a message:');
        $this->writeln('a message that comes back after its id was purged is handled a second time.');
        $this->writeln('');
        $this->writeln('Examples:');
        $this->writeln('  pdodb inbox purge --older-than=30d --dry-run');
        $this->writeln('  pdodb inbox purge --older-than=30d --table=billing_inbox');

        return 0;
    }
}
