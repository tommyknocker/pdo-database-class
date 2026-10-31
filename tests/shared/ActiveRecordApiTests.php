<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\orm\AttributeCaster;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

enum ApiOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
enum ApiPriority: int
{
    case Normal = 1;
}
final class ApiOrder extends Model
{
    public static function tableName(): string
    {
        return 'api_orders';
    }
    public static function casts(): array
    {
        return ['status' => ApiOrderStatus::class, 'created_at' => DateTimeImmutable::class, 'metadata' => 'json'];
    }
    public static function safeAttributes(): array
    {
        return ['status', 'metadata'];
    }
    public static function relations(): array
    {
        return ['items' => ['hasMany', 'modelClass' => ApiItem::class, 'foreignKey' => 'order_id', 'localKey' => 'id']];
    }
}
final class ApiItem extends Model
{
    public static function tableName(): string
    {
        return 'api_items';
    }
}

final class ActiveRecordApiTests extends TestCase
{
    private PdoDb $db;

    protected function setUp(): void
    {
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->db->rawQuery('CREATE TABLE api_orders (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT, metadata TEXT, secret TEXT)');
        $this->db->rawQuery('CREATE TABLE api_items (id INTEGER PRIMARY KEY, order_id INTEGER, sku TEXT, secret TEXT)');
        $this->db->rawQuery("INSERT INTO api_orders VALUES (1, 'pending', '2026-01-01 12:00:00.123456', '{\"priority\":true}', 'private')");
        $this->db->rawQuery("INSERT INTO api_items VALUES (1, 1, 'A-1', 'private-item')");
        ApiOrder::setDb($this->db);
        ApiItem::setDb($this->db);
    }

    public function testTypedAttributesAndSafeUpdateRoundTrip(): void
    {
        $order = ApiOrder::findOne(1);
        self::assertNotNull($order);
        self::assertSame(ApiOrderStatus::Pending, $order->status);
        self::assertInstanceOf(DateTimeImmutable::class, $order->created_at);
        self::assertSame(['priority' => true], $order->metadata);
        $order->setAttributes(['status' => ApiOrderStatus::Paid, 'metadata' => ['priority' => false], 'secret' => 'changed'], true);
        self::assertTrue($order->save());
        $reloaded = ApiOrder::findOne(1);
        self::assertSame(ApiOrderStatus::Paid, $reloaded->status);
        self::assertSame(['priority' => false], $reloaded->metadata);
        self::assertSame('private', $reloaded->secret);
    }

    public function testPaginationHydratesModelsAndLoadsOnlyRequestedFields(): void
    {
        $page = ApiOrder::find()->with('items')->paginateModels(10, 1);
        self::assertSame(1, $page->total());
        $order = $page->items()[0];
        self::assertInstanceOf(ApiOrder::class, $order);
        self::assertSame([
            'id' => 1, 'status' => 'pending', 'created_at' => '2026-01-01T12:00:00.123456+00:00',
            'items' => [['sku' => 'A-1']],
        ], $order->present(['id', 'status', 'created_at'], ['items' => ['sku']]));
        self::assertIsArray(ApiOrder::find()->paginate(10, 1)->items()[0]);
    }

    public function testPresentationDoesNotSilentlyLoadRelations(): void
    {
        $order = ApiOrder::findOne(1);
        $this->expectException(RuntimeException::class);
        $order->present(['id'], ['items' => ['sku']]);
    }

    public function testDatetimeWriteIsNormalizedToUtc(): void
    {
        $order = ApiOrder::findOne(1);
        $order->created_at = new DateTimeImmutable('2026-01-02T15:00:00+03:00');
        self::assertTrue($order->save());
        self::assertSame('2026-01-02T12:00:00.000000+00:00', ApiOrder::findOne(1)->created_at->format('Y-m-d\TH:i:s.uP'));
    }

    public function testInvalidStoredJsonFailsExplicitly(): void
    {
        $this->db->rawQuery("UPDATE api_orders SET metadata = 'not-json' WHERE id = 1");
        $order = ApiOrder::findOne(1);
        $this->expectException(InvalidArgumentException::class);
        $unused = $order->metadata;
    }

    public function testUnknownFieldsCannotBeAccidentallyPresented(): void
    {
        $order = ApiOrder::findOne(1);
        $this->expectException(RuntimeException::class);
        $order->present(['not_selected']);
    }

    public function testIntegerBackedEnumsAcceptIntegerDatabaseStrings(): void
    {
        self::assertSame(ApiPriority::Normal, AttributeCaster::fromDatabase(ApiPriority::class, '1'));
        $this->expectException(InvalidArgumentException::class);
        AttributeCaster::fromDatabase(ApiPriority::class, 'not-an-integer');
    }
}
