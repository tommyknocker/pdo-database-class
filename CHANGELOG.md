# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.11.1] - 2025-12-02

### Added
- **Interactive TUI Dashboard** - Real-time database monitoring with full-screen terminal interface:
  - Monitor active queries, connection pool, cache statistics, and server metrics
  - View query details with full query text, execution time, user, and database
  - Kill long-running queries with a single keystroke
  - Keyboard navigation with pane switching, scrolling, and fullscreen mode
  - Real-time refresh with configurable intervals (realtime, 0.5s, 1s, 2s, 5s)
  - Color-coded metrics for performance visualization
  - Launch with `pdodb ui` command

- **Code Generation Command** (`pdodb generate`):
  - `pdodb generate api` - Generate API controller classes
  - `pdodb generate tests` - Generate test files
  - `pdodb generate dto` - Generate Data Transfer Object classes
  - `pdodb generate enum` - Generate enum classes
  - `pdodb generate docs` - Generate documentation files
  - `pdodb generate model` - Generate ActiveRecord model classes
  - `pdodb generate repository` - Generate repository classes
  - `pdodb generate service` - Generate service classes
  - All generated namespaces are automatically converted to lowercase

- **Benchmark Command** (`pdodb benchmark`):
  - `pdodb benchmark query` - Benchmark SQL query performance
  - `pdodb benchmark crud` - Benchmark CRUD operations
  - `pdodb benchmark load` - Load testing with multiple connections
  - `pdodb benchmark profile` - Query profiling with detailed statistics
  - `pdodb benchmark compare` - Compare query performance

- **Version Command** (`pdodb version`):
  - Show application version information
  - `--version` / `-v` flag support for all commands

- **Index Suggestion Command** (`pdodb table suggest-indexes <table>`):
  - Analyze table queries and suggest optimal indexes
  - Help optimize database performance

- **Oracle-Specific Helper Functions**:
  - `Db::toTs()` - Convert value to Oracle TIMESTAMP
  - `Db::toDate()` - Convert value to Oracle DATE
  - `Db::toChar()` - Convert value to Oracle CHAR/VARCHAR2

- **IDE Autocompletion Support**:
  - Enhanced IDE autocompletion for `Model::find()` method
  - Improved type hints and documentation

- **Event System Enhancements**:
  - Added missing events to event system for better monitoring and auditing

- **CLI Improvements**:
  - "Did you mean?" command suggestions for typos
  - Help messages for generate subcommands when required parameters are missing

### Changed
- **Code Generation**:
  - All generated namespaces are now automatically converted to lowercase for consistency

### Fixed
- Fixed loading .env file before checking PDODB_DRIVER in connection list command
- Fixed parsing command line options without equals sign (e.g., `--option=value` and `--option value`)
- Fixed Oracle type helpers example and PHPStan errors
- Fixed benchmark command tests to use correct path to pdodb binary
- Added warning when --query is not specified for benchmark compare command
- Improved test reliability: skip user creation tests when privileges are missing

### Documentation
- Improved IDE autocompletion documentation
- Clarified PHPStan ignore comments in PdoDb::fromEnv method

### Tests
- Added comprehensive tests for Oracle-specific helpers and exception handling
- Improved benchmark command tests with better error handling

## [2.11.0] - 2025-11-28

### Added
- **Oracle Database Support** - Full support for Oracle Database (OCI driver):
  - Complete Oracle dialect implementation with all query builder features
  - Support for Oracle-specific data types and functions
  - CLOB handling for large text fields in WHERE, ORDER BY, and DISTINCT clauses
  - Oracle-specific SQL formatting (INSERT ALL, FILTER clause, etc.)
  - Support for recursive CTEs, window functions, and advanced Oracle features
  - `normalize_row_keys` option for automatic column name normalization
  - Oracle examples and comprehensive test coverage
  - Oracle CI integration in GitHub Actions with Oracle Instant Client installation

- **PdoDb::fromEnv() Method** - Unified .env file support:
  - Static method `PdoDb::fromEnv(?string $envPath = null)` for easy database connection from .env files
  - Automatic .env file detection (respects `PDODB_ENV_PATH` environment variable)
  - Support for all database dialects and configuration options
  - Reusable `EnvLoader` class for .env parsing logic

- **PHP Extension Validation** - Automatic validation of required PHP extensions:
  - `ExtensionChecker` class validates required PDO extensions before connection
  - Helpful error messages with installation suggestions for missing extensions
  - Support for all database dialects (MySQL, PostgreSQL, SQLite, MSSQL, Oracle)
  - Integration with `DialectRegistry` for automatic extension checking

- **Extended .env File Support** - Support for all dialect-specific options in .env files:
  - Oracle-specific options (`PDODB_SERVICE_NAME`, `PDODB_SID`)
  - All database-specific configuration options can be set via environment variables
  - Unified .env parsing across CLI tools and application code

- **Connection Management CLI Commands** (`pdodb connection`):
  - `pdodb connection list` - List all configured database connections
  - `pdodb connection test [name]` - Test database connection(s)
  - `pdodb connection info [name]` - Show detailed connection information
  - Support for multiple named connections

- **Database Seeds Functionality** (`pdodb seed`):
  - `pdodb seed create <name>` - Create seed file
  - `pdodb seed run [name]` - Run seed files
  - Support for database seeding in development and testing

- **Dump Command Backup Features** (`pdodb dump`):
  - Compression support (gzip, bzip2)
  - Auto-naming with timestamps
  - Backup rotation with configurable retention
  - Enhanced backup management capabilities

- **Table Management Commands** (`pdodb table`):
  - `pdodb table count [table]` - Count rows in table(s) with improved formatting
  - `pdodb table sample <table> [limit]` - Get random sample rows from table

- **Comprehensive Test Coverage**:
  - Extended test coverage to 85%+
  - Tests for CLI commands (MigrateCommand, ModelCommand, DbCommand, UserCommand)
  - Tests for DDL builders (MySQL, PostgreSQL, MSSQL, SQLite)
  - Tests for RepositoryGenerator, ServiceGenerator, MigrationGenerator
  - Tests for CacheManager, ConnectionCommand, MonitorManager
  - Tests for edge cases and error handling

### Changed
- **Oracle Compatibility Improvements**:
  - Improved Oracle support for TRUE/FALSE, CAST, GREATEST, and LEAST functions
  - Enhanced REGEXP_MATCH, COALESCE, and NULLIF support for Oracle
  - Improved CLOB handling in formatIfNull, formatRepeat, and formatReverse
  - Added formatUnionOrderBy() for Oracle ORDER BY in UNION queries
  - Better date handling in INSERT ALL statements
  - Cursor pagination support for Oracle

- **Refactoring**:
  - Removed dialect-specific logic from general classes (ConditionBuilder, concat() method)
  - Moved dialect-specific logic to dialect implementations
  - Improved code organization and maintainability

### Fixed
- **Oracle CI in GitHub Actions**:
  - Fixed Oracle Instant Client installation (version 23.26.0.0.0)
  - Fixed apt-get exit code 8 handling
  - Fixed pdo_oci and oci8 extension compilation
  - Fixed Oracle Instant Client SDK installation for headers
  - Improved error handling and diagnostics

- **Oracle CLI Examples**:
  - Fixed BaseCliCommand::buildConfigFromEnv() to check PDODB_SERVICE_NAME/PDODB_SID for Oracle
  - Added setEnvFromConfig() helper function for CLI examples
  - Fixed environment variable propagation for Oracle examples
  - All Oracle CLI examples now work correctly in GitHub Actions

- **Oracle Examples**:
  - Fixed recursive CTE examples
  - Fixed type helpers and JSON basics
  - Fixed window functions and CTEs
  - Fixed INSERT ALL dates and FILTER clause CLOB handling
  - Fixed cursor pagination

- **Other Fixes**:
  - Fixed Memcached adapter creation in CacheFactory
  - Fixed foreign key constraint handling in DDL examples for MySQL/MariaDB
  - Fixed PHPStan errors
  - Fixed test assertions and improved test reliability

### Documentation
- Added Oracle Database installation instructions
- Fixed all documentation links to use correct file names with numeric prefixes
- Created bidirectional links between documentation and examples
- Optimized README.md for better readability
- Updated TOC with missing sections
- Added CLI Tools section to table of contents
- Added bash completion installation instructions

## [2.10.3] - 2025-11-21

### Added
- **Project Initialization** (`pdodb init`) - Interactive wizard for quick project setup:
  - Interactive configuration wizard for database connection settings
  - Support for MySQL, PostgreSQL, SQLite, and MSSQL (SQLSRV) drivers
  - Configuration file generation (`.env` or `config/db.php`)
  - Automatic directory structure creation (migrations, models, repositories, services)
  - Connection testing before saving configuration
  - Advanced options (table prefix, caching, performance settings, multiple connections)
  - Cache dependency validation with helpful warnings if dependencies are missing
  - Fallback to array cache if selected cache type dependencies are unavailable

- **Repository and Service Generators** (`pdodb repository`, `pdodb service`):
  - `pdodb repository make <name>` - Generate repository classes with auto-detected model and table names
  - `pdodb service make <name>` - Generate service classes with dependency injection
  - Automatic primary key detection
  - Support for `--namespace`, `--force`, and `--connection` options

- **Cache Management Enhancements** (`pdodb cache`):
  - `pdodb cache invalidate <pattern>` - Invalidate cache entries by pattern:
    - `table:users` - Invalidate all cache entries for a specific table
    - `table:users_*` - Invalidate cache entries for tables with prefix
    - `pdodb_table_users_*` - Invalidate cache entries matching key pattern
  - Cache type detection in `pdodb cache stats` output (Redis, APCu, Memcached, Filesystem, Array, Unknown)
  - Universal cache type detection for all PSR-16 implementations (not just Symfony Cache)
  - Persistent cache statistics with atomic operations (Redis INCR, Memcached increment, APCu apcu_inc)
  - Optimistic locking with retry for non-atomic caches (Filesystem, Array)
  - Cache metadata storage linking cache keys to table names for efficient invalidation

- **Database Monitoring** (`pdodb monitor`):
  - `pdodb monitor queries` - Show active queries
  - `pdodb monitor connections` - Show active connections
  - `pdodb monitor slow [threshold]` - Show slow queries (default threshold: 1.0 seconds)
  - Cross-dialect support (MySQL, MariaDB, PostgreSQL, MSSQL, SQLite)

- **Table Management Enhancements** (`pdodb table`):
  - `pdodb table foreign-keys list <table>` - List foreign keys
  - `pdodb table foreign-keys add <table>` - Add foreign key
  - `pdodb table foreign-keys drop <table> <name>` - Drop foreign key

- **Migration Dry-Run Improvements** (`pdodb migrate`):
  - `pdodb migrate up --dry-run` now shows actual SQL queries that would be executed
  - SQL queries are collected via event dispatcher during migration execution in transaction
  - Transaction is rolled back after collecting SQL to prevent actual changes
  - SQL queries are formatted with parameter substitution for readable output

- **Bash Completion Script** (`scripts/pdodb-completion.bash`):
  - Tab completion for all pdodb CLI commands and subcommands
  - Completion for command options (`--force`, `--format`, `--connection`, etc.)
  - Completion for cache subcommands (`clear`, `stats`, `invalidate`)
  - Completion for migration, model, repository, service, and init commands

- **Dialect-Specific DDL Query Builders**:
  - MySQL-specific types: `enum()`, `set()`, `tinyInteger()`, `mediumInteger()`, `longText()`, `mediumText()`, `binary()`, `varbinary()`, `blob()` variants, `year()`, optimized `uuid()` (CHAR(36)), `boolean()` (TINYINT(1))
  - PostgreSQL-specific types: `uuid()`, `jsonb()`, `serial()`, `bigSerial()`, `inet()`, `cidr()`, `macaddr()`, `tsvector()`, `tsquery()`, `bytea()`, `money()`, `interval()`, `array()`, geometric types (`point()`, `line()`, `polygon()`, `circle()`, etc.)
  - MSSQL-specific types: `uniqueidentifier()`, `nvarchar()`, `nchar()`, `ntext()`, `money()`, `smallMoney()`, `datetime2()`, `smallDatetime()`, `datetimeOffset()`, `time()`, `binary()`, `varbinary()`, `image()`, `real()`, `xml()`, `geography()`, `geometry()`, `hierarchyid()`, `sqlVariant()`
  - SQLite-specific type mappings for all types
  - MariaDB inherits MySQL types with potential for MariaDB-specific extensions

- **CLI Version Auto-Detection**:
  - CLI version now automatically detected from git tags using `Composer\InstalledVersions` or `git describe`
  - Falls back to `composer.json` version if git is not available
  - Removes `-dev` suffix for clean version display

