# File Loading

Load data from CSV and XML files into your database.

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

## Next Steps

- [Bulk Operations](bulk-operations.md) - Multi-row operations
- [Batch Processing](batch-processing.md) - Large datasets
- [Troubleshooting](../10-cookbook/troubleshooting.md) - Common issues