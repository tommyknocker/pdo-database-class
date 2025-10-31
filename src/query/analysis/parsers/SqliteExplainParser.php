<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis\parsers;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

/**
 * Parser for SQLite EXPLAIN QUERY PLAN output.
 */
class SqliteExplainParser implements ExplainParserInterface
{
    public function parse(array $explainResults): ParsedExplainPlan
    {
        $plan = new ParsedExplainPlan();
        $plan->nodes = $explainResults;

        foreach ($explainResults as $row) {
            $opcodeValue = $row['opcode'] ?? '';
            $opcode = is_string($opcodeValue) ? $opcodeValue : '';
            $p4Value = $row['p4'] ?? '';
            $p4 = is_string($p4Value) ? $p4Value : '';
            $p5 = $row['p5'] ?? null;

            // SQLite opcodes indicate scan types
            if ($opcode === 'ScanTable') {
                // Full table scan
                $table = $p4 !== '' ? trim($p4) : 'unknown';
                if (!in_array($table, $plan->tableScans, true)) {
                    $plan->tableScans[] = $table;
                }
                $plan->accessType = 'Table Scan';
            } elseif ($opcode === 'OpenRead') {
                // Index scan if p5 is set (cursor number)
                if ($p5 !== null) {
                    // Try to extract index name from comment or p4
                    $commentValue = $row['comment'] ?? '';
                    $comment = is_string($commentValue) ? $commentValue : '';
                    if ($comment !== '' && preg_match('/INDEX\s+(\S+)/i', $comment, $matches)) {
                        $plan->usedIndex = trim($matches[1]);
                        $plan->accessType = 'Index Scan';
                    } elseif ($p4 !== '') {
                        // Sometimes p4 contains table/index info
                        $plan->usedIndex = trim($p4);
                        $plan->accessType = 'Index Scan';
                    }
                }
            } elseif ($opcode === 'IdxScan') {
                // Explicit index scan
                $table = $p4 !== '' ? trim($p4) : 'unknown';
                $plan->usedIndex = $table;
                $plan->accessType = 'Index Scan';
            }

            // Extract row estimates from p2 (rows to read)
            $p2 = $row['p2'] ?? null;
            if ($p2 !== null && is_numeric($p2)) {
                $rows = (int)$p2;
                if ($rows > 0) {
                    $plan->estimatedRows = max($plan->estimatedRows, $rows);
                }
            }
        }

        // Detect warnings for full table scans without index
        if (!empty($plan->tableScans) && $plan->usedIndex === null) {
            foreach ($plan->tableScans as $table) {
                $plan->warnings[] = sprintf(
                    'Full table scan on "%s" without index usage',
                    $table
                );
            }
        }

        return $plan;
    }
}
