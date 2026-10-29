<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use tommyknocker\pdodb\ai\mcp\McpConfig;
use tommyknocker\pdodb\ai\mcp\McpExecutionGuard;
use tommyknocker\pdodb\ai\mcp\McpServer;
use tommyknocker\pdodb\PdoDb;

/** Explicit local integration suite; excluded from normal offline/shared tests. */
final class McpDatabasePolicyTests extends TestCase
{
    public static function databases(): array
    {
        return array_map(static fn ($name) => [$name], ['sqlite', 'mysql', 'mariadb', 'postgresql', 'mssql', 'oracle']);
    }

    public static function timeoutDatabases(): array
    {
        return [['postgresql'], ['mariadb']];
    }

    private function database(string $name): PdoDb
    {
        if ($name === 'sqlite') {
            return new PdoDb('sqlite', ['path' => ':memory:']);
        }
        $classes = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL', 'mssql' => 'MSSQL', 'oracle' => 'Oracle'];
        $base = new ReflectionClass('tommyknocker\\pdodb\\tests\\' . $name . '\\Base' . $classes[$name] . 'TestCase');
        $config = ['enable_regexp' => false];
        foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_USER' => 'username', 'DB_PASSWORD' => 'password',
            'DB_NAME' => 'dbname', 'DB_CHARSET' => 'charset', 'DB_SERVICE_NAME' => 'service_name'] as $constant => $key) {
            if ($base->hasConstant($constant)) {
                $config[$key] = $base->getConstant($constant);
            }
        }
        $config += ['encrypt' => true, 'trust_server_certificate' => true];
        return new PdoDb(['postgresql' => 'pgsql', 'mssql' => 'sqlsrv', 'oracle' => 'oci'][$name] ?? $name, $config);
    }

    private function call(McpServer $server, string $tool, array $arguments = []): array
    {
        $response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments]]);
        self::assertArrayNotHasKey('error', $response);
        self::assertFalse($response['result']['isError'] ?? false, json_encode($response));
        return (array)$response['result']['structuredContent'];
    }

    #[DataProvider('databases')]
    public function testCatalogVisibilityAndColumnSearch(string $name): void
    {
        $db = $this->database($name);
        $pdo = $db->connection->getPdo();
        $dialect = $db->connection->getDialect();
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $visible = 'MCP_V_' . $suffix;
        $hidden = 'MCP_H_' . $suffix;
        $v = $dialect->quoteIdentifier($visible);
        $h = $dialect->quoteIdentifier($hidden);

        try {
            $pdo->exec('CREATE TABLE ' . $h . ' (id INTEGER PRIMARY KEY)');
            $pdo->exec('CREATE TABLE ' . $v . ' (id INTEGER PRIMARY KEY, marker_column VARCHAR(40), ref_id INTEGER, FOREIGN KEY (ref_id) REFERENCES ' . $h . '(id))');
            $pdo->exec('CREATE INDEX ' . $dialect->quoteIdentifier('MCP_I_' . $suffix) . ' ON ' . $v . '(marker_column)');
            $server = new McpServer($db, config: new McpConfig(['allowed_tables' => [$visible]]));
            $context = $this->call($server, 'get_context');
            self::assertTrue($context['mcp_policy']['metadata_only']);
            self::assertSame([$visible], $this->call($server, 'search_schema')['tables']);
            $columns = $this->call($server, 'search_schema', ['kind' => 'columns', 'query' => 'marker_column']);
            self::assertCount(1, $columns['columns']);
            self::assertSame($visible, $columns['columns'][0]['table']);
            $schema = $this->call($server, 'get_schema', ['table' => $visible]);
            self::assertNotEmpty($schema['columns']);
            self::assertNotEmpty($schema['indexes']);
            self::assertSame([], $schema['foreign_keys']);
            self::assertTrue($schema['foreign_keys_filtered']);
            self::assertStringNotContainsString($hidden, json_encode($schema));
            $unrestricted = new McpServer($db);
            self::assertNotEmpty($this->call($unrestricted, 'get_schema', ['table' => $visible])['foreign_keys']);
        } finally {
            foreach ([$v, $h] as $table) {
                try {
                    $pdo->exec('DROP TABLE ' . $table);
                } catch (PDOException) {
                    // Only this test's randomly named fixtures are touched.
                }
            }
        }
    }

    private function timeout(PDO $pdo, string $name): float
    {
        return (float)$pdo->query($name === 'postgresql'
            ? "SELECT setting FROM pg_settings WHERE name = 'statement_timeout'" : 'SELECT @@SESSION.max_statement_time * 1000')->fetchColumn();
    }

    #[DataProvider('timeoutDatabases')]
    public function testTimeoutActuallyAbortsAndSessionIsRestored(string $name): void
    {
        $db = $this->database($name);
        $pdo = $db->connection->getPdo();
        $before = $this->timeout($pdo, $name);
        $guard = new McpExecutionGuard($db, 25);

        try {
            $guard->run(function () use ($pdo, $name): void {
                self::assertLessThanOrEqual(25, $this->timeout($pdo, $name));
                $pdo->query($name === 'postgresql' ? 'SELECT pg_sleep(0.2)' : 'SELECT SLEEP(0.2)');
            });
            self::fail('The database must abort the deliberately slow statement');
        } catch (PDOException $e) {
            if ($name === 'postgresql') {
                self::assertSame('57014', $e->getCode());
            } else {
                self::assertSame(1969, $e->errorInfo[1]);
            }
        }
        self::assertSame($before, $this->timeout($pdo, $name));
        self::assertSame('1', (string)$pdo->query('SELECT 1')->fetchColumn());

        try {
            $guard->run(static function (): void {
                throw new RuntimeException('callback sentinel');
            });
        } catch (RuntimeException $e) {
            self::assertSame('callback sentinel', $e->getMessage());
        }
        self::assertSame($before, $this->timeout($pdo, $name));
        $pdo->beginTransaction();

        try {
            try {
                $guard->run(static fn () => true);
                self::fail('An active caller transaction must be rejected');
            } catch (InvalidArgumentException) {
                self::assertTrue($pdo->inTransaction());
            }
        } finally {
            $pdo->rollBack();
        }
    }

    public function testPostgreSqlDuplicateNamesDoNotMixSchemasOrForeignKeys(): void
    {
        $db = $this->database('postgresql');
        $pdo = $db->connection->getPdo();
        $suffix = bin2hex(random_bytes(4));
        $a = 'mcp_a_' . $suffix;
        $b = 'mcp_b_' . $suffix;

        try {
            foreach ([$a => 'left_only', $b => 'right_only'] as $schema => $column) {
                $pdo->exec('CREATE SCHEMA ' . $schema);
                $pdo->exec('CREATE TABLE ' . $schema . '.parents (id INTEGER PRIMARY KEY)');
                $pdo->exec('CREATE TABLE ' . $schema . '.parents_other (other_id INTEGER PRIMARY KEY)');
                $pdo->exec('CREATE TABLE ' . $schema . '.items (id INTEGER PRIMARY KEY, ' . $column . ' TEXT, parent_id INTEGER, CONSTRAINT same_fk FOREIGN KEY (parent_id) REFERENCES ' . $schema . '.parents(id))');
                $pdo->exec('CREATE TABLE ' . $schema . '.items_other (id INTEGER PRIMARY KEY, parent_other_id INTEGER, CONSTRAINT same_fk FOREIGN KEY (parent_other_id) REFERENCES ' . $schema . '.parents_other(other_id))');
                $pdo->exec('CREATE INDEX same_idx ON ' . $schema . '.items(' . $column . ')');
            }
            $server = new McpServer($db);
            $left = $this->call($server, 'get_schema', ['schema' => $a, 'table' => 'items']);
            self::assertContains('left_only', array_column($left['columns'], 'column_name'));
            self::assertNotContains('right_only', array_column($left['columns'], 'column_name'));
            self::assertCount(1, $left['foreign_keys']);
            self::assertSame('parents', $left['foreign_keys'][0]['referenced_table_name']);
            self::assertSame($a, $left['foreign_keys'][0]['referenced_table_schema']);
            self::assertStringNotContainsString($b, json_encode($left));
            $right = $this->call($server, 'get_schema', ['table' => $b . '.items']);
            self::assertContains('right_only', array_column($right['columns'], 'column_name'));
            $restricted = new McpServer($db, config: new McpConfig(['allowed_schemas' => [$a], 'allowed_tables' => ['items', 'parents']]));
            self::assertSame($a, $this->call($restricted, 'get_context')['metadata_default_schema']);
            self::assertSame([$a], $this->call($restricted, 'search_schema', ['kind' => 'schemas'])['schemas']);
            self::assertCount(1, $this->call($restricted, 'get_schema', ['table' => 'items'])['foreign_keys']);
        } finally {
            foreach ([$a, $b] as $schema) {
                $pdo->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            }
        }
    }
}
