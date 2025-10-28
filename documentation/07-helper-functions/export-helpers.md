# Export Helpers

Export database results to various formats: JSON, CSV, and XML.

## JSON Export

### Basic Export

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('mysql', [...]);

// Get data
$users = $db->find()->from('users')->get();

// Export to JSON
$json = Db::toJson($users);
echo $json;
```

### With Custom Options

```php
// Compact format (no pretty print)
$json = Db::toJson($users, 0);

// Custom flags
$json = Db::toJson($users, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Custom depth
$json = Db::toJson($users, JSON_PRETTY_PRINT, 256);
```

### Save to File

```php
// Save JSON to file
$json = Db::toJson($users);
file_put_contents('users.json', $json);

// For download
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="users.json"');
echo $json;
```

## CSV Export

### Basic Export

```php
$users = $db->find()->from('users')->get();

// Export to CSV
$csv = Db::toCsv($users);
echo $csv;
```

### With Custom Delimiter

```php
// Semicolon delimiter (European format)
$csv = Db::toCsv($users, ';');

// Tab delimiter
$csv = Db::toCsv($users, "\t");

// Pipe delimiter
$csv = Db::toCsv($users, '|');
```

### Custom Enclosure

```php
// Single quote enclosure
$csv = Db::toCsv($users, ',', "'");

// Custom escape character
$csv = Db::toCsv($users, ',', '"', '#');
```

### Save to File

```php
// Save CSV to file
$csv = Db::toCsv($users);
file_put_contents('users.csv', $csv);

// For download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="users.csv"');
echo $csv;
```

## XML Export

### Basic Export

```php
$users = $db->find()->from('users')->get();

// Export to XML
$xml = Db::toXml($users);
echo $xml;
```

### Custom Element Names

```php
// Custom root and item elements
$xml = Db::toXml($users, 'users', 'user');

// With custom encoding
$xml = Db::toXml($users, 'users', 'user', 'ISO-8859-1');
```

### Save to File

```php
// Save XML to file
$xml = Db::toXml($users);
file_put_contents('users.xml', $xml);

// For download
header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="users.xml"');
echo $xml;
```

## Export Filtered Data

### With Conditions

```php
// Export only active users
$activeUsers = $db->find()
    ->from('users')
    ->where('active', 1)
    ->get();

$json = Db::toJson($activeUsers);
```

### Specific Columns

```php
// Export only specific columns
$userData = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->get();

$csv = Db::toCsv($userData);
```

## Export Complex Data

### Nested Arrays

```php
$complexData = [
    [
        'id' => 1,
        'name' => 'Alice',
        'tags' => ['php', 'mysql'],
        'metadata' => [
            'location' => 'USA',
            'status' => 'active'
        ]
    ]
];

$json = Db::toJson($complexData);
// Handles nested structures automatically
```

## Empty Data Handling

### Empty Result Sets

```php
$empty = $db->find()
    ->from('users')
    ->where('id', 9999)
    ->get(); // Returns []

// Empty JSON
$json = Db::toJson($empty); // Returns "[]"

// Empty CSV
$csv = Db::toCsv($empty); // Returns ""

// Empty XML
$xml = Db::toXml($empty); // Returns <data/>
```

## Browser Download

### JSON Download

```php
$data = $db->find()->from('users')->get();
$json = Db::toJson($data);

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="users.json"');
echo $json;
exit;
```

### CSV Download

```php
$data = $db->find()->from('users')->get();
$csv = Db::toCsv($data);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="users.csv"');
echo $csv;
exit;
```

### XML Download

```php
$data = $db->find()->from('users')->get();
$xml = Db::toXml($data);

header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="users.xml"');
echo $xml;
exit;
```

## Performance Tips

### Memory-Efficient Export

```php
// For large datasets, use batch processing
foreach ($db->find()->from('users')->cursor() as $user) {
    // Process individual records without loading all into memory
}

// Then export in chunks
$chunk = [];
foreach ($db->find()->from('users')->batch(1000) as $batch) {
    $json = Db::toJson($batch);
    // Append to file or process
}
```

## Parameters Reference

### Db::toJson()

```php
Db::toJson(array $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, int $depth = 512): string
```

- `$data` - Array of data to export
- `$flags` - JSON encoding flags
- `$depth` - Maximum encoding depth

### Db::toCsv()

```php
Db::toCsv(array $data, string $delimiter = ',', string $enclosure = '"', string $escapeCharacter = '\\'): string
```

- `$data` - Array of data to export
- `$delimiter` - CSV field delimiter
- `$enclosure` - CSV field enclosure
- `$escapeCharacter` - CSV escape character

### Db::toXml()

```php
Db::toXml(array $data, string $rootElement = 'data', string $itemElement = 'item', string $encoding = 'UTF-8'): string
```

- `$data` - Array of data to export
- `$rootElement` - Root XML element name
- `$itemElement` - Individual item element name
- `$encoding` - XML encoding

## Next Steps

- [Core Helpers](core-helpers.md) - Basic helpers
- [Real-World Examples](../10-cookbook/real-world-examples.md) - Practical examples
- [Common Patterns](../10-cookbook/common-patterns.md) - Common patterns


