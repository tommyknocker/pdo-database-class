<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class DiagnosticExampleUser extends Model
{
    public static function tableName(): string { return 'users'; }
}
final class DiagnosticExampleOrder extends Model
{
    public static function tableName(): string { return 'orders'; }
    public static function relations(): array
    {
        return ['user' => ['belongsTo', 'modelClass' => DiagnosticExampleUser::class, 'foreignKey' => 'user_id', 'ownerKey' => 'id']];
    }
}

$db = new PdoDb('sqlite', ['path' => ':memory:']);
$db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$db->rawQuery('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER)');
for ($i = 1; $i <= 12; $i++) {
    $db->find()->table('users')->insert(['id' => $i, 'name' => 'User ' . $i]);
    $db->find()->table('orders')->insert(['id' => $i, 'user_id' => $i]);
}
Model::setDb($db);
$db->enableProfiling();

foreach (DiagnosticExampleOrder::find()->all() as $order) {
    $order->user; // One extra query per order.
}
$lazy = $db->profileReport()->summary()['relations'][0];
printf("Lazy loading: %d relation queries; possible N+1: %s\n", $lazy['lazy_queries'], $lazy['possible_n_plus_one'] ? 'yes' : 'no');

echo "Example relation SQL: {$lazy['sql'][0]}\n";
$db->getProfiler()->reset();
foreach (DiagnosticExampleOrder::find()->with('user')->all() as $order) {
    $order->user; // Already loaded in one query for the page.
}
$eager = $db->profileReport()->summary()['relations'][0];
printf("Eager loading: %d relation queries; possible N+1: %s\n", $eager['eager_queries'], $eager['possible_n_plus_one'] ? 'yes' : 'no');

// Inspect a query locally. Neither operation calls an AI provider.
$query = $db->find()->from('orders')->where('id', 1);
$localAdvice = $query->explainAdvice();
$preview = $query->explainAiContext();
echo "Local EXPLAIN and AI context preview are available.\n";
// After configuring a provider and reviewing $preview, you may call
// $query->explainAiAdvice() explicitly; it sends context to that provider.