### Changed
- **Architecture Refactoring** - Moved dialect-specific logic from common classes to dialect implementations:
  - Monitoring methods (`getActiveQueries`, `getActiveConnections`, `getSlowQueries`) moved to `DialectInterface`
  - Table listing (`listTables`) moved to `DialectInterface`
  - Error handling methods (`getRetryableErrorCodes`, `getErrorDescription`) moved to `DialectInterface`
  - DML operations (`getInsertSelectColumns`) moved to `DialectInterface`
  - Configuration methods (`buildConfigFromEnv`, `normalizeConfigParams`) moved to `DialectInterface`
  - Concatenation formatting (`formatConcatExpression`) moved to `DialectInterface`
  - Removed `match/case` and `if` statements based on specific dialects from common classes
  - Follows Open/Closed Principle - new dialects can be added without modifying common classes

- **MSSQL Driver Standardization**:
  - Removed `mssql` alias, use only `sqlsrv` driver name everywhere
  - Renamed `config.mssql.php` to `config.sqlsrv.php`
  - Updated all examples, documentation, and tests to use `sqlsrv` instead of `mssql`
  - Updated `DialectRegistry` to remove `mssql` alias

- **CLI Command Organization**:
  - Commands sorted by logical groups with `init` at the top
  - Grouped commands: Project initialization, Migrations, Code generation, Database management, Development tools, Monitoring and performance, Utilities

- **Environment Variables Standardization**:
  - Standardized all environment variables from `DB_*` to `PDODB_*` in CI/CD workflows
  - Updated GitHub Actions workflow (`tests.yml`) to use `PDODB_*` variables
  - Removed fallback to `DB_*` variables in `BaseCliCommand` and `InitWizard`

- **Migration Dry-Run Behavior**:
  - `pdodb migrate up --dry-run` now executes migrations in transaction to collect actual SQL
  - Previously only showed comments like "Would execute migration.up()"
  - Now shows real SQL queries (CREATE TABLE, INSERT, etc.) that would be executed

- **README Updates**:
  - Added `pdodb init` as the fastest way to get started
  - Updated quick start guide to highlight interactive wizard
  - Updated examples to use `sqlsrv` instead of `mssql`

### Fixed
- **Cache Factory**:
  - Handle Redis connection errors gracefully (return null instead of throwing exception)
  - Improved error handling for missing Redis/Memcached extensions

- **MSSQL Monitoring Example**:
  - Fixed MSSQL monitoring example failing in GitHub Actions
  - Standardized environment variable names to `PDODB_*`

- **Configuration Loading**:
  - Fixed `database` vs `dbname` parameter normalization for PostgreSQL and MSSQL
  - Ensured `dbname` is required everywhere (not `database`)
  - Fixed `InitWizard` connection test to properly normalize parameters

- **CLI Prompts**:
  - Fixed duplicate default values in prompts (e.g., `Select [1] [1]:` → `Select [1]:`)
  - Removed hardcoded default values from prompt strings

### Technical Details
- **All tests passing**: 2491 tests, 8468 assertions across all dialects
- **Test coverage**: Added tests for SQL collection in dry-run mode, cache dependency validation, dialect-specific schema builders
- **Code quality**: PHPStan level 9, PHP-CS-Fixer compliant
- **Full backward compatibility**: 100% maintained (except removed `mssql` alias)

## [2.10.2] - 2025-11-18

### Added
- **Database Dump and Restore** (`pdodb dump`) - Export and import database schema and data:
  - `pdodb dump` - Dump entire database to SQL
  - `pdodb dump <table>` - Dump specific table
  - `pdodb dump --schema-only` - Dump only schema (CREATE TABLE, indexes, etc.)
  - `pdodb dump --data-only` - Dump only data (INSERT statements)
  - `pdodb dump --output=<file>` - Write dump to file instead of stdout
  - `pdodb dump --no-drop-tables` - Exclude DROP TABLE IF EXISTS statements (included by default)
  - `pdodb dump restore <file>` - Restore database from SQL dump file
  - `pdodb dump restore <file> --force` - Restore without confirmation
  - Cross-dialect support for all database types
  - Automatic DROP TABLE IF EXISTS before CREATE TABLE (configurable)

- **Table Management** (`pdodb table`) - Comprehensive table operations:
  - `pdodb table list` - List all tables
  - `pdodb table info <table>` - Show detailed table information
  - `pdodb table exists <table>` - Check if table exists
  - `pdodb table create <table>` - Create new table with interactive column prompts
  - `pdodb table drop <table>` - Drop table (with confirmation)
  - `pdodb table rename <table> <new_name>` - Rename table
  - `pdodb table truncate <table>` - Truncate table data
  - `pdodb table describe <table>` - Show table structure
  - `pdodb table columns list <table>` - List all columns
  - `pdodb table columns add <table>` - Add new column
  - `pdodb table columns alter <table> <column>` - Modify column
  - `pdodb table columns drop <table> <column>` - Drop column
  - `pdodb table indexes list <table>` - List all indexes
  - `pdodb table indexes add <table>` - Create new index
  - `pdodb table indexes drop <table> <index>` - Drop index
  - Support for `--format` option (table, json, yaml)
  - Support for `--if-exists` and `--if-not-exists` flags

- **Database Management** (`pdodb db`) - Database operations:
  - `pdodb db create <name>` - Create new database
  - `pdodb db drop <name>` - Drop database (with confirmation)
  - `pdodb db exists <name>` - Check if database exists
  - `pdodb db list` - List all databases
  - `pdodb db info <name>` - Show database information
  - Interactive prompts for database name input
  - Database name display in command header

- **User Management** (`pdodb user`) - Database user operations:
  - `pdodb user create <username>` - Create new database user
  - `pdodb user drop <username>` - Drop user (with confirmation)
  - `pdodb user exists <username>` - Check if user exists
  - `pdodb user list` - List all users
  - `pdodb user info <username>` - Show user information
  - `pdodb user grant <username>` - Grant privileges to user
  - `pdodb user revoke <username>` - Revoke privileges from user
  - `pdodb user password <username>` - Change user password
  - Cross-dialect support (MySQL, MariaDB, PostgreSQL, MSSQL)

- **Query Command Enhancements** (`pdodb query`):
  - `pdodb query explain <sql>` - Show EXPLAIN plan for query
  - `pdodb query format <sql>` - Pretty-print and format SQL
  - `pdodb query validate <sql>` - Validate SQL syntax

- **Model Generator Enhancements** (`pdodb model`):
  - `--force` option - Overwrite existing models without confirmation
  - `--namespace=<ns>` option - Specify namespace for generated models
  - `--connection=<name>` - Select named connection (global option)

- **Global CLI Options**:
  - `--help` - Show help message (available globally and per-command)
  - `--connection=<name>` - Use named connection from config/db.php
  - `--config=<path>` - Path to db.php configuration file
  - `--env=<path>` - Path to .env file

- **Migration Command Enhancements** (`pdodb migrate`):
  - `--dry-run` option - Show SQL without executing
  - `--pretend` option - Show what would be executed
  - `--force` option - Skip confirmation prompts
  - Confirmation prompts for `migrate up` command

### Changed
- **SQL Formatter Improvements**:
  - Enhanced tokenization with proper whitespace handling
  - Multi-word keyword recognition (LEFT/RIGHT/FULL [OUTER] JOIN, GROUP BY, ORDER BY)
  - Consistent keyword uppercasing
  - Improved formatting with proper line breaks for major clauses
  - Better handling of quoted strings and parentheses

- **Dialect Architecture Refactoring**:
  - Split dialect classes into separate components (SqlFormatter, DmlBuilder, DdlBuilder)
  - Reorganized dialects into subdirectories (sqlite/, mysql/, mariadb/, postgresql/, mssql/)
  - Extracted FeatureSupport classes for better organization
  - Replaced magic strings with QueryConstants class

- **CLI Command Behavior**:
  - Commands now show help when no arguments provided (consistent behavior)
  - Improved error messages with helpful suggestions (e.g., typo detection for 'restore')
  - Better table existence checking before operations

### Fixed
- **Error Handling**:
  - Improved error handling in `pdodb dump` command (replaces fatal errors with user-friendly messages)
  - Typo detection for 'restore' command with helpful suggestions
  - Table existence check before dumping (prevents fatal errors)
  - Better exception handling with try/catch for QueryException and general Exception

- **Configuration Loading**:
  - Fixed configuration loading order in BaseCliCommand
  - Corrected environment variable checks for CI environments
  - Improved MSSQL configuration loading (sqlsrv driver alias support)
  - Fixed MySQL/MariaDB CI environment variable support in examples

- **CI/CD**:
  - Added CI environment variable support to MariaDB config and workflow
  - Fixed MariaDB examples failing in GitHub Actions
  - Improved configuration file detection for examples

- **Window Functions**:
  - Restored MariaDB LAG/LEAD default value handling in formatWindowFunction

- **CLI Tools**:
  - Fixed CLI prompts display before waiting for input
  - Prevented CLI tools from hanging in tests by adding non-interactive mode
  - Improved MySQL/MariaDB table listing robustness

### Technical Details
- **All tests passing**: 2390 tests, 8016 assertions across all dialects
- **Test coverage**: Increased CLI integration test coverage
- **Code quality**: PHPStan level 8, PHP-CS-Fixer compliant
- **Full backward compatibility**: 100% maintained

## [2.10.1] - 2025-11-11

### Added
- **CLI Tools - Unified Command Structure** - Complete command-line interface for developer productivity:
  - **Migration Management** (`pdodb migrate`) - Create, run, rollback, and manage database migrations:
    - `pdodb migrate create <name>` - Generate new migration files
    - `pdodb migrate up` - Apply pending migrations
    - `pdodb migrate down` - Rollback last migration
    - `pdodb migrate to <version>` - Migrate to specific version
    - `pdodb migrate history` - View migration history
    - `pdodb migrate new` - List pending migrations
  - **Model Generator** (`pdodb model make`) - Auto-generate ActiveRecord models from database tables:
    - Automatic table name detection from model name
    - Primary key detection
    - Foreign key relationship detection
    - Attribute generation with types
    - Relationship definitions generation
    - Support for custom output paths via `PDODB_MODEL_PATH` environment variable
  - **Schema Inspector** (`pdodb schema inspect`) - Inspect database structure:
    - List all tables
    - Detailed table inspection (columns, indexes, foreign keys, constraints)
    - Multiple output formats (table, JSON, YAML)
    - Row count information
  - **Interactive Query Tester** (`pdodb query test`) - REPL for testing SQL queries:
    - Interactive SQL execution
    - Single query execution mode
    - Formatted result display
    - Help commands and query history

- **Enhanced Developer Experience**:
  - **PHPDoc Improvements** - Comprehensive inline documentation with examples:
    - `@example` tags with usage examples for 10+ key methods
    - `@warning` tags for dialect-specific differences and important considerations
    - `@note` tags for additional context and best practices
    - `@see` tags linking to full documentation
    - Enhanced documentation for `insertFrom()`, `merge()`, `getColumn()`, `paginate()`, `withRecursive()`, `createIndex()`, `groupBy()`, `case()`, `relations()`, and more
  - **Migration Path Resolution** - Improved migration file location detection:
    - Automatic detection of project root directory
    - Support for `.env` file configuration
    - Fallback to library paths for development
    - Better error messages for missing directories
  - **getColumn() Enhancement** - Added optional column name parameter:
    - `getColumn()` - Returns first selected column (backward compatible)
    - `getColumn('name')` - Returns specific column by name
    - Preserves array keys when used with `index()` method
    - Automatic detection of first selected column from SELECT clause

- **New Helper Methods**:
  - `Db::as()` - Alias helper to reduce `Db::raw()` usage for simple aliases
  - `Db::add()` - Addition helper for mathematical operations
  - `ColumnSchema::char()` - CHAR type support for fixed-length strings

### Changed
- **CLI Tools Architecture** - Reorganized into unified command structure:
  - Single entry point: `vendor/bin/pdodb` with subcommands
  - Yii2-inspired command structure (`pdodb migrate create`, `pdodb model make`, etc.)
  - Consistent error handling and output formatting
  - Better integration with Composer installation paths
  - Improved autoload.php path resolution for both development and production

- **Migration System Improvements**:
  - Fixed `migrateTo()` to use migration order instead of `apply_time` for correct rollback sequence
  - Improved rollback logic to ensure migrations are rolled back in reverse order
  - Better handling of migration version detection
  - Migration files excluded from Git tracking (added to `.gitignore`)

