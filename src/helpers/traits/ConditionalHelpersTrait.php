<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for conditional logic.
 */
trait ConditionalHelpersTrait
{
    /**
     * Returns a RawValue instance representing a CASE statement.
     *
     * @param array<string, string> $cases An associative array where keys are WHEN conditions and values are THEN results.
     * @param string|null $else An optional ELSE result.
     *
     * @return RawValue The RawValue instance for the CASE statement.
     */
    public static function case(array $cases, string|null $else = null): RawValue
    {
        $sql = 'CASE';
        foreach ($cases as $when => $then) {
            // Quote identifiers in simple conditions (e.g., "plan = 'value'" -> "[plan] = 'value'")
            // This handles MSSQL reserved words like 'plan'
            $whenQuoted = static::quoteIdentifiersInCondition($when);
            $sql .= " WHEN $whenQuoted THEN $then";
        }
        if ($else !== null) {
            $sql .= " ELSE $else";
        }
        $sql .= ' END';

        return new RawValue($sql);
    }

    /**
     * Quote identifiers in simple SQL conditions.
     * Handles patterns like "column = 'value'", "column = value", etc.
     * Only quotes identifiers that are reserved words, not SQL keywords/operators.
     *
     * @param string $condition The condition string.
     *
     * @return string The condition with quoted identifiers.
     */
    protected static function quoteIdentifiersInCondition(string $condition): string
    {
        // List of MSSQL reserved words that should be quoted when used as identifiers
        // These are words that can be column/table names but are reserved in MSSQL
        $mssqlReservedWords = [
            'plan', 'order', 'group', 'user', 'table', 'index', 'key', 'value',
            'select', 'insert', 'update', 'delete', 'create', 'drop', 'alter',
            'where', 'from', 'join', 'inner', 'left', 'right', 'outer', 'on',
            'as', 'case', 'when', 'then', 'else', 'end', 'if', 'null', 'not',
            'like', 'in', 'exists', 'between', 'is', 'distinct', 'by', 'having',
            'union', 'intersect', 'except', 'all', 'any', 'some', 'over',
            'partition', 'rows', 'range', 'preceding', 'following', 'current',
            'row', 'unbounded', 'first', 'last', 'rank', 'dense_rank', 'row_number',
            'percent_rank', 'cume_dist', 'lag', 'lead', 'first_value', 'last_value',
            'nth_value', 'ntile', 'count', 'sum', 'avg', 'min', 'max', 'stddev',
            'variance', 'cast', 'convert', 'extract', 'date', 'time', 'timestamp',
            'year', 'month', 'day', 'hour', 'minute', 'second', 'interval',
            'truncate', 'round', 'floor', 'ceiling', 'abs', 'sign', 'mod', 'power',
            'sqrt', 'exp', 'ln', 'log', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan',
            'atan2', 'degrees', 'radians', 'pi', 'random', 'trunc', 'greatest',
            'least', 'coalesce', 'nullif', 'ifnull', 'nvl', 'decode', 'iif',
            'choose', 'concat', 'substring', 'left', 'right', 'length', 'char_length',
            'character_length', 'upper', 'lower', 'trim', 'ltrim', 'rtrim', 'replace',
            'reverse', 'repeat', 'space', 'stuff', 'charindex', 'patindex', 'soundex',
            'difference', 'str', 'format', 'dateadd', 'datediff', 'datepart', 'datename',
            'getdate', 'getutcdate', 'sysdatetime', 'sysutcdatetime', 'current_timestamp',
            'current_date', 'current_time', 'localtime', 'localtimestamp', 'utc_timestamp',
            'utc_date', 'utc_time', 'now', 'curdate', 'curtime', 'dayofweek', 'dayofyear',
            'week', 'weekday', 'quarter', 'yearweek', 'timestampadd', 'timestampdiff',
            'adddate', 'subdate', 'addtime', 'subtime', 'maketime', 'makedate', 'from_days',
            'to_days', 'from_unixtime', 'unix_timestamp', 'sec_to_time', 'time_to_sec',
            'str_to_date', 'date_format', 'time_format', 'to_char', 'to_date', 'to_number',
            'to_timestamp', 'age', 'date_trunc', 'make_interval', 'justify_interval',
            'justify_days', 'justify_hours', 'overlaps', 'isfinite', 'isnormal',
            'width_bucket', 'regr_slope', 'regr_intercept', 'regr_count', 'regr_r2',
            'regr_avgx', 'regr_avgy', 'regr_sxx', 'regr_syy', 'regr_sxy', 'corr',
            'covar_pop', 'covar_samp', 'stddev_pop', 'stddev_samp', 'var_pop', 'var_samp',
            'percentile_cont', 'percentile_disc', 'mode', 'median', 'percentile',
            'ratio_to_report', 'listagg', 'xmlagg', 'json_arrayagg', 'json_objectagg',
            'json_agg', 'array_agg', 'string_agg', 'group_concat', 'wm_concat',
            'json_array', 'json_object', 'json_extract', 'json_unquote', 'json_contains',
            'json_contains_path', 'json_depth', 'json_length', 'json_keys', 'json_pretty',
            'json_quote', 'json_remove', 'json_replace', 'json_search', 'json_set',
            'json_type', 'json_valid', 'json_value', 'json_query', 'json_modify',
            'isjson', 'json_path_exists', 'openjson', 'json_table', 'json_each',
            'json_each_text', 'json_populate_record', 'json_populate_recordset',
            'json_to_record', 'json_to_recordset', 'jsonb_array_elements',
            'jsonb_array_elements_text', 'jsonb_each', 'jsonb_each_text',
            'jsonb_object_keys', 'jsonb_populate_record', 'jsonb_populate_recordset',
            'jsonb_to_record', 'jsonb_to_recordset', 'jsonb_path_exists', 'jsonb_path_match',
            'jsonb_path_query', 'jsonb_path_query_array', 'jsonb_path_query_first',
            'jsonb_typeof', 'jsonb_pretty', 'jsonb_strip_nulls', 'jsonb_set', 'jsonb_insert',
            'jsonb_replace', 'jsonb_set_lax', 'jsonb_path_exists_tz', 'jsonb_path_match_tz',
            'jsonb_path_query_tz', 'jsonb_path_query_array_tz', 'jsonb_path_query_first_tz',
            'jsonb_extract_path', 'jsonb_extract_path_text', 'jsonb_array_length',
            'jsonb_object_field', 'jsonb_object_field_text',
        ];

        // SQL keywords/operators that should NOT be quoted
        $sqlKeywords = [
            'and', 'or', 'not', 'is', 'null', 'true', 'false', 'default',
            'like', 'in', 'exists', 'between', 'distinct', 'all', 'any', 'some',
            'as', 'case', 'when', 'then', 'else', 'end', 'if', 'cast', 'convert',
            'extract', 'date', 'time', 'timestamp', 'year', 'month', 'day',
            'hour', 'minute', 'second', 'interval', 'truncate', 'round', 'floor',
            'ceiling', 'abs', 'sign', 'mod', 'power', 'sqrt', 'exp', 'ln', 'log',
            'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2', 'degrees',
            'radians', 'pi', 'random', 'trunc', 'greatest', 'least', 'coalesce',
            'nullif', 'ifnull', 'nvl', 'decode', 'iif', 'choose', 'concat',
            'substring', 'left', 'right', 'length', 'char_length', 'character_length',
            'upper', 'lower', 'trim', 'ltrim', 'rtrim', 'replace', 'reverse',
            'repeat', 'space', 'stuff', 'charindex', 'patindex', 'soundex',
            'difference', 'str', 'format', 'dateadd', 'datediff', 'datepart',
            'datename', 'getdate', 'getutcdate', 'sysdatetime', 'sysutcdatetime',
            'current_timestamp', 'current_date', 'current_time', 'localtime',
            'localtimestamp', 'utc_timestamp', 'utc_date', 'utc_time', 'now',
            'curdate', 'curtime', 'dayofweek', 'dayofyear', 'week', 'weekday',
            'quarter', 'yearweek', 'timestampadd', 'timestampdiff', 'adddate',
            'subdate', 'addtime', 'subtime', 'maketime', 'makedate', 'from_days',
            'to_days', 'from_unixtime', 'unix_timestamp', 'sec_to_time', 'time_to_sec',
            'str_to_date', 'date_format', 'time_format', 'to_char', 'to_date',
            'to_number', 'to_timestamp', 'age', 'date_trunc', 'make_interval',
            'justify_interval', 'justify_days', 'justify_hours', 'overlaps',
            'isfinite', 'isnormal', 'width_bucket', 'regr_slope', 'regr_intercept',
            'regr_count', 'regr_r2', 'regr_avgx', 'regr_avgy', 'regr_sxx',
            'regr_syy', 'regr_sxy', 'corr', 'covar_pop', 'covar_samp',
            'stddev_pop', 'stddev_samp', 'var_pop', 'var_samp', 'percentile_cont',
            'percentile_disc', 'mode', 'median', 'percentile', 'ratio_to_report',
            'listagg', 'xmlagg', 'json_arrayagg', 'json_objectagg', 'json_agg',
            'array_agg', 'string_agg', 'group_concat', 'wm_concat', 'json_array',
            'json_object', 'json_extract', 'json_unquote', 'json_contains',
            'json_contains_path', 'json_depth', 'json_length', 'json_keys',
            'json_pretty', 'json_quote', 'json_remove', 'json_replace', 'json_search',
            'json_set', 'json_type', 'json_valid', 'json_value', 'json_query',
            'json_modify', 'isjson', 'json_path_exists', 'openjson', 'json_table',
            'json_each', 'json_each_text', 'json_populate_record', 'json_populate_recordset',
            'json_to_record', 'json_to_recordset', 'jsonb_array_elements',
            'jsonb_array_elements_text', 'jsonb_each', 'jsonb_each_text',
            'jsonb_object_keys', 'jsonb_populate_record', 'jsonb_populate_recordset',
            'jsonb_to_record', 'jsonb_to_recordset', 'jsonb_path_exists', 'jsonb_path_match',
            'jsonb_path_query', 'jsonb_path_query_array', 'jsonb_path_query_first',
            'jsonb_typeof', 'jsonb_pretty', 'jsonb_strip_nulls', 'jsonb_set',
            'jsonb_insert', 'jsonb_replace', 'jsonb_set_lax', 'jsonb_path_exists_tz',
            'jsonb_path_match_tz', 'jsonb_path_query_tz', 'jsonb_path_query_array_tz',
            'jsonb_path_query_first_tz', 'jsonb_extract_path', 'jsonb_extract_path_text',
            'jsonb_array_length', 'jsonb_object_field', 'jsonb_object_field_text',
        ];

        // Pattern: simple column comparison (e.g., "plan = 'value'", "column = value")
        // Match: identifier operator value
        // Only match identifiers that appear before operators, not SQL keywords
        // Operators: =, !=, <>, <, >, <=, >=, LIKE, ILIKE, IN, NOT IN, IS, IS NOT
        $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)\s*(=|!=|<>|<|>|<=|>=|LIKE|ILIKE|IN|NOT\s+IN|IS|IS\s+NOT)\s*/i';
        
