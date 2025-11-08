# Savepoints Examples

This directory contains examples demonstrating the use of savepoints and nested transactions in PDOdb.

## Examples

- **01-savepoint-examples.php** - Demonstrates basic savepoint operations, nested savepoints, error handling, and savepoint stack management.

## Features Demonstrated

- Creating savepoints within transactions
- Rolling back to specific savepoints
- Releasing savepoints without rolling back
- Nested savepoint management
- Error handling with savepoints
- Savepoint stack tracking

## Usage

Run the examples:

```bash
php examples/34-savepoints/01-savepoint-examples.php
```

Or use the test script:

```bash
./scripts/test-examples.sh
```

## Notes

- Savepoints are supported by all database dialects (MySQL, MariaDB, PostgreSQL, SQLite)
- Savepoints can only be created within an active transaction
- Rolling back to a savepoint removes all savepoints created after it
- Releasing a savepoint removes it without rolling back changes
- The savepoint stack is automatically cleared when committing or rolling back the main transaction

