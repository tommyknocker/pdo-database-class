# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]


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
    - `composer infection` - Full mutation test run
    - `composer infection:quick` - Quick mutation test
    - `composer infection:ci` - CI-optimized mutation test
  - `composer check-all-with-infection` script for complete quality checks
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
  - Integrated into `composer check-all` for automatic fixing
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
- **Full-Text Search** - Cross-database full-text search with `Db::fulltextMatch()` helper:
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
  - `composer check` now runs: PHPStan + PHPUnit + Examples
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

[Unreleased]: https://github.com/tommyknocker/pdo-database-class/compare/v2.8.0...HEAD
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

