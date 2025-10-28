# Schema Introspection

PDOdb provides methods to inspect database schema information including table structures, indexes, foreign keys, and constraints.

## Get Table Structure

### Basic Describe

```php
// Get column information
$structure = $db->describe('users');

// Returns array with column information:
// MySQL: Field, Type, Null, Key, Default, Extra
// PostgreSQL: column_name, data_type, is_nullable, column_default
// SQLite: cid, name, type, notnull, dflt_value, pk
```

### Inspect Specific Table

```php
foreach ($structure as $column) {
    echo $column['name'] . ': ' . $column['type'] . "\n";
}
```

## Get Indexes

```php
// Get indexes via QueryBuilder
$indexes = $db->find()->from('users')->indexes();

// Get indexes via direct call
$indexes = $db->indexes('users');

foreach ($indexes as $index) {
    echo "Index: " . $index['name'] . "\n";
    echo "Columns: " . $index['column_name'] . "\n";
    echo "Type: " . ($index['unique'] ? 'UNIQUE' : 'NORMAL') . "\n";
}
```

## Get Foreign Keys

```php
// Get foreign keys via QueryBuilder
$foreignKeys = $db->find()->from('orders')->keys();

// Get foreign keys via direct call
$foreignKeys = $db->keys('orders');

foreach ($foreignKeys as $fk) {
    echo "FK: " . $fk['column_name'] 
         . " references " . $fk['referenced_table_name'] 
         . "." . $fk['referenced_column_name'] . "\n";
}
```

## Get Constraints

```php
// Get constraints via QueryBuilder
$constraints = $db->find()->from('users')->constraints();

// Get constraints via direct call
$constraints = $db->constraints('users');

foreach ($constraints as $constraint) {
    echo "Type: " . $constraint['constraint_type'] . "\n";
    echo "Name: " . $constraint['constraint_name'] . "\n";
    echo "Column: " . $constraint['column_name'] . "\n";
}
```

## Cross-Database Compatibility

The methods return different column names depending on the database:

| MySQL | PostgreSQL | SQLite |
|-------|-----------|--------|
| `Field`, `Type`, `Key` | `column_name`, `data_type`, `constraint_type` | `name`, `type`, `pk` |

Always check for column existence before accessing:

```php
$name = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? 'unknown';
```

## Database-Specific Features

### MySQL

- `SHOW INDEXES FROM table` provides detailed index information
- Uses `INFORMATION_SCHEMA` for constraints and foreign keys

### PostgreSQL

- Uses `pg_indexes` view for index information
- Accesses `information_schema` for constraints and foreign keys
- Returns comprehensive constraint type information

### SQLite

- Uses `sqlite_master` for index and table information
- `PRAGMA foreign_key_list()` for foreign keys
- Limited constraint information compared to MySQL/PostgreSQL

## Use Cases

### Validate Schema

```php
function validateTableExists($db, $tableName): bool
{
    try {
        $structure = $db->describe($tableName);
        return !empty($structure);
    } catch (Exception $e) {
        return false;
    }
}
```

### Check Indexes

```php
function checkIndexes($db, $tableName): array
{
    $indexes = $db->indexes($tableName);
    $indexNames = array_column($indexes, 'name');
    return $indexNames;
}
```

### Verify Foreign Keys

```php
function getReferencedTables($db, $tableName): array
{
    $foreignKeys = $db->keys($tableName);
    return array_unique(array_column($foreignKeys, 'referenced_table_name'));
}
```

## Related

- [Table Structure Analysis](../05-advanced-features/query-analysis.md#table-structure-analysis)
- [API Reference](../09-reference/api-reference.md)
- [Dialect Differences](../09-reference/dialect-differences.md)

