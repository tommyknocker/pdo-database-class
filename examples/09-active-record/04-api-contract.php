<?php

declare(strict_types=1);

/** Optional Struct integration. Run after: composer require tommyknocker/struct */
require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\struct\CollectionType;
use tommyknocker\struct\Field;
use tommyknocker\struct\Struct;

if (!class_exists(Struct::class)) {
    fwrite(STDERR, "This optional example needs tommyknocker/struct: composer require tommyknocker/struct\n");
    exit(0);
}

enum ApiExampleStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
final class ApiExampleOrder extends Model
{
    public static function tableName(): string
    {
        return 'orders';
    }
    public static function casts(): array
    {
        return ['status' => ApiExampleStatus::class];
    }
    public static function safeAttributes(): array
    {
        return ['status'];
    }
    public static function relations(): array
    {
        return ['items' => ['hasMany', 'modelClass' => ApiExampleItem::class, 'foreignKey' => 'order_id', 'localKey' => 'id']];
    }
}
final class ApiExampleItem extends Model
{
    public static function tableName(): string
    {
        return 'order_items';
    }
}
final class ApiExampleStatusRequest extends Struct
{
    protected const STRICT = true;
    #[Field] public readonly ApiExampleStatus $status;
}
final class ApiExampleItemResponse extends Struct
{
    #[Field] public readonly string $sku;
}
final class ApiExampleOrderResponse extends Struct
{
    #[Field] public readonly int $id;
    #[Field] public readonly ApiExampleStatus $status;
    /** @var list<ApiExampleItemResponse> */
    #[Field(ApiExampleItemResponse::class, collection: CollectionType::List)]
    public readonly array $items;
}

// A fresh in-memory database; replace this setup with your application's connection.
$db = new PdoDb('sqlite', ['path' => ':memory:']);
$db->rawQuery('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT NOT NULL, internal_note TEXT)');
$db->rawQuery('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, sku TEXT NOT NULL)');
$db->rawQuery("INSERT INTO orders VALUES (1, 'pending', 'never sent to clients')");
$db->rawQuery("INSERT INTO order_items VALUES (1, 1, 'BOOK')");
Model::setDb($db);

// A controller would obtain this JSON from its request body.
$request = ApiExampleStatusRequest::fromJson('{"status":"paid"}');
$db->transaction(function () use ($request): void {
    $order = ApiExampleOrder::findOne(1);
    if ($order === null) {
        throw new RuntimeException('Order not found');
    }
    $order->setAttributes(['status' => $request->status]);
    if (!$order->save()) {
        throw new RuntimeException('Order validation or save failed');
    }
});

$page = ApiExampleOrder::find()->with('items')->paginateModels(10, 1);
$responses = array_map(
    static fn (Model $order): array => (new ApiExampleOrderResponse($order->present(['id', 'status'], ['items' => ['sku']])))->toArray(),
    $page->items()
);
// Only fields explicitly selected for the response are emitted.
echo json_encode(['data' => $responses, 'total' => $page->total()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
