<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\ai\QueryInspectionService;
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
            'note' => 'Recognized single-quoted string literals, numeric literals, and comments are removed from SQL. Double-quoted text is removed only when this connection treats double quotes as string delimiters. Otherwise it is kept, because it may be an identifier. On SQLite an unknown double-quoted name can still be a string value. Bound parameter values are omitted. This is not a complete privacy boundary. possible_n_plus_one means one SQL shape ran many times; it is not proof of an N+1 bug.',
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

    public function export(): string
    {
        return json_encode($this->summary(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
