# Dialect-Specific Schema Types

PDOdb provides both universal schema types that work across all databases and dialect-specific types that are optimized for each database engine. This allows you to write portable code while still taking advantage of database-specific features when needed.

## Architecture

Each database dialect now provides its own `DdlQueryBuilder` that extends the base functionality with dialect-specific column types:

```php
// Universal approach - works everywhere
$db->schema()->string(255)->notNull()
$db->schema()->integer()->autoIncrement()
$db->schema()->boolean()->defaultValue(true)

// Dialect-specific approach - optimized for current database
$db->schema()->enum(['active', 'inactive'])  // MySQL/MariaDB only
$db->schema()->uuid()->defaultExpression('gen_random_uuid()')  // PostgreSQL native UUID
$db->schema()->uniqueidentifier()->defaultExpression('NEWID()')  // MSSQL GUID
```

## Universal Types

These types work across all supported databases:

### Basic Types
```php
$schema->string(?int $length = null)     // VARCHAR/NVARCHAR/TEXT
$schema->text()                          // TEXT/NTEXT
$schema->integer(?int $length = null)    // INT/INTEGER
$schema->float(?int $precision = null, ?int $scale = null)  // FLOAT/REAL
$schema->decimal(int $precision = 10, int $scale = 2)       // DECIMAL/NUMERIC
$schema->boolean()                       // BOOLEAN/TINYINT(1)/BIT/INTEGER
$schema->datetime()                      // DATETIME/TIMESTAMP/TEXT
$schema->timestamp()                     // TIMESTAMP/TEXT
$schema->date()                          // DATE/TEXT
$schema->time()                          // TIME/TEXT
$schema->json()                          // JSON/JSONB/TEXT
$schema->uuid()                          // UUID/CHAR(36)/UNIQUEIDENTIFIER/TEXT
```

### Extended Universal Types
```php
$schema->tinyInteger(?int $length = null)    // TINYINT/SMALLINT/INTEGER
$schema->smallInteger(?int $length = null)   // SMALLINT/INTEGER
$schema->mediumInteger(?int $length = null)  // MEDIUMINT/INTEGER
$schema->bigInteger()                        // BIGINT/INTEGER
$schema->longText()                          // LONGTEXT/TEXT/NVARCHAR(MAX)
$schema->mediumText()                        // MEDIUMTEXT/TEXT
$schema->tinyText()                          // TINYTEXT/TEXT
$schema->binary(?int $length = null)         // BINARY/BYTEA/BLOB
$schema->varbinary(?int $length = null)      // VARBINARY/BYTEA/BLOB
$schema->blob()                              // BLOB/BYTEA
$schema->longBlob()                          // LONGBLOB/BLOB
$schema->mediumBlob()                        // MEDIUMBLOB/BLOB
$schema->tinyBlob()                          // TINYBLOB/BLOB
$schema->year(?int $length = null)           // YEAR/SMALLINT/INTEGER
```

## MySQL/MariaDB-Specific Types

MySQL and MariaDB share the same schema builder with additional MySQL-specific types:

### Enum and Set Types
```php
// ENUM type - MySQL specialty
$schema->enum(['draft', 'published', 'archived'])
    ->defaultValue('draft')

// SET type - allows multiple values
$schema->set(['read', 'write', 'delete'])
```

### Integer Types with MySQL Optimization
```php
$schema->tinyInteger()      // TINYINT (-128 to 127)
$schema->tinyInteger()->unsigned()  // TINYINT UNSIGNED (0 to 255)
$schema->mediumInteger()    // MEDIUMINT (-8388608 to 8388607)
```

### Text Types with Size Variants
```php
$schema->tinyText()         // TINYTEXT (up to 255 characters)
$schema->mediumText()       // MEDIUMTEXT (up to 16MB)
$schema->longText()         // LONGTEXT (up to 4GB)
```

### Binary and Blob Types
```php
$schema->binary(16)         // BINARY(16) - fixed length
$schema->varbinary(255)     // VARBINARY(255) - variable length
$schema->tinyBlob()         // TINYBLOB (up to 255 bytes)
$schema->mediumBlob()       // MEDIUMBLOB (up to 16MB)
$schema->longBlob()         // LONGBLOB (up to 4GB)
```

