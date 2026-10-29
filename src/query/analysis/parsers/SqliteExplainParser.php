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
            if (isset($row['detail']) && is_string($row['detail'])) {
                $detail = $row['detail'];
                if (preg_match('/^(SCAN|SEARCH)\s+(?:TABLE\s+)?(\S+)/i', $detail, $match)) {
                    $plan->accessType = strtoupper($match[1]) === 'SCAN' ? 'Table Scan' : 'Index Search';
                    if (strtoupper($match[1]) === 'SCAN') {
                        $plan->tableScans[] = $match[2];
                    }
                }
                if (preg_match('/USING (?:COVERING )?INDEX (\S+)/i', $detail, $match)) {
                    $plan->usedIndex = $match[1];
                }
                if (str_contains($detail, 'USE TEMP B-TREE')) {
                    $plan->warnings[] = 'Temporary sorting/grouping B-tree required';
                }
                continue;
            }
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
                    }
                }
            } elseif ($opcode === 'IdxScan') {
                // Explicit index scan
                $table = $p4 !== '' ? trim($p4) : 'unknown';
                $plan->usedIndex = $table;
                $plan->accessType = 'Index Scan';
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