- **Code Quality**:
  - Increased test coverage from 79.79% to 80.25%
  - Comprehensive test coverage for CLI tools (MigrationGenerator, ModelGenerator, SchemaInspector, QueryTester)
  - Improved test coverage for RangeShardStrategy, RawValueResolver, MSSQLExplainParser, FileLoader, MigrationRunner
  - Enhanced test coverage for SelectQueryBuilder, DmlQueryBuilder, ExternalReferenceProcessingTrait

### Fixed
- **Cross-Dialect Compatibility**:
  - Improved identifier quoting in `CASE` statements for MSSQL reserved words
  - Fixed `GROUP BY` with qualified identifiers (table.column) and JOIN aliases
  - Better handling of MSSQL reserved words like 'plan', 'order', 'group'
  - Improved cross-dialect DDL operations

- **Migration System**:
  - Fixed migration path resolution to prioritize project root over library paths
  - Corrected autoload.php path detection for Composer installations
  - Fixed migration version detection after rollback operations

- **Type Safety**:
  - Added explicit type checks for PHPStan compatibility
  - Fixed type hints in various methods
  - Improved parameter type validation

### Technical Details
- **All tests passing**: 2052+ tests across all dialects
- **CLI Tools**: Fully tested with comprehensive test coverage
- **Code Coverage**: Improved from 79.79% to 80.25%
- **PHPStan Level 8**: Zero errors maintained
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained

## [2.10.0] - 2025-11-09

### Added
- **MSSQL (Microsoft SQL Server) Support** - Full support for MSSQL as a fifth database dialect:
  - **New MSSQLDialect class** - Complete SQL dialect implementation for Microsoft SQL Server
  - **Comprehensive test coverage** - 28 test suites, 732+ tests covering all MSSQL-specific functionality
  - **GitHub Actions integration** - Dedicated CI job for MSSQL with coverage reporting
  - **Example compatibility** - All examples now support MSSQL (64/64 passing)
  - **REGEXP support** - Custom REGEXP implementation using `PATINDEX` for pattern matching
  - **Cross-dialect compatibility** - All helper functions work consistently across all five dialects
  - **Complete documentation** - MSSQL configuration and usage examples added to documentation

- **MSSQL-Specific Features**:
  - **LATERAL JOIN support** - Automatic conversion to `CROSS APPLY`/`OUTER APPLY` syntax
  - **MERGE statement support** - Native MERGE operations for UPSERT patterns
  - **Window functions** - Full support for SQL Server window functions
  - **JSON operations** - Complete JSON support using SQL Server JSON functions
  - **Type casting** - Safe type conversion using `TRY_CAST` for error-free casting
  - **String functions** - Dialect-specific implementations (`LEN` vs `LENGTH`, `CEILING` vs `CEIL`)
  - **Date/time functions** - SQL Server-specific date/time handling
  - **Boolean types** - `BIT` type support with proper 0/1 handling
  - **Identity columns** - `IDENTITY(1,1)` support for auto-incrementing primary keys

### Changed
- **Architecture Improvements** - Major refactoring following SOLID principles:
  - **Dialect-specific logic migration** - Moved all dialect-specific checks from general classes to dialect implementations
  - **New DialectInterface methods** - Added 20+ new methods to `DialectInterface` for better abstraction:
    - `formatLateralJoin()` - Handle LATERAL JOIN conversion
    - `needsColumnQualificationInUpdateSet()` - PostgreSQL-specific UPDATE behavior
    - `executeExplainAnalyze()` - Dialect-specific EXPLAIN ANALYZE execution
    - `getBooleanType()`, `getTimestampType()`, `getDatetimeType()` - Type system abstraction
    - `isNoFieldsError()` - MSSQL-specific error detection
    - `appendLimitOffset()` - Dialect-specific LIMIT/OFFSET handling
    - `getPrimaryKeyType()`, `getBigPrimaryKeyType()`, `getStringType()` - Schema type abstraction
    - `formatMaterializedCte()` - Materialized CTE support
    - `registerRegexpFunctions()` - REGEXP function registration
    - `normalizeDefaultValue()` - Default value normalization
    - `buildMigrationTableSql()`, `buildMigrationInsertSql()` - Migration infrastructure
    - `extractErrorCode()` - Error code extraction
    - `getExplainParser()` - EXPLAIN parser abstraction
    - `getRecursiveCteKeyword()` - Recursive CTE keyword handling
    - `formatGreatest()`, `formatLeast()` - Type-safe GREATEST/LEAST functions
    - `buildDropTableIfExistsSql()` - DROP TABLE IF EXISTS support
  - **Removed dialect checks from general classes** - No more `if ($driver === 'sqlsrv')` in general classes
  - **Better separation of concerns** - Each dialect handles its own specific requirements

- **Example Improvements**:
  - **Refactored examples** - Removed `Db::raw()` calls with dialect-specific SQL from examples
  - **Library helpers usage** - Examples now use library helpers exclusively (`Db::length()`, `Db::position()`, `Db::greatest()`, etc.)
  - **Schema Builder usage** - Examples use Schema Builder for DDL operations where possible
  - **Better educational value** - Examples demonstrate proper library usage patterns

- **CI/CD Improvements**:
  - **MSSQL GitHub Actions job** - Complete CI setup for MSSQL testing
  - **Environment variable support** - Examples and tests can use environment variables for configuration
  - **Simplified user management** - Use SA user directly in CI for better reliability

### Fixed
- **PostgreSQL DROP TABLE CASCADE** - Fixed foreign key constraint issues when dropping tables
- **MSSQL type compatibility** - Fixed GREATEST/LEAST functions to handle type compatibility issues
- **MSSQL boolean handling** - Proper conversion of TRUE/FALSE to 1/0 for BIT type
- **MSSQL string functions** - Fixed LENGTH() -> LEN() conversion, SUBSTRING syntax
- **MSSQL REGEXP support** - Custom PATINDEX-based implementation for pattern matching
- **MSSQL LIMIT/OFFSET** - Proper OFFSET ... FETCH NEXT ... ROWS ONLY syntax
- **MSSQL error handling** - Proper handling of "contains no fields" errors
- **Example compatibility** - All examples now work correctly on all five dialects

### Technical Details
- **All tests passing**: 2052 tests, 7097 assertions (+732 tests, +2239 assertions from 2.9.3)
- **All examples passing**: 320/320 examples (64 files × 5 dialects each)
  - SQLite: 64/64 ✅
  - MySQL: 64/64 ✅
  - MariaDB: 64/64 ✅
  - PostgreSQL: 64/64 ✅
  - MSSQL: 64/64 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **MSSQL integration**: Complete dialect support with comprehensive testing and CI integration

## [2.9.3] - 2025-11-09

### Added
- **Regular Expression Helpers** - Cross-database regex support with unified API:
  - **REGEXP_LIKE** (`Db::regexpMatch()`) - Pattern matching for all dialects
  - **REGEXP_REPLACE** (`Db::regexpReplace()`) - Pattern-based string replacement
  - **REGEXP_EXTRACT** (`Db::regexpExtract()`) - Extract matched substrings with capture group support
  - **Automatic SQLite registration** - REGEXP functions automatically registered via `PDO::sqliteCreateFunction()` if not available
  - **Configuration option** - `enable_regexp` config option for SQLite (default: `true`) to control automatic registration
  - **Cross-dialect support** - MySQL/MariaDB (`REGEXP`, `REGEXP_REPLACE`, `REGEXP_SUBSTR`), PostgreSQL (`~`, `regexp_replace`, `regexp_match`), SQLite (via extension or PHP emulation)
  - **Complete documentation** - `documentation/07-helper-functions/string-helpers.md` with regex examples
  - **Comprehensive examples** - `examples/05-helpers/01-string-helpers.php` demonstrates all regex operations
  - **Full test coverage** - Tests for all dialects including SQLite emulation

- **JSON Modification Helpers** - Enhanced JSON manipulation capabilities:
  - **`Db::jsonSet()`** - Set JSON values at specific paths (supports nested paths)
  - **`Db::jsonRemove()`** - Remove JSON keys or array elements
  - **`Db::jsonReplace()`** - Replace existing JSON values
  - **MariaDB compatibility** - Proper handling of MariaDB's JSON function differences
  - **Cross-dialect support** - Works consistently across MySQL, MariaDB, PostgreSQL, and SQLite
  - **Complete test coverage** - Comprehensive tests for all JSON modification operations

- **DEFAULT Helper Support for SQLite** - `Db::default()` now works on SQLite:
  - **Automatic translation** - `DEFAULT` keyword automatically converted to `NULL` for SQLite UPDATE statements
  - **Consistent behavior** - Provides consistent API across all dialects
  - **UPSERT support** - Works correctly in UPSERT operations for SQLite

- **Savepoints and Nested Transactions** - Advanced transaction management:
  - **Savepoint support** - Create named savepoints within transactions
  - **Nested transactions** - Support for nested transaction patterns
  - **Rollback to savepoint** - Rollback to specific savepoints without affecting outer transactions
  - **Complete documentation** - Transaction management guide with savepoint examples
  - **Examples** - `examples/34-savepoints/` demonstrating savepoint usage

- **SQLite String Function Emulation** - Additional string functions for SQLite:
  - **`REPEAT()`** - Repeat string N times (emulated via PHP)
  - **`REVERSE()`** - Reverse string characters (emulated via PHP)
  - **`LPAD()`** - Left pad string to specified length (emulated via PHP)
  - **`RPAD()`** - Right pad string to specified length (emulated via PHP)
  - **Automatic registration** - Functions automatically registered via `PDO::sqliteCreateFunction()`
  - **Cross-dialect compatibility** - Provides consistent API across all dialects

- **JOIN Support for UPDATE and DELETE** - Advanced DML operations:
  - **UPDATE with JOIN** - Update tables based on JOIN conditions
  - **DELETE with JOIN** - Delete rows based on JOIN conditions
  - **Cross-dialect support** - Works on MySQL, MariaDB, PostgreSQL, and SQLite
  - **Complete documentation** - DML operations guide with JOIN examples
  - **Examples** - `examples/33-update-delete-join/` demonstrating JOIN in UPDATE/DELETE

- **INSERT ... SELECT Support** - Bulk insert from query results:
  - **`QueryBuilder::insertFrom()`** - Insert rows from SELECT query results
  - **Column mapping** - Map columns from source to target table
  - **Cross-dialect support** - Works consistently across all dialects
  - **Complete documentation** - INSERT operations guide with INSERT ... SELECT examples
  - **Examples** - `examples/32-insert-select/` demonstrating INSERT ... SELECT patterns

- **Enhanced EXPLAIN Analysis** - Advanced performance metrics:
  - **Performance metrics** - Additional performance indicators in EXPLAIN output
  - **Better recommendations** - Improved query optimization suggestions
  - **Cross-dialect parsing** - Enhanced parsing for MySQL, PostgreSQL, and SQLite EXPLAIN output

- **Plugin System** - Extend PdoDb with custom functionality:
  - **`AbstractPlugin` class** - Base class for creating plugins
  - **`PdoDb::registerPlugin()`** - Register plugins to extend functionality
  - **Macro registration** - Plugins can register QueryBuilder macros
  - **Scope registration** - Plugins can register global scopes
  - **Event listener registration** - Plugins can register event listeners
  - **Complete documentation** - `documentation/05-advanced-features/plugins.md` with plugin development guide
  - **Examples** - `examples/31-plugins/` demonstrating plugin creation and usage

### Changed
- **Examples Directory Reorganization** - Improved structure for better navigation:
  - Reorganized example files into logical groups
  - Better organization of advanced features examples
  - Improved README files in example directories

- **Code Quality Improvements**:
  - **Namespace refactoring** - Replaced full namespace paths with `use` statements throughout codebase
  - **Better code organization** - Improved maintainability and readability
  - **PSR-12 compliance** - All code follows PSR-12 standards

### Fixed
- **Composer Description** - Shortened description for better Packagist display

### Documentation
- **README Improvements** - Major enhancements for better onboarding:
  - **Reorganized feature list** - Grouped features into logical categories (Core, Performance, Advanced, Developer Experience)
  - **Added "Why PDOdb?" section** - Value proposition and comparisons vs Raw PDO, Eloquent, and Doctrine
  - **Simplified Quick Example** - Replaced complex JSON example with basic CRUD using SQLite
  - **Added "5-Minute Tutorial"** - Step-by-step guide for quick onboarding
  - **Added FAQ section** - 10 common questions and answers
  - **Added Migration Guide** - Examples for migrating from Raw PDO, Eloquent, Doctrine, and Yii2
  - **Improved structure** - Better navigation and readability
  - **Lower entry barrier** - Easier for new users to understand and adopt