### Spatial Types
```php
$schema->geometry()         // GEOMETRY
$schema->point()            // POINT
$schema->lineString()       // LINESTRING
$schema->polygon()          // POLYGON
```

### Other MySQL Types
```php
$schema->year()             // YEAR(4)
$schema->year(2)            // YEAR(2) - deprecated but supported
```

### Optimized Types
```php
$schema->uuid()             // CHAR(36) - optimized for MySQL
$schema->boolean()          // TINYINT(1) - MySQL boolean convention
```

## PostgreSQL-Specific Types

PostgreSQL provides the richest set of native data types:

### UUID and JSON Types
```php
$schema->uuid()             // Native UUID type
    ->defaultExpression('gen_random_uuid()')

$schema->json()             // JSON type
$schema->jsonb()            // JSONB (binary JSON) - faster queries
```

### Serial Types (Auto-increment)
```php
$schema->serial()           // SERIAL (auto-incrementing integer)
$schema->bigSerial()        // BIGSERIAL (auto-incrementing bigint)
$schema->smallSerial()      // SMALLSERIAL (auto-incrementing smallint)
```

### Array Types
```php
$schema->array('TEXT')      // TEXT[] array
$schema->array('INTEGER')   // INTEGER[] array
$schema->array('TEXT', 2)   // TEXT[][] - 2D array
```

### Network Address Types
```php
$schema->inet()             // INET - IPv4/IPv6 addresses
$schema->cidr()             // CIDR - network addresses
$schema->macaddr()          // MACADDR - MAC addresses
$schema->macaddr8()         // MACADDR8 - EUI-64 format
```

### Text Search Types
```php
$schema->tsvector()         // TSVECTOR - text search vector
$schema->tsquery()          // TSQUERY - text search query
```

### Geometric Types
```php
$schema->point()            // POINT
$schema->line()             // LINE
$schema->lseg()             // LSEG (line segment)
$schema->box()              // BOX (rectangular box)
$schema->path()             // PATH
$schema->polygon()          // POLYGON
$schema->circle()           // CIRCLE
```

### Other PostgreSQL Types
```php
$schema->bytea()            // BYTEA - binary data
$schema->money()            // MONEY
$schema->interval()         // INTERVAL
```

### Type Overrides
```php
$schema->tinyInteger()      // Maps to SMALLINT (PostgreSQL has no TINYINT)
$schema->boolean()          // Native BOOLEAN type
$schema->longText()         // Maps to TEXT (no size limit in PostgreSQL)
```

## MSSQL-Specific Types

MSSQL provides extensive Unicode support and specialized types:

### UUID Type
```php
$schema->uniqueidentifier() // UNIQUEIDENTIFIER
    ->defaultExpression('NEWID()')

$schema->uuid()             // Alias for uniqueidentifier()
```

### Unicode String Types
```php
$schema->nvarchar(255)      // NVARCHAR(255) - Unicode variable string
$schema->nvarchar()         // NVARCHAR(MAX)
$schema->nchar(10)          // NCHAR(10) - Unicode fixed string
$schema->ntext()            // NTEXT - Unicode text
```

### Money Types
```php
$schema->money()            // MONEY
$schema->smallMoney()       // SMALLMONEY
```

### Enhanced DateTime Types
```php
$schema->datetime2(3)       // DATETIME2(3) - with fractional seconds
$schema->datetimeOffset()   // DATETIMEOFFSET - with timezone
$schema->smallDatetime()    // SMALLDATETIME
$schema->time(5)            // TIME(5) - with precision
```

### Binary Types
```php
$schema->binary(16)         // BINARY(16)
$schema->varbinary(255)     // VARBINARY(255)
$schema->varbinary()        // VARBINARY(MAX)
$schema->image()            // IMAGE - legacy binary type
```

### Special Types
```php
$schema->real()             // REAL - single precision float
$schema->xml()              // XML
$schema->geography()        // GEOGRAPHY - spatial data
$schema->geometry()         // GEOMETRY - spatial data
$schema->hierarchyid()      // HIERARCHYID
$schema->sqlVariant()       // SQL_VARIANT
```

