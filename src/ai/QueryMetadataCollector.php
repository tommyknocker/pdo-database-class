<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\catalog\CatalogQueriesInterface;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\PdoDb;

/** Reads only the named object's catalogs, with normalized fields and partial failures. */
final class QueryMetadataCollector
{
    private ?string $schema = null;
    private int $queries = 0;
    private float $started;

    /** @param array<string, mixed> $settings */
    public function __construct(private PdoDb $db, private array $settings)
    {
        $this->started = microtime(true);
    }

    private function catalog(): CatalogQueriesInterface
    {
        return $this->db->connection->getDialect()->catalogQueries();
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    /**
     * @param array<int|string, mixed> $params
     *
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
            $catalog = $this->catalog();
            $current = $catalog->buildCurrentSchemaQuery();
            $row = $current === null ? ['main'] : ($this->rows($current[0], $current[1], limit: 1)[0] ?? []);
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
        [$schema, $table] = $this->foldReferenceIdentifiers($name);
        if ($schema === null) {
            $schema = $this->defaultSchema();
            $pgResolve = $this->catalog()->buildPgResolveSchemaQuery($this->quote($table));
            if ($pgResolve !== null) {
                $lookup = $this->rows($pgResolve[0], $pgResolve[1], 1);
                $schema = (string)($lookup[0]['schema_name'] ?? $schema);
            }
        }
        $result = ['schema' => $schema, 'table' => $table, 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'warnings' => [], 'complete' => true];
        $resolved = $this->catalog()->resolveOracleObjectName(
            fn (string $sql, array $params, int $limit): array => $this->rows($sql, $params, $limit),
            $schema,
            $table
        );
        if ($resolved !== $table) {
            $table = $resolved;
            $result['table'] = $resolved;
        }
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
        $catalog = $this->catalog();
        [$sql,] = $catalog->buildAiColumnsQuery($schema, $table);
        $rows = $this->rows(
            $sql,
            $catalog->aiColumnsQueryParameters($schema, $table),
            $this->settings['max_columns'] + 1
        );
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
        $catalog = $this->catalog();
        if ($catalog->usesSqlitePragmaIndexes()) {
            [$listSql,] = $catalog->buildSqliteIndexListQuery($schema, $table);
            $indexes = $this->rows($listSql, [], limit: $this->settings['max_indexes'] + 1);
            $result = [];
            foreach ($indexes as $index) {
                [$xinfoSql,] = $catalog->buildSqliteIndexXinfoQuery($schema, (string)$index['name']);
                $columns = $this->rows($xinfoSql, [], limit: $this->settings['max_columns'] + 1);
                [$defSql, $defParams] = $catalog->buildSqliteIndexDefinitionQuery($schema, (string)$index['name']);
                $definition = $this->rows($defSql, $defParams, 1)[0]['sql'] ?? null;
                $result[] = ['name' => $index['name'], 'unique' => (bool)$index['unique'], 'primary' => ($index['origin'] ?? '') === 'pk',
                    'columns' => array_values(array_map(static fn ($c) => ['name' => $c['name'], 'position' => (int)$c['seqno'] + 1, 'descending' => (bool)$c['desc'], 'expression' => $c['cid'] === -2 ? 'See definition' : null], array_filter($columns, static fn ($c) => (bool)($c['key'] ?? true)))),
                    'partial' => (bool)($index['partial'] ?? false), 'definition' => is_string($definition) ? $this->text($definition) : null];
            }

            return $result;
        }
        [$sql,] = $catalog->buildAiIndexesQuery($schema, $table);
        $rows = $this->rows(
            $sql,
            $catalog->aiIndexesQueryParameters($schema, $table),
            ($this->settings['max_indexes'] + 1) * ($this->settings['max_columns'] + 1)
        );
        $indexes = [];
        foreach ($rows as $row) {
            $row = $catalog->normalizeAiIndexColumnRow($row);
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
        $catalog = $this->catalog();
        [$sql,] = $catalog->buildAiForeignKeysQuery($schema, $table);
        $rows = $this->rows(
            $sql,
            $catalog->aiForeignKeysQueryParameters($schema, $table),
            $this->settings['max_columns'] + 1
        );
        return array_map(static fn ($r) => ['name' => $r['name'] ?? $r['id'] ?? null, 'column' => $r['column_name'] ?? $r['from'] ?? null,
            'referenced_schema' => $r['referenced_schema'] ?? $schema, 'referenced_table' => $r['referenced_table'] ?? $r['table'] ?? null,
            'referenced_column' => $r['referenced_column'] ?? $r['to'] ?? null], $rows);
    }

    /** @return array<string, mixed> */
    private function statistics(string $schema, string $table): array
    {
        $catalog = $this->catalog();
        $query = $catalog->buildAiStatisticsQuery($schema, $table);
        $row = $query === null ? [] : ($this->rows($query[0], $query[1], 1)[0] ?? []);
        if (isset($row['row_count']) && (float)$row['row_count'] < 0) {
            $row['row_count'] = null;
        }
        $source = $query === null ? 'unavailable' : $catalog->aiStatisticsSourceLabel();

        return $row + ['row_count' => null, 'exact' => false, 'source' => $source, 'freshness' => 'Unknown unless an analyze timestamp is present'];
    }

    /**
     * Fold each unquoted identifier on its own. A quote on the schema does not keep the table's original case.
     *
     * @param array{schema: ?string, table: string, schema_quoted: bool, table_quoted: bool} $name
     *
     * @return array{0: ?string, 1: string}
     */
    protected function foldReferenceIdentifiers(array $name): array
    {
        $schema = $name['schema'];
        $table = $name['table'];
        $dialect = $this->db->connection->getDialect();
        if (!$dialect instanceof DialectAbstract) {
            return [$schema, $table];
        }
        if (is_string($schema) && !$name['schema_quoted']) {
            $schema = $dialect->foldUnquotedIdentifier($schema);
        }
        if (!$name['table_quoted']) {
            $table = $dialect->foldUnquotedIdentifier($table);
        }

        return [$schema, $table];
    }
}