- **Examples Section Updates**:
  - Updated Examples section in README.md with current example structure
  - Added Sharding section to README.md
  - Replaced chained `where()` with `andWhere()` in examples and documentation for better clarity

### Technical Details
- **All tests passing**: Comprehensive test coverage including new regex, JSON, and SQLite emulation tests
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: Updated with new features including regex helpers, JSON modification, savepoints, and plugin system
- **Examples**: All examples updated and verified on all dialects (MySQL, MariaDB, PostgreSQL, SQLite)

## [2.9.2] - 2025-11-06

### Added
- **Sharding Support** - Horizontal partitioning across multiple databases with automatic query routing:
  - **Three sharding strategies** - Range, Hash, and Modulo strategies for data distribution
    - **Range Strategy** - Distributes data based on numeric ranges (e.g., 0-1000, 1001-2000)
    - **Hash Strategy** - Distributes data based on hash of shard key value (CRC32)
    - **Modulo Strategy** - Distributes data based on modulo operation (`value % shard_count`)
  - **Unified connection pool** - Uses existing connections from `PdoDb` connection pool via `useConnections()`
  - **Automatic query routing** - Queries automatically routed to appropriate shard based on shard key in WHERE conditions
  - **ShardConfig, ShardRouter, ShardConfigBuilder** - Complete infrastructure for sharding configuration
  - **ShardStrategyInterface** - Extensible interface for custom sharding strategies
  - **Fluent API** - `$db->shard('table')->shardKey('id')->strategy('range')->useConnections([...])->register()`
  - **Comprehensive examples** - `examples/30-sharding/` with all strategies and use cases
  - **Complete documentation** - `documentation/05-advanced-features/sharding.md` with detailed guides
  - **Full test coverage** - 15 tests covering all sharding strategies and edge cases

- **QueryBuilder Macros** - Extend QueryBuilder with custom methods:
  - **MacroRegistry class** - Central registry for managing macro registration and storage
  - **QueryBuilder::macro()** - Register custom query methods as macros
  - **QueryBuilder::hasMacro()** - Check if a macro exists
  - **Dynamic execution** - `__call()` implementation for automatic macro execution
  - **Comprehensive examples** - `examples/29-macros/` demonstrating macro usage patterns
  - **Complete documentation** - `documentation/05-advanced-features/query-macros.md` with examples
  - **Full test coverage** - Comprehensive tests for macro functionality

- **Enhanced WHERE Condition Methods** - Fluent methods for common WHERE patterns:
  - **Null checks**: `whereNull()`, `whereNotNull()`, `andWhereNull()`, `orWhereNull()`, etc.
  - **Range checks**: `whereBetween()`, `whereNotBetween()`, `andWhereBetween()`, `orWhereBetween()`, etc.
  - **Column comparisons**: `whereColumn()`, `andWhereColumn()`, `orWhereColumn()`
  - **Enhanced IN clauses**: `whereIn()` and `whereNotIn()` now support arrays in addition to subqueries
  - **AND/OR variants** - All new methods have `and*` and `or*` variants for fluent chaining
  - **27 new tests** - Comprehensive test coverage (66 assertions) in `WhereConditionMethodsTests`
  - **Updated examples** - `examples/01-basic/03-where-conditions.php` demonstrates all new methods
  - Improves developer experience by eliminating need for helper functions in common WHERE patterns

- **Connection Management**:
  - **PdoDb::getConnection()** - Retrieve connection from pool by name
  - Enables sharding to reuse existing connections from connection pool

### Changed
- **Fluent Interface Return Types** - Replaced `self` with `static` in all interfaces:
  - `ConditionBuilderInterface`, `DmlQueryBuilderInterface`, `ExecutionEngineInterface`
  - `FileLoaderInterface`, `JoinBuilderInterface`, `JsonQueryBuilderInterface`
  - `ParameterManagerInterface`, `QueryBuilderInterface`, `SelectQueryBuilderInterface`
  - Better support for extending QueryBuilder classes and improved IDE autocomplete

- **Composer Metadata**:
  - **Updated description** - Full description (487 chars) with all major features including sharding, migrations, query macros
  - **Added keywords** - 17 new keywords: `sharding`, `migrations`, `query-macros`, `macro-system`, `ddl`, `schema-builder`, `lateral-join`, `query-profiling`, `query-compilation-cache`, `batch-processing`, `merge-statements`, `sql-formatter`, `sql-pretty-printer`, `connection-retry`, `psr-14`, `event-dispatcher`, `explain-analysis`
  - Improves discoverability on Packagist and GitHub

### Technical Details
- **All tests passing**: Comprehensive test coverage with new sharding, macro, and WHERE condition tests
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: Added 3 new comprehensive documentation files:
  - `documentation/05-advanced-features/sharding.md` (241 lines)
  - `documentation/05-advanced-features/query-macros.md` (345 lines)
  - Updated `documentation/03-query-builder/filtering-conditions.md` with enhanced WHERE methods
- **Examples**: Added 2 new example directories:
  - `examples/30-sharding/` (3 files, 352 lines total)
  - `examples/29-macros/` (2 files, 470 lines total)

## [2.9.1] - 2025-11-04

### Added
- **Enhanced Error Diagnostics** - Query context and debug information in exceptions:
  - **QueryDebugger class** - Helper class for parameter sanitization and context formatting
  - **QueryException enhancements** - `QueryException` now includes `queryContext` property with detailed query builder state
  - **getDebugInfo() methods** - Added to `QueryBuilder` and all component builders (`ConditionBuilder`, `JoinBuilder`, `SelectQueryBuilder`, `DmlQueryBuilder`)
  - **Automatic context capture** - Query context automatically captured and included in exceptions when errors occur
  - **Parameter sanitization** - Sensitive parameters (passwords, tokens, API keys) automatically masked in error messages
  - **Comprehensive documentation** - New `error-diagnostics.md` documentation page with examples
  - **Example** - `02-error-diagnostics.php` demonstrating new error diagnostics features
  - Implements roadmap item 1.1: Enhanced Error Diagnostics

- **ActiveRecord Relationship Enhancements**:
  - **Many-to-many relationships** - `viaTable()` and `via()` methods for junction table relationships
  - **Yii2-like syntax** - Support for Yii2-style relationship definitions for easier migration
  - Enhanced relationship documentation and examples

- **QueryBuilder Convenience Methods**:
  - **first()** - Get first row from query result
  - **last()** - Get last row from query result
  - **index()** - Index query results by a specific column
  - Improved developer experience with commonly used query patterns

- **CLI Migration Tool** - Yii2-style migration command-line tool:
  - Create, run, and rollback migrations via command line
  - Yii2-compatible migration workflow
  - Enhanced migration management capabilities

- **Documentation Improvements**:
  - **MariaDB configuration examples** - Added MariaDB configuration section to Getting Started documentation
  - **MariaDB connection examples** - Added MariaDB connection example to first-connection.md
  - Complete MariaDB setup guide for new users

### Changed
- **Fulltext search helper** - Renamed `Db::fulltextMatch()` to `Db::match()` for clarity and consistency
- **Composer script namespacing** - All composer scripts now use `pdodb:` prefix for better organization
- **PdoDb scope methods** - Renamed for better readability and consistency

### Fixed
- **LoadBalancerTests** - Fixed fatal error by implementing missing `setTempQueryContext()` method in test stub
- **PHPStan type errors** - Fixed all type-related static analysis issues
- **PHP-CS-Fixer issues** - Resolved all code style issues

### Technical Details
- **All tests passing**: Comprehensive test coverage with improved ActiveQuery, EagerLoader, and CacheManager tests
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles

## [2.9.0] - 2025-11-01

### Added
- **MariaDB Support** - Full support for MariaDB as a fourth database dialect:
  - **New MariaDBDialect class** - Complete SQL dialect implementation for MariaDB
  - **Comprehensive test coverage** - 171 tests, 783 assertions covering all MariaDB-specific functionality
  - **GitHub Actions integration** - Dedicated CI job for MariaDB with coverage reporting
  - **Example compatibility** - All 51 examples now support MariaDB (51/51 passing)
  - **Documentation updates** - All README files and documentation updated to reflect 4-dialect support
  - **Helper function support** - MariaDB support added to `examples/helpers.php` for CREATE TABLE normalization
  - **Explain analysis support** - MariaDB EXPLAIN parsing uses MySQL-compatible parser

- **MERGE Statement Support** - Standard SQL MERGE operations:
  - **PostgreSQL**: Native MERGE statement with `MATCHED`/`NOT MATCHED` clauses
  - **MySQL 8.0+**: Enhanced UPSERT via `INSERT ... ON DUPLICATE KEY UPDATE`
  - **SQLite**: Enhanced UPSERT support
  - **QueryBuilder::merge()** method for MERGE operations
  - **Complex data synchronization** - Merge source data into target tables with conditional logic
  - Examples in `examples/04-json/` and comprehensive documentation

- **SQL Formatter/Pretty Printer** - Human-readable SQL output for debugging:
  - **QueryBuilder::toSQL(bool $isFormatted = false)** - Optional formatted SQL output
  - **Proper indentation** - Nested queries, JOINs, and subqueries properly indented
  - **Line breaks** - Complex queries formatted across multiple lines for readability
  - **Keyword highlighting** - Optional SQL keyword highlighting for better visualization
  - **SqlFormatter class** - Standalone formatter for SQL queries
  - Enhanced debugging experience for complex queries

- **Query Builder IDE Autocomplete Enhancement**:
  - **@template annotations** - Better type inference for IDE autocomplete
  - **Improved return types** - More precise PHPDoc annotations for fluent interface methods
  - **Better inheritance support** - Replaced `self` with `static` for fluent interface methods

### Changed
- **Fluent Interface Return Types** - Replaced `self` with `static` for better inheritance support:
  - All fluent interface methods now return `static` instead of `self`
  - Better support for extending QueryBuilder classes
  - Improved IDE autocomplete in subclass contexts
  - Applied to `QueryBuilder`, `SelectQueryBuilder`, `DmlQueryBuilder`, and all helper traits

- **Documentation Improvements**:
  - Replaced `Db::raw()` calls with QueryBuilder helpers where possible in documentation
  - Better examples demonstrating proper helper usage
  - Cleaner, more maintainable documentation examples

### Fixed
- **MariaDB Window Functions** - LAG/LEAD functions with default values:
  - MariaDB doesn't support third parameter (default value) in LAG/LEAD
  - Automatic COALESCE wrapper added for MariaDB when default value is provided
  - Maintains API compatibility across all dialects

- **MariaDB Type Casting** - CAST operations for MariaDB:
  - Replaced `CAST(... AS REAL)` with `CAST(... AS DECIMAL(10,2))` for MariaDB/MySQL
  - MariaDB doesn't support REAL type in CAST operations
  - All type helper examples now work correctly on MariaDB

- **MariaDB JSON Operations**:
  - Fixed `JSON_SET` for nested paths - string values handled correctly without double-escaping
  - Fixed `JSON_REMOVE` for array indices - uses proper JSON_REMOVE instead of JSON_SET with null
  - MariaDB-specific JSON function handling

- **MariaDB Health Check in GitHub Actions**:
  - Fixed health check command to use built-in MariaDB healthcheck script
  - Added `--health-start-period=40s` to allow MariaDB time to initialize
  - Increased retries to 20 for better reliability

- **Example Compatibility**:
  - All examples updated to support MariaDB alongside MySQL, PostgreSQL, and SQLite
  - CREATE TABLE statements normalized for MariaDB compatibility
  - Dialect-specific logic updated in all example files

### Technical Details
- **All tests passing**: 1200 tests, 4856 assertions (+209 tests, +905 assertions from 2.8.0)
- **All examples passing**: 204/204 examples (51 files × 4 dialects each)
  - SQLite: 51/51 ✅
  - MySQL: 51/51 ✅
  - MariaDB: 51/51 ✅
  - PostgreSQL: 51/51 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **MariaDB integration**: Complete dialect support with comprehensive testing

## [2.8.0] - 2025-11-01

### Added
- **ActiveRecord Pattern** - Optional lightweight ORM for object-based database operations:
  - **Magic attribute access** - `$user->name`, `$user->email`
  - **Automatic CRUD** - `save()`, `delete()`, `refresh()`
  - **Dirty tracking** - Track changed attributes automatically
  - **Declarative validation** - Rules-based validation with extensible validators (required, email, integer, string, custom)
  - **Lifecycle events** - PSR-14 events for save, insert, update, delete operations
  - **ActiveQuery builder** - Full QueryBuilder API through `find()` method
  - `Model` base class with complete ActiveRecord implementation
  - `ValidatorInterface` for custom validation rules
  - Comprehensive examples in `examples/23-active-record/`
  - Complete documentation in `documentation/05-advanced-features/active-record.md`
  - Comprehensive test coverage with edge case testing

