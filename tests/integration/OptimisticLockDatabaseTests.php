<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\exceptions\OptimisticLockException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class OptimisticDatabaseOrder extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
    public static function optimisticLock(): ?string
    {
        return 'version';
    }
}

final class OptimisticLockDatabaseTests extends TestCase
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
    public function testStaleUpdatesAndDeletesAreRejected(string $name): void
    {
        $db = $this->database($name);
        $table = 'OL_' . strtoupper(bin2hex(random_bytes(5)));
        $quoted = $db->connection->getDialect()->quoteIdentifier($table);
        $db->rawQuery("CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY, status VARCHAR(40), version INTEGER NOT NULL)");

        try {
            $db->rawQuery("INSERT INTO {$quoted} (id, status, version) VALUES (1, 'pending', 1)");
            OptimisticDatabaseOrder::$table = $table;
            OptimisticDatabaseOrder::setDb($db);
            $a = OptimisticDatabaseOrder::findOne(1);
            $b = OptimisticDatabaseOrder::findOne(1);
            $a->status = 'paid';
            self::assertTrue($a->save());
            $b->status = 'cancelled';

            try {
                $b->save();
                self::fail('Expected conflict');
            } catch (OptimisticLockException) {
                self::assertSame('paid', OptimisticDatabaseOrder::findOne(1)->status);
            }

            try {
                $b->delete();
                self::fail('Expected delete conflict');
            } catch (OptimisticLockException) {
                self::assertNotNull(OptimisticDatabaseOrder::findOne(1));
            }
        } finally {
            $db->rawQuery("DROP TABLE {$quoted}");
        }
    }
}
