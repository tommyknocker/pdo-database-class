<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\PdoDb;

/** Schema-scoped catalog queries. No application rows are read. */
class McpMetadataService
{
    private ?string $defaultSchema = null;
    private string $driver;

    public function __construct(private PdoDb $db, private McpConfig $config)
    {
        $this->driver = $db->connection->getDialect()->getDriverName();
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

    private function quote(string $name): string
    {
        return $this->db->connection->getDialect()->quoteIdentifier($name);
    }

    public function defaultSchema(): string
    {
        if ($this->defaultSchema === null) {
            $sql = match ($this->driver) {
                'mysql', 'mariadb' => 'SELECT DATABASE()',
                'pgsql' => 'SELECT current_schema()',
                'sqlsrv' => 'SELECT SCHEMA_NAME()',
                'oci' => "SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') FROM DUAL",
                default => null,
            };
            $row = $sql === null ? ['main'] : ($this->rows($sql)[0] ?? []);
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
        $sql = match ($this->driver) {
            'mysql', 'mariadb', 'pgsql', 'sqlsrv' => "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            'oci' => "SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = ? AND TABLE_NAME NOT LIKE 'BIN$%' ORDER BY TABLE_NAME",
            'sqlite' => 'SELECT name FROM ' . $this->quote($schema) . ".sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            default => throw new InvalidArgumentException('Unsupported MCP metadata adapter'),
        };
        $rows = $this->rows($sql, $this->driver === 'sqlite' ? [] : [$schema]);
        $names = [];
        foreach ($rows as $row) {
            $table = (string)reset($row);
            if ($this->config->allowsTable($schema, $table, $this->defaultSchema())) {
                // Legacy metadata builders cannot safely handle arbitrary SQL identifier syntax.
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
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')",
            'pgsql', 'sqlsrv' => "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('pg_catalog', 'information_schema', 'INFORMATION_SCHEMA', 'sys') AND SCHEMA_NAME NOT LIKE 'pg_toast%'",
            'oci' => "SELECT DISTINCT OWNER FROM ALL_TABLES WHERE OWNER NOT IN ('SYS', 'SYSTEM', 'XDB', 'CTXSYS', 'MDSYS')",
            'sqlite' => 'PRAGMA database_list',
            default => throw new InvalidArgumentException('Unsupported MCP metadata adapter'),
        };
        $names = [];
        foreach ($this->rows($sql) as $row) {
            $schema = $this->driver === 'sqlite' ? (string)$row['name'] : (string)reset($row);
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
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SELECT COLUMN_NAME AS Field, COLUMN_TYPE AS Type, IS_NULLABLE AS `Null`, COLUMN_KEY AS `Key`, COLUMN_DEFAULT AS `Default`, EXTRA AS Extra FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            'pgsql' => 'SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            'sqlsrv' => "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMNPROPERTY(OBJECT_ID(QUOTENAME(TABLE_SCHEMA) + '.' + QUOTENAME(TABLE_NAME)), COLUMN_NAME, 'IsIdentity') AS is_identity FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            'oci' => 'SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, NULLABLE, DATA_DEFAULT FROM ALL_TAB_COLUMNS WHERE OWNER = ? AND TABLE_NAME = ? ORDER BY COLUMN_ID',
            'sqlite' => 'PRAGMA ' . $this->quote($schema) . '.table_info(' . $this->quote($table) . ')',
            default => throw new InvalidArgumentException('Unsupported MCP metadata adapter'),
        };
        return $this->rows($sql, $this->driver === 'sqlite' ? [] : [$schema, $table]);
    }

    /** @return list<array<string, mixed>> */
    private function indexes(string $schema, string $table): array
    {
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SHOW INDEXES FROM ' . $this->quote($schema) . '.' . $this->quote($table),
            'pgsql' => 'SELECT indexname AS index_name, tablename AS table_name, indexdef AS definition FROM pg_indexes WHERE schemaname = ? AND tablename = ? ORDER BY indexname',
            'sqlsrv' => 'SELECT i.name AS IndexName, i.type_desc AS IndexType, ic.key_ordinal AS KeyOrdinal, c.name AS ColumnName FROM sys.indexes i JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_SCHEMA_NAME(i.object_id) = ? AND OBJECT_NAME(i.object_id) = ? ORDER BY i.name, ic.key_ordinal',
            'oci' => 'SELECT INDEX_NAME AS index_name, TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_POSITION AS column_position FROM ALL_IND_COLUMNS WHERE TABLE_OWNER = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, COLUMN_POSITION',
            'sqlite' => 'SELECT name, tbl_name AS table_name, sql FROM ' . $this->quote($schema) . ".sqlite_master WHERE type = 'index' AND tbl_name = ?",
            default => throw new InvalidArgumentException('Unsupported MCP metadata adapter'),
        };
        return $this->rows($sql, match ($this->driver) {
            'mysql', 'mariadb' => [], 'sqlite' => [$table], default => [$schema, $table],
        });
    }

    /** @return list<array<string, mixed>> */
    private function foreignKeys(string $schema, string $table): array
    {
        $sql = match ($this->driver) {
            'mysql', 'mariadb' => 'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            'pgsql' => "SELECT con.conname AS constraint_name, a.attname AS column_name, refns.nspname AS referenced_table_schema, ref.relname AS referenced_table_name, ra.attname AS referenced_column_name FROM pg_catalog.pg_constraint con JOIN pg_catalog.pg_class tab ON tab.oid = con.conrelid JOIN pg_catalog.pg_namespace ns ON ns.oid = tab.relnamespace JOIN pg_catalog.pg_class ref ON ref.oid = con.confrelid JOIN pg_catalog.pg_namespace refns ON refns.oid = ref.relnamespace JOIN LATERAL generate_subscripts(con.conkey, 1) AS pos(n) ON true JOIN pg_catalog.pg_attribute a ON a.attrelid = tab.oid AND a.attnum = con.conkey[pos.n] JOIN pg_catalog.pg_attribute ra ON ra.attrelid = ref.oid AND ra.attnum = con.confkey[pos.n] WHERE con.contype = 'f' AND ns.nspname = ? AND tab.relname = ? ORDER BY con.conname, pos.n",
            'sqlsrv' => 'SELECT fk.name AS CONSTRAINT_NAME, c.name AS COLUMN_NAME, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_SCHEMA, OBJECT_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_NAME, rc.name AS REFERENCED_COLUMN_NAME FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = ? AND OBJECT_NAME(fk.parent_object_id) = ?',
            'oci' => "SELECT c.CONSTRAINT_NAME, col.COLUMN_NAME, ref.OWNER AS REFERENCED_TABLE_SCHEMA, ref.TABLE_NAME AS REFERENCED_TABLE_NAME, refcol.COLUMN_NAME AS REFERENCED_COLUMN_NAME FROM ALL_CONSTRAINTS c JOIN ALL_CONS_COLUMNS col ON col.OWNER = c.OWNER AND col.CONSTRAINT_NAME = c.CONSTRAINT_NAME JOIN ALL_CONSTRAINTS ref ON ref.OWNER = c.R_OWNER AND ref.CONSTRAINT_NAME = c.R_CONSTRAINT_NAME JOIN ALL_CONS_COLUMNS refcol ON refcol.OWNER = ref.OWNER AND refcol.CONSTRAINT_NAME = ref.CONSTRAINT_NAME AND refcol.POSITION = col.POSITION WHERE c.OWNER = ? AND c.TABLE_NAME = ? AND c.CONSTRAINT_TYPE = 'R' ORDER BY c.CONSTRAINT_NAME, col.POSITION",
            'sqlite' => 'PRAGMA ' . $this->quote($schema) . '.foreign_key_list(' . $this->quote($table) . ')',
            default => throw new InvalidArgumentException('Unsupported MCP metadata adapter'),
        };
        return $this->rows($sql, $this->driver === 'sqlite' ? [] : [$schema, $table]);
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