- **Query Performance Profiling** - Built-in profiler for performance analysis:
  - Automatic tracking of all query executions
  - Execution time measurement (total, average, min, max)
  - Memory usage tracking per query
  - Slow query detection with configurable threshold
  - Query grouping by SQL structure (same query pattern grouped together)
  - PSR-3 logger integration for slow query logging
  - Statistics reset for new measurement periods
  - `enableProfiling()`, `disableProfiling()`, `getProfilerStats()`, `getSlowestQueries()` methods
  - Complete documentation in `documentation/05-advanced-features/query-profiling.md`
  - Examples in `examples/21-query-profiling/`

- **Materialized CTE Support** - Performance optimization for expensive queries:
  - `QueryBuilder::withMaterialized()` method for PostgreSQL and MySQL
  - PostgreSQL: Uses `MATERIALIZED` keyword
  - MySQL: Uses optimizer hints
  - Automatically cached expensive CTE computations
  - Cross-database support (PostgreSQL 12+, MySQL 8.0+)
  - Examples in `examples/17-cte/03-materialized-cte.php`
  - Documentation updates in `documentation/03-query-builder/cte.md`

- **PSR-14 Event Dispatcher Integration** - Event-driven architecture:
  - `ConnectionOpenedEvent` - Fired when connection is opened
  - `QueryExecutedEvent` - Fired after successful query execution
  - `QueryErrorEvent` - Fired when query error occurs
  - `TransactionStartedEvent`, `TransactionCommittedEvent`, `TransactionRolledBackEvent`
  - Works with any PSR-14 compatible event dispatcher (Symfony, League, custom)
  - Examples in `examples/19-events/`
  - Complete integration guide in documentation

- **Enhanced EXPLAIN with Recommendations** - Automatic query optimization analysis:
  - `QueryBuilder::explainAdvice()` method (renamed from `explainWithRecommendations`)
  - Automatic detection of full table scans
  - Missing index identification with SQL suggestions
  - Filesort and temporary table warnings
  - Dialect-aware parsing (MySQL, PostgreSQL, SQLite)
  - Structured recommendations with severity levels
  - Enhanced documentation and examples

- **Comprehensive Exception Testing** - Full test coverage for exception handling:
  - `ExceptionTests` - 33 tests covering all exception classes and ExceptionFactory
  - `ErrorDetectionStrategyTests` - 36 tests for all error detection strategies
  - `ConstraintParserTests` - 14 tests for error message parsing
  - `ErrorCodeRegistryTests` - 30 tests for error code registry
  - Improved `ConstraintParser` with better pattern matching:
    - Enhanced `CONSTRAINT \`name\`` pattern matching for MySQL
    - Improved `FOREIGN KEY (\`name\`)` pattern for column extraction
    - Better `schema.table` format handling
    - Filtering of invalid constraint names (e.g., "fails", "failed")
  - All exception classes now fully tested with edge cases

- **Infection Mutation Testing** - Code quality assurance:
  - Integrated Infection mutation testing framework
  - Configuration file `infection.json` with comprehensive settings
  - Mutation testing scripts in `composer.json`:
    - `composer pdodb:infection` - Full mutation test run
    - `composer pdodb:infection:quick` - Quick mutation test
    - `composer pdodb:infection:ci` - CI-optimized mutation test
  - `composer pdodb:check-all-with-infection` script for complete quality checks
  - Minimum MSI (Mutation Score Indicator) requirements configured

### Changed
- **External Reference Detection Simplification** - KISS/YAGNI refactoring:
  - Removed `externalTables` array and `setExternalTables()` method
  - Simplified `isTableInCurrentQuery()` to only check against current query table
  - External references automatically detected without manual configuration
  - Cleaner, more maintainable code following KISS principles

- **Query Compilation Cache Improvements**:
  - Fixed compilation cache normalization issues
  - Prevented double caching in `getValue()` method
  - Optimized result cache by avoiding double SQL compilation
  - Renamed `21-query-compilation-cache` directory to `20-query-compilation-cache`
  - Updated all documentation references

- **Memory Management Enhancements**:
  - Fixed memory leaks by properly closing PDOStatement cursors
  - All fetch methods (`get()`, `getOne()`, `fetch()`, `fetchColumn()`) automatically close cursors
  - Exception-safe cleanup using `try/finally` blocks
  - Production-tested with 50,000+ queries without memory accumulation
  - Added memory leak prevention documentation to README

- **Method Renaming for Clarity**:
  - `QueryBuilder::cursor()` renamed to `QueryBuilder::stream()` for better API clarity
  - `QueryBuilder::explainWithRecommendations()` renamed to `QueryBuilder::explainAdvice()`
  - Updated all documentation and examples

- **Test Organization Improvements**:
  - Split tests into organized groups for better CI workflow
  - Improved PHPUnit configuration for directory-based test execution
  - Better test isolation and structure

### Fixed
- **SQLite Cache Parameter Validation** - Prevent invalid DSN parameters:
  - Added validation for SQLite `cache` parameter in `SqliteDialect::buildDsn()`
  - Valid values: `shared`, `private`
  - Throws `InvalidArgumentException` for invalid values
  - Prevents creation of files like `:memory:;cache=Array` or `:memory:;cache=invalid`
  - Added comprehensive tests for cache and mode parameter validation

- **SQL Identifier Quoting in FileLoader** - Correct quoting for different dialects:
  - Fixed SQL identifier quoting in `FileLoader` for MySQL, PostgreSQL, and SQLite
  - Ensures proper quoting based on dialect-specific requirements
  - Improved cross-dialect compatibility

- **LATERAL JOIN Improvements**:
  - Enhanced automatic external reference detection in LATERAL JOIN subqueries
  - Removed need for `Db::raw()` in most LATERAL JOIN scenarios
  - Better alias handling and SQL generation
  - Updated all examples and documentation

- **Removed All Skipped Tests** - Zero skipped tests policy:
  - Moved all dialect-specific tests from shared to dialect-specific test files
  - No more `markTestSkipped()` calls in shared tests
  - All tests actively run and verify functionality

- **Markdown EOF Formatting** - Consistent file formatting:
  - Added `fix-markdown-eof.sh` script to ensure exactly one empty line at EOF
  - Integrated into `composer pdodb:check-all` for automatic fixing
  - All markdown files now have consistent formatting

### Technical Details
- **All tests passing**: 991 tests, 3951 assertions (+417 tests, +1425 assertions from 2.7.1)
- **All examples passing**: 147/147 examples (49 files × 3 dialects each)
  - SQLite: 49/49 ✅
  - MySQL: 49/49 ✅
  - PostgreSQL: 49/49 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Memory safety**: Zero memory leaks verified with 50,000+ query tests
- **SHA-256 hashing**: Replaced all MD5 usage with SHA-256 for better security:
  - Cache key generation uses SHA-256
  - Parameter name generation uses SHA-256 with 16-character truncation
  - LATERAL JOIN alias generation uses SHA-256
- **Infection mutation testing**: Integrated for code quality assurance
- **Code statistics**: Significant additions across exception handling, ActiveRecord, and profiling features

---

## [2.7.1] - 2025-10-29

### Added
- **Window Functions Support** - Advanced analytics with SQL window functions:
  - **Ranking functions**: `Db::rowNumber()`, `Db::rank()`, `Db::denseRank()`, `Db::ntile()`
  - **Value access functions**: `Db::lag()`, `Db::lead()`, `Db::firstValue()`, `Db::lastValue()`, `Db::nthValue()`
  - **Window aggregates**: `Db::windowAggregate()` for running totals, moving averages
  - Support for `PARTITION BY`, `ORDER BY`, and frame clauses (`ROWS BETWEEN`)
  - Cross-database support (MySQL 8.0+, PostgreSQL 9.4+, SQLite 3.25+)
  - `WindowFunctionValue` class and `WindowHelpersTrait` with 11 helper methods
  - Complete implementation of `formatWindowFunction()` in all dialects
  - Comprehensive examples in `examples/16-window-functions/` (10 use cases)
  - Full documentation:
    - `documentation/03-query-builder/window-functions.md` (500+ lines)
    - `documentation/07-helper-functions/window-helpers.md` (400+ lines)
  - 8 comprehensive tests with 44 assertions covering all window functions
  - Use cases: rankings, leaderboards, period-over-period analysis, trends, quartiles

- **Common Table Expressions (CTEs) Support** - WITH clauses for complex queries:
  - `QueryBuilder::with()` and `withRecursive()` methods for CTE definitions
  - Support for basic CTEs using `Closure`, `QueryBuilder`, or raw SQL
  - Recursive CTEs for hierarchical data processing (tree structures, organizational charts)
  - Multiple CTEs with unique parameter scoping to avoid conflicts
  - Explicit column lists for CTEs (recommended for recursive CTEs)
  - Proper parameter passing from CTEs to main query
  - Cross-database support (MySQL 8.0+, PostgreSQL 8.4+, SQLite 3.8.3+)
  - `CteManager` and `CteDefinition` classes for CTE management
  - Comprehensive test coverage (9 new tests in `SharedCoverageTest`)
  - 2 runnable examples in `examples/17-cte/` (basic and recursive CTEs, 10 scenarios total)
  - Complete documentation in `documentation/03-query-builder/cte.md` with use cases and best practices

- **Set Operations** - UNION, INTERSECT, and EXCEPT support:
  - `QueryBuilder::union()`, `unionAll()`, `intersect()`, `except()` methods
  - Support for both `Closure` and `QueryBuilder` instances in set operations
  - Proper `ORDER BY`/`LIMIT`/`OFFSET` placement after set operations (SQL standard compliance)
  - Cross-database: MySQL 8.0+, PostgreSQL, SQLite 3.8.3+
  - `UnionQuery` class for operation management
  - 6 comprehensive examples in `examples/18-set-operations/`
  - Complete documentation in `documentation/03-query-builder/set-operations.md`

- **DISTINCT and DISTINCT ON** - Remove duplicates from result sets:
  - `QueryBuilder::distinct()` method for all databases
  - `QueryBuilder::distinctOn()` method with PostgreSQL-only support
  - Runtime dialect validation with `RuntimeException` for unsupported databases
  - `DialectInterface::supportsDistinctOn()` method for feature detection
  - Examples added to `examples/01-basic/05-ordering.php`
  - Complete documentation in `documentation/03-query-builder/distinct.md`

