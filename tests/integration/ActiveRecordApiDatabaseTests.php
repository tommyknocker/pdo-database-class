<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\orm\behaviors\SoftDeleteBehavior;
use tommyknocker\pdodb\orm\behaviors\TimestampBehavior;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

enum ApiDatabaseState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
final class ApiDatabaseOrder extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
    public static function casts(): array
    {
        return ['status' => ApiDatabaseState::class, 'created_at' => DateTimeImmutable::class, 'metadata' => 'json'];
    }
}

final class ApiClockStamped extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
    public static function behaviors(): array
    {
        return [TimestampBehavior::class, SoftDeleteBehavior::class];
    }
}
final class ApiClockCustom extends Model
{
    public static string $table;
    public static function tableName(): string
    {
        return self::$table;
    }
    public static function behaviors(): array
    {
        return [TimestampBehavior::class => ['updatedAt' => null, 'value' => static fn (Model $model, string $column): string => '2020-01-02 03:04:05']];
    }
}

final class ActiveRecordApiDatabaseTests extends TestCase
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

    public function testOracleRejectsLossyTimestampSessionFormat(): void
    {
        $db = $this->database('oracle');
        $db->rawQuery("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS'");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('preserve microseconds');
        ApiDatabaseOrder::validateCastConnection($db);
    }

    #[DataProvider('databases')]
    public function testTimestampBehaviorsUseDatabaseClockByDefault(string $name): void
    {
        $db = $this->database($name);
        if ($name === 'oracle') {
            $db->rawQuery("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS'");
        }
        $table = 'AC_' . strtoupper(bin2hex(random_bytes(5)));
        $quoted = $db->connection->getDialect()->quoteIdentifier($table);
        $type = match ($name) {
            'mysql', 'mariadb' => 'DATETIME(6)',
            'mssql' => 'DATETIME2(6)',
            'oracle', 'postgresql' => 'TIMESTAMP(6)',
            default => 'TEXT',
        };
        $db->rawQuery("CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY, label VARCHAR(50), created_at {$type}, updated_at {$type}, deleted_at {$type})");

        try {
            ApiClockStamped::$table = $table;
            ApiClockStamped::setDb($db);
            $model = new ApiClockStamped();
            $model->id = 1;
            $model->label = 'first';
            self::assertTrue($model->save());
            self::assertIsString($model->created_at);
            self::assertNotSame('', $model->created_at);
            self::assertIsString($model->updated_at);

            $model->label = 'second';
            self::assertTrue($model->save());
            self::assertIsString($model->updated_at);

            self::assertTrue($model->delete());
            self::assertIsString($model->deleted_at);
            self::assertNull(ApiClockStamped::findOne(1));
            $row = ApiClockStamped::find()->withoutGlobalScope(SoftDeleteBehavior::SCOPE)->where('id', 1)->one();
            self::assertNotNull($row);
            self::assertNotEmpty($row->deleted_at);

            $created = ApiClockStamped::firstOrCreate(['id' => 2], ['label' => 'upserted']);
            self::assertNotEmpty($created->created_at);
            self::assertNotEmpty($created->updated_at);

            self::assertSame(1, ApiClockStamped::deleteAll(['id' => 2]));

            ApiClockCustom::$table = $table;
            ApiClockCustom::setDb($db);
            $custom = new ApiClockCustom();
            $custom->id = 3;
            $custom->label = 'custom';
            self::assertTrue($custom->save());
            self::assertStringStartsWith('2020-01-02 03:04:05', (string) $custom->created_at);
            self::assertEmpty(ApiClockCustom::findOne(3)?->updated_at);
        } finally {
            $db->rawQuery("DROP TABLE {$quoted}");
        }
    }

    #[DataProvider('databases')]
    public function testNativeTimestampRoundTrip(string $name): void
    {
        $db = $this->database($name);
        if ($name === 'oracle') {
            $db->rawQuery("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS.FF6'");
        }
        $table = 'AT_' . strtoupper(bin2hex(random_bytes(5)));
        $quoted = $db->connection->getDialect()->quoteIdentifier($table);
        $type = match ($name) {
            'mysql', 'mariadb' => 'DATETIME(6)',
            'mssql' => 'DATETIME2(6)',
            'oracle', 'postgresql' => 'TIMESTAMP(6)',
            default => 'TEXT',
        };
        $db->rawQuery("CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY, created_at {$type})");

        try {
            ApiDatabaseOrder::$table = $table;
            ApiDatabaseOrder::setDb($db);
            $order = new ApiDatabaseOrder();
            $order->id = 1;
            $order->created_at = new DateTimeImmutable('2026-01-02T15:00:00+03:00');
            self::assertTrue($order->save());
            $loaded = ApiDatabaseOrder::findOne(1);
            self::assertNotNull($loaded);
            self::assertSame('2026-01-02T12:00:00.000000+00:00', $loaded->created_at->format('Y-m-d\\TH:i:s.uP'));
        } finally {
            $db->rawQuery("DROP TABLE {$quoted}");
        }
    }

    #[DataProvider('databases')]
    public function testCastsPersistAndReadAcrossDrivers(string $name): void
    {
        $db = $this->database($name);
        if ($name === 'oracle') {
            $db->rawQuery("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS.FF6'");
        }
        $table = 'AR_' . strtoupper(bin2hex(random_bytes(5)));
        $quoted = $db->connection->getDialect()->quoteIdentifier($table);
        $db->rawQuery("CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY, status VARCHAR(40), created_at VARCHAR(50), metadata VARCHAR(500))");

        try {
            $db->rawQuery("INSERT INTO {$quoted} (id, status, created_at, metadata) VALUES (1, 'pending', '2026-01-01 12:00:00.123456', '{\"priority\":true}')");
            ApiDatabaseOrder::$table = $table;
            ApiDatabaseOrder::setDb($db);
            $order = ApiDatabaseOrder::findOne(1);
            self::assertNotNull($order);
            self::assertSame(ApiDatabaseState::Pending, $order->status);
            self::assertSame(['priority' => true], $order->metadata);
            self::assertSame('2026-01-01T12:00:00.123456+00:00', $order->created_at->format('Y-m-d\\TH:i:s.uP'));
            $order->status = ApiDatabaseState::Paid;
            $order->metadata = ['priority' => false];
            $order->created_at = new DateTimeImmutable('2026-01-02T15:00:00+03:00');
            self::assertTrue($order->save());
            $order = ApiDatabaseOrder::findOne(1);
            self::assertSame(ApiDatabaseState::Paid, $order->status);
            self::assertSame(['priority' => false], $order->metadata);
            self::assertTrue($order->refresh());
            self::assertSame('2026-01-02T12:00:00.000000+00:00', $order->created_at->format('Y-m-d\\TH:i:s.uP'));
        } finally {
            $db->rawQuery("DROP TABLE {$quoted}");
        }
    }
}
