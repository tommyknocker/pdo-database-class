# Installation

Installation guide for the PDOdb library.

## Requirements

- **PHP**: 8.4 or higher
- **PDO Extensions**:
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_pgsql` for PostgreSQL
  - `pdo_sqlite` for SQLite
- **Supported Databases**:
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 9.4+
  - SQLite 3.38+

## Installing via Composer

### Latest Version

```bash
composer require tommyknocker/pdo-database-class
```

### Specific Versions

```bash
# Latest 2.x version
composer require tommyknocker/pdo-database-class:^2.0

# Latest 1.x version (legacy)
composer require tommyknocker/pdo-database-class:^1.0

# Development version
composer require tommyknocker/pdo-database-class:dev-master
```

## Verifying Installation

After installation, you can verify it by creating a simple test file:

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

// Test SQLite connection (no setup required)
$db = new PdoDb('sqlite', [
    'path' => ':memory:'
]);

echo "PDOdb installed successfully!\n";
```

Run the test:

```bash
php test-installation.php
```

## Checking PDO Extensions

Before proceeding, verify that the required PDO extensions are installed:

```bash
php -m | grep pdo
```

You should see:
- `pdo_mysql` (for MySQL)
- `pdo_pgsql` (for PostgreSQL)
- `pdo_sqlite` (for SQLite)

### Installing PDO Extensions

#### Ubuntu/Debian

```bash
# MySQL
sudo apt-get install php8.4-mysql

# PostgreSQL
sudo apt-get install php8.4-pgsql

# SQLite
sudo apt-get install php8.4-sqlite3
```

#### macOS

```bash
# Using Homebrew
brew install php
```

#### CentOS/RHEL

```bash
# MySQL
sudo yum install php-mysql

# PostgreSQL
sudo yum install php-pgsql

# SQLite
sudo yum install php-sqlite3
```

### Verifying SQLite JSON Support

SQLite needs to be compiled with JSON support:

```bash
sqlite3 :memory: "SELECT json_valid('{}')"
```

If this returns `1`, JSON support is enabled. If it returns an error, you need to use a different SQLite build or recompile SQLite with JSON support.

## Next Steps

- [Configuration](configuration.md) - Configure your database connection
- [First Connection](first-connection.md) - Make your first connection
- [Hello World](hello-world.md) - Build your first query

