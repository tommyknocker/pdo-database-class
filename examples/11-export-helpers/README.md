# Export Helpers

Examples demonstrating the use of export helper functions: `Db::toJson()`, `Db::toCsv()`, and `Db::toXml()`.

## Features

- **JSON Export** - Export data to JSON format with customizable encoding options
- **CSV Export** - Export data to CSV format with custom delimiters
- **XML Export** - Export data to XML format with customizable element names
- **Format Customization** - Control output format through parameters
- **Empty Data Handling** - Gracefully handles empty result sets

## Usage

```bash
# Run examples
php 01-export-examples.php

# Output files will be created in output/ directory:
# - users.json
# - users.csv
# - users.xml
```

## Examples

### 1. Basic JSON Export

```php
$data = $db->find()->from('users')->get();
$json = Db::toJson($data);
```

### 2. JSON with Custom Options

```php
// Compact format
$json = Db::toJson($data, 0);

// With custom flags
$json = Db::toJson($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

### 3. CSV Export

```php
$csv = Db::toCsv($data);

// Custom delimiter
$csv = Db::toCsv($data, ';');
```

### 4. XML Export

```php
$xml = Db::toXml($data);

// Custom element names
$xml = Db::toXml($data, 'users', 'user');
```

## Parameters

### Db::toJson($data, $flags, $depth)

- `$data` - Array of data to export
- `$flags` - JSON encoding flags (default: `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`)
- `$depth` - Maximum encoding depth (default: 512)

### Db::toCsv($data, $delimiter, $enclosure, $escapeCharacter)

- `$data` - Array of data to export
- `$delimiter` - CSV field delimiter (default: ',')
- `$enclosure` - CSV field enclosure (default: '"')
- `$escapeCharacter` - CSV escape character (default: '\\')

### Db::toXml($data, $rootElement, $itemElement, $encoding)

- `$data` - Array of data to export
- `$rootElement` - Root XML element name (default: 'data')
- `$itemElement` - Individual item element name (default: 'item')
- `$encoding` - XML encoding (default: 'UTF-8')

## Output

Generated files will be saved in `output/` directory with the exported data in the specified format.

