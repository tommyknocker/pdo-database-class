# File Loading Examples

Examples demonstrating how to load data from JSON, CSV, and XML files into database tables.

## Files

### 01-json-loading.php

Demonstrates loading data from JSON files:

- **Array format**: Standard JSON array `[{...}, {...}]`
- **NDJSON format**: Newline-delimited JSON (one object per line)
- **Column mapping**: Specifying which columns to load
- **Batch size control**: Processing large files in batches
- **Nested JSON**: Handling objects and arrays in JSON values

**Key Features:**
- Auto-detection of JSON format
- Memory-efficient processing with generators
- Transaction support for data integrity
- Support for nested JSON structures

## Usage

```bash
# Run with SQLite (default)
php 01-json-loading.php

# Run with MySQL
PDODB_DRIVER=mysql php 01-json-loading.php

# Run with PostgreSQL
PDODB_DRIVER=pgsql php 01-json-loading.php
```

## JSON File Formats

### Array Format

```json
[
  {"name": "Laptop", "price": 1299.99, "stock": 15},
  {"name": "Mouse", "price": 29.99, "stock": 150}
]
```

### NDJSON Format (Newline-Delimited)

```json
{"name": "Laptop", "price": 1299.99, "stock": 15}
{"name": "Mouse", "price": 29.99, "stock": 150}
```

NDJSON is recommended for large files as it allows streaming without loading the entire file into memory.

## Options

### loadJson() Options

```php
$db->find()->table('products')->loadJson('products.json', [
    'format' => 'auto',        // 'auto', 'array', or 'lines'
    'columns' => [],           // Specific columns to load (empty = all)
    'batchSize' => 500,        // Number of rows per batch
]);
```

## Notes

- All loaders use transactions for data integrity
- Nested JSON objects/arrays are automatically encoded as JSON strings
- Format auto-detection: files starting with `[` are treated as arrays
- Batch processing ensures efficient memory usage for large files

## See Also

- [File Loading Documentation](../../documentation/05-advanced-features/file-loading.md)
- [Bulk Operations](../03-advanced/02-bulk-operations.php)
- [Batch Processing](../10-batch-processing/01-batch-examples.php)