        return preg_replace_callback($pattern, function ($matches) use ($mssqlReservedWords, $sqlKeywords) {
            $identifier = $matches[1];
            $operator = $matches[2];
            
            // Skip if identifier is already quoted
            if (preg_match('/^\[.*\]$/', $identifier) || preg_match('/^".*"$/', $identifier)) {
                return $matches[0];
            }
            
            // Skip if identifier is a SQL keyword/operator (not a column name)
            $identifierLower = strtolower($identifier);
            if (in_array($identifierLower, $sqlKeywords, true)) {
                return $matches[0];
            }
            
            // Check if identifier contains a reserved word that should be quoted
            $parts = explode('.', $identifier);
            $needsQuoting = false;
            foreach ($parts as $part) {
                $partLower = strtolower($part);
                // Only quote if it's a reserved word AND not a SQL keyword
                if (in_array($partLower, $mssqlReservedWords, true) && !in_array($partLower, $sqlKeywords, true)) {
                    $needsQuoting = true;
                    break;
                }
            }
            
            if ($needsQuoting) {
                // Only quote for MSSQL (check via environment variable)
                // Other dialects don't need quoting for these reserved words
                $driver = strtolower(getenv('PDODB_DRIVER') ?: '');
                if ($driver === 'mssql' || $driver === 'sqlsrv') {
                    // Quote each part of qualified identifier using MSSQL brackets
                    $quotedParts = array_map(function ($part) {
                        return '[' . str_replace(']', ']]', $part) . ']';
                    }, $parts);
                    $quotedIdentifier = implode('.', $quotedParts);
                    return $quotedIdentifier . ' ' . $operator . ' ';
                }
            }
            
            return $matches[0];
        }, $condition);
    }
}
