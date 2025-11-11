<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\QueryTester;

/**
 * Query command for testing SQL queries.
 */
class QueryCommand extends Command
{
    /**
     * Create query command.
     */
    public function __construct()
    {
        parent::__construct('query', 'Test SQL queries interactively');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        $subcommand = $this->getArgument(0);

        if ($subcommand === null || $subcommand === '--help' || $subcommand === 'help') {
            $this->showHelp();
            return 0;
        }

        return match ($subcommand) {
            'test' => $this->test(),
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Test query.
     *
     * @return int Exit code
     */
    protected function test(): int
    {
        $query = $this->getArgument(1);

        try {
            QueryTester::test($query);
            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
            // @phpstan-ignore-next-line
            return 1;
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Query Testing\n\n";
        echo "Usage: pdodb query test [query]\n\n";
        echo "Arguments:\n";
        echo "  query             SQL query to execute (optional, starts interactive mode if not provided)\n\n";
        echo "Examples:\n";
        echo "  pdodb query test\n";
        echo "  pdodb query test \"SELECT * FROM users LIMIT 10\"\n";
        return 0;
    }
}
