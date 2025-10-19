# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- **Comprehensive examples directory** (`examples/`) with 18 runnable examples:
  - Basic operations (connection, CRUD, WHERE conditions)
  - Intermediate patterns (JOINs, aggregations, pagination, transactions)
  - Advanced features (connection pooling, bulk operations, UPSERT)
  - JSON operations (complete guide with real-world usage)
  - Helper functions (string, math, date/time, NULL handling)
  - Real-world applications (blog system with posts, comments, tags)
- **Comprehensive edge-case test coverage** for new helpers and dialect-specific behaviors
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
- **Updated .gitignore**: Cleaned up and added examples-related ignores

### Fixed
- Restored `RawValue` union type support in `rawQuery()`, `rawQueryOne()`, `rawQueryValue()` methods
- Corrected method calls in `lock()`, `unlock()`, `loadData()`, `loadXml()` to use `prepare()->execute()` pattern
- SQLite JSON support fixes for edge cases (array indexing, value encoding, numeric sorting)

### Technical Details
- All tests passing: 246 tests, 1296 assertions across MySQL, PostgreSQL, and SQLite
- Full backward compatibility maintained
- Zero breaking changes
- Examples tested and verified on PHP 8.4.13

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

[Unreleased]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.5...HEAD
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

