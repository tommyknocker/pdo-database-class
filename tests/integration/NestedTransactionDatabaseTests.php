<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class ScopedDatabaseFixture extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
    public static function globalScopes(): array
    {
        return ['tenant' => static fn ($q) => $q->where('tenant_id', 1)];
    }
}

/** Uses unique fixture tables; exercises DML transactions on every supported database. */
final class NestedTransactionDatabaseTests extends TestCase
{
    public static function databases(): array
    {
        return array_map(static fn ($name) => [$name], ['sqlite', 'mysql', 'mariadb', 'postgresql', 'mssql', 'oracle']);
    }

    private function database(string $name): PdoDb
    {
        if ($name === 'sqlite') {
            return new PdoDb('sqlite', ['path' => ':memory:']);
        }
        $classes = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL', 'mssql' => 'MSSQL', 'oracle' => 'Oracle'];
        $base = new ReflectionClass('tommyknocker\\pdodb\\tests\\' . $name . '\\Base' . $classes[$name] . 'TestCase');
        $config = ['enable_regexp' => false];
        foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_USER' => 'username', 'DB_PASSWORD' => 'password', 'DB_NAME' => 'dbname', 'DB_CHARSET' => 'charset', 'DB_SERVICE_NAME' => 'service_name'] as $constant => $key) {
            if ($base->hasConstant($constant)) {
                $config[$key] = $base->getConstant($constant);
            }
        }
        $config += ['encrypt' => true, 'trust_server_certificate' => true];
        return new PdoDb(['postgresql' => 'pgsql', 'mssql' => 'sqlsrv', 'oracle' => 'oci'][$name] ?? $name, $config);
    }

    #[DataProvider('databases')]
    public function testScopesIsolateOrConditionsOnWrites(string $name): void
    {
        $db = $this->database($name);
        $table = 'NS_' . strtoupper(bin2hex(random_bytes(5)));
        $quoted = $db->connection->getDialect()->quoteIdentifier($table);
        $db->rawQuery("CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY, tenant_id INTEGER)");

        try {
            $db->rawQuery("INSERT INTO {$quoted} (id, tenant_id) VALUES (1, 1)");
            $db->rawQuery("INSERT INTO {$quoted} (id, tenant_id) VALUES (2, 2)");
            ScopedDatabaseFixture::$table = $table;
            ScopedDatabaseFixture::setDb($db);
            $query = ScopedDatabaseFixture::find()->where('id', 2)->orWhere('id', 1);
            self::assertTrue($query->exists());
            self::assertSame(1, $query->delete());
            self::assertCount(1, $db->rawQuery("SELECT * FROM {$quoted} WHERE id = 2"));
        } finally {
            $db->rawQuery("DROP TABLE {$quoted}");
        }
    }

    #[DataProvider('databases')]
    public function testNestedTransactionsRespectOuterBoundary(string $name): void
    {
        $db = $this->database($name);
        $table = 'NT_' . strtoupper(bin2hex(random_bytes(5)));
        $quoted = $db->connection->getDialect()->quoteIdentifier($table);
        $db->rawQuery("CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY)");

        try {
            try {
                $db->transaction(function (PdoDb $db) use ($quoted): void {
                    $db->transaction(fn (PdoDb $db) => $db->rawQuery("INSERT INTO {$quoted} VALUES (1)"));

                    throw new RuntimeException('outer');
                });
                self::fail('Expected outer failure');
            } catch (RuntimeException $e) {
                self::assertSame('outer', $e->getMessage());
            }
            self::assertCount(0, $db->rawQuery("SELECT * FROM {$quoted}"));
            $db->transaction(function (PdoDb $db) use ($quoted): void {
                $db->rawQuery("INSERT INTO {$quoted} VALUES (2)");

                try {
                    $db->transaction(function (PdoDb $db) use ($quoted): void {
                        $db->rawQuery("INSERT INTO {$quoted} VALUES (3)");

                        throw new RuntimeException('inner');
                    });
                } catch (RuntimeException $e) {
                    self::assertSame('inner', $e->getMessage());
                }
            });
            self::assertCount(1, $db->rawQuery("SELECT * FROM {$quoted}"));
        } finally {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $db->rawQuery("DROP TABLE {$quoted}");
        }
    }
}
