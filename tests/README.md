# Testing Guide

## Local AI tests and private credentials

Run the deterministic AI/MCP suite without an external provider:

```bash
composer pdodb:test:ai
```

It checks configuration, HTTP request shape, MCP stdio, Composer installation
paths and the smoke-test runner using fake keys and a loopback HTTP server.
It does not load `.env.ai.local` or make paid requests. No database servers
are needed; fixtures use SQLite in memory.

For real OpenRouter testing, create `.env.ai.local` from
[the template](../.env.ai.local.example), set its permissions to `600`, then
edit the key and an actual model ID in your local editor. The local file is
ignored by Git; the template contains no credentials. Existing shell environment
variables take precedence over the file. Do not put the actual key in test
sources, Composer scripts, shell command arguments or committed configuration.

```bash
chmod 600 .env.ai.local
composer pdodb:test:ai:check  # Validate local setup; no API request
composer pdodb:test:ai:live   # Explicitly make up to three AI requests
```

The live smoke test covers `explainAiAdvice()`, schema analysis and the MCP
`explain_plan` tool. It uses a synthetic SQLite database in memory, not the
project's configured databases. A successful run makes three requests; it stops
at the first failure and does not retry automatically. Defaults are 256 output
tokens and a 45-second timeout per request, adjustable through
`PDODB_AI_LIVE_MAX_TOKENS` (64–512) and `PDODB_AI_LIVE_TIMEOUT` (5–90).
For OpenRouter models that support it, set `PDODB_AI_LIVE_REASONING_EFFORT=none`
to reserve a small budget for visible output. Leave it empty to use the model's
default, or choose another supported effort. Reasoning can consume the entire
token limit and leave `content` empty; that is reported as budget exhaustion.
Disabling reasoning is model-dependent and is not supported by every model.
The script logs pass/fail summaries, never the key or raw provider/model output.
Live requests are blocked when `CI` or `GITHUB_ACTIONS` is present.

The file can also live outside the repository:

```bash
php scripts/test-ai-live.php --check --env=/absolute/path/to/private-ai.env
php scripts/test-ai-live.php --run --env=/absolute/path/to/private-ai.env
```

Without `--run`, the script only checks configuration. `--check` confirms that
settings and the local SQLite driver are present; it does not validate the key
against the provider. Network requests occur only with `--run`.

To test a particular PHP version:

```bash
php8.4 scripts/test-ai-live.php --run
php8.5 scripts/test-ai-live.php --run
```

Each live invocation makes its own requests. These are integration smoke tests:
they check transport, result contracts and a nonempty AI response, not the
correctness or performance of the model's SQL recommendations.

## MCP policy integration tests

`composer pdodb:test:ai` includes offline tests for allowlists, column/schema
search, hidden foreign keys, isolated connection selection, invalid configuration
and unsupported timeouts. For native catalog and statement-cancellation checks:

```bash
composer pdodb:test:mcp:db
```

This explicitly selected suite requires the same local test servers/accounts as
the existing MySQL, MariaDB, PostgreSQL, MSSQL and Oracle suites, plus SQLite. It
creates and removes only randomly named `MCP_*` test tables and PostgreSQL test
schemas. It verifies scoped metadata on all six engines and actual cancellation
and session restoration on PostgreSQL/MariaDB. It never calls an AI provider and
is excluded from the ordinary offline/shared suite.

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
- The PDO connection is configured with `Pdo\Mysql::ATTR_LOCAL_INFILE => true` in `MySQLDialect.php`

### PostgreSQL

PostgreSQL's `COPY FROM 'filepath'` command has a **known limitation** in containerized environments:
- PostgreSQL runs inside a Docker container
- The CSV file exists on the host filesystem
- PostgreSQL cannot access host files without volume mounting

**In GitHub Actions and similar CI environments**, the PostgreSQL LoadCsv test will automatically skip with a descriptive message. This is expected and not a failure.

#### Local Development
When running PostgreSQL tests locally with a native (non-Docker) PostgreSQL installation:

```sql
-- Grant file read permissions
GRANT pg_read_server_files TO testuser;

-- Or run tests as postgres superuser
```

If using Docker locally, the test will skip (same as CI).

## Test Structure

The test suite is organized into several directories:

- **`tests/shared/`** - Tests that work across all database dialects (SQLite, MySQL, MariaDB, PostgreSQL, MSSQL, Oracle)
- **`tests/sqlite/`** - SQLite-specific tests
- **`tests/mysql/`** - MySQL-specific tests
- **`tests/mariadb/`** - MariaDB-specific tests
- **`tests/postgresql/`** - PostgreSQL-specific tests
- **`tests/mssql/`** - MSSQL-specific tests
- **`tests/oracle/`** - Oracle-specific tests

### CLI Command Tests

CLI command tests are located in `tests/shared/` and dialect-specific directories:

- **`*CommandCliTests.php`** - Tests for CLI commands (model, repository, service, generate, etc.)
- **`*GeneratorTests.php`** - Tests for code generators (ModelGenerator, RepositoryGenerator, ServiceGenerator, etc.)