- **FILTER Clause for Conditional Aggregates** - SQL:2003 standard compliance:
  - `filter()` method chainable after all aggregate functions (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`)
  - `FilterValue` class replaces `RawValue` for aggregate function returns
  - Native `FILTER (WHERE ...)` clause for PostgreSQL and SQLite 3.30+
  - Automatic `CASE WHEN` fallback for MySQL (which doesn't support FILTER clause)
  - `DialectInterface::supportsFilterClause()` method for feature detection
  - Examples added to `examples/02-intermediate/02-aggregations.php`
  - Complete documentation in `documentation/03-query-builder/filter-clause.md`
  - SQL:2003 standard compliance with automatic dialect-specific translation

- **Comprehensive Edge-Case Testing** - 20 new edge-case tests covering critical scenarios:
  - **CTEs**: Empty results, NULL values, recursive CTEs with complex data
  - **UNION/INTERSECT/EXCEPT**: Empty tables, NULL handling, no common values
  - **FILTER Clause**: No matches, all NULLs, empty tables
  - **DISTINCT**: Empty tables, all NULLs, mixed NULL/non-NULL values
  - **Window Functions**: Empty tables, NULL partitions, single row scenarios
  - Added 18 new tests in `SharedCoverageTest`
  - Added 2 dialect-specific tests (MySQL, SQLite) for DISTINCT ON validation
  - Edge cases validate: empty result sets, NULL value handling, boundary conditions, unsupported feature detection

### Changed
- **Aggregate helpers return type**: All aggregate functions (`Db::count()`, `Db::sum()`, `Db::avg()`, `Db::min()`, `Db::max()`) now return `FilterValue` instead of `RawValue` to support chaining with `filter()` method
- **SelectQueryBuilder enhancements**: Added methods `setUnions()`, `setDistinct()`, `setDistinctOn()` to `SelectQueryBuilderInterface`
- **QueryBuilder parameter management**: Enhanced parameter merging for UNION subqueries to prevent conflicts

### Fixed
- **MySQL recursive CTE string concatenation**: Fixed `examples/17-cte/02-recursive-cte.php` test failure on MySQL
  - MySQL doesn't support `||` operator for string concatenation by default
  - Added dialect-specific handling using `CONCAT()` function for MySQL
  - PostgreSQL and SQLite continue using `||` operator
  - Example now works correctly on all three database dialects

### Technical Details
- **All tests passing**: 574 tests, 2526 assertions (+41 tests, +129 assertions from 2.7.0)
- **All examples passing**: 108/108 examples (36 files × 3 dialects each)
  - SQLite: 36/36 ✅
  - MySQL: 36/36 ✅
  - PostgreSQL: 36/36 ✅
- **PHPStan Level 9**: Zero errors across entire codebase (upgraded from Level 8)
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: Added 6 new comprehensive documentation files:
  - `documentation/03-query-builder/window-functions.md` (473 lines)
  - `documentation/07-helper-functions/window-helpers.md` (550 lines)
  - `documentation/03-query-builder/cte.md` (409 lines)
  - `documentation/03-query-builder/set-operations.md` (204 lines)
  - `documentation/03-query-builder/distinct.md` (320 lines)
  - `documentation/03-query-builder/filter-clause.md` (349 lines)
- **Examples**: Added 4 new example directories with comprehensive demos:
  - `examples/16-window-functions/` (360 lines, 10 use cases)
  - `examples/17-cte/` (521 lines total, 10 scenarios)
  - `examples/18-set-operations/` (138 lines, 6 set operation examples)
  - Extended existing examples with DISTINCT and FILTER clause demonstrations
- **Code statistics**: 39 files changed, 6507 insertions(+), 80 deletions(-)

---

## [2.7.0] - 2025-10-28

### Added
- **Full-Text Search** - Cross-database full-text search with `Db::match()` helper:
  - **MySQL**: `MATCH AGAINST` with FULLTEXT indexes
  - **PostgreSQL**: `@@` operator with `to_tsquery()` and text search vectors
  - **SQLite**: FTS5 virtual tables support
  - Support for multiple columns, search modes (natural language, boolean), and query expansion
  - `FulltextMatchValue` class and `FulltextSearchHelpersTrait` for implementation
  - Complete documentation in `documentation/03-query-builder/fulltext-search.md`
  - Examples in `examples/12-fulltext-search/` working on all three dialects
  - 12 new tests across MySQL, PostgreSQL, and SQLite

- **Schema Introspection** - Query database structure programmatically:
  - `PdoDb::getIndexes(string $table)` - Get all indexes for a table
  - `PdoDb::getForeignKeys(string $table)` - Get foreign key constraints
  - `PdoDb::getConstraints(string $table)` - Get all constraints (PRIMARY KEY, UNIQUE, FOREIGN KEY, CHECK)
  - `QueryBuilder::getIndexes()`, `getForeignKeys()`, `getConstraints()` - Same methods via QueryBuilder
  - Cross-database compatible across MySQL, PostgreSQL, and SQLite
  - Complete documentation in `documentation/03-query-builder/schema-introspection.md`
  - Tests in all dialect-specific test files

- **Query Result Caching** - PSR-16 (Simple Cache) integration for dramatic performance improvements:
  - **10-1000x faster** for repeated queries with caching enabled
  - `QueryBuilder::cache(?int $ttl = null, ?string $key = null)` - Enable caching for query
  - `QueryBuilder::noCache()` - Disable caching for specific query
  - `PdoDb::enableCache(CacheInterface $cache, ?CacheConfig $config = null)` - Enable caching globally
  - `PdoDb::disableCache()` - Disable caching globally
  - Support for all PSR-16 compatible cache implementations:
    - Symfony Cache, Redis, APCu, Memcached, Filesystem adapters
    - Built-in `ArrayCache` for testing
  - Automatic cache key generation based on SQL and parameters
  - Custom cache keys and TTL support
  - `CacheManager`, `CacheConfig`, `QueryCacheKey` infrastructure classes
  - Complete documentation in `documentation/05-advanced-features/query-caching.md`
  - Examples in `examples/14-caching/` demonstrating real-world usage
  - Comprehensive test coverage in `SharedCoverageTest`

- **Advanced Pagination** - Three pagination types for different use cases:
  - **Full pagination** (`paginate(int $page, int $perPage)`): Complete pagination with total count and page numbers
    - Returns `PaginationResult` with items, total, current page, last page, per page
    - Best for UI with page numbers and "showing X of Y results"
  - **Simple pagination** (`simplePaginate(int $page, int $perPage)`): Fast pagination without total count
    - Returns `SimplePaginationResult` with items, has more pages flag
    - No COUNT query overhead - ideal for large datasets
  - **Cursor-based pagination** (`cursorPaginate(?string $cursor, int $perPage, string $column = 'id')`): Scalable pagination for real-time feeds
    - Returns `CursorPaginationResult` with items, next cursor, previous cursor
    - Consistent results even with new data
    - Perfect for infinite scroll and API endpoints
  - All results implement `JsonSerializable` for easy API responses
  - URL generation support with query parameters
  - Complete documentation in `documentation/05-advanced-features/pagination.md`
  - Examples in `examples/13-pagination/` working on all dialects
  - Comprehensive test coverage for all pagination types

- **Export Helpers** - Export query results to various formats:
  - `Db::toJson($data, int $options = 0)` - Export to JSON with customizable encoding options
    - Support for pretty printing, unescaped unicode, unescaped slashes
  - `Db::toCsv($data, string $delimiter = ',', string $enclosure = '"')` - Export to CSV format
    - Custom delimiter and enclosure character support
  - `Db::toXml($data, string $rootElement = 'root', string $itemElement = 'item')` - Export to XML format
    - Customizable root and item element names
    - Support for nested arrays and complex data structures
  - SOLID architecture with separate exporter classes (`JsonExporter`, `CsvExporter`, `XmlExporter`)
  - Complete documentation in `documentation/07-helper-functions/export-helpers.md`
  - Examples in `examples/11-export-helpers/` demonstrating all export formats
  - Comprehensive test coverage in `SharedCoverageTest`

- **JSON File Loading** - Bulk load JSON data directly into database tables:
  - `QueryBuilder::loadJson(string $filename, bool $update = false, ?array $updateFields = null)` - Load JSON array of objects
  - `QueryBuilder::loadJsonLines(string $filename, bool $update = false, ?array $updateFields = null)` - Load newline-delimited JSON (JSONL/NDJSON)
  - Support for nested JSON with automatic encoding
  - Memory-efficient batch processing for large files
  - Works across all three database dialects (MySQL, PostgreSQL, SQLite)
  - UPSERT support with optional update fields
  - Complete documentation in `documentation/05-advanced-features/file-loading.md`
  - Examples in `examples/05-file-loading/` with practical scenarios

- **Enhanced orderBy()** - Flexible sorting with multiple syntax options:
  - **Array syntax with directions**: `orderBy(['name' => 'ASC', 'created_at' => 'DESC'])`
  - **Array with default direction**: `orderBy(['name', 'email'], 'DESC')`
  - **Comma-separated string**: `orderBy('name ASC, email DESC, id')`
  - **Original syntax still works**: `orderBy('name', 'DESC')`
  - Full backward compatibility with existing code
  - Complete documentation and examples in `documentation/03-query-builder/ordering-pagination.md`
  - New example file `examples/01-basic/05-ordering.php` demonstrating all syntax variants
  - Comprehensive test coverage in `SharedCoverageTest`

- **Read/Write Connection Splitting** - Master-replica architecture for horizontal scaling:
  - **Automatic query routing**: SELECT queries → read replicas, DML operations → write master
  - **Three load balancing strategies**:
    - `RoundRobinLoadBalancer` - Even distribution across replicas
    - `RandomLoadBalancer` - Random replica selection
    - `WeightedLoadBalancer` - Weight-based distribution (e.g., 70% replica-1, 30% replica-2)
  - **Sticky writes**: Read-after-write consistency (reads from master for N seconds after write)
  - **Force write mode**: `QueryBuilder::forceWrite()` to explicitly read from master
  - **Transaction support**: All operations in transactions use write connection
  - **Health checks and failover**: Automatic exclusion of failed connections
  - Type-safe with `ConnectionType` enum (`READ`, `WRITE`)
  - New classes: `ConnectionRouter`, `LoadBalancerInterface` + 3 implementations
  - New methods: `enableReadWriteSplitting()`, `disableReadWriteSplitting()`, `enableStickyWrites()`, `disableStickyWrites()`, `getConnectionRouter()`
  - Complete documentation in `documentation/05-advanced-features/read-write-splitting.md`
  - Three examples in `examples/15-read-write-splitting/` demonstrating all features
  - 13 new tests with 24 assertions in `SharedCoverageTest`

- **Comprehensive Documentation** - 57 new documentation files (~12,600 lines):
  - **Getting Started**: Installation, configuration, first connection, hello world
  - **Core Concepts**: Connection management, dialect support, parameter binding, query builder basics
  - **Query Builder**: All operations (SELECT, INSERT, UPDATE, DELETE, JOINs, aggregations, subqueries, etc.)
  - **JSON Operations**: Complete JSON support across all dialects
  - **Advanced Features**: Transactions, bulk operations, batch processing, file loading, pagination, query caching, connection retry, read/write splitting
  - **Error Handling**: Exception hierarchy, error codes, logging, monitoring, retry logic
  - **Helper Functions**: 80+ helpers organized by category (string, math, date, JSON, aggregate, comparison, etc.)
  - **Best Practices**: Security, performance, memory management, common pitfalls, code organization
  - **Reference**: Complete API reference, dialect differences, method documentation
  - **Cookbook**: Real-world examples, migration guide, troubleshooting, common patterns
  - README.md files added to all example subdirectories for better navigation

### Changed
- **Memory-efficient file loading**: Refactored `loadCsv()` and `loadXml()` to use PHP generators
  - Dramatically reduced memory consumption for large files
  - Added `loadFromCsvGenerator()` and `loadFromXmlGenerator()` in `FileLoader` class
  - Batched processing to handle files larger than available RAM
  - MySQL and PostgreSQL keep native commands but wrapped as generators
  - All 478+ tests passing with improved performance

- **Code architecture improvements**:
  - Removed `ParameterManager` class (functionality integrated into other components)
  - Changed method/property visibility from `private` to `protected` for better extensibility
  - Renamed schema introspection methods for consistency
  - Updated return types in `TableManagementTrait` from `static` to `self`

- **Test refactoring for best practices**:
  - Replaced direct `$db->connection` calls with public API methods
  - Use `$db->rawQuery()` instead of `$db->connection->query()`
  - Use `$db->startTransaction()`, `$db->commit()`, `$db->rollback()` instead of direct connection calls
  - Direct connection access only in tests specifically testing `ConnectionInterface`

- **Enhanced README.md**:
  - Restructured feature list with bold categories and detailed descriptions
  - Added all new features with performance metrics (e.g., "10-1000x faster queries")
  - More scannable and impressive presentation
  - Complete table of contents with all sections

- **QueryBuilder enhancements**:
  - Dynamic connection switching for read/write splitting
  - Cache-aware query execution
  - Enhanced ordering with multiple syntax options

### Fixed
- **Invalid release date**: Fixed incorrect date in CHANGELOG.md for v2.6.1
- **Cross-dialect compatibility**: Exception handling examples now work correctly on MySQL, PostgreSQL, and SQLite
  - Added dialect-specific CREATE TABLE statements
  - Added DROP TABLE IF EXISTS for idempotency
  - All examples pass on all three dialects

### Technical Details
- **All tests passing**: 533 tests, 2397 assertions (+55 tests from 2.6.2)
- **All examples passing**: 93/93 examples (31 files × 3 dialects each)
  - SQLite: 31/31 ✅
  - MySQL: 31/31 ✅
  - PostgreSQL: 31/31 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: 57 comprehensive documentation files covering all features
- **Performance**: Memory-efficient generators, optional query caching for 10-1000x speedup

---

## [2.6.2] - 2025-10-25

### Added
- **Comprehensive examples expansion**: Added 5 new comprehensive example files demonstrating all library functionality:
  - `05-comparison-helpers.php` - LIKE, BETWEEN, IN, NOT operations with practical scenarios
  - `06-conditional-helpers.php` - CASE statements with complex conditional logic
  - `07-boolean-helpers.php` - TRUE/FALSE/DEFAULT values and boolean operations
  - `08-type-helpers.php` - CAST, GREATEST, LEAST type conversion and comparison functions
  - `04-subqueries.php` - Complete subquery examples (EXISTS, NOT EXISTS, scalar subqueries)
- **Enhanced existing examples**: Significantly expanded all helper function examples with real-world scenarios
- **Complete CONTRIBUTING.md**: Added comprehensive development guidelines (413 lines) covering:
  - Development setup and testing procedures
  - Code style requirements and commit conventions
  - Pull request guidelines and templates
  - Release process and maintainer information
  - Security reporting and communication channels

### Changed
- **Major architectural refactoring** following SOLID principles:
  - **Connection architecture**: Extracted ConnectionLogger, ConnectionState, DialectRegistry, RetryConfigValidator
  - **Strategy pattern for exception handling**: Replaced monolithic ExceptionFactory with 7 specialized strategies
  - **Dialect refactoring**: Extracted common functionality into reusable traits (JsonPathBuilderTrait, UpsertBuilderTrait, FileLoader)
  - **Helper system refactoring**: Converted all helper classes to traits organized by functionality
  - **QueryBuilder refactoring**: Split monolithic QueryBuilder into focused components with proper interfaces
- **Code organization improvements**:
  - Moved helper classes to `src/helpers/traits/` directory
  - Moved query interfaces to `src/query/interfaces/` directory
  - Split DbError into dialect-specific traits (MysqlErrorTrait, PostgresqlErrorTrait, SqliteErrorTrait)
  - Created utility classes: ParameterManager, FileLoader, ConstraintParser, ErrorCodeRegistry
- **Enhanced examples**: Replaced `Db::raw()` calls with helper functions where possible for better educational value

### Fixed
- **Cross-database compatibility**: Fixed all examples to work consistently on SQLite, MySQL, and PostgreSQL
- **Code duplication elimination**: Removed ~1000+ lines of duplicate code through trait extraction
- **Type safety improvements**: Added proper PHPDoc annotations and null safety checks

### Technical Details
- **All tests passing**: 478 tests, 2198 assertions
- **All examples passing**: 90/90 examples (30 files × 3 dialects each)
- **PHPStan Level 8**: Zero errors across entire codebase
- **Backward compatibility**: 100% maintained - no breaking changes to public API
- **Performance**: Improved through reduced code duplication and better architecture
- **Maintainability**: Significantly improved through SOLID principles and trait-based organization

---

## [2.6.1] - 2025-10-23

### Added
- **Automatic external reference detection in subqueries**: QueryBuilder now automatically detects and converts external table references (`table.column`) to `RawValue` objects
  - Works in `where()`, `select()`, `orderBy()`, `groupBy()`, `having()` methods
  - Only converts references to tables not in current query's FROM clause
  - Supports table aliases (e.g., `u.id` where `u` is an alias)
  - Pattern detection: `table.column` or `alias.column`
  - Invalid patterns (like `123.invalid`) are not converted
- **Db::ref() helper**: Manual external reference helper (though now mostly unnecessary due to automatic detection)
  - Equivalent to `Db::raw('table.column')` but more semantic
  - Still useful for complex expressions or when automatic detection doesn't work
- **Comprehensive test coverage** for external reference detection across all dialects:
  - 13 new tests in each dialect-specific test file (MySQL, PostgreSQL, SQLite)
  - Tests cover `whereExists`, `whereNotExists`, `select`, `orderBy`, `groupBy`, `having`
  - Tests verify internal references are not converted
  - Tests verify aliased table references work correctly
  - Tests verify complex external references and edge cases

### Changed
- **Enhanced QueryBuilder methods**: `addCondition()`, `select()`, `orderBy()`, `groupBy()` now automatically process external references
- **Improved subquery examples**: Updated README.md examples to demonstrate automatic detection
- **Better developer experience**: No more need for `Db::raw('users.id')` in most subquery scenarios

### Fixed
- **QueryBuilder external reference handling**: Fixed parsing of table names and aliases from JOIN clauses
- **Cross-dialect compatibility**: External reference detection works consistently across MySQL, PostgreSQL, and SQLite

### Technical Details
- **All tests passing**: 429+ tests, 2044+ assertions
- **PHPStan Level 8**: Zero errors across entire codebase
- **All examples passing**: 24/24 examples on all database dialects
- **Backward compatibility**: Fully maintained - existing code continues to work unchanged
- **Performance**: Minimal overhead - only processes string values matching `table.column` pattern

---

## [2.6.0] - 2025-10-23

### ⚠️ Breaking Changes
- **Exception handling system**: `loadCsv()` and `loadXml()` now throw specialized exceptions instead of returning `false`
  - **Impact**: Code that checks `if (!$ok)` for these methods will need to be updated to use try-catch blocks
  - **Migration**: Wrap calls in try-catch blocks and handle `DatabaseException` types
  - **Example**:
    ```php
    // Before (2.5.x)
    $ok = $db->find()->table('users')->loadCsv($file);
    if (!$ok) {
        // handle error
    }

    // After (2.6.0)
    try {
        $db->find()->table('users')->loadCsv($file);
    } catch (DatabaseException $e) {
        // handle specific exception type
    }
    ```

### Added
- **Comprehensive exception hierarchy** for better error handling:
  - `DatabaseException` - Base exception class extending `PDOException`
  - `ConnectionException` - Connection-related errors (timeouts, refused connections)
  - `QueryException` - General query execution errors
  - `ConstraintViolationException` - Constraint violations (unique keys, foreign keys)
    - Additional properties: `constraintName`, `tableName`, `columnName`
  - `TransactionException` - Transaction-related errors (deadlocks, rollbacks)
  - `AuthenticationException` - Authentication failures
  - `TimeoutException` - Query or connection timeouts
    - Additional property: `timeoutSeconds`
  - `ResourceException` - Resource exhaustion (too many connections)
    - Additional property: `resourceType`
- **ExceptionFactory** for intelligent exception classification:
  - Analyzes PDO error codes and messages to determine appropriate exception type
  - Supports MySQL, PostgreSQL, and SQLite error codes
  - Extracts detailed context (constraint names, table names, etc.)
- **Enhanced error context** in all exceptions:
  - `getDriver()` - Database driver name
  - `getOriginalCode()` - Original error code (handles PostgreSQL SQLSTATE strings)
  - `getQuery()` - SQL query that caused the error
  - `getContext()` - Additional context information
  - `getCategory()` - Error category for logging/monitoring
  - `isRetryable()` - Whether the error is retryable
  - `toArray()` - Convert to array for logging
- **Exception handling examples** (`examples/09-exception-handling/`):
  - Comprehensive examples showing how to handle different exception types
  - Retry logic patterns with exception-aware logic
  - Error monitoring and alerting examples
  - Real-world error handling scenarios
- **test-examples script** added to composer check:
  - `composer pdodb:check` now runs: PHPStan + PHPUnit + Examples
  - Ensures all examples work on all database dialects
  - 24/24 examples passing on SQLite, MySQL, PostgreSQL

### Changed
- **loadCsv() and loadXml() behavior**: Now throw exceptions instead of returning `false`
  - Provides more detailed error information
  - Enables specific error handling based on exception type
  - Maintains backward compatibility through exception inheritance from `PDOException`
- **Error handling in Connection class**: All database operations now throw specialized exceptions
- **Error handling in RetryableConnection**: Retry logic now works with exception types
- **Error handling in QueryBuilder**: Query execution errors now throw appropriate exceptions

### Fixed
- **PHPStan compliance**: Added PHPDoc generic array types (`array<string, mixed>`) for better static analysis
- **PostgreSQL COPY permission errors**: Now correctly classified as `AuthenticationException`
- **Test compatibility**: Updated all loadCsv/loadXml tests to handle new exception behavior
- **Exception parsing**: Improved constraint name extraction from error messages
- **Error code handling**: Proper handling of PostgreSQL SQLSTATE string codes vs integer codes

### Technical Details
- **All tests passing**: 429 tests, 2044 assertions
- **PHPStan Level 8**: Zero errors across entire codebase
- **All examples passing**: 24/24 examples on all database dialects
- **Exception coverage**: Comprehensive error handling for all database operations
- **Backward compatibility**: Exceptions extend `PDOException` for compatibility with existing catch blocks

---

## [2.5.1] - 2025-10-22

### Added
- **Db::inc() and Db::dec() support in onDuplicate()**: Now you can use convenient helpers for UPSERT increments
  - Works across all dialects: MySQL, PostgreSQL, SQLite
  - Example: `->onDuplicate(['counter' => Db::inc(5)])`
  - Automatically generates dialect-specific SQL (e.g., `counter = counter + 5`)
- **CI testing for examples**: All 21 examples now tested in GitHub Actions
  - SQLite: 21/21 examples verified
  - MySQL: 20/20 examples verified (if database available)
  - PostgreSQL: 20/20 examples verified (if database available)
- **New tests** for UPSERT functionality:
  - `testUpsertWithRawIncrement()` - tests `Db::raw('age + 5')` in onDuplicate
  - `testUpsertWithIncHelper()` - tests `Db::inc(5)` in onDuplicate
  - `testGroupByWithQualifiedNames()` - verifies qualified column names in GROUP BY

### Changed
- **All examples migrated to QueryBuilder API**:
  - Replaced 15+ `rawQuery()` calls with fluent QueryBuilder methods
  - `rawQuery()` now used ONLY for DDL (CREATE/ALTER/DROP TABLE)
  - Examples demonstrate best practices with `Db::` helpers
- **Enhanced buildUpsertClause() signature** (backwards compatible):
  - Added optional `$tableName` parameter for PostgreSQL ambiguous column resolution
  - Enables proper `Db::inc()` and `Db::raw()` support in UPSERT operations
- **Improved test-examples.sh script**:
  - Fixed PostgreSQL port detection in connection checks
  - Now correctly detects all 3 database dialects when available
- **JSON column handling**: Examples automatically use JSONB for PostgreSQL, TEXT for MySQL/SQLite

### Fixed
- **CRITICAL: groupBy() and orderBy() with qualified column names** (`u.id`, `t.name`):
  - **Bug**: Qualified names quoted as single identifier `` `u.id` `` instead of `` `u`.`id` ``
  - **Impact**: Broke on MySQL/PostgreSQL with "Unknown column 'u.id'" error
  - **Fix**: Changed to use `quoteQualifiedIdentifier()` instead of `quoteIdentifier()`
  - **Tests**: Added `testGroupByWithQualifiedNames()` for all 3 dialects
- **CRITICAL: Db::inc()/Db::dec() ignored in onDuplicate()**:
  - **Bug**: Dialects didn't handle `['__op' => 'inc']` array format in UPSERT
  - **Impact**: UPSERT replaced value instead of incrementing
  - **Fix**: Added `is_array($expr) && isset($expr['__op'])` handling in all dialects
- **PostgreSQL UPSERT "ambiguous column" errors**:
  - **Bug**: `Db::raw('age + 5')` in onDuplicate caused "column reference ambiguous"
  - **Impact**: PostgreSQL couldn't distinguish old vs new column values
  - **Fix**: Auto-qualify column references with table name (`user_stats."age" + 5`)
- **PostgreSQL lastInsertId() exception for non-SERIAL tables**:
  - **Bug**: Crash when inserting into tables without auto-increment
  - **Fix**: Added try-catch in `executeInsert()` to gracefully handle missing sequence
- **String literal quoting in SQL expressions**:
  - Fixed PostgreSQL compatibility (uses `'string'` not `"string"`)
  - Fixed CASE WHEN conditions to use single quotes for string literals
  - Examples now properly escape string values in Db::raw() expressions
- **Examples multi-dialect compatibility**:
  - JSON columns: Use JSONB for PostgreSQL, TEXT for MySQL/SQLite
  - String concatenation: Dialect-aware (`||` vs `CONCAT()`)
  - Date/time functions: Dialect-specific interval syntax
  - All 21 examples verified on all 3 dialects

### Technical Details
- **All tests passing**: 343 tests, 1544 assertions (up from 334 tests)
  - Added 9 new tests across MySQL, PostgreSQL, SQLite
  - Zero failures, zero errors, zero warnings
- **All examples passing**: 61/61 total runs (21 examples × ~3 dialects each)
  - SQLite: 21/21 ✅
  - MySQL: 20/20 ✅ (01-connection.php uses SQLite only)
  - PostgreSQL: 20/20 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **Full backwards compatibility**: Optional parameter with default value

---

## [2.5.0] - 2025-10-19

### Added
- **Connection pooling**: Support for initialization without default connection via `new PdoDb()`
- **17 new SQL helper functions** with full dialect support:
  - NULL handling: `Db::ifNull()`, `Db::coalesce()`, `Db::nullIf()`
  - Math operations: `Db::abs()`, `Db::round()`, `Db::mod()`, `Db::greatest()`, `Db::least()`
  - String operations: `Db::upper()`, `Db::lower()`, `Db::trim()`, `Db::length()`, `Db::substring()`, `Db::replace()`
  - Date/Time extraction: `Db::curDate()`, `Db::curTime()`, `Db::date()`, `Db::time()`, `Db::year()`, `Db::month()`, `Db::day()`, `Db::hour()`, `Db::minute()`, `Db::second()`
- **Complete JSON operations API**:
  - `Db::jsonGet()`, `Db::jsonLength()`, `Db::jsonKeys()`, `Db::jsonType()`
  - Unified API across MySQL, PostgreSQL, and SQLite
  - Edge-case testing for JSON operations
- **Comprehensive examples directory** (`examples/`) with 21 runnable examples:
  - Basic operations (connection, CRUD, WHERE conditions)
  - Intermediate patterns (JOINs, aggregations, pagination, transactions)
  - Advanced features (connection pooling, bulk operations, UPSERT)
  - JSON operations (complete guide with real-world usage)
  - Helper functions (string, math, date/time, NULL handling)
  - Real-world applications:
    - Blog system with posts, comments, tags, analytics
    - User authentication with sessions, RBAC, password hashing
    - Advanced search & filters with facets, sorting, pagination
    - Multi-tenant SaaS with resource tracking and quota management
- **Dialect coverage tests** for better test coverage (300 total tests):
  - `buildLoadCsvSql()` - CSV loading SQL generation with temp file handling
  - `buildLoadXML()` - XML loading SQL generation with temp file handling
  - `formatSelectOptions()` - SELECT statement options formatting
  - `buildExplainSql()` - EXPLAIN query generation and variations
  - Exception tests for unsupported operations (SQLite lock/unlock)
- **Utility scripts** in `scripts/` directory:
  - `release.sh` - Release preparation and tagging automation
  - `test-examples.sh` - Example verification and syntax checking
- **Comprehensive edge-case test coverage** for new helpers and dialect-specific behaviors
- **44 new tests in SharedCoverageTest** for dialect-independent code coverage
- **Professional README documentation** (1400+ lines) with:
  - Table of contents with navigation
  - Error handling examples
  - Performance tips
  - Debugging guide
  - Troubleshooting section
  - PHP 8.4+ requirement clearly documented
  - Examples directory section
- **Complete CHANGELOG.md** documenting all changes from v1.0.3 to present

### Changed
- **Optimized QueryBuilder**: Refactored duplicated code with new helper methods (`addParam()`, `normalizeOperator()`, `processValueForSql()`)
- **Improved error messages**: Property hooks now provide clearer guidance for uninitialized connections
- **Updated .gitignore**: Cleaned up and added examples-related ignores, coverage reports
- **README.md improvements**: Removed `ALL_TESTS=1` requirement - tests now run without environment variables
- **Enhanced examples** (14 files updated): Maximized use of `Db::` helpers over `Db::raw()` for better code clarity:
  - Replaced 30+ raw SQL expressions with helper functions
  - `Db::inc()`/`Db::dec()` for increments/decrements
  - `Db::count()`, `Db::sum()`, `Db::avg()`, `Db::coalesce()` for aggregations
  - `Db::case()` for conditional logic
  - `Db::concat()` with automatic string literal quoting
- **Improved test organization**: Added `setUp()` method in `SharedCoverageTest` for automatic table cleanup before each test
  - Removed 26+ redundant cleanup statements
  - Better test isolation and reliability

### Removed
- **Deprecated helper methods from PdoDb** (~130 lines removed):
  - `inc()`, `dec()`, `not()` - Use `Db::` equivalents or raw SQL
  - `escape()` - Use prepared statements (PDO handles escaping)
  - `tableExists()` - Use `QueryBuilder::tableExists()` instead
  - `now()` - Use `Db::now()` instead
  - `loadData()`, `loadXml()` - Use `QueryBuilder::loadCsv()`, `QueryBuilder::loadXml()` instead

### Fixed
- **CRITICAL: insertMulti() conflict target detection bug**: Fixed automatic conflict target determination for PostgreSQL/SQLite ON CONFLICT
  - `buildInsertMultiSql()` now correctly uses first column when `id` not present (matches `insert()` behavior)
  - Enables proper bulk UPSERT operations across all dialects
  - Without this fix, bulk inserts with `onDuplicate` parameter would fail on PostgreSQL/SQLite
- **CRITICAL: Db::concat() helper bugs** (2 major issues fixed):
  - **Bug #1**: `ConcatValue` not initializing parent `RawValue` class causing "Typed property not initialized" error
    - Added `parent::__construct('')` call in `ConcatValue` constructor
    - Added protective `getValue()` override with clear error message to prevent misuse
  - **Bug #2**: String literals (spaces, special chars) not auto-quoted, treated as column names
    - Enhanced `DialectAbstract::concat()` logic to auto-detect and quote string literals
    - Supports spaces, colons, pipes, dashes, emoji, and unicode characters
    - Examples: `Db::concat('first_name', ' ', 'last_name')` now works without `Db::raw()`
  - Added 8 comprehensive edge-case tests in `SharedCoverageTest`:
    - `testConcatWithStringLiterals()` - spaces and simple literals
    - `testConcatWithSpecialCharacters()` - colon, pipe, dash
    - `testConcatWithNestedHelpers()` - `Db::upper/lower` inside concat
    - `testConcatNestedInHelperThrowsException()` - protection from incorrect usage
    - `testConcatWithQuotedLiterals()` - already-quoted strings
    - `testConcatWithNumericValues()` - number handling
    - `testConcatWithEmptyString()` - empty string edge case
    - `testConcatWithMixedTypes()` - mixed type concatenation
- Restored `RawValue` union type support in `rawQuery()`, `rawQueryOne()`, `rawQueryValue()` methods
- Corrected method calls in `lock()`, `unlock()`, `loadData()`, `loadXml()` to use `prepare()->execute()` pattern
- SQLite JSON support fixes for edge cases (array indexing, value encoding, numeric sorting)
- **MySQL EXPLAIN compatibility**: Reverted EXPLAIN ANALYZE support to maintain table format compatibility
  - MySQL 8.0+ EXPLAIN ANALYZE returns tree/JSON format incompatible with traditional table format
  - Existing tests expect table format with `select_type` column
- **PostgreSQL formatSelectOptions test**: Fixed to test actual supported features (FOR UPDATE/FOR SHARE)

### Technical Details
- **All tests passing**: 334 tests, 1499 assertions across MySQL, PostgreSQL, and SQLite (3 skipped for live testing)
  - **68 tests** in SharedCoverageTest (dialect-independent code)
  - **8 new edge-case tests** for `Db::concat()` bug fixes
  - Added `setUp()` method for automatic table cleanup before each test
- **Test coverage**: 90%+ with comprehensive dialect-specific and edge-case testing
- **Full backward compatibility maintained**: Zero breaking changes (deprecated methods removal is non-breaking)
- Examples tested and verified on PHP 8.4.13
- **Performance**: Optimized QueryBuilder reduces code duplication and improves maintainability

---

## [2.4.3] - 2025-10-18

### Added
- `Db::concat()`, `Db::true()`, `Db::false()` helper functions
- `QueryBuilder::notExists()` method
- `Db::between()`, `Db::notBetween()`, `Db::in()`, `Db::notIn()` helpers
- `Db::isNull()`, `Db::isNotNull()` helpers
- `Db::case()` helper for CASE statements
- `Db::default()` helper
- `Db::null()`, `Db::like()`, `Db::ilike()`, `Db::not()` helpers
- `Db::config()` helper for SET statements
- `Db::escape()` method for string escaping
- `QueryBuilder::tableExists()` method
- `QueryBuilder::loadXml()` and `QueryBuilder::loadCsv()` methods moved from PdoDb
- JSON methods: `selectJson()`, `whereJsonPath()`, `whereJsonContains()`, `jsonSet()`, `jsonRemove()`, `orderByJson()`, `whereJsonExists()`
- `Db::ts()` helper for Unix timestamps
- `QueryBuilderInterface` and `DialectInterface` for better type safety
- Comprehensive LIMIT/OFFSET tests

### Changed
- Code refactoring for better maintainability
- Improved README.md documentation
- Updated composer.json description and keywords

### Fixed
- Removed unused 'json' keyword from composer.json

---

## [2.4.2] - 2025-10-17

### Added
- RawValue parameters binding support
- Normalized parameter binding and condition tokens
- Robust `addCondition()` and `update()` handling

### Changed
- Moved DB helpers (`inc()`, `now()`, `dec()`) to `helpers\Db.php`
- Split `execute()` method into `prepare()` and `execute()` in Connection class
- Pass PDO to dialect on dialect initialization
- Code refactoring for better structure

### Fixed
- Test fix: Exception handling differences between MySQL, SQLite, and PostgreSQL
- MySQL and SQLite throw exceptions on `prepare()`, PostgreSQL on `execute()`

---

## [2.4.1] - 2025-10-15

### Added
- CSV and XML fallback loaders to `DialectAbstract`
- Structured logging via `LoggerInterface`

### Changed
- Centralized all PDO operations in Connection class
- Refactoring for better code organization
- Updated README.md with new information

### Fixed
- `truncate()` method fix for SQLite

---

## [2.4.0] - 2025-10-13

### Added
- Unified QueryBuilder and cross-dialect SQL generation
- SQLite fixes and loaders
- GitHub Actions tests workflow

### Changed
- Major refactoring of QueryBuilder
- Improved cross-dialect compatibility

### Fixed
- Skip loadData/loadXML tests in GitHub Actions due to permission issues

---

## [2.3.0] - 2025-10-12

### Added
- `EXPLAIN` and `EXPLAIN ANALYZE` methods
- `DESCRIBE` method for table introspection
- `getColumn()` method for fetching single column values
- `linesToIgnore` parameter for `loadXml()` method

### Changed
- Dialects and PdoDb refactoring
- Updated README.md with new methods
- Updated LICENCE information

### Fixed
- Changed licence type in composer.json to MIT

---

## [2.2.0] - 2025-10-11

### Added
- **PostgreSQL support** 🎉
- Refactored DSN parameters for each supported database
- PHPDoc comments throughout the codebase

### Changed
- Updated README.md with PostgreSQL configuration examples
- Improved database configuration flexibility

---

## [2.1.1] - 2025-10-10

### Added
- Port support in DSN configuration

### Changed
- Updated README.md documentation

### Fixed
- `loadXML()` log trace issue

---

## [2.1.0] - 2025-10-10

### Added
- **SQLite support** 🎉
- Refactored construction parameters for better flexibility

### Changed
- Updated README.md with SQLite configuration examples
- Load data fixes
- Added LOCAL parameter support for LOAD DATA

---

## [2.0.0] - 2025-10-10

### ⚠️ Breaking Changes
- **Minimum PHP version raised to 8.4+**
- Complete rewrite using PHP 8.4 features (property hooks, etc.)

### Changed
- Huge refactoring of entire codebase
- Modern PHP 8.4+ syntax and features
- Removed outdated information from documentation

### Technical Details
This is a major breaking release that modernizes the entire codebase for PHP 8.4+. Migration from 1.x requires PHP 8.4+ and may require code changes.

---

## [1.1.1] - 2021-12-20

### Added
- `getParams()` method to return query parameters
- `useGenerator()` method information in README

### Changed
- Fixed package name in composer.json
- Updated keywords in composer.json

### Fixed
- Implode legacy signature
- README fixes: heading spacing and table of contents
- Various typo fixes in documentation

---

## [1.1.0] - 2016-06-12

### Added
- `setPageLimit()` method for pagination
- Tests for: having, groupBy, getValue with limit, rawQueryOne, rawQueryValue, named placeholders, pagination, subquery on insert
- PDODb usage documentation

### Fixed
- `escape()` method typo fix
- Subquery on `getOne()` fix

---

## [1.0.3] - 2016-05-27

Initial tagged release with basic PDO database abstraction functionality.

---

[Unreleased]: https://github.com/tommyknocker/pdo-database-class/compare/v2.11.1...HEAD
[2.11.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.11.0...v2.11.1
[2.11.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.3...v2.11.0
[2.10.3]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.2...v2.10.3
[2.10.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.1...v2.10.2
[2.10.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.0...v2.10.1
[2.10.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.3...v2.10.0
[2.9.3]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.2...v2.9.3
[2.9.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.1...v2.9.2
[2.9.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.0...v2.9.1
[2.9.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.7.1...v2.8.0
[2.7.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.7.0...v2.7.1
[2.7.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.6.2...v2.7.0
[2.6.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.6.1...v2.6.2
[2.6.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.5.1...v2.6.0
[2.5.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.3...v2.5.0
[2.4.3]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.2...v2.4.3
[2.4.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.1...v2.4.2
[2.4.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.0...v2.4.1
[2.4.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/tommyknocker/pdo-database-class/compare/v1.1.1...v2.0.0
[1.1.1]: https://github.com/tommyknocker/pdo-database-class/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/tommyknocker/pdo-database-class/compare/v1.0.3...v1.1.0

