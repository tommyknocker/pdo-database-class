<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Interactive query tester (REPL).
 *
 * Provides an interactive REPL for testing SQL queries against the database.
 */
class QueryTester extends BaseCliCommand
{
    /**
     * Start interactive query tester.
     *
     * @param string|null $query Initial query to execute (optional)
     */
    public static function test(?string $query = null): void
    {
        $db = static::createDatabase();
        $driver = static::getDriverName($db);

        static::cliWriteln('PDOdb Query Tester (REPL)');
        static::cliWriteln("Database: {$driver}");
        static::cliWriteln("Type 'exit' or 'quit' to exit, 'help' for help\n");

        if ($query !== null) {
            static::executeQuery($db, $query);
            return;
        }

        // Without a terminal readInput() returns '' at once and the loop below would spin forever.
        if (CliEnvironment::isNonInteractive()) {
            throw new \RuntimeException('The interactive REPL needs a terminal. Pass the query as an argument: pdodb query test "SELECT 1"');
        }

        // Interactive mode
        $history = [];
        while (true) {
            $input = static::readInput('pdodb> ');

            if ($input === '') {
                continue;
            }

            $input = trim($input);
            $command = strtolower($input);

            if (in_array($command, ['exit', 'quit', 'q'], true)) {
                static::cliWriteln('Goodbye!');
                break;
            }

            if ($command === 'help') {
                static::showHelp();
                continue;
            }

            if ($command === 'clear' || $command === 'cls') {
                // Clear screen (works on most terminals)
                static::cliWrite("\033[2J\033[H");
                continue;
            }

            if ($command === 'history') {
                foreach ($history as $i => $histQuery) {
                    static::cliWrite(($i + 1) . ". {$histQuery}\n");
                }
                continue;
            }

            $history[] = $input;
            static::executeQuery($db, $input);
        }
    }

    /**
     * Execute a query and display results.
     *
     * @param PdoDb $db Database instance
     * @param string $query SQL query
     */
    protected static function executeQuery(PdoDb $db, string $query): void
    {
        try {
            // Check if it's a SELECT query
            $queryTrimmed = trim($query);
            $isSelect = stripos($queryTrimmed, 'SELECT') === 0 || stripos($queryTrimmed, 'DESCRIBE') === 0 || stripos($queryTrimmed, 'SHOW') === 0 || stripos($queryTrimmed, 'EXPLAIN') === 0;

            if ($isSelect) {
                // Use raw query for SELECT queries
                $result = $db->rawQuery($query);
                static::displayResults($result);
            } else {
                // Execute non-SELECT queries
                $db->rawQuery($query);
                static::success('Query executed successfully.');
            }
        } catch (QueryException $e) {
            static::cliWriteErrorLine('Error: ' . $e->getMessage());
            if ($e->getQuery() !== null) {
                static::cliWriteErrorLine('Query: ' . $e->getQuery());
            }
        }
    }

    /**
     * Display query results in a formatted table.
     *
     * @param array<int, array<string, mixed>> $results Query results
     */
    protected static function displayResults(array $results): void
    {
        if (empty($results)) {
            static::info('No results found.');
            return;
        }

        $firstRow = $results[0];
        $columns = array_keys($firstRow);

        // Calculate column widths
        $widths = [];
        foreach ($columns as $col) {
            $colStr = (string)$col;
            $widths[$col] = strlen($colStr);
            foreach ($results as $row) {
                $value = $row[$col] ?? '';
                $valueStr = is_scalar($value) ? (string)$value : json_encode($value);
                if ($valueStr === false) {
                    $valueStr = '';
                }
                $valueStrLen = strlen($valueStr);
                if ($valueStrLen > 50) {
                    // substr can only return false if start > length, which is impossible here
                    /** @var string $valueStrSub */
                    $valueStrSub = substr($valueStr, 0, 47);
                    $valueStr = $valueStrSub . '...';
                }
                $widths[$col] = max($widths[$col], $valueStrLen);
            }
            $widths[$col] = min($widths[$col], 50); // Max width
        }

        // Print header
        static::cliWriteln('');
        foreach ($columns as $col) {
            $colStr = (string)$col;
            printf('%-' . ($widths[$col] + 2) . 's', $colStr);
        }
        static::cliWriteln('');
        static::cliWriteln(str_repeat('-', array_sum($widths) + (count($columns) * 2)));

        // Print rows
        $displayCount = min(count($results), 100); // Limit to 100 rows
        for ($i = 0; $i < $displayCount; $i++) {
            $row = $results[$i];
            foreach ($columns as $col) {
                $value = $row[$col] ?? '';
                $valueStr = is_scalar($value) ? (string)$value : json_encode($value);
                if ($valueStr === false) {
                    $valueStr = '';
                }
                $valueStrLen = strlen($valueStr);
                if ($valueStrLen > 50) {
                    // substr can only return false if start > length, which is impossible here
                    /** @var string $valueStrSub */
                    $valueStrSub = substr($valueStr, 0, 47);
                    $valueStr = $valueStrSub . '...';
                }
                printf('%-' . ($widths[$col] + 2) . 's', $valueStr);
            }
            static::cliWriteln('');
        }

        if (count($results) > $displayCount) {
            static::cliWrite("\n... (" . (count($results) - $displayCount) . " more rows)\n");
        }

        static::cliWrite("\nTotal rows: " . count($results) . "\n\n");
    }

    /**
     * Show help message.
     */
    protected static function showHelp(): void
    {
        static::cliWriteln("\nAvailable commands:");
        static::cliWriteln('  exit, quit, q    Exit the query tester');
        static::cliWriteln('  help             Show this help message');
        static::cliWriteln('  clear, cls       Clear the screen');
        static::cliWriteln('  history          Show query history');
        static::cliWriteln('');
        static::cliWriteln('You can execute any SQL query directly.');
        static::cliWriteln('Examples:');
        static::cliWriteln('  SELECT * FROM users LIMIT 10');
        static::cliWriteln('  SELECT COUNT(*) FROM users');
        static::cliWriteln('  DESCRIBE users');
        static::cliWriteln('');
    }
}
