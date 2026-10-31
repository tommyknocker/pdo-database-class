<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\dialects\catalog\CatalogQueriesInterface;
use tommyknocker\pdodb\PdoDb;

/** Schema-scoped catalog queries. No application rows are read. */
class McpMetadataService
{
    private ?string $defaultSchema = null;

    public function __construct(private PdoDb $db, private McpConfig $config)
    {
    }

    private function catalog(): CatalogQueriesInterface
    {
        return $this->db->connection->getDialect()->catalogQueries();
    }

    /** @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->db->connection->getPdo()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return array_values($rows);
    }

    public function defaultSchema(): string
    {
        if ($this->defaultSchema === null) {
            $catalog = $this->catalog();
            $current = $catalog->buildCurrentSchemaQuery();
            $row = $current === null ? ['main'] : ($this->rows($current[0], $current[1])[0] ?? []);
            $value = reset($row);
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('Unable to determine the MCP metadata schema');
            }
            $this->defaultSchema = $value;
            if (!$this->config->allowsSchema($value) && $this->config->allowedSchemas !== []) {
                $this->defaultSchema = $this->config->allowedSchemas[0] ?? $value;
            }
        }
        return $this->defaultSchema;
    }

    public function scope(?string $schema): string
    {
        if ($schema !== null) {
            McpConfig::assertIdentifier($schema);
        }
        $schema ??= $this->defaultSchema();
        if (!$this->config->allowsSchema($schema)) {
            throw new InvalidArgumentException('Schema is not visible under the MCP policy');
        }
        return $schema;
    }

    /** @return array{string, string} */
    private function resolve(string $table, ?string $schema): array
    {
        McpConfig::assertIdentifier($table, true);
        if (str_contains($table, '.')) {
            [$qualifiedSchema, $table] = explode('.', $table, 2);
            if ($schema !== null && $schema !== $qualifiedSchema) {
                throw new InvalidArgumentException('Conflicting table and schema arguments');
            }
            $schema = $qualifiedSchema;
        }
        $schema = $this->scope($schema);
        if (!$this->config->allowsTable($schema, $table, $this->defaultSchema()) || !in_array($table, $this->tables($schema), true)) {
            throw new InvalidArgumentException('Table was not found or is not visible under the MCP policy');
        }
        return [$schema, $table];
    }

    /** @return list<string> */
    public function tables(?string $schema = null): array
    {
        $schema = $this->scope($schema);
        $catalog = $this->catalog();
        [$sql,] = $catalog->buildMcpListTablesQuery($schema);
        $rows = $this->rows($sql, $catalog->mcpListTablesParameters($schema));
        $names = [];
        foreach ($rows as $row) {
            $table = (string)reset($row);
            if ($this->config->allowsTable($schema, $table, $this->defaultSchema())) {
                try {
                    McpConfig::assertIdentifier($table);
                    $names[] = $table;
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }
        sort($names);
        return $names;
    }

    /** @return list<string> */
    public function schemas(): array
    {
        $catalog = $this->catalog();
        [$sql, $params] = $catalog->buildMcpListSchemasQuery();
        $names = [];
        foreach ($this->rows($sql, $params) as $row) {
            $schema = $catalog->mcpSchemaNameFromRow($row);
            if ($this->config->allowsAnyTableInSchema($schema, $this->defaultSchema())) {
                $names[] = $schema;
            }
        }
        sort($names);
        return $names;
    }

    /** @return list<array<string, mixed>> */
    private function columns(string $schema, string $table): array
    {
        $catalog = $this->catalog();
        [$sql,] = $catalog->buildMcpColumnsQuery($schema, $table);
        return $this->rows($sql, $catalog->mcpColumnsParameters($schema, $table));
    }

    /** @return list<array<string, mixed>> */
    private function indexes(string $schema, string $table): array
    {
        $catalog = $this->catalog();
        [$sql,] = $catalog->buildMcpIndexesQuery($schema, $table);
        return $this->rows($sql, $catalog->mcpIndexesParameters($schema, $table));
    }

    /** @return list<array<string, mixed>> */
    private function foreignKeys(string $schema, string $table): array
    {
        $catalog = $this->catalog();
        [$sql,] = $catalog->buildMcpForeignKeysQuery($schema, $table);
        return $this->rows($sql, $catalog->mcpForeignKeysParameters($schema, $table));
    }

    /** @return array<string, mixed> */
    public function describe(string $table, ?string $schema = null): array
    {
        [$schema, $table] = $this->resolve($table, $schema);
        return $this->describeResolved($schema, $table);
    }

    /** @return array<string, mixed> */
    private function describeResolved(string $schema, string $table): array
    {
        $foreignKeys = $this->foreignKeys($schema, $table);
        $visibleKeys = array_values(array_filter($foreignKeys, function ($row) use ($schema): bool {
            $row = array_change_key_case($row, CASE_LOWER);
            $target = $row['referenced_table_name'] ?? $row['table'] ?? null;
            return is_string($target) && $this->config->allowsTable((string)($row['referenced_table_schema'] ?? $schema), $target, $this->defaultSchema());
        }));
        return ['table' => $table, 'schema' => $schema, 'columns' => $this->columns($schema, $table),
            'indexes' => $this->indexes($schema, $table), 'foreign_keys' => $visibleKeys,
            'foreign_keys_filtered' => count($visibleKeys) !== count($foreignKeys)];
    }

    /** @return array<string, mixed> */
    public function describeAll(?string $schema = null): array
    {
        $schema = $this->scope($schema);
        $result = [];
        foreach ($this->tables($schema) as $table) {
            $info = $this->describeResolved($schema, $table);
            unset($info['table']);
            $result[$table] = $info;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function search(string $query = '', int $limit = 20, int $offset = 0, string $kind = 'tables', ?string $schema = null, ?string $table = null): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 100 || !in_array($kind, ['tables', 'columns', 'schemas'], true)) {
            throw new InvalidArgumentException('Invalid MCP schema search pagination or kind');
        }
        $scope = $kind === 'schemas' ? null : $this->scope($schema);
        if ($kind === 'schemas') {
            $matches = $this->schemas();
        } elseif ($kind === 'tables') {
            $matches = $this->tables($scope);
        } else {
            $columnSchema = $this->scope($schema);
            if ($table === null) {
                $tables = $this->tables($columnSchema);
            } else {
                [$columnSchema, $name] = $this->resolve($table, $schema);
                $scope = $columnSchema;
                $tables = [$name];
            }
            if (count($tables) > 200) {
                throw new InvalidArgumentException('Column search spans more than 200 tables; specify a table or a narrower schema');
            }
            $matches = [];
            foreach ($tables as $name) {
                foreach ($this->columns($columnSchema, $name) as $column) {
                    $column = array_change_key_case($column, CASE_LOWER);
                    $matches[] = ['schema' => $columnSchema, 'table' => $name,
                        'column' => (string)($column['column_name'] ?? $column['field'] ?? $column['name'] ?? ''),
                        'type' => $column['data_type'] ?? $column['type'] ?? null];
                }
            }
        }
        $matches = array_values(array_filter($matches, static function ($item) use ($query): bool {
            $text = is_array($item) ? $item['column'] : $item;
            return $query === '' || mb_stripos($text, $query, 0, 'UTF-8') !== false;
        }));
        return [$kind => array_slice($matches, $offset, $limit), 'kind' => $kind, 'schema' => $scope,
            'total' => count($matches), 'next_offset' => count($matches) > $offset + $limit ? $offset + $limit : null,
            'detail_tool' => 'get_schema'];
    }
}
