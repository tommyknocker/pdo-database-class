# Installation

Installation guide for the PDOdb library.

## Requirements

- **PHP**: 8.4 or higher
- **PDO Extensions**:
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_pgsql` for PostgreSQL
  - `pdo_sqlite` for SQLite
  - `pdo_sqlsrv` for Microsoft SQL Server (MSSQL)
- **Supported Databases**:
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 9.4+
  - SQLite 3.38+
  - Microsoft SQL Server 2019+ (MSSQL)

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
- `pdo_mysql` (for MySQL/MariaDB)
- `pdo_pgsql` (for PostgreSQL)
- `pdo_sqlite` (for SQLite)
- `pdo_sqlsrv` (for MSSQL, if installed)

### Installing PDO Extensions

#### Ubuntu/Debian

```bash
# MySQL
sudo apt-get install php8.4-mysql

# PostgreSQL
sudo apt-get install php8.4-pgsql

# SQLite
sudo apt-get install php8.4-sqlite3

# MSSQL (Microsoft SQL Server)
# Note: MSSQL requires additional setup - see MSSQL section below
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

### MSSQL (Microsoft SQL Server)

MSSQL requires the `pdo_sqlsrv` extension, which needs additional setup:

**Ubuntu/Debian:**

```bash
# Install Microsoft ODBC Driver
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

# Install pdo_sqlsrv extension
sudo pecl install pdo_sqlsrv
echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/8.4/mods-available/pdo_sqlsrv.ini
sudo phpenmod pdo_sqlsrv
```

**macOS:**

```bash
# Install Microsoft ODBC Driver
brew tap microsoft/mssql-release https://github.com/Microsoft/homebrew-mssql-release
brew update
brew install msodbcsql18

# Install pdo_sqlsrv extension
pecl install pdo_sqlsrv
```

**Windows:**

The `pdo_sqlsrv` extension is typically included with PHP installations on Windows. Ensure you have the Microsoft ODBC Driver installed.

**Verifying MSSQL Extension:**

```bash
php -m | grep pdo_sqlsrv
```

If you see `pdo_sqlsrv`, the extension is installed correctly.

## Next Steps

- [Configuration](configuration.md) - Configure your database connection
- [First Connection](first-connection.md) - Make your first connection
- [Hello World](hello-world.md) - Build your first query
