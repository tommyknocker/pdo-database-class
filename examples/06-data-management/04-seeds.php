<?php

declare(strict_types=1);

/**
 * Database Seeds Example
 *
 * This example demonstrates how to use database seeds to populate your database
 * with initial or test data using the PDOdb seed system.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\seeds\SeedRunner;

// Get database connection
$db = createExampleDb();
$driver = getenv('PDODB_DRIVER') ?: 'mysql';

echo "=== Database Seeds Example ===\n";
echo "Driver: {$driver}\n\n";

// Set up seed path
$seedPath = __DIR__ . '/seeds';
if (!is_dir($seedPath)) {
    mkdir($seedPath, 0755, true);
}

try {
    // Clean up any existing seed files first
    cleanupSeedFiles($seedPath);
    
    // Create seed runner
    $runner = new SeedRunner($db, $seedPath);

    echo "1. Creating example seed files...\n";

    // Create users table seed
    createUsersSeed($seedPath);
    echo "   ✓ Created users seed\n";

    // Create categories seed
    createCategoriesSeed($seedPath);
    echo "   ✓ Created categories seed\n";

    // Create products seed
    createProductsSeed($seedPath);
    echo "   ✓ Created products seed\n";

    echo "\n2. Setting up database tables...\n";

    // Create tables if they don't exist
    $schema = $db->schema();

    // Drop tables if they exist (for clean example)
    // Disable foreign key checks temporarily
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $db->rawQuery('SET FOREIGN_KEY_CHECKS = 0');
    }
    
    if ($schema->tableExists('products')) {
        $schema->dropTable('products');
    }
    if ($schema->tableExists('categories')) {
        $schema->dropTable('categories');
    }
    if ($schema->tableExists('users')) {
        $schema->dropTable('users');
    }
    // Note: Don't drop __seeds table as it's needed for seed tracking
    
    // Re-enable foreign key checks
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $db->rawQuery('SET FOREIGN_KEY_CHECKS = 1');
    }

    // Create users table
    {
        $schema->createTable('users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(255)->notNull()->unique(),
            'role' => $schema->string(50)->defaultValue('user'),
            'created_at' => $schema->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        echo "   ✓ Created users table\n";
    }

    // Create categories table
    {
        $schema->createTable('categories', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
            'slug' => $schema->string(100)->notNull()->unique(),
            'description' => $schema->text(),
            'created_at' => $schema->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        echo "   ✓ Created categories table\n";
    }

    // Create products table
    {
        $schema->createTable('products', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(200)->notNull(),
            'category_id' => $schema->integer()->notNull(),
            'price' => $schema->decimal(10, 2)->notNull(),
            'description' => $schema->text(),
            'in_stock' => $schema->boolean()->defaultValue(true),
            'created_at' => $schema->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // Add foreign key (only for non-SQLite databases)
        if ($driver !== 'sqlite') {
            $schema->addForeignKey('fk_products_category', 'products', 'category_id', 'categories', 'id', 'CASCADE', 'CASCADE');
        }
        echo "   ✓ Created products table\n";
    }

    echo "\n3. Listing available seeds...\n";
    $allSeeds = $runner->getAllSeeds();
    $executedSeeds = $runner->getExecutedSeeds();
    $newSeeds = $runner->getNewSeeds();

    foreach ($allSeeds as $seed) {
        $status = in_array($seed, $executedSeeds, true) ? '[EXECUTED]' : '[PENDING]';
        echo "   {$status} {$seed}\n";
    }

    echo "\n   Summary: " . count($allSeeds) . " total, " . count($executedSeeds) . " executed, " . count($newSeeds) . " pending\n";

    echo "\n4. Running seeds...\n";
    $executed = $runner->run();

    if (!empty($executed)) {
        echo "   Successfully executed " . count($executed) . " seed(s):\n";
        foreach ($executed as $seedName) {
            echo "   ✓ {$seedName}\n";
        }
    } else {
        echo "   No new seeds to run.\n";
    }

    echo "\n5. Checking seeded data...\n";

    // Check users
    $users = $db->find()->table('users')->get();
    echo "   Users: " . count($users) . " records\n";
    foreach ($users as $user) {
        echo "     - {$user['name']} ({$user['email']}) - {$user['role']}\n";
    }

    // Check categories
    $categories = $db->find()->table('categories')->get();
    echo "   Categories: " . count($categories) . " records\n";
    foreach ($categories as $category) {
        echo "     - {$category['name']} ({$category['slug']})\n";
    }

    // Check products
    $products = $db->find()->table('products')->get();
    echo "   Products: " . count($products) . " records\n";
    foreach ($products as $product) {
        $price = number_format((float)$product['price'], 2);
        $stock = $product['in_stock'] ? 'In Stock' : 'Out of Stock';
        
        // Get category name
        $category = $db->find()->table('categories')->where('id', $product['category_id'])->first();
        $categoryName = $category ? $category['name'] : 'Unknown';
        
        echo "     - {$product['name']} (\${$price}) - {$categoryName} - {$stock}\n";
    }

    echo "\n6. Demonstrating seed rollback...\n";

    // Get seed history
    $history = $runner->getSeedHistory(3);
    if (!empty($history)) {
        echo "   Recent seeds:\n";
        foreach ($history as $record) {
            echo "     - {$record['seed']} (batch {$record['batch']}) - {$record['executed_at']}\n";
        }

        // Rollback last batch
        echo "\n   Rolling back last batch...\n";
        $rolledBack = $runner->rollback();
        if (!empty($rolledBack)) {
            echo "   Successfully rolled back " . count($rolledBack) . " seed(s):\n";
            foreach ($rolledBack as $seedName) {
                echo "   ✓ {$seedName}\n";
            }

            // Check data after rollback
            $usersAfter = count($db->find()->table('users')->get());
            $categoriesAfter = count($db->find()->table('categories')->get());
            $productsAfter = count($db->find()->table('products')->get());

            echo "\n   Data after rollback:\n";
            echo "     Users: {$usersAfter} records\n";
            echo "     Categories: {$categoriesAfter} records\n";
            echo "     Products: {$productsAfter} records\n";
        } else {
            echo "   No seeds to rollback.\n";
        }
    }

    echo "\n7. Demonstrating dry-run mode...\n";

    // Re-run seeds in dry-run mode
    $runner->setDryRun(true);
    $runner->run();

    $queries = $runner->getCollectedQueries();
    if (!empty($queries)) {
        echo "   SQL queries that would be executed:\n";
        foreach (array_slice($queries, 0, 10) as $query) { // Show first 10 queries
            if (trim($query) !== '') {
                echo "     " . trim($query) . "\n";
            }
        }
        if (count($queries) > 10) {
            echo "     ... and " . (count($queries) - 10) . " more queries\n";
        }
    }

    echo "\n✅ Seeds example completed successfully!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Clean up seed files
    cleanupSeedFiles($seedPath);
}

/**
 * Create users seed file.
 */