Some CLI tests are dialect-specific:
- **User management tests** - Only in `tests/mysql/`, `tests/mariadb/`, `tests/postgresql/` (user management not supported in SQLite)
- **ENUM generation tests** - Only in `tests/mysql/`, `tests/mariadb/`, `tests/postgresql/` (ENUM types not supported in SQLite)

## Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run all checks (PHPStan, PHP CS Fixer, PHPUnit, examples)
composer pdodb:check-all

# Run specific database tests (using testsuite)
vendor/bin/phpunit --testsuite "MySQL Tests"
vendor/bin/phpunit --testsuite "PostgreSQL Tests"
vendor/bin/phpunit --testsuite "Oracle Tests"
vendor/bin/phpunit --testsuite "MariaDB Tests"
vendor/bin/phpunit --testsuite "MSSQL Tests"
vendor/bin/phpunit --testsuite "SQLite Tests"

# Run shared tests (cross-dialect)
vendor/bin/phpunit --testsuite "Shared Tests"

# Run specific test file
vendor/bin/phpunit tests/shared/GenerateCommandCliTests.php

# Run specific test method
vendor/bin/phpunit --filter testGenerateModel tests/shared/GenerateCommandCliTests.php

# Run CLI command tests
vendor/bin/phpunit tests/shared/*CommandCliTests.php
vendor/bin/phpunit tests/shared/*GeneratorTests.php
```

## Test Behavior

### Automatic Skipping

Some tests will automatically skip if the required database configuration is unavailable:

#### MySQL LoadCsv/LoadXml
Will skip if `local_infile` is disabled:
```
S LoadCsv test requires MySQL configured with local_infile enabled. Error: [detailed PDO error]
```

#### PostgreSQL LoadCsv
Will skip in Docker/CI environments due to file access limitations:
```
S PostgreSQL COPY FROM requires file access from database server. In Docker/CI environments...
```

This is **expected behavior** and not a test failure. The business logic is still fully tested in other database engines (MySQL, SQLite).

## Troubleshooting

If you want LoadCsv/LoadXml tests to run (not skip):

### For MySQL:
1. **Enable local_infile globally**: `SET GLOBAL local_infile=1;`
2. **Check PHP extensions**: `php -m | grep -E "simplexml|xmlreader"`
3. **Verify PDO option**: The connection has `Pdo\Mysql::ATTR_LOCAL_INFILE => true` (already configured in `MySQLDialect.php`)
4. **Check file permissions**: Temporary files are created in `sys_get_temp_dir()`

### For PostgreSQL:
1. **Grant file access**: `GRANT pg_read_server_files TO testuser;`
2. **Or use superuser**: Run tests with a superuser account
3. **Check file permissions**: PostgreSQL process must be able to read the CSV file

## CLI Command Testing

### Code Generation Commands

The test suite includes comprehensive tests for code generation commands:

#### Legacy Commands (Still Supported)
- `pdodb model make` - Tested in `tests/shared/ModelCommandCliTests.php` and `tests/shared/CliToolsTests.php` (ModelGenerator tests)
- `pdodb repository make` - Tested in `tests/shared/RepositoryCommandCliTests.php` and `tests/shared/RepositoryGeneratorTests.php`
- `pdodb service make` - Tested in `tests/shared/ServiceCommandCliTests.php` and `tests/shared/ServiceGeneratorTests.php`

#### Unified Generate Command
- `pdodb generate model` - Tested in `tests/shared/GenerateCommandCliTests.php`
- `pdodb generate repository` - Tested in `tests/shared/GenerateCommandCliTests.php`
- `pdodb generate service` - Tested in `tests/shared/GenerateCommandCliTests.php`
- `pdodb generate api` - Tested in `tests/shared/GenerateCommandCliTests.php`
- `pdodb generate tests` - Tested in `tests/shared/GenerateCommandCliTests.php`
- `pdodb generate dto` - Tested in `tests/shared/GenerateCommandCliTests.php`
- `pdodb generate enum` - Tested in dialect-specific files (`tests/mysql/GenerateCommandCliTests.php`, `tests/mariadb/GenerateCommandCliTests.php`, `tests/postgresql/GenerateCommandCliTests.php`)
- `pdodb generate docs` - Tested in `tests/shared/GenerateCommandCliTests.php`

### Test Environment

CLI tests use temporary directories and SQLite databases to avoid side effects:

- Temporary database files are created in `sys_get_temp_dir()`
- Temporary output directories are created for generated files
- All temporary files are cleaned up in `tearDown()` methods
- Tests set `PDODB_NON_INTERACTIVE=1` to suppress interactive prompts

### Running CLI Tests

```bash
# Run all CLI command tests
vendor/bin/phpunit tests/shared/*CommandCliTests.php

# Run all generator tests
vendor/bin/phpunit tests/shared/*GeneratorTests.php

# Run generate command tests
vendor/bin/phpunit tests/shared/GenerateCommandCliTests.php

# Run dialect-specific generate enum tests
vendor/bin/phpunit --testsuite "MySQL Tests" --filter GenerateCommandCliTests
vendor/bin/phpunit --testsuite "MariaDB Tests" --filter GenerateCommandCliTests
vendor/bin/phpunit --testsuite "PostgreSQL Tests" --filter GenerateCommandCliTests
```

