<?php

declare(strict_types=1);

/**
 * Example: Dialect-Specific Schema Types
 * 
 * This example demonstrates how to use dialect-specific column types
 * that are optimized for each database engine.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Get database instance
$db = createExampleDb();

echo "=== Dialect-Specific Schema Types Example ===\n\n";

// Get current dialect name
$dialectName = $db->connection->getDriverName();
echo "Current dialect: " . strtoupper($dialectName) . "\n\n";

// Demonstrate dialect-specific types based on current database
switch ($dialectName) {
    case 'mysql':
    case 'mariadb':
        demonstrateMySQLTypes($db);
        break;
    case 'pgsql':
        demonstratePostgreSQLTypes($db);
        break;
    case 'sqlsrv':
        demonstrateMSSQLTypes($db);
        break;
    case 'sqlite':
        demonstrateSQLiteTypes($db);
        break;
    case 'oci':
        demonstrateOracleTypes($db);
        break;
    default:
        echo "Unknown dialect: $dialectName\n";
        break;
}

function demonstrateMySQLTypes(PdoDb $db): void
{
    echo "=== MySQL/MariaDB-Specific Types ===\n\n";
    
    $tableName = 'demo_mysql_types';
    
    // Drop table if exists
    try {
        $db->schema()->dropTable($tableName);
        echo "Dropped existing table '$tableName'\n";
    } catch (\Throwable) {
        // Table doesn't exist, continue
    }
    
    // Create table with MySQL-specific types
    echo "Creating table with MySQL-specific types...\n";
    $db->schema()->createTable($tableName, [
        'id' => $db->schema()->primaryKey(),
        
        // ENUM type - MySQL specialty
        'status' => $db->schema()->enum(['draft', 'published', 'archived'])
            ->defaultValue('draft'),
        
        // SET type - MySQL specialty
        'permissions' => $db->schema()->set(['read', 'write', 'delete']),
        
        // Integer types with MySQL optimization
        'tiny_num' => $db->schema()->tinyInteger()->unsigned(),
        'medium_num' => $db->schema()->mediumInteger(),
        
        // Text types with MySQL sizes
        'short_text' => $db->schema()->tinyText(),
        'medium_text' => $db->schema()->mediumText(),
        'long_text' => $db->schema()->longText(),
        
        // Binary types
        'fixed_binary' => $db->schema()->binary(16),
        'var_binary' => $db->schema()->varbinary(255),
        
        // BLOB types
        'small_blob' => $db->schema()->tinyBlob(),
        'medium_blob' => $db->schema()->mediumBlob(),
        'large_blob' => $db->schema()->longBlob(),
        
        // Note: Spatial types require special handling for data insertion
        
        // Year type
        'birth_year' => $db->schema()->year(),
        
        // Optimized types
        'uuid_field' => $db->schema()->uuid(),
        'is_active' => $db->schema()->boolean()->defaultValue(true),
        
        'created_at' => $db->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    ]);
    
    echo "✓ Table created successfully!\n\n";
    
    // Insert sample data using dialect-specific values
    echo "Inserting sample data...\n";
    $db->find()->table($tableName)->insert([
        'status' => 'published',
        'permissions' => 'read,write',
        'tiny_num' => 255,
        'medium_num' => 1000000,
        'short_text' => 'Short text content',
        'medium_text' => 'Medium length text content for testing',
        'long_text' => 'Very long text content that could be much larger...',
        'fixed_binary' => str_repeat('A', 16),
        'var_binary' => 'variable_binary_data',
        'small_blob' => 'small blob content',
        'medium_blob' => 'medium blob content',
        'large_blob' => 'large blob content',
        'birth_year' => 1990,
        'uuid_field' => '550e8400-e29b-41d4-a716-446655440000',
        'is_active' => true,
    ]);
    
    echo "✓ Sample data inserted!\n\n";
    
    // Query and display
    $record = $db->find()->table($tableName)->first();
    echo "Sample record:\n";
    echo "- Status (ENUM): {$record['status']}\n";
    echo "- Permissions (SET): {$record['permissions']}\n";
    echo "- Tiny number (TINYINT UNSIGNED): {$record['tiny_num']}\n";
    echo "- Medium number (MEDIUMINT): {$record['medium_num']}\n";
    echo "- UUID (CHAR(36)): {$record['uuid_field']}\n";
    echo "- Is active (TINYINT(1)): " . ($record['is_active'] ? 'Yes' : 'No') . "\n";
    echo "- Birth year (YEAR): {$record['birth_year']}\n\n";
    
    // Clean up
    $db->schema()->dropTable($tableName);
    echo "✓ Table dropped\n\n";
}

function demonstratePostgreSQLTypes(PdoDb $db): void
{
    echo "=== PostgreSQL-Specific Types ===\n\n";
    
    $tableName = 'demo_postgresql_types';
    
    // Drop table if exists
    try {
        $db->schema()->dropTable($tableName);
        echo "Dropped existing table '$tableName'\n";
    } catch (\Throwable) {
        // Table doesn't exist, continue
    }
    
    // Create table with PostgreSQL-specific types
    echo "Creating table with PostgreSQL-specific types...\n";
    $db->schema()->createTable($tableName, [
        'id' => $db->schema()->primaryKey(),
        
        // Native UUID type
        'uuid_field' => $db->schema()->uuid(),
        
        // JSON types
        'metadata' => $db->schema()->json(),
        'config' => $db->schema()->jsonb(),
        
        // Array types
        'tags' => $db->schema()->array('TEXT'),
        'numbers' => $db->schema()->array('INTEGER'),
        
        // Network types
        'ip_address' => $db->schema()->inet(),
        'network' => $db->schema()->cidr(),
        'mac_address' => $db->schema()->macaddr(),
        
        // Text search types
        'search_vector' => $db->schema()->tsvector(),
        
        // Special types
        'binary_data' => $db->schema()->bytea(),
        
        // Note: MONEY, INTERVAL, and geometric types require special data formats
        
        // Native boolean
        'is_active' => $db->schema()->boolean()->defaultValue(true),
        
        'created_at' => $db->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    ]);
    
    echo "✓ Table created successfully!\n\n";
    
    // Insert sample data using PostgreSQL-specific formats
    echo "Inserting sample data...\n";
    $db->find()->table($tableName)->insert([
        'uuid_field' => '550e8400-e29b-41d4-a716-446655440000',
        'metadata' => '{"key": "value", "number": 42}',
        'config' => '{"theme": "dark", "notifications": true}',
        'tags' => '{programming,php,database}',
        'numbers' => '{1,2,3,4,5}',
        'ip_address' => '192.168.1.1',
        'network' => '192.168.1.0/24',
        'mac_address' => '08:00:2b:01:02:03',
        'search_vector' => 'programming & database',
        'binary_data' => 'binary_content_here',
        'is_active' => true,
    ]);
    
    echo "✓ Sample data inserted!\n\n";
    
    // Query and display
    $record = $db->find()->table($tableName)->first();
    echo "Sample record:\n";
    echo "- UUID (UUID): {$record['uuid_field']}\n";
    echo "- Metadata (JSON): {$record['metadata']}\n";
    echo "- Config (JSONB): {$record['config']}\n";
    echo "- Tags (TEXT[]): {$record['tags']}\n";
    echo "- Numbers (INTEGER[]): {$record['numbers']}\n";
    echo "- IP Address (INET): {$record['ip_address']}\n";
    echo "- Network (CIDR): {$record['network']}\n";
    echo "- MAC Address (MACADDR): {$record['mac_address']}\n";
    echo "- Is active (BOOLEAN): " . ($record['is_active'] ? 'Yes' : 'No') . "\n\n";
    
    // Clean up
    $db->schema()->dropTable($tableName);
    echo "✓ Table dropped\n\n";
}

function demonstrateMSSQLTypes(PdoDb $db): void
{
    echo "=== MSSQL-Specific Types ===\n\n";
    
    $tableName = 'demo_mssql_types';
    
    // Drop table if exists
    try {
        $db->schema()->dropTable($tableName);
        echo "Dropped existing table '$tableName'\n";
    } catch (\Throwable) {
        // Table doesn't exist, continue
    }
    
    // Create table with MSSQL-specific types
    echo "Creating table with MSSQL-specific types...\n";
    $db->schema()->createTable($tableName, [
        'id' => $db->schema()->primaryKey(),
        
        // UNIQUEIDENTIFIER for UUIDs
        'uuid_field' => $db->schema()->uniqueidentifier(),
        
        // Unicode string types
        'name' => $db->schema()->nvarchar(255),
        'code' => $db->schema()->nchar(10),
        'description' => $db->schema()->ntext(),
        
        // Money types
        'price' => $db->schema()->money(),
        'small_price' => $db->schema()->smallMoney(),
        
        // Enhanced datetime types
        'created_at' => $db->schema()->datetime2(3)->defaultExpression('GETDATE()'),
        'updated_at' => $db->schema()->datetimeOffset(),
        'small_date' => $db->schema()->smallDatetime(),
        'time_only' => $db->schema()->time(0),
        
        // Binary types
        'fixed_binary' => $db->schema()->binary(16),
        'var_binary' => $db->schema()->varbinary(255),
        'image_data' => $db->schema()->image(),
        
        // Special types
        'real_number' => $db->schema()->real(),
        'xml_data' => $db->schema()->xml(),
        'location' => $db->schema()->geography(),
        'geometry_data' => $db->schema()->geometry(),
        'hierarchy' => $db->schema()->hierarchyid(),
        'variant_data' => $db->schema()->sqlVariant(),
        
        // Optimized boolean (BIT)
        'is_active' => $db->schema()->boolean()->defaultValue(true),
        
        // TINYINT (0-255 in MSSQL)
        'status_code' => $db->schema()->tinyInteger(),
    ]);
    
    echo "✓ Table created successfully!\n\n";
    
    // Insert sample data using MSSQL-specific formats
    echo "Inserting sample data...\n";
    $db->find()->table($tableName)->insert([
        'uuid_field' => '550e8400-e29b-41d4-a716-446655440000',
        'name' => 'Test Product',
        'code' => 'PROD001',
        'description' => 'Test product description with Unicode support',
        'price' => 99.99,
        'small_price' => 9.99,
        'small_date' => '2023-01-01 12:00:00',
        'time_only' => '14:30:00',
        // Use CONVERT for BINARY fields - MSSQL requires explicit type conversion
        'fixed_binary' => Db::raw("CONVERT(VARBINARY(16), 0x" . bin2hex(str_repeat('A', 16)) . ")"),
        'var_binary' => Db::raw("CONVERT(VARBINARY(255), 'variable_binary_data')"),
        'real_number' => 3.14159,
        'xml_data' => '<root><item>value</item></root>',
        'is_active' => true,
        'status_code' => 1,
    ]);
    
    echo "✓ Sample data inserted!\n\n";
    
    // Query and display
    $record = $db->find()->table($tableName)->first();
    echo "Sample record:\n";
    echo "- UUID (UNIQUEIDENTIFIER): {$record['uuid_field']}\n";
    echo "- Name (NVARCHAR): {$record['name']}\n";
    echo "- Code (NCHAR): {$record['code']}\n";
    echo "- Price (MONEY): {$record['price']}\n";
    echo "- Small Price (SMALLMONEY): {$record['small_price']}\n";
    echo "- Real Number (REAL): {$record['real_number']}\n";
    echo "- Is active (BIT): " . ($record['is_active'] ? 'Yes' : 'No') . "\n";
    echo "- Status code (TINYINT): {$record['status_code']}\n\n";
    
    // Clean up
    $db->schema()->dropTable($tableName);
    echo "✓ Table dropped\n\n";
}

function demonstrateSQLiteTypes(PdoDb $db): void
{
    echo "=== SQLite Type Mapping ===\n\n";
    
    $tableName = 'demo_sqlite_types';
    
    // Drop table if exists
    try {
        $db->schema()->dropTable($tableName);
        echo "Dropped existing table '$tableName'\n";
    } catch (\Throwable) {
        // Table doesn't exist, continue
    }
    
    // Create table showing SQLite type mapping
    echo "Creating table showing SQLite type mapping...\n";
    $db->schema()->createTable($tableName, [
        'id' => $db->schema()->primaryKey(),
        
        // All integer types map to INTEGER
        'tiny_int' => $db->schema()->tinyInteger(),
        'big_int' => $db->schema()->bigInteger(),
        
        // Boolean maps to INTEGER
        'is_active' => $db->schema()->boolean()->defaultValue(true),
        
        // All text types map to TEXT
        'short_text' => $db->schema()->string(),
        'uuid_field' => $db->schema()->uuid(),
        'json_data' => $db->schema()->json(),
        
        // Float types map to REAL
        'float_num' => $db->schema()->float(),
        'decimal_num' => $db->schema()->decimal(),
        
        // Binary types map to BLOB
        'blob_data' => $db->schema()->blob(),
        
        // DateTime types map to TEXT
        'created_at' => $db->schema()->datetime(),
    ]);
    
    echo "✓ Table created successfully!\n\n";
    
    // Insert sample data showing type coercion
    echo "Inserting sample data...\n";
    $db->find()->table($tableName)->insert([
        'tiny_int' => 127,
        'big_int' => 9223372036854775807,
        'is_active' => true,
        'short_text' => 'Short text',
        'uuid_field' => '550e8400-e29b-41d4-a716-446655440000',
        'json_data' => '{"key": "value", "number": 42}',
        'float_num' => 3.14159,
        'decimal_num' => 99.99,
        'blob_data' => 'blob_content',
        'created_at' => '2023-01-01 12:00:00',
    ]);
    
    echo "✓ Sample data inserted!\n\n";
    
    // Query and display showing SQLite type affinity
    $record = $db->find()->table($tableName)->first();
    echo "Sample record (showing SQLite type mapping):\n";
    echo "- Tiny int (INTEGER): {$record['tiny_int']}\n";
    echo "- Big int (INTEGER): {$record['big_int']}\n";
    echo "- Is active (INTEGER): " . ($record['is_active'] ? '1 (true)' : '0 (false)') . "\n";
    echo "- UUID (TEXT): {$record['uuid_field']}\n";
    echo "- JSON (TEXT): {$record['json_data']}\n";
    echo "- Float (REAL): {$record['float_num']}\n";
    echo "- Decimal (REAL): {$record['decimal_num']}\n";
    echo "- Created at (TEXT): {$record['created_at']}\n\n";
    
    // Clean up
    $db->schema()->dropTable($tableName);
    echo "✓ Table dropped\n\n";
}

echo "=== Universal vs Dialect-Specific Types ===\n\n";

echo "Universal types (work everywhere):\n";
echo "- \$schema->string(255)     // VARCHAR/NVARCHAR/TEXT\n";
echo "- \$schema->integer()       // INT/INTEGER\n";
echo "- \$schema->text()          // TEXT/NTEXT\n";
echo "- \$schema->datetime()      // DATETIME/TIMESTAMP/TEXT\n";
echo "- \$schema->json()          // JSON/JSONB/TEXT\n\n";

echo "Dialect-specific optimizations:\n";
echo "MySQL/MariaDB:\n";
echo "- \$schema->enum(['a','b'])     // ENUM type\n";
echo "- \$schema->tinyInteger()       // TINYINT\n";
echo "- \$schema->mediumText()        // MEDIUMTEXT\n";
echo "- \$schema->uuid()              // CHAR(36)\n\n";

echo "PostgreSQL:\n";
echo "- \$schema->uuid()              // UUID type\n";
echo "- \$schema->jsonb()             // JSONB (binary)\n";
echo "- \$schema->array('TEXT')       // TEXT[]\n";
echo "- \$schema->inet()              // INET type\n\n";

echo "MSSQL:\n";
echo "- \$schema->uniqueidentifier()  // UNIQUEIDENTIFIER\n";
echo "- \$schema->nvarchar(255)       // NVARCHAR (Unicode)\n";
echo "- \$schema->datetime2(3)        // DATETIME2 with precision\n";
echo "- \$schema->money()             // MONEY type\n\n";

echo "SQLite:\n";
echo "- All types map to: INTEGER, REAL, TEXT, BLOB, NULL\n";
echo "- \$schema->uuid() -> TEXT\n";
echo "- \$schema->boolean() -> INTEGER\n";
echo "- \$schema->decimal() -> REAL\n\n";

echo "Oracle:\n";
echo "- \$schema->number(10, 2)        // NUMBER with precision/scale\n";
echo "- \$schema->varchar2(255)       // VARCHAR2 (variable-length)\n";
echo "- \$schema->clob()               // CLOB (large text)\n";
echo "- \$schema->nclob()              // NCLOB (Unicode large text)\n";
echo "- \$schema->blob()               // BLOB (binary large object)\n";
echo "- \$schema->timestamp()          // TIMESTAMP\n";
echo "- \$schema->xmltype()            // XMLTYPE\n";
echo "- \$schema->uuid()               // RAW(16)\n\n";

function demonstrateOracleTypes(PdoDb $db): void
{
    echo "=== Oracle-Specific Types ===\n\n";
    
    $tableName = 'demo_oracle_types';
    
    // Drop table if exists
    try {
        $db->schema()->dropTable($tableName);
        echo "Dropped existing table '$tableName'\n";
    } catch (\Throwable) {
        // Table doesn't exist, continue
    }
    
    // Create table with Oracle-specific types
    echo "Creating table with Oracle-specific types...\n";
    $db->schema()->createTable($tableName, [
        'id' => $db->schema()->primaryKey(),
        
        // NUMBER type with precision and scale
        'price' => $db->schema()->number(10, 2),
        'quantity' => $db->schema()->number(10),
        
        // VARCHAR2 for variable-length strings
        'name' => $db->schema()->varchar2(255),
        'code' => $db->schema()->char(10),
        
        // Large text types
        'description' => $db->schema()->clob(),
        'unicode_text' => $db->schema()->nclob(),
        
        // Binary types
        'binary_data' => $db->schema()->blob(),
        
        // Date/Time types
        'created_at' => $db->schema()->timestamp()->defaultExpression('SYSTIMESTAMP'),
        'updated_at' => $db->schema()->date(),
        
        // Special types
        'uuid_field' => $db->schema()->uuid(), // RAW(16)
        'xml_data' => $db->schema()->xmltype(),
        
        // Boolean emulation (NUMBER(1))
        'is_active' => $db->schema()->boolean()->defaultValue(true),
        
        // JSON (VARCHAR2(4000) IS JSON)
        'metadata' => $db->schema()->json(),
    ]);
    
    echo "✓ Table created successfully!\n\n";
    
    // Insert sample data using Oracle-specific formats
    echo "Inserting sample data...\n";
    $db->find()->table($tableName)->insert([
        'price' => 99.99,
        'quantity' => 100,
        'name' => 'Test Product',
        'code' => 'PROD001',
        'description' => 'Long description text for CLOB field',
        'unicode_text' => 'Unicode text content',
        // Note: binary_data (BLOB) is omitted for simplicity
        // BLOB fields require actual binary data, not text strings
        'updated_at' => '2023-01-01',
        'uuid_field' => '550e8400-e29b-41d4-a716-446655440000',
        'xml_data' => '<root><item>value</item></root>',
        'is_active' => true,
        'metadata' => '{"key": "value", "number": 42}',
    ]);

    echo "✓ Sample data inserted!\n\n";
    
    // Query and display
    $record = $db->find()->table($tableName)->first();
    echo "Sample record:\n";
    echo "- Price (NUMBER(10,2)): {$record['PRICE']}\n";
    echo "- Quantity (NUMBER(10)): {$record['QUANTITY']}\n";
    echo "- Name (VARCHAR2): {$record['NAME']}\n";
    echo "- Code (CHAR): {$record['CODE']}\n";
    echo "- UUID (RAW(16)): {$record['UUID_FIELD']}\n";
    echo "- Is active (NUMBER(1)): " . ($record['IS_ACTIVE'] ? '1 (true)' : '0 (false)') . "\n";
    echo "- Metadata (VARCHAR2 IS JSON): {$record['METADATA']}\n\n";
    
    // Clean up
    $db->schema()->dropTable($tableName);
    echo "✓ Table dropped\n\n";
}

echo "✅ Dialect-specific schema types example completed!\n";