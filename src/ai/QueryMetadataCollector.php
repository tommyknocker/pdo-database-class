<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use PDO;
use RuntimeException;
use tommyknocker\pdodb\PdoDb;

/** Reads only the named object's catalogs, with normalized fields and partial failures. */
final class QueryMetadataCollector
{
    private string $driver;
    private ?string $schema = null;
    private int $queries = 0;
    private float $started;

    /** @param array<string, mixed> $settings */
    public function __construct(private PdoDb $db, private array $settings)
    {
        $this->driver = $db->connection->getDialect()->getDriverName();
        $this->started = microtime(true);
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    /** @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = [], int $limit = 1000): array
    {
        if ($this->queries >= $this->settings['max_catalog_queries'] || (microtime(true) - $this->started) * 1000 >= $this->settings['max_collection_ms']) {
            throw new RuntimeException('Metadata collection time budget reached');
        }
        ++$this->queries;
        $statement = $this->db->connection->getPdo()->prepare($sql);
        $statement->execute($params);
        $rows = [];

        try {
            while (count($rows) < $limit && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = array_change_key_case($row, CASE_LOWER);
            }
        } finally {
            $statement->closeCursor();
        }
        return $rows;
    }

    private function quote(string $name): string
    {
        return $this->db->connection->getDialect()->quoteIdentifier($name);
    }

    public function defaultSchema(): string
    {
        if ($this->schema === null) {
            $sql = match ($this->driver) {
                'mysql', 'mariadb' => 'SELECT DATABASE()',
                'pgsql' => 'SELECT current_schema()',
                'sqlsrv' => 'SELECT SCHEMA_NAME()',
                'oci' => "SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') FROM DUAL",
                default => null,
            };
            $row = $sql === null ? ['main'] : ($this->rows($sql, limit: 1)[0] ?? []);
            $value = reset($row);
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('Unable to determine the current metadata schema');
            }
            $this->schema = $value;
        }
        return $this->schema;
    }

    /** @return array<string, mixed> */
    public function collect(string $reference): array
    {
        $name = SqlContextTokenizer::table($reference);
        if ($name === null) {
            return ['reference' => $reference, 'warnings' => ['Unsupported table reference'], 'complete' => false];
        }
        $table = $name['table'];
        $schema = $name['schema'] ?? $this->defaultSchema();
        if ($name['schema'] === null && $this->driver === 'pgsql') {
            $lookup = $this->rows('SELECT n.nspname AS schema_name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass(?)', [$this->quote($table)], 1);
            $schema = (string)($lookup[0]['schema_name'] ?? $schema);
        }
        // Unquoted Oracle and PostgreSQL identifiers are folded by the server.
        if (!str_contains($reference, '"') && !str_contains($reference, '`') && !str_contains($reference, '[')) {
            if ($this->driver === 'oci') {
                $schema = strtoupper($schema);
                $table = strtoupper($table);
            } elseif ($this->driver === 'pgsql') {
                $schema = strtolower($schema);
                $table = strtolower($table);
            }
        }
        $result = ['schema' => $schema, 'table' => $table, 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'warnings' => [], 'complete' => true];
        foreach (['columns', 'indexes', 'foreignKeys'] as $part) {
            $key = $part === 'foreignKeys' ? 'foreign_keys' : $part;

            try {
                $result[$key] = $this->$part($schema, $table);
            } catch (\Throwable) {
                $result['warnings'][] = "Unable to collect {$key}; catalog access or collection budget may be limited";
                $result['complete'] = false;
            }
        }
        if ($result['columns'] === []) {
            $result['warnings'][] = 'No columns were returned; this object may be missing or inaccessible';
            $result['complete'] = false;
        }
        if (count($result['columns']) > $this->settings['max_columns']) {
            $result['columns'] = array_slice($result['columns'], 0, $this->settings['max_columns']);
            $result['warnings'][] = 'Column limit reached';
            $result['complete'] = false;
        }
        if (count($result['indexes']) > $this->settings['max_indexes']) {
            $result['indexes'] = array_slice($result['indexes'], 0, $this->settings['max_indexes']);
            $result['warnings'][] = 'Index limit reached';
            $result['complete'] = false;
        }
        if (count($result['foreign_keys']) > $this->settings['max_columns']) {
            $result['foreign_keys'] = array_slice($result['foreign_keys'], 0, $this->settings['max_columns']);
            $result['warnings'][] = 'Foreign-key row limit reached';
            $result['complete'] = false;
        }
        foreach ($result['indexes'] as &$index) {
            if (count($index['columns']) > $this->settings['max_columns']) {
                $index['columns'] = array_slice($index['columns'], 0, $this->settings['max_columns']);
                $result['warnings'][] = 'Index-column limit reached';
                $result['complete'] = false;
            }
        }
        unset($index);
        if ($this->settings['include_statistics']) {
            try {
                $result['statistics'] = $this->statistics($schema, $table);
            } catch (\Throwable) {
                $result['statistics'] = ['row_count' => null, 'exact' => false, 'source' => 'unavailable'];
            }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function columns(string $schema, string $table): array
    {
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS column_key, COLUMN_DEFAULT AS default_value FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            'pgsql', 'sqlsrv' => 'SELECT column_name AS name, data_type AS type, is_nullable AS nullable, column_default AS default_value FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            'oci' => 'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, NULLABLE AS nullable, DATA_DEFAULT AS default_value FROM ALL_TAB_COLUMNS WHERE OWNER = ? AND TABLE_NAME = ? ORDER BY COLUMN_ID',
            default => 'PRAGMA ' . $this->quote($schema) . '.table_xinfo(' . $this->quote($table) . ')',
        };
        $rows = $this->rows($sql, $this->driver === 'sqlite' ? [] : [$schema, $table], $this->settings['max_columns'] + 1);
        return array_map(function ($row) {
            $column = ['name' => $row['name'], 'type' => $row['type'],
                'nullable' => isset($row['notnull']) ? !(bool)$row['notnull'] && !(bool)($row['pk'] ?? false) : in_array($row['nullable'] ?? '', ['YES', 'Y'], true),
                'primary_key' => isset($row['pk']) ? (bool)$row['pk'] : (($row['column_key'] ?? '') === 'PRI' ? true : null)];
            if ($this->settings['privacy'] === 'raw') {
                $column['default'] = $row['default_value'] ?? $row['dflt_value'] ?? null;
            }
            return $column;
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function indexes(string $schema, string $table): array
    {
        if ($this->driver === 'sqlite') {
            $indexes = $this->rows('PRAGMA ' . $this->quote($schema) . '.index_list(' . $this->quote($table) . ')', limit: $this->settings['max_indexes'] + 1);
            $result = [];
            foreach ($indexes as $index) {
                $columns = $this->rows('PRAGMA ' . $this->quote($schema) . '.index_xinfo(' . $this->quote((string)$index['name']) . ')', limit: $this->settings['max_columns'] + 1);
                $definition = $this->rows('SELECT sql FROM ' . $this->quote($schema) . '.sqlite_master WHERE name = ? AND type = \'index\'', [(string)$index['name']], 1)[0]['sql'] ?? null;
                $result[] = ['name' => $index['name'], 'unique' => (bool)$index['unique'], 'primary' => ($index['origin'] ?? '') === 'pk',
                    'columns' => array_values(array_map(static fn ($c) => ['name' => $c['name'], 'position' => (int)$c['seqno'] + 1, 'descending' => (bool)$c['desc'], 'expression' => $c['cid'] === -2 ? 'See definition' : null], array_filter($columns, static fn ($c) => (bool)($c['key'] ?? true)))),
                    'partial' => (bool)($index['partial'] ?? false), 'definition' => is_string($definition) ? $this->text($definition) : null];
            }
            return $result;
        }
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SHOW INDEXES FROM ' . $this->quote($schema) . '.' . $this->quote($table),
            'pgsql' => 'SELECT ci.relname AS name, i.indisunique AS is_unique, i.indisprimary AS is_primary, k.pos AS position, a.attname AS column_name, pg_index_column_has_property(i.indexrelid, k.pos, \'desc\') AS descending_key, pg_get_indexdef(i.indexrelid, k.pos, true) AS column_expression, k.pos > i.indnkeyatts AS is_included, pg_get_indexdef(i.indexrelid) AS definition, pg_get_expr(i.indpred, i.indrelid) AS predicate FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace JOIN pg_class ci ON ci.oid = i.indexrelid CROSS JOIN LATERAL generate_series(1, i.indnatts) AS k(pos) LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[k.pos - 1] WHERE ns.nspname = ? AND t.relname = ? ORDER BY ci.relname, k.pos',
            'sqlsrv' => 'SELECT i.name, i.is_unique, i.is_primary_key AS is_primary, ic.key_ordinal AS position, c.name AS column_name, ic.is_descending_key AS descending_key, ic.is_included_column AS is_included, i.filter_definition AS predicate FROM sys.indexes i JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_SCHEMA_NAME(i.object_id) = ? AND OBJECT_NAME(i.object_id) = ? ORDER BY i.name, ic.key_ordinal, ic.index_column_id',
            'oci' => 'SELECT i.INDEX_NAME AS name, i.UNIQUENESS AS uniqueness, c.COLUMN_NAME AS column_name, c.COLUMN_POSITION AS position, c.DESCEND AS direction, e.COLUMN_EXPRESSION AS column_expression FROM ALL_INDEXES i JOIN ALL_IND_COLUMNS c ON c.INDEX_OWNER = i.OWNER AND c.INDEX_NAME = i.INDEX_NAME LEFT JOIN ALL_IND_EXPRESSIONS e ON e.INDEX_OWNER = c.INDEX_OWNER AND e.INDEX_NAME = c.INDEX_NAME AND e.COLUMN_POSITION = c.COLUMN_POSITION WHERE i.TABLE_OWNER = ? AND i.TABLE_NAME = ? ORDER BY i.INDEX_NAME, c.COLUMN_POSITION',
            default => throw new RuntimeException('Unsupported index catalog'),
        };
        $rows = $this->rows($sql, in_array($this->driver, ['mysql', 'mariadb'], true) ? [] : [$schema, $table], ($this->settings['max_indexes'] + 1) * ($this->settings['max_columns'] + 1));
        $indexes = [];
        foreach ($rows as $row) {
            if ($this->driver === 'oci' && isset($row['column_expression'])) {
                $expression = trim((string)$row['column_expression']);
                // Oracle represents descending/function indexes through hidden columns.
                if (preg_match('/^"((?:[^"]|"")+)"$/D', $expression, $column)) {
                    $row['column_name'] = str_replace('""', '"', $column[1]);
                } elseif (str_starts_with((string)($row['column_name'] ?? ''), 'SYS_NC')) {
                    $row['column_name'] = null;
                }
            }
            $name = (string)($row['name'] ?? $row['key_name']);
            $indexes[$name] ??= ['name' => $name, 'unique' => isset($row['non_unique']) ? !(bool)$row['non_unique'] : (isset($row['is_unique']) ? (bool)$row['is_unique'] : ($row['uniqueness'] ?? '') === 'UNIQUE'),
                'primary' => isset($row['is_primary']) ? (bool)$row['is_primary'] : ($name === 'PRIMARY' ? true : null), 'columns' => [],
                'predicate' => isset($row['predicate']) ? $this->text((string)$row['predicate']) : null,
                'definition' => isset($row['definition']) ? $this->text((string)$row['definition']) : null];
            $indexes[$name]['columns'][] = ['name' => $row['column_name'] ?? null,
                'expression' => isset($row['column_expression']) ? $this->text((string)$row['column_expression']) : (isset($row['expression']) ? $this->text((string)$row['expression']) : null),
                'position' => (int)($row['position'] ?? $row['seq_in_index'] ?? 0),
                'descending' => isset($row['descending_key']) ? (bool)$row['descending_key'] : (($row['collation'] ?? $row['direction'] ?? '') === 'D' || ($row['direction'] ?? '') === 'DESC'),
                'included' => (bool)($row['is_included'] ?? false)];
        }
        return array_values($indexes);
    }

    private function text(string $text): string
    {
        return $this->settings['privacy'] === 'raw' ? $text : SqlContextTokenizer::redact($text);
    }

    /** @return list<array<string, mixed>> */
    private function foreignKeys(string $schema, string $table): array
    {
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SELECT CONSTRAINT_NAME AS name, COLUMN_NAME AS column_name, REFERENCED_TABLE_SCHEMA AS referenced_schema, REFERENCED_TABLE_NAME AS referenced_table, REFERENCED_COLUMN_NAME AS referenced_column FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            'pgsql' => 'SELECT con.conname AS name, a.attname AS column_name, refns.nspname AS referenced_schema, ref.relname AS referenced_table, ra.attname AS referenced_column FROM pg_constraint con JOIN pg_class tab ON tab.oid = con.conrelid JOIN pg_namespace ns ON ns.oid = tab.relnamespace JOIN pg_class ref ON ref.oid = con.confrelid JOIN pg_namespace refns ON refns.oid = ref.relnamespace JOIN LATERAL generate_subscripts(con.conkey, 1) AS pos(n) ON true JOIN pg_attribute a ON a.attrelid = tab.oid AND a.attnum = con.conkey[pos.n] JOIN pg_attribute ra ON ra.attrelid = ref.oid AND ra.attnum = con.confkey[pos.n] WHERE con.contype = \'f\' AND ns.nspname = ? AND tab.relname = ? ORDER BY con.conname, pos.n',
            'sqlsrv' => 'SELECT fk.name, c.name AS column_name, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS referenced_schema, OBJECT_NAME(fk.referenced_object_id) AS referenced_table, rc.name AS referenced_column FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = ? AND OBJECT_NAME(fk.parent_object_id) = ? ORDER BY fk.name, fkc.constraint_column_id',
            'oci' => 'SELECT c.CONSTRAINT_NAME AS name, col.COLUMN_NAME AS column_name, ref.OWNER AS referenced_schema, ref.TABLE_NAME AS referenced_table, refcol.COLUMN_NAME AS referenced_column FROM ALL_CONSTRAINTS c JOIN ALL_CONS_COLUMNS col ON col.OWNER = c.OWNER AND col.CONSTRAINT_NAME = c.CONSTRAINT_NAME JOIN ALL_CONSTRAINTS ref ON ref.OWNER = c.R_OWNER AND ref.CONSTRAINT_NAME = c.R_CONSTRAINT_NAME JOIN ALL_CONS_COLUMNS refcol ON refcol.OWNER = ref.OWNER AND refcol.CONSTRAINT_NAME = ref.CONSTRAINT_NAME AND refcol.POSITION = col.POSITION WHERE c.OWNER = ? AND c.TABLE_NAME = ? AND c.CONSTRAINT_TYPE = \'R\' ORDER BY c.CONSTRAINT_NAME, col.POSITION',
            default => 'PRAGMA ' . $this->quote($schema) . '.foreign_key_list(' . $this->quote($table) . ')',
        };
        $rows = $this->rows($sql, $this->driver === 'sqlite' ? [] : [$schema, $table], $this->settings['max_columns'] + 1);
        return array_map(static fn ($r) => ['name' => $r['name'] ?? $r['id'] ?? null, 'column' => $r['column_name'] ?? $r['from'] ?? null,
            'referenced_schema' => $r['referenced_schema'] ?? $schema, 'referenced_table' => $r['referenced_table'] ?? $r['table'] ?? null,
            'referenced_column' => $r['referenced_column'] ?? $r['to'] ?? null], $rows);
    }

    /** @return array<string, mixed> */
    private function statistics(string $schema, string $table): array
    {
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SELECT TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS size_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            'pgsql' => 'SELECT c.reltuples AS row_count, s.last_analyze, s.last_autoanalyze FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace LEFT JOIN pg_stat_all_tables s ON s.relid = c.oid WHERE n.nspname = ? AND c.relname = ?',
            'oci' => 'SELECT NUM_ROWS AS row_count, LAST_ANALYZED AS last_analyzed FROM ALL_TABLES WHERE OWNER = ? AND TABLE_NAME = ?',
            'sqlsrv' => 'SELECT SUM(p.rows) AS row_count FROM sys.partitions p JOIN sys.tables t ON p.object_id = t.object_id JOIN sys.schemas s ON s.schema_id = t.schema_id WHERE s.name = ? AND t.name = ? AND p.index_id IN (0, 1)',
            default => null,
        };
        $row = $sql === null ? [] : ($this->rows($sql, [$schema, $table], 1)[0] ?? []);
        if (isset($row['row_count']) && (float)$row['row_count'] < 0) {
            $row['row_count'] = null;
        }
        return $row + ['row_count' => null, 'exact' => false, 'source' => $sql === null ? 'unavailable' : $this->driver . '_catalog', 'freshness' => 'Unknown unless an analyze timestamp is present'];
    }
}
