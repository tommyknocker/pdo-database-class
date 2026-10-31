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
            default => $this->fail("Unknown subcommand: {$subcommand}"),
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
        $this->writeln("Query Testing\n");
        $this->writeln('Usage:');
        $this->writeln('  pdodb query test [query]          Start interactive REPL or execute single query');
        $this->writeln('  pdodb query explain <query>       Show EXPLAIN plan for the given SQL');
        $this->writeln('  pdodb query format <query>        Pretty-print and format the given SQL');
        $this->writeln("  pdodb query validate <query>      Validate SQL syntax (no execution)\n");
        $this->writeln('Arguments:');
        $this->writeln("  query             SQL query\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb query test');
        $this->writeln('  pdodb query test "SELECT * FROM users LIMIT 10"');
        $this->writeln('  pdodb query explain "SELECT * FROM users WHERE id = 1"');
        $this->writeln('  pdodb query format "select  *  from users where  id=1 order   by  name"');
        $this->writeln('  pdodb query validate "SELECT COUNT(*) FROM users"');
        return 0;
    }

    /**
     * Explain a SQL query using database-specific EXPLAIN.
     */
    protected function explain(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->fail('Query string is required for "explain".');
        }

        try {
            $db = $this->getDb();
            /** @var array<mixed> $plan */
            $plan = $db->explain($sql, []);
            $this->writeln('EXPLAIN plan:');
            // pretty print (most dialects return arrays)
            $this->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        } catch (\Throwable $e) {
            return $this->fail('Failed to explain query: ' . $e->getMessage());
        }
    }

    /**
     * Format a raw SQL string for readability.
     */
    protected function format(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->fail('Query string is required for "format".');
        }
        $formatter = new SqlFormatter(false, 4, ' ');
        $formatted = $formatter->format($sql);
        $this->writeln($formatted);
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
            return $this->fail('Query string is required for "validate".');
        }

        try {
            $db = $this->getDb();
            // Rely on DB to parse via EXPLAIN; if invalid, it will throw
            $db->explain($sql, []);
            $this->writeln('✓ SQL is valid');
            return 0;
        } catch (\Throwable $e) {
            return $this->fail('Invalid SQL - ' . $e->getMessage());
        }
    }
}
