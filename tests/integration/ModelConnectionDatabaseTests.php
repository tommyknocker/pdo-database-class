<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class ConnectionDatabaseOwner extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
    public static function relations(): array
    {
        return ['items' => ['hasMany', 'modelClass' => ConnectionDatabaseItem::class, 'foreignKey' => 'owner_id', 'localKey' => 'id']];
    }
}
final class ConnectionDatabaseItem extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
}

final class ModelConnectionDatabaseTests extends TestCase
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
    public function testExplicitConnectionPropagatesToRelatedModels(string $name): void
    {
        $default = $this->database($name);
        $explicit = $name === 'sqlite' ? $default : $this->database($name);
        $suffix = strtoupper(bin2hex(random_bytes(5)));
        $owner = 'CO_' . $suffix;
        $item = 'CI_' . $suffix;
        $qOwner = $default->connection->getDialect()->quoteIdentifier($owner);
        $qItem = $default->connection->getDialect()->quoteIdentifier($item);
        $default->rawQuery("CREATE TABLE {$qOwner} (id INTEGER PRIMARY KEY, name VARCHAR(40))");

        try {
            $default->rawQuery("CREATE TABLE {$qItem} (id INTEGER PRIMARY KEY, owner_id INTEGER, name VARCHAR(40))");

            try {
                $default->rawQuery("INSERT INTO {$qOwner} (id, name) VALUES (1, 'owner')");
                $default->rawQuery("INSERT INTO {$qItem} (id, owner_id, name) VALUES (1, 1, 'item')");
                ConnectionDatabaseOwner::$table = $owner;
                ConnectionDatabaseItem::$table = $item;
                ConnectionDatabaseOwner::setDb($default);
                ConnectionDatabaseItem::setDb($default);
                $model = ConnectionDatabaseOwner::on($explicit)->with('items')->where('id', 1)->one();
                self::assertNotNull($model);
                self::assertSame($explicit, $model->getModelDb());
                self::assertCount(1, $model->items);
                self::assertSame($explicit, $model->items[0]->getModelDb());
                $model->name = 'changed';
                self::assertTrue($model->save());
                self::assertTrue($model->refresh());
                self::assertSame('changed', $model->name);
            } finally {
                $default->rawQuery("DROP TABLE {$qItem}");
            }
        } finally {
            $default->rawQuery("DROP TABLE {$qOwner}");
        }
    }
}
