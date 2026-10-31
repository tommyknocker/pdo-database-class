<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\ai\QueryInspectionService;
use tommyknocker\pdodb\exceptions\ProfileReportException;
use tommyknocker\pdodb\PdoDb;

/**
 * In-process profile summary and local plan diagnosis.
 *
 * The report reads the profiler of this connection. Another process, including
 * `pdodb monitor stats`, does not see it. diagnose() uses SQL supplied by the
 * caller and does not select a row from this summary.
 */
class ProfileReport
{
    public function __construct(protected PdoDb $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $profiler = $this->db->getProfiler();
        if ($profiler === null || !$profiler->isEnabled()) {
            return [
                'enabled' => false,
                'note' => 'Profiling is not enabled on this connection. A separate process cannot read an in-memory profile.',
            ];
        }

        return [
            'enabled' => true,
            'aggregated' => $profiler->getAggregatedStats(),
            'queries' => $profiler->getSlowestQueries(20),
            'truncated' => $profiler->isTruncated(),
            'parameters_included' => false,
            'note' => 'Recognized single-quoted string literals, numeric literals, and comments are removed from SQL. Double-quoted text is removed when this connection treats it as a string or when SQLite may execute an unknown double-quoted name as a value. On MySQL and MariaDB without ANSI_QUOTES, double quotes are strings. Otherwise double-quoted text may stay visible because it can be an identifier. Bound parameter values are omitted. This is not a complete privacy boundary. possible_n_plus_one means one SQL shape ran many times; it is not proof of an N+1 bug.',
        ];
    }

    /**
     * Local plan and index advice. This method does not call a model.
     *
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function diagnose(string $sql, array $params = []): array
    {
        $inspection = (new QueryInspectionService($this->db))->inspect($sql, $params);
        return [
            'source' => $inspection['source'],
            'warnings' => $inspection['warnings'],
            'analysis' => QueryInspectionService::format($inspection['analysis']),
            'model_request' => false,
        ];
    }

    /**
     * Diagnose the Nth slowest query on this connection (1 = slowest). Uses raw SQL from the profiler.
     *
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function diagnoseRankedQuery(int $rank, array $params = []): array
    {
        $profiler = $this->db->getProfiler();
        if ($profiler === null || !$profiler->isEnabled()) {
            throw new ProfileReportException(
                'Profiling is not enabled on this connection. Enable it before diagnosing a ranked query.'
            );
        }

        $sql = $profiler->getRawSqlBySlowestRank($rank);
        if ($sql === null || $sql === '') {
            throw new ProfileReportException("No profiled query at rank {$rank}.");
        }

        $result = $this->diagnose($sql, $params);
        $result['rank'] = $rank;
        $result['sql'] = $sql;

        return $result;
    }

    /**
     * Parse JSON from {@see export()}.
     *
     * @return array<string, mixed>
     */
    public static function parseExport(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data) || !array_key_exists('enabled', $data) || !is_bool($data['enabled'])) {
            throw new ProfileReportException('Profile export must be a JSON object with an enabled flag.');
        }

        return $data;
    }

    /**
     * Human-readable summary of a parsed export (for CLI --text).
     *
     * @param array<string, mixed> $data
     */
    public static function formatExportAsText(array $data): string
    {
        if (($data['enabled'] ?? false) !== true) {
            $note = is_string($data['note'] ?? null) ? $data['note'] : 'Profiling was disabled in the export.';

            return "Query profile summary\n=====================\nStatus: disabled\n{$note}\n";
        }

        $agg = is_array($data['aggregated'] ?? null) ? $data['aggregated'] : [];
        $lines = [
            'Query profile summary',
            '=====================',
            'Status: enabled',
            sprintf('Total queries: %d', (int) ($agg['total_queries'] ?? 0)),
            sprintf('Total time: %.2f ms', (float) ($agg['total_time'] ?? 0)),
            sprintf('Average time: %.2f ms', (float) ($agg['avg_time'] ?? 0)),
            sprintf('Slow queries: %d', (int) ($agg['slow_queries'] ?? 0)),
        ];

        if (($data['truncated'] ?? false) === true) {
            $lines[] = 'Note: profiler storage was truncated; counts may be incomplete.';
        }

        $queries = is_array($data['queries'] ?? null) ? $data['queries'] : [];
        if ($queries !== []) {
            $lines[] = '';
            $lines[] = 'Slowest query shapes:';
            $rank = 1;
            foreach ($queries as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sql = is_string($row['sql'] ?? null) ? $row['sql'] : '(unknown sql)';
                $count = (int) ($row['count'] ?? 0);
                $totalTime = (float) ($row['total_time'] ?? 0);
                $nPlusOne = ($row['possible_n_plus_one'] ?? false) === true ? ' possible N+1' : '';
                $lines[] = sprintf(
                    '%d. x%d, %.2f ms total%s',
                    $rank,
                    $count,
                    $totalTime,
                    $nPlusOne
                );
                $lines[] = '   ' . $sql;
                ++$rank;
            }
        }

        $lines[] = '';
        $lines[] = 'Use pdodb profile diagnose "SQL" for plan advice on a specific statement.';

        return implode("\n", $lines) . "\n";
    }

    public function export(): string
    {
        return json_encode($this->summary(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
