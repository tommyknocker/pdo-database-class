# Data Management Examples

Examples demonstrating data loading, batch processing, and export operations.

## Examples

### 01-file-loading.php
Loading data from JSON files into database tables.

**Topics covered:**
- Loading data from JSON files with `loadFromJson()`
- Batch size configuration for large files
- Error handling in file loading operations
- Automatic table creation from JSON structure
- Data type inference and conversion

### 02-batch-processing.php
Memory-efficient batch processing for large datasets.

**Topics covered:**
- Generator-based batch processing
- Processing large datasets without memory leaks
- Batch size configuration
- Progress tracking
- Error handling in batch operations

### 03-export-helpers.php
Exporting query results to various formats.

**Topics covered:**
- **JSON Export** - Export data to JSON format with customizable encoding options
- **CSV Export** - Export data to CSV format with custom delimiters
- **XML Export** - Export data to XML format with customizable element names
- Format customization through parameters
- Empty data handling

### 04-seeds.php
Database seeds for populating initial or test data.

**Topics covered:**
- Creating seed classes that extend `Seed`
- Using seed helper methods (`schema()`, `find()`, `insert()`, `update()`, `delete()`)
- Running seeds with `SeedRunner`
- Batch tracking and rollback support
- Seed execution with transactions
- Dry-run and pretend modes

### 05-seeds-cli.php
CLI commands for seed management.

**Topics covered:**
- Creating seeds via CLI: `pdodb seed create`
- Running seeds: `pdodb seed run`
- Listing seeds: `pdodb seed list`
- Rolling back seeds: `pdodb seed rollback`
- Dry-run and pretend modes
- Force execution

## Usage

```bash
# Run examples
php 01-file-loading.php
php 02-batch-processing.php
php 03-export-helpers.php
php 04-seeds.php
php 05-seeds-cli.php

# Export examples create output files in output/ directory:
# - users.json
# - users.csv
# - users.xml
```

## Features

- **File Loading** - Load data from JSON files into database tables
- **Batch Processing** - Process large datasets efficiently with generators
- **Export Helpers** - Export query results to JSON, CSV, and XML formats
- **Database Seeds** - Populate database with initial or test data, batch tracking, rollback support
- **CLI Seed Management** - Create, run, list, and rollback seeds via command line
- **Memory Efficiency** - Handle large datasets without memory issues
- **Error Handling** - Robust error handling for all operations

## Related Examples

- [Bulk Operations](../03-advanced/02-bulk-operations.php) - Bulk inserts and multi-row operations
- [JSON Operations](../04-json/) - Working with JSON data in queries
- [Migrations](../05-schema/03-migrations.php) - Database schema versioning
