<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\exceptions\OptimisticLockException;
use tommyknocker\pdodb\orm\Model;

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
        return IntegrationDatabases::provider();
    }

    #[DataProvider('databases')]
    public function testStaleUpdatesAndDeletesAreRejected(string $name): void
    {
        $db = IntegrationDatabases::connect($name);
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
