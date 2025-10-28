# File Loading

Load data from CSV, XML, and JSON files into your database.

## CSV Loading

### Basic CSV Load

```php
$db->loadCsv('users', 'users.csv', [
    'name', 'email', 'age'
]);
```

### CSV with Options

```php
$db->loadCsv('users', 'users.csv', [
    'columns' => ['name', 'email', 'age'],
    'delimiter' => ',',
    'enclosure' => '"',
    'escape' => '\\',
    'header' => true,
    'skip' => 1
]);
```

### Large CSV File

```php
use tommyknocker\pdodb\helpers\Db;

// Process in batches
$handler = function($row) use ($db) {
    $db->find()
        ->table('users')
        ->insert([
            'name' => $row[0],
            'email' => $row[1],
            'created_at' => Db::now()
        ]);
};

$db->loadCsv('users', 'large_file.csv', [
    'handler' => $handler,
    'batch_size' => 1000
]);
```

## XML Loading

### Basic XML Load

```php
$db->loadXml('products', 'products.xml', [
    'name' => 'title',
    'price' => 'cost',
    'stock' => 'quantity'
]);
```

### XML with Namespace

```php
$db->loadXml('products', 'products.xml', [
    'name' => 'title',
    'price' => 'cost',
    'xml_namespace' => 'http://example.com/products'
]);
```

### XML with XPath

```php
$db->loadXml('products', 'products.xml', [
    'mapping' => [
        'name' => '//product/title',
        'price' => '//product/price',
        'description' => '//product/description'
    ]
]);
```

## JSON Loading

### Basic JSON Load

Load data from a JSON array format:

```php
$db->find()
    ->table('products')
    ->loadJson('products.json');
```

### NDJSON Format

Load data from a newline-delimited JSON format:

```php
$db->find()
    ->table('products')
    ->loadJson('products.ndjson', [
        'format' => 'lines',
    ]);
```

### JSON with Options

```php
$db->find()
    ->table('products')
    ->loadJson('products.json', [
        'columns' => ['name', 'price', 'stock'],
        'batchSize' => 1000,
        'format' => 'auto', // 'auto', 'array', 'lines'
    ]);
```

### JSON File Formats

PDOdb supports two JSON formats:

**Array format** (default):
```json
[
  {"name": "Laptop", "price": 1299.99, "stock": 15},
  {"name": "Mouse", "price": 29.99, "stock": 150}
]
```

**NDJSON format** (newline-delimited):
```json
{"name": "Laptop", "price": 1299.99, "stock": 15}
{"name": "Mouse", "price": 29.99, "stock": 150}
```

The NDJSON format is useful for very large files as it allows streaming without loading the entire file into memory.

### Handling Nested JSON

JSON values containing nested objects or arrays are automatically encoded:

```php
$db->find()
    ->table('products')
    ->loadJson('products.json');
```

Where `products.json` contains:
```json
[
  {
    "name": "Laptop",
    "specs": {"ram": "16GB", "cpu": "Intel i7"},
    "tags": ["gaming", "professional"]
  }
]
```

The nested data will be stored as JSON-encoded strings in the database.

## Next Steps

- [Bulk Operations](bulk-operations.md) - Multi-row operations
- [Batch Processing](batch-processing.md) - Large datasets
- [Troubleshooting](../10-cookbook/troubleshooting.md) - Common issues