# Testing Guide

## LoadCsv/LoadXml Tests

### Overview

The `testLoadCsv()` and `testLoadXml()` tests verify bulk data loading functionality. These tests will automatically skip if the required database configuration is not available, making them safe to run in any environment.

### MySQL Configuration

For MySQL tests to run successfully, the server must have `local_infile` enabled:

#### Local Development
When running MySQL locally, ensure `local_infile` is enabled:

```bash
# Docker
docker run --name mysql-test \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=testdb \
  -e MYSQL_USER=testuser \
  -e MYSQL_PASSWORD=testpass \
  -p 3306:3306 \
  mysql:8.0 --local-infile=1

# Or via my.cnf
[mysqld]
local_infile=1
```

#### GitHub Actions
The `.github/workflows/tests.yml` is configured to enable `local_infile` via SQL command after MySQL starts:

```yaml
- name: Enable MySQL local_infile
  run: |
    mysql -h 127.0.0.1 -uroot -proot -e "SET GLOBAL local_infile=1;"
```

### Required PHP Extensions

For XML loading tests to work, the following PHP extensions must be installed:
- `simplexml`
- `xmlreader`

These are already included in the GitHub Actions workflow configuration.

### Why These Requirements?

- **MySQL `local-infile`**: `LOAD DATA LOCAL INFILE` is disabled by default in MySQL 8.0+ for security reasons
- **PHP Extensions**: XML parsing requires `simplexml` and `xmlreader` extensions
- The PDO connection is configured with `PDO::MYSQL_ATTR_LOCAL_INFILE => true` in `MySQLDialect.php`

### PostgreSQL

PostgreSQL's `COPY FROM` command requires the `pg_read_server_files` role.

#### GitHub Actions
The `.github/workflows/tests.yml` automatically creates a testuser with necessary permissions:

```yaml
- name: Setup PostgreSQL test user with permissions
  run: |
    PGPASSWORD=postgres psql -h localhost -p 5433 -U postgres -d testdb -c "CREATE USER testuser WITH PASSWORD 'testpass';"
    PGPASSWORD=postgres psql -h localhost -p 5433 -U postgres -d testdb -c "GRANT pg_read_server_files TO testuser;"
```

#### Local Development
When running PostgreSQL tests locally, ensure your testuser has the necessary permissions:

```sql
GRANT pg_read_server_files TO testuser;
-- Or run tests as postgres superuser
```

## Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific database tests
vendor/bin/phpunit tests/PdoDbMySQLTest.php
vendor/bin/phpunit tests/PdoDbPostgreSQLTest.php
vendor/bin/phpunit tests/PdoDbSqliteTest.php

# Run specific test
vendor/bin/phpunit --filter testLoadCsv tests/PdoDbMySQLTest.php
```

## Test Behavior

### Automatic Skipping

MySQL LoadCsv/LoadXml tests use try-catch blocks and will automatically skip with a descriptive message if `local_infile` is disabled:

Example skip message:
```
S LoadCsv test requires MySQL configured with local_infile enabled. Error: [detailed PDO error]
```

This is **expected behavior** if MySQL is not properly configured and is not a test failure.

**PostgreSQL tests** in GitHub Actions are fully configured and will run without skipping.

## Troubleshooting

If you want LoadCsv/LoadXml tests to run (not skip):

### For MySQL:
1. **Enable local_infile globally**: `SET GLOBAL local_infile=1;`
2. **Check PHP extensions**: `php -m | grep -E "simplexml|xmlreader"`
3. **Verify PDO option**: The connection has `PDO::MYSQL_ATTR_LOCAL_INFILE => true` (already configured in `MySQLDialect.php`)
4. **Check file permissions**: Temporary files are created in `sys_get_temp_dir()`

### For PostgreSQL:
1. **Grant file access**: `GRANT pg_read_server_files TO testuser;`
2. **Or use superuser**: Run tests with a superuser account
3. **Check file permissions**: PostgreSQL process must be able to read the CSV file

