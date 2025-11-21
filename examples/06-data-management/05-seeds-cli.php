<?php

declare(strict_types=1);

/**
 * Database Seeds CLI Example
 *
 * This example demonstrates how to use the PDOdb CLI commands for managing seeds.
 * Run this script to see examples of seed CLI commands.
 */

require_once __DIR__ . '/../helpers.php';

echo "=== Database Seeds CLI Example ===\n";
echo "Driver: " . (getenv('PDODB_DRIVER') ?: 'mysql') . "\n\n";

echo "This example demonstrates the PDOdb seed CLI commands.\n";
echo "You can use these commands in your terminal:\n\n";

echo "1. Create a new seed:\n";
echo "   vendor/bin/pdodb seed create users_table\n";
echo "   vendor/bin/pdodb seed create categories_data\n";
echo "   vendor/bin/pdodb seed create products_seed\n\n";

echo "2. List all seeds with their status:\n";
echo "   vendor/bin/pdodb seed list\n\n";

echo "3. Run all new seeds:\n";
echo "   vendor/bin/pdodb seed run\n\n";

echo "4. Run a specific seed:\n";
echo "   vendor/bin/pdodb seed run users_table\n\n";

echo "5. Run seeds with options:\n";
echo "   vendor/bin/pdodb seed run --dry-run    # Show SQL without executing\n";
echo "   vendor/bin/pdodb seed run --pretend    # Simulate execution\n";
echo "   vendor/bin/pdodb seed run --force      # Skip confirmation prompts\n\n";

echo "6. Rollback seeds:\n";
echo "   vendor/bin/pdodb seed rollback         # Rollback last batch\n";
echo "   vendor/bin/pdodb seed rollback users_table  # Rollback specific seed\n\n";

echo "7. Get help:\n";
echo "   vendor/bin/pdodb seed help\n\n";

echo "Example seed file structure:\n";
echo "seeds/\n";
echo "├── s20231121120000_users_table.php\n";
echo "├── s20231121120001_categories_data.php\n";
echo "└── s20231121120002_products_seed.php\n\n";

echo "Example seed file content:\n";
echo "```php\n";
echo "<?php\n";
echo "declare(strict_types=1);\n";
echo "use tommyknocker\\pdodb\\seeds\\Seed;\n\n";
echo "class UsersTableSeed extends Seed\n";
echo "{\n";
echo "    public function run(): void\n";
echo "    {\n";
echo "        \$users = [\n";
echo "            ['name' => 'John Doe', 'email' => 'john@example.com'],\n";
echo "            ['name' => 'Jane Smith', 'email' => 'jane@example.com'],\n";
echo "        ];\n\n";
echo "        \$this->insertBatch('users', \$users);\n";
echo "    }\n\n";
echo "    public function rollback(): void\n";
echo "    {\n";
echo "        \$this->delete('users', ['email' => 'john@example.com']);\n";
echo "        \$this->delete('users', ['email' => 'jane@example.com']);\n";
echo "    }\n";
echo "}\n";
echo "```\n\n";

echo "Environment Variables:\n";
echo "- PDODB_SEED_PATH: Custom path for seed files (default: ./seeds)\n";
echo "- PDODB_DRIVER: Database driver (mysql, pgsql, sqlite, sqlsrv)\n";
echo "- PDODB_HOST: Database host\n";
echo "- PDODB_DATABASE: Database name\n";
echo "- PDODB_USERNAME: Database username\n";
echo "- PDODB_PASSWORD: Database password\n\n";

echo "Seed Features:\n";
echo "✓ Automatic timestamp-based naming\n";
echo "✓ Batch tracking for rollbacks\n";
echo "✓ Dry-run mode to preview SQL\n";
echo "✓ Transaction safety\n";
echo "✓ Interactive confirmation prompts\n";
echo "✓ Bash completion support\n\n";

echo "Best Practices:\n";
echo "1. Use descriptive names for your seeds\n";
echo "2. Keep seeds idempotent (safe to run multiple times)\n";
echo "3. Always implement rollback() method\n";
echo "4. Use insertBatch() for multiple records\n";
echo "5. Test seeds in dry-run mode first\n";
echo "6. Order seeds by dependencies (users before posts, etc.)\n\n";

echo "✅ CLI example completed!\n";
echo "Try running the commands above in your terminal.\n";


