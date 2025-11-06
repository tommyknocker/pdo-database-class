<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

$driver = $_ENV['PDODB_DRIVER'] ?? 'sqlite';

// Create main database connection
$db = match ($driver) {
    'mysql' => new PdoDb('mysql', [
        'host' => $_ENV['MYSQL_HOST'] ?? 'localhost',
        'dbname' => $_ENV['MYSQL_DATABASE'] ?? 'test',
        'user' => $_ENV['MYSQL_USER'] ?? 'root',
        'password' => $_ENV['MYSQL_PASSWORD'] ?? '',
    ]),
    'mariadb' => new PdoDb('mariadb', [
        'host' => $_ENV['MARIADB_HOST'] ?? 'localhost',
        'dbname' => $_ENV['MARIADB_DATABASE'] ?? 'test',
        'user' => $_ENV['MARIADB_USER'] ?? 'root',
        'password' => $_ENV['MARIADB_PASSWORD'] ?? '',
    ]),
    'pgsql' => new PdoDb('pgsql', [
        'host' => $_ENV['POSTGRES_HOST'] ?? 'localhost',
        'dbname' => $_ENV['POSTGRES_DATABASE'] ?? 'test',
        'user' => $_ENV['POSTGRES_USER'] ?? 'postgres',
        'password' => $_ENV['POSTGRES_PASSWORD'] ?? '',
    ]),
    default => new PdoDb('sqlite', ['path' => ':memory:']),
};

echo "=== Sharding Strategies Example ===\n\n";

// For SQLite, demonstrate different strategies
if ($driver === 'sqlite') {
    // Create shard connections
    $db->addConnection('shard1', ['driver' => 'sqlite', 'path' => ':memory:']);
    $db->addConnection('shard2', ['driver' => 'sqlite', 'path' => ':memory:']);
    $db->addConnection('shard3', ['driver' => 'sqlite', 'path' => ':memory:']);

    // Create tables in each shard
    foreach (['shard1', 'shard2', 'shard3'] as $shard) {
        $db->connection($shard);
        $db->rawQuery('
            CREATE TABLE products (
                product_id INTEGER PRIMARY KEY,
                name TEXT,
                price DECIMAL(10,2)
            )
        ');
    }

    // 1. Range Strategy
    echo "1. Range Strategy:\n";
    echo "   Distributes data based on numeric ranges.\n";

    $db->shard('products')
        ->shardKey('product_id')
        ->strategy('range')
        ->ranges([
            'shard1' => [0, 1000],
            'shard2' => [1001, 2000],
            'shard3' => [2001, 3000],
        ])
        ->useConnections(['shard1', 'shard2', 'shard3'])
        ->register();

    // Insert products using range strategy
    $db->find()->table('products')->insert(['product_id' => 500, 'name' => 'Product A', 'price' => 99.99]);
    $db->find()->table('products')->insert(['product_id' => 1500, 'name' => 'Product B', 'price' => 199.99]);
    $db->find()->table('products')->insert(['product_id' => 2500, 'name' => 'Product C', 'price' => 299.99]);

    echo "   Inserted products: 500 (shard1), 1500 (shard2), 2500 (shard3)\n\n";

    // 2. Hash Strategy
    echo "2. Hash Strategy:\n";
    echo "   Distributes data based on hash of the shard key.\n";

    // Clear previous config and data
    $shardRouter = $db->getShardRouter();
    if ($shardRouter !== null) {
        $shardRouter->removeShard('products');
    }
    foreach (['shard1', 'shard2', 'shard3'] as $shard) {
        $db->connection($shard);
        $db->rawQuery('DELETE FROM products');
    }

    $db->shard('products')
        ->shardKey('product_id')
        ->strategy('hash')
        ->useConnections(['shard1', 'shard2', 'shard3'])
        ->register();

    // Insert products using hash strategy
    $db->find()->table('products')->insert(['product_id' => 1, 'name' => 'Product 1', 'price' => 10.00]);
    $db->find()->table('products')->insert(['product_id' => 2, 'name' => 'Product 2', 'price' => 20.00]);
    $db->find()->table('products')->insert(['product_id' => 3, 'name' => 'Product 3', 'price' => 30.00]);

    echo "   Products distributed across shards based on hash\n\n";

    // 3. Modulo Strategy
    echo "3. Modulo Strategy:\n";
    echo "   Distributes data based on modulo operation.\n";

    // Clear previous config and data
    $shardRouter = $db->getShardRouter();
    if ($shardRouter !== null) {
        $shardRouter->removeShard('products');
    }
    foreach (['shard1', 'shard2', 'shard3'] as $shard) {
        $db->connection($shard);
        $db->rawQuery('DELETE FROM products');
    }

    $db->shard('products')
        ->shardKey('product_id')
        ->strategy('modulo')
        ->useConnections(['shard1', 'shard2', 'shard3'])
        ->register();

    // Insert products using modulo strategy
    $db->find()->table('products')->insert(['product_id' => 1, 'name' => 'Product 1', 'price' => 10.00]);
    $db->find()->table('products')->insert(['product_id' => 2, 'name' => 'Product 2', 'price' => 20.00]);
    $db->find()->table('products')->insert(['product_id' => 3, 'name' => 'Product 3', 'price' => 30.00]);

    // Query products
    $product1 = $db->find()->from('products')->where('product_id', 1)->getOne();
    $product2 = $db->find()->from('products')->where('product_id', 2)->getOne();
    $product3 = $db->find()->from('products')->where('product_id', 3)->getOne();

    echo "   Product 1 (1 % 3 = 1) -> shard2\n";
    echo "   Product 2 (2 % 3 = 2) -> shard3\n";
    echo "   Product 3 (3 % 3 = 0) -> shard1\n\n";
}

echo "Sharding strategies example completed!\n";

