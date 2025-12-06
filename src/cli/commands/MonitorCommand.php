<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\MonitorManager;
use tommyknocker\pdodb\PdoDb;

/**
 * Monitor command for database monitoring.
 */
class MonitorCommand extends Command
{
    /**
     * Create monitor command.
     */
    public function __construct()
    {
        parent::__construct('monitor', 'Monitor database queries, connections, and performance');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        // Show help if explicitly requested
        if ($this->getOption('help', false)) {
            $this->showHelp();
            return 0;
        }

        $sub = $this->getArgument(0);
        if ($sub === null || $sub === '--help' || $sub === 'help') {
            return $this->showHelp();
        }

        $subStr = is_string($sub) ? $sub : '';
        return match ($subStr) {
            'queries' => $this->monitorQueries(),
            'connections' => $this->monitorConnections(),
            'slow' => $this->monitorSlow(),
            'stats' => $this->monitorStats(),
            default => $this->showError('Unknown subcommand: ' . $subStr),
        };
    }

    /**
     * Monitor active queries.
     *
     * @return int Exit code
     */
    protected function monitorQueries(): int
    {
        $db = $this->getDb();
        $watch = (bool)$this->getOption('watch', false);
        $formatVal = $this->getOption('format', 'table');
        $format = is_string($formatVal) ? $formatVal : 'table';

        if ($watch) {
            return $this->watchQueries($db, $format);
        }

        $queries = MonitorManager::getActiveQueries($db);
        return $this->printFormatted(['queries' => $queries], $format);
    }

    /**
     * Monitor active connections.
     *
     * @return int Exit code
     */
    protected function monitorConnections(): int
    {
        $db = $this->getDb();
        $watch = (bool)$this->getOption('watch', false);
        $formatVal = $this->getOption('format', 'table');
        $format = is_string($formatVal) ? $formatVal : 'table';

        if ($watch) {
            return $this->watchConnections($db, $format);
        }

        $connections = MonitorManager::getActiveConnections($db);
        return $this->printFormatted(['connections' => $connections], $format);
    }

    /**
     * Monitor slow queries.
     *
     * @return int Exit code
     */
    protected function monitorSlow(): int
    {
        $db = $this->getDb();
        $thresholdVal = $this->getOption('threshold', '1s');
        $threshold = is_string($thresholdVal) ? $thresholdVal : '1s';
        $limitVal = $this->getOption('limit', 10);
        $limit = is_int($limitVal) ? $limitVal : (is_string($limitVal) ? (int)$limitVal : 10);
        $formatVal = $this->getOption('format', 'table');
        $format = is_string($formatVal) ? $formatVal : 'table';

        $thresholdSeconds = $this->parseTimeThreshold((string)$threshold);
        $slowQueries = MonitorManager::getSlowQueries($db, $thresholdSeconds, $limit);

        return $this->printFormatted(['slow_queries' => $slowQueries], $format);
    }

    /**
     * Monitor query statistics.
     *
     * @return int Exit code
     */
    protected function monitorStats(): int
    {
        $db = $this->getDb();
        $formatVal = $this->getOption('format', 'table');
        $format = is_string($formatVal) ? $formatVal : 'table';

        $stats = MonitorManager::getQueryStats($db);
        if ($stats === null) {
            static::warning('Query profiling is not enabled. Enable it with $db->enableProfiling()');
            return 1;
        }

        return $this->printFormatted(['stats' => $stats], $format);
    }

    /**
     * Watch queries in real-time.
     *
     * @param PdoDb $db
     * @param string $format
     *
     * @return int Exit code
     */
    protected function watchQueries(PdoDb $db, string $format): int
    {
        // Use ANSI escape sequence to clear screen (cross-platform)
        $clear = "\033[2J\033[H";

        echo $clear;
        echo "Monitoring active queries (Press Ctrl+C to exit)\n\n";

        /* @phpstan-ignore-next-line */
        while (true) {
            $queries = MonitorManager::getActiveQueries($db);
            echo $clear;
            echo "Monitoring active queries (Press Ctrl+C to exit)\n";
            echo 'Updated: ' . date('Y-m-d H:i:s') . "\n\n";
            $this->printFormatted(['queries' => $queries], $format);
            sleep(2);
        }
    }

    /**
     * Watch connections in real-time.
     *
     * @param PdoDb $db
     * @param string $format
     *
     * @return int Exit code
     */
    protected function watchConnections(PdoDb $db, string $format): int
    {
        // Use ANSI escape sequence to clear screen (cross-platform)
        $clear = "\033[2J\033[H";

        echo $clear;
        echo "Monitoring active connections (Press Ctrl+C to exit)\n\n";

        /* @phpstan-ignore-next-line */
        while (true) {
            $connections = MonitorManager::getActiveConnections($db);
            echo $clear;
            echo "Monitoring active connections (Press Ctrl+C to exit)\n";
            echo 'Updated: ' . date('Y-m-d H:i:s') . "\n\n";
            $this->printFormatted(['connections' => $connections], $format);
            sleep(2);
        }
    }