function createUsersSeed(string $seedPath): void
{
    $timestamp = date('YmdHis');
    $filename = "s{$timestamp}_example_users_data.php";
    $filepath = $seedPath . '/' . $filename;

    $content = <<<'PHP'
<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class ExampleUsersDataSeed extends Seed
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'role' => 'admin',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'role' => 'user',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Bob Johnson',
                'email' => 'bob@example.com',
                'role' => 'moderator',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMulti('users', $users);
    }

    public function rollback(): void
    {
        $this->delete('users', ['email' => 'john@example.com']);
        $this->delete('users', ['email' => 'jane@example.com']);
        $this->delete('users', ['email' => 'bob@example.com']);
    }
}
PHP;

    file_put_contents($filepath, $content);
    sleep(1); // Ensure different timestamps
}

/**
 * Create categories seed file.
 */
function createCategoriesSeed(string $seedPath): void
{
    $timestamp = date('YmdHis');
    $filename = "s{$timestamp}_example_categories_data.php";
    $filepath = $seedPath . '/' . $filename;

    $content = <<<'PHP'
<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class ExampleCategoriesDataSeed extends Seed
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Electronics',
                'slug' => 'electronics',
                'description' => 'Electronic devices and gadgets',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Books',
                'slug' => 'books',
                'description' => 'Books and literature',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Clothing',
                'slug' => 'clothing',
                'description' => 'Apparel and fashion',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMulti('categories', $categories);
    }

    public function rollback(): void
    {
        $this->delete('categories', ['slug' => 'electronics']);
        $this->delete('categories', ['slug' => 'books']);
        $this->delete('categories', ['slug' => 'clothing']);
    }
}
PHP;

    file_put_contents($filepath, $content);
    sleep(1); // Ensure different timestamps
}

/**
 * Create products seed file.
 */
function createProductsSeed(string $seedPath): void
{
    $timestamp = date('YmdHis');
    $filename = "s{$timestamp}_example_products_data.php";
    $filepath = $seedPath . '/' . $filename;

    $content = <<<'PHP'
<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class ExampleProductsDataSeed extends Seed
{
    public function run(): void
    {
        // Get category IDs
        $electronics = $this->find()->table('categories')->where('slug', 'electronics')->first();
        $books = $this->find()->table('categories')->where('slug', 'books')->first();
        $clothing = $this->find()->table('categories')->where('slug', 'clothing')->first();

        if (!$electronics || !$books || !$clothing) {
            throw new \Exception('Categories must be seeded first');
        }

        $products = [
            [
                'name' => 'Smartphone',
                'category_id' => $electronics['id'],
                'price' => 599.99,
                'description' => 'Latest smartphone with advanced features',
                'in_stock' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Laptop',
                'category_id' => $electronics['id'],
                'price' => 1299.99,
                'description' => 'High-performance laptop for work and gaming',
                'in_stock' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Programming Book',
                'category_id' => $books['id'],
                'price' => 49.99,
                'description' => 'Learn programming with this comprehensive guide',
                'in_stock' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'T-Shirt',
                'category_id' => $clothing['id'],
                'price' => 19.99,
                'description' => 'Comfortable cotton t-shirt',
                'in_stock' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMulti('products', $products);
    }

    public function rollback(): void
    {
        $this->delete('products', ['name' => 'Smartphone']);
        $this->delete('products', ['name' => 'Laptop']);
        $this->delete('products', ['name' => 'Programming Book']);
        $this->delete('products', ['name' => 'T-Shirt']);
    }
}
PHP;

    file_put_contents($filepath, $content);
}

/**
 * Clean up seed files.
 */
function cleanupSeedFiles(string $seedPath): void
{
    if (is_dir($seedPath)) {
        $files = glob($seedPath . '/s*.php');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
