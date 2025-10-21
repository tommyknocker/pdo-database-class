# Testing Guide

## Requirement for LoadCsv/LoadXml Tests

### MySQL Configuration

The `testLoadCsv()` and `testLoadXml()` tests require specific MySQL server configuration:

#### Local Development
When running MySQL locally, ensure it's started with the `--local-infile=1` option:

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
The `.github/workflows/tests.yml` is already configured with `--local-infile=1` option in the MySQL service configuration.

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

PostgreSQL's `COPY` command may require specific file permissions. In most development and CI environments, this works out of the box.

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

## Troubleshooting

If LoadCsv/LoadXml tests fail:

1. **Check MySQL server configuration**: Ensure `--local-infile=1` is set
2. **Check PHP extensions**: `php -m | grep -E "simplexml|xmlreader"`
3. **Check PDO connection**: The connection should have `PDO::MYSQL_ATTR_LOCAL_INFILE => true`
4. **Check file permissions**: Temporary files are created in `sys_get_temp_dir()`

