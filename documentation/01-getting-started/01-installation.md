# Installation

Installation guide for the PDOdb library.

## Requirements

- **PHP**: 8.4 or higher
- **PDO Extensions**:
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_pgsql` for PostgreSQL
  - `pdo_sqlite` for SQLite
  - `pdo_sqlsrv` for Microsoft SQL Server (MSSQL)
  - `pdo_oci` for Oracle Database
- **Database compatibility targets** (individual features may require newer versions):
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 9.4+
  - SQLite 3.38+
  - Microsoft SQL Server 2019+ (MSSQL)
  - Oracle Database 12c+

The transactional outbox and the inbox need newer servers (for example MySQL 8.0.13+ and MariaDB 10.6+); see [Minimum server versions](../05-advanced-features/27-transactional-outbox.md#minimum-server-versions).

These are compatibility targets, not a claim that every historical version is
covered by CI or still maintained by its vendor. See the [CI database matrix](../../README.md#database-test-coverage) and check vendor support before deploying.

## Installing via Composer

### Latest Version

```bash
composer require tommyknocker/pdo-database-class
```

### Specific Versions

```bash
# Latest 3.x version (requires PHP 8.4+)
composer require tommyknocker/pdo-database-class:^3.0

# Previous major versions (no longer maintained)
composer require tommyknocker/pdo-database-class:^2.0
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
- `pdo_oci` (for Oracle, if installed)

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

# Oracle
# Note: Oracle requires additional setup - see Oracle section below
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

# Oracle
# Note: Oracle requires additional setup - see Oracle section below
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

Follow Microsoft's current guide for your distribution and PHP version:
[Install the Microsoft ODBC driver for SQL Server (Linux)](https://learn.microsoft.com/en-us/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server).

Then install the `pdo_sqlsrv` PECL extension (adjust the PHP version in the path
if yours differs):

```bash
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

### Oracle Database

Oracle requires the `pdo_oci` extension, which needs Oracle Instant Client:

**Ubuntu/Debian:**

```bash
# Install Oracle Instant Client
# Download Oracle Instant Client Basic and SDK packages from Oracle website
# https://www.oracle.com/database/technologies/instant-client/linux-x86-64-downloads.html

# Extract packages to /opt/oracle/instantclient_21_1
sudo mkdir -p /opt/oracle
sudo unzip instantclient-basic-linux.x64-21.1.0.0.0.zip -d /opt/oracle
sudo unzip instantclient-sdk-linux.x64-21.1.0.0.0.zip -d /opt/oracle

# Set environment variables
echo 'export ORACLE_HOME=/opt/oracle/instantclient_21_1' | sudo tee -a /etc/environment
echo 'export LD_LIBRARY_PATH=$ORACLE_HOME:$LD_LIBRARY_PATH' | sudo tee -a /etc/environment
sudo ldconfig

# Install pdo_oci extension
sudo pecl install pdo_oci
echo "extension=pdo_oci.so" | sudo tee /etc/php/8.4/mods-available/pdo_oci.ini
sudo phpenmod pdo_oci
```

**macOS:**

```bash
# Install Oracle Instant Client via Homebrew
brew tap InstantClientTap/instantclient
brew install instantclient-basic instantclient-sdk

# Install pdo_oci extension
pecl install pdo_oci
```

**Windows:**

The `pdo_oci` extension is typically included with PHP installations on Windows. Ensure you have Oracle Instant Client installed and configured.

**Verifying Oracle Extension:**

```bash
php -m | grep pdo_oci
```

If you see `pdo_oci`, the extension is installed correctly.

**Note:** Oracle uses service names instead of database names. When configuring Oracle connections, use `service_name` (for Oracle 12c+ PDBs) or `sid` (for older installations) instead of `dbname`. See [Configuration Documentation](04-configuration.md#oracle-database-configuration) for details.

## Next Steps

- [Configuration](04-configuration.md) - Configure your database connection
- [First Connection](05-first-connection.md) - Make your first connection
- [Hello World](06-hello-world.md) - Build your first query
