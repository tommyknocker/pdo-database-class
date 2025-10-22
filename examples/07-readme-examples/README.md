# README Examples

This directory contains examples extracted from the main README.md documentation.

## Files

- `01-readme-examples.php` - Comprehensive demonstration of all major features shown in the README

## Features Demonstrated

- Basic CRUD operations
- Filtering and joining
- JSON operations
- Transactions
- Raw queries
- Complex conditions
- Callback subqueries (new feature)
- Helper functions

## Running the Examples

The examples are designed to work across all three supported database dialects:
- MySQL
- PostgreSQL  
- SQLite

Use the test script to run across all dialects:

```bash
bash scripts/test-examples.sh
```

Or run individually:

```bash
php examples/07-readme-examples/01-readme-examples.php
```