### Type Overrides
```php
$schema->tinyInteger()      // TINYINT (0-255 in MSSQL)
$schema->boolean()          // BIT
$schema->string(255)        // Maps to NVARCHAR(255) for Unicode
$schema->text()             // Maps to NTEXT for Unicode
$schema->longText()         // Maps to NVARCHAR(MAX)
```

## SQLite Type Mapping

SQLite has a simplified type system with type affinity. All types map to one of: INTEGER, REAL, TEXT, BLOB, NULL.

### Integer Types
```php
$schema->tinyInteger()      // INTEGER
$schema->smallInteger()     // INTEGER
$schema->mediumInteger()    // INTEGER
$schema->integer()          // INTEGER
$schema->bigInteger()       // INTEGER
$schema->boolean()          // INTEGER (0/1)
```

### Text Types
```php
$schema->string(255)        // TEXT
$schema->text()             // TEXT
$schema->tinyText()         // TEXT
$schema->mediumText()       // TEXT
$schema->longText()         // TEXT
$schema->uuid()             // TEXT
$schema->json()             // TEXT
```

### Numeric Types
```php
$schema->float()            // REAL
$schema->decimal(10, 2)     // REAL
$schema->numeric(10, 2)     // NUMERIC (with affinity)
```

### Binary Types
```php
$schema->binary(16)         // BLOB
$schema->varbinary(255)     // BLOB
$schema->blob()             // BLOB
$schema->tinyBlob()         // BLOB
$schema->mediumBlob()       // BLOB
$schema->longBlob()         // BLOB
```

### DateTime Types
```php
$schema->datetime()         // TEXT (ISO8601 format)
$schema->timestamp()        // TEXT
$schema->date()             // TEXT
$schema->time()             // TEXT
```

## Usage Examples

### Universal Table (Works Everywhere)
```php
$db->schema()->createTable('users', [
    'id' => $db->schema()->integer()->autoIncrement()->primaryKey(),
    'name' => $db->schema()->string(255)->notNull(),
    'email' => $db->schema()->string(255)->unique(),
    'is_active' => $db->schema()->boolean()->defaultValue(true),
    'created_at' => $db->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
]);
```

### MySQL-Optimized Table
```php
$db->schema()->createTable('products', [
    'id' => $db->schema()->integer()->autoIncrement()->primaryKey(),
    'name' => $db->schema()->string(255)->notNull(),
    'status' => $db->schema()->enum(['draft', 'published', 'archived'])
        ->defaultValue('draft'),
    'permissions' => $db->schema()->set(['read', 'write', 'delete']),
    'price' => $db->schema()->decimal(10, 2)->unsigned(),
    'description' => $db->schema()->mediumText(),
    'uuid' => $db->schema()->uuid(), // CHAR(36)
    'location' => $db->schema()->point(),
]);
```

### PostgreSQL-Optimized Table
```php
$db->schema()->createTable('articles', [
    'id' => $db->schema()->serial()->primaryKey(),
    'uuid' => $db->schema()->uuid()->defaultExpression('gen_random_uuid()'),
    'title' => $db->schema()->string(255)->notNull(),
    'content' => $db->schema()->text(),
    'metadata' => $db->schema()->jsonb(),
    'tags' => $db->schema()->array('TEXT'),
    'search_vector' => $db->schema()->tsvector(),
    'is_published' => $db->schema()->boolean()->defaultValue(false),
    'created_at' => $db->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
]);
```

### MSSQL-Optimized Table
```php
$db->schema()->createTable('customers', [
    'id' => $db->schema()->integer()->autoIncrement()->primaryKey(),
    'uuid' => $db->schema()->uniqueidentifier()->defaultExpression('NEWID()'),
    'name' => $db->schema()->nvarchar(255)->notNull(),
    'description' => $db->schema()->ntext(),
    'balance' => $db->schema()->money(),
    'created_at' => $db->schema()->datetime2(3)->defaultExpression('GETDATE()'),
    'updated_at' => $db->schema()->datetimeOffset(),
    'is_active' => $db->schema()->boolean()->defaultValue(true),
]);
```