    /**
     * Parse time threshold string (e.g., "1s", "500ms", "2.5s").
     *
     * @param string $threshold
     *
     * @return float Seconds
     */
    protected function parseTimeThreshold(string $threshold): float
    {
        $threshold = trim($threshold);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(s|ms|sec|seconds|milliseconds?)?$/i', $threshold, $matches)) {
            $value = (float)$matches[1];
            $unit = strtolower($matches[2] ?? 's');
            if (str_starts_with($unit, 'ms') || str_starts_with($unit, 'milli')) {
                return $value / 1000;
            }
            return $value;
        }
        // Default to seconds if parsing fails
        return (float)$threshold;
    }

    /**
     * Print formatted output.
     *
     * @param array<string, mixed> $data
     * @param string $format
     *
     * @return int Exit code
     */
    protected function printFormatted(array $data, string $format): int
    {
        $fmt = strtolower($format);
        if ($fmt === 'json') {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }
        // table format (default)
        $this->printTable($data);
        return 0;
    }

    /**
     * Print table format.
     *
     * @param array<string, mixed> $data
     */
    protected function printTable(array $data): void
    {
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                // Handle connections structure: {connections: [...], summary: {...}}
                if ($key === 'connections' && isset($val['connections']) && isset($val['summary'])) {
                    echo ucfirst('connections') . ":\n";
                    if (!empty($val['connections']) && is_array($val['connections'])) {
                        /** @var array<int, array<string, mixed>> $connections */
                        $connections = $val['connections'];
                        $this->printTableRows($connections);
                    } else {
                        echo "  (none)\n";
                    }
                    echo "\nSummary:\n";
                    if (is_array($val['summary'])) {
                        foreach ($val['summary'] as $summaryKey => $summaryVal) {
                            $summaryKeyStr = is_string($summaryKey) ? $summaryKey : '';
                            $summaryValStr = is_string($summaryVal) || is_int($summaryVal) || is_float($summaryVal) ? (string)$summaryVal : '';
                            echo "  {$summaryKeyStr}: {$summaryValStr}\n";
                        }
                    }
                    continue;
                }

                if (!empty($val)) {
                    $keyStr = is_string($key) ? $key : '';
                    echo ucfirst($keyStr) . ":\n";
                    $this->printTableRows($val);
                } else {
                    $keyStr = is_string($key) ? $key : '';
                    echo ucfirst($keyStr) . ": (none)\n";
                }
            } else {
                $keyStr = is_string($key) ? $key : '';
                $valStr = is_string($val) || is_int($val) || is_float($val) ? (string)$val : '';
                echo ucfirst($keyStr) . ": {$valStr}\n";
            }
        }
    }

    /**
     * Print table rows.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function printTableRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // Get all column names
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $col) {
                if (!in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
        }

        // Calculate column widths
        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = strlen($col);
            foreach ($rows as $row) {
                $val = $row[$col] ?? '';
                $valStr = is_string($val) || is_int($val) || is_float($val) ? (string)$val : '';
                $widths[$col] = max($widths[$col], strlen($valStr));
            }
        }

        // Print header
        $header = '  ';
        $separator = '  ';
        foreach ($columns as $col) {
            $header .= str_pad($col, $widths[$col]) . '  ';
            $separator .= str_repeat('-', $widths[$col]) . '  ';
        }
        echo $header . "\n";
        echo $separator . "\n";

        // Print rows
        foreach ($rows as $row) {
            $line = '  ';
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                $valStr = is_string($val) || is_int($val) || is_float($val) ? (string)$val : '';
                $line .= str_pad($valStr, $widths[$col]) . '  ';
            }
            echo $line . "\n";
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Database Monitoring\n\n";
        echo "Usage: pdodb monitor <subcommand> [options]\n\n";
        echo "Subcommands:\n";
        echo "  queries [--watch] [--format=table|json]     Monitor active queries\n";
        echo "  connections [--watch] [--format=table|json] Monitor active connections\n";
        echo "  slow [--threshold=1s] [--limit=10] [--format=table|json]  Monitor slow queries\n";
        echo "  stats [--format=table|json]                Show query statistics\n\n";
        echo "Options:\n";
        echo "  --watch              Update in real-time (for queries/connections)\n";
        echo "  --format=table|json Output format (default: table)\n";
        echo "  --threshold=TIME    Slow query threshold (e.g., 1s, 500ms, 2.5s)\n";
        echo "  --limit=N           Maximum number of slow queries to show (default: 10)\n\n";
        echo "Examples:\n";
        echo "  pdodb monitor queries\n";
        echo "  pdodb monitor queries --watch\n";
        echo "  pdodb monitor connections --format=json\n";
        echo "  pdodb monitor slow --threshold=2s --limit=20\n";
        echo "  pdodb monitor stats\n";
        return 0;
    }
}
