<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\QueryTester;
use tommyknocker\pdodb\query\formatter\SqlFormatter;

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
            'explain' => $this->explain(),
            'format' => $this->format(),
            'validate' => $this->validate(),
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
        echo "Usage:\n";
        echo "  pdodb query test [query]          Start interactive REPL or execute single query\n";
        echo "  pdodb query explain <query>       Show EXPLAIN plan for the given SQL\n";
        echo "  pdodb query format <query>        Pretty-print and format the given SQL\n";
        echo "  pdodb query validate <query>      Validate SQL syntax (no execution)\n\n";
        echo "Arguments:\n";
        echo "  query             SQL query\n\n";
        echo "Examples:\n";
        echo "  pdodb query test\n";
        echo "  pdodb query test \"SELECT * FROM users LIMIT 10\"\n";
        echo "  pdodb query explain \"SELECT * FROM users WHERE id = 1\"\n";
        echo "  pdodb query format \"select  *  from users where  id=1 order   by  name\"\n";
        echo "  pdodb query validate \"SELECT COUNT(*) FROM users\"\n";
        return 0;
    }

    /**
     * Explain a SQL query using database-specific EXPLAIN.
     */
    protected function explain(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->showError('Query string is required for "explain".');
        }

        try {
            $db = $this->getDb();
            /** @var array<mixed> $plan */
            $plan = $db->explain($sql, []);
            echo "EXPLAIN plan:\n";
            // pretty print (most dialects return arrays)
            echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        } catch (\Throwable $e) {
            return $this->showError('Failed to explain query: ' . $e->getMessage());
        }
    }

    /**
     * Format a raw SQL string for readability.
     */
    protected function format(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->showError('Query string is required for "format".');
        }
        $formatter = new SqlFormatter(false, 4, ' ');
        $formatted = $formatter->format($sql);
        echo $formatted . "\n";
        return 0;
    }

    /**
     * Validate SQL syntax without executing it.
     * Uses database EXPLAIN to check syntax validity.
     */
    protected function validate(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->showError('Query string is required for "validate".');
        }

        try {
            $db = $this->getDb();
            // Rely on DB to parse via EXPLAIN; if invalid, it will throw
            $db->explain($sql, []);
            echo "✓ SQL is valid\n";
            return 0;
        } catch (\Throwable $e) {
            echo 'Error: Invalid SQL - ' . $e->getMessage() . "\n";
            return 1;
        }
    }
}