## Best Practices

### 1. Start Universal, Optimize Later
```php
// Start with universal types for portability
$db->schema()->createTable('users', [
    'id' => $db->schema()->integer()->autoIncrement()->primaryKey(),
    'name' => $db->schema()->string(255)->notNull(),
    'is_active' => $db->schema()->boolean()->defaultValue(true),
]);

// Later optimize for specific databases if needed
if ($db->getDialect()->getDriverName() === 'mysql') {
    // Add MySQL-specific columns
    $db->schema()->addColumn('users', 'status', 
        $db->schema()->enum(['active', 'inactive'])->defaultValue('active')
    );
}
```

### 2. Use Dialect Detection for Conditional Logic
```php
$schema = $db->schema();
$dialectName = $db->getDialect()->getDriverName();

$uuidColumn = match($dialectName) {
    'pgsql' => $schema->uuid()->defaultExpression('gen_random_uuid()'),
    'sqlsrv' => $schema->uniqueidentifier()->defaultExpression('NEWID()'),
    'mysql', 'mariadb' => $schema->uuid(),
    'sqlite' => $schema->uuid(),
    default => $schema->string(36), // Fallback
};
```

### 3. Leverage Type-Specific Features
```php
// PostgreSQL: Use arrays for tags
if ($dialectName === 'pgsql') {
    $tagsColumn = $schema->array('TEXT');
} else {
    // Fallback: JSON array
    $tagsColumn = $schema->json();
}

// MySQL: Use ENUM for status
if (in_array($dialectName, ['mysql', 'mariadb'])) {
    $statusColumn = $schema->enum(['draft', 'published', 'archived']);
} else {
    // Fallback: VARCHAR with check constraint
    $statusColumn = $schema->string(20);
}
```

### 4. Consider Migration Compatibility
```php
// When writing migrations, document dialect-specific behavior
class CreateProductsTable extends Migration
{
    public function up(): void
    {
        $this->db->schema()->createTable('products', [
            'id' => $this->db->schema()->integer()->autoIncrement()->primaryKey(),
            'name' => $this->db->schema()->string(255)->notNull(),
            
            // This will be ENUM in MySQL/MariaDB, VARCHAR elsewhere
            'status' => $this->getStatusColumn(),
            
            // This will be UUID in PostgreSQL, UNIQUEIDENTIFIER in MSSQL, CHAR(36) in MySQL, TEXT in SQLite
            'uuid' => $this->db->schema()->uuid(),
        ]);
    }
    
    protected function getStatusColumn(): ColumnSchema
    {
        $dialectName = $this->db->getDialect()->getDriverName();
        
        if (in_array($dialectName, ['mysql', 'mariadb'])) {
            return $this->db->schema()->enum(['draft', 'published', 'archived'])
                ->defaultValue('draft');
        }
        
        return $this->db->schema()->string(20)->defaultValue('draft');
    }
}
```

## Testing Dialect-Specific Features

Each dialect has its own test suite for dialect-specific features:

- `tests/mysql/DialectSpecificSchemaTests.php`
- `tests/postgresql/DialectSpecificSchemaTests.php`
- `tests/mssql/DialectSpecificSchemaTests.php`
- `tests/sqlite/DialectSpecificSchemaTests.php`
- `tests/mariadb/DialectSpecificSchemaTests.php`

Run tests for your specific database:
```bash
# Test MySQL-specific features
vendor/bin/phpunit tests/mysql/DialectSpecificSchemaTests.php

# Test PostgreSQL-specific features
vendor/bin/phpunit tests/postgresql/DialectSpecificSchemaTests.php

# Test all dialect-specific features
vendor/bin/phpunit tests/*/DialectSpecificSchemaTests.php
```

## See Also

- [Schema Builder Basics](./19-schema-builder.md)
- [Migrations](./18-migrations.md)
- [Database Connections](../02-basic-usage/03-connections.md)
- [Examples: Dialect-Specific Types](../../examples/11-schema/08-dialect-specific-types.php)
