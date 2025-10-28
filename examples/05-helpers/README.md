# Helper Functions Examples

SQL helper functions for common operations across all database dialects.

## Overview

PDOdb provides 50+ SQL helper functions through the `Db` class. These helpers abstract dialect-specific SQL syntax, providing a unified API that works across MySQL, PostgreSQL, and SQLite.

## Examples

### 01-string-helpers.php
String manipulation functions.

**Topics covered:**
- `upper()`, `lower()` - Case conversion
- `trim()`, `ltrim()`, `rtrim()` - Whitespace removal
- `concat()` - String concatenation
- `substring()` - Extract substring
- `length()` - String length
- `replace()` - Replace substring
- `reverse()` - Reverse string
- `position()` - Find substring position

### 02-math-helpers.php
Mathematical operations and functions.

**Topics covered:**
- `abs()` - Absolute value
- `ceil()`, `floor()`, `round()` - Rounding
- `power()` - Exponentiation
- `sqrt()` - Square root
- `mod()` - Modulo operation
- `sign()` - Sign of number
- `random()` - Random numbers

### 03-date-helpers.php
Date and time functions.

**Topics covered:**
- `now()`, `curDate()`, `curTime()` - Current date/time
- `year()`, `month()`, `day()` - Extract date parts
- `hour()`, `minute()`, `second()` - Extract time parts
- `dateAdd()`, `dateSub()` - Date arithmetic
- `dateDiff()` - Difference between dates
- `dateFormat()` - Format dates
- `timestamp()` - Unix timestamps

### 04-null-helpers.php
NULL handling and coalescing.

**Topics covered:**
- `isNull()`, `isNotNull()` - NULL checks
- `coalesce()` - First non-NULL value
- `ifNull()` - NULL replacement
- `nullIf()` - Return NULL if equal
- NULL-safe comparisons

### 05-comparison-helpers.php
Comparison operators and conditions.

**Topics covered:**
- `like()`, `ilike()` - Pattern matching
- `notLike()` - Negated pattern matching
- `between()`, `notBetween()` - Range checks
- `in()`, `notIn()` - Value lists
- `not()` - Negation wrapper

### 06-conditional-helpers.php
Conditional logic and CASE statements.

**Topics covered:**
- `case()` - CASE WHEN expressions
- `if()` - Simple IF conditions
- `greatest()`, `least()` - Value comparison
- Complex multi-condition logic
- Nested CASE statements
- Conditional aggregations

### 07-boolean-helpers.php
Boolean values and operations.

**Topics covered:**
- `true()`, `false()` - Boolean literals
- `default()` - DEFAULT keyword
- Boolean expressions
- Dialect-specific boolean handling
- Boolean type conversions

### 08-type-helpers.php
Type casting and conversion functions.

**Topics covered:**
- `cast()` - Type casting
- `castToInt()`, `castToString()`, `castToDate()` - Specific casts
- `greatest()`, `least()` - Type-aware comparisons
- Type conversion across dialects
- Handling type differences

## Using Helper Functions

All helpers are accessed through the `Db` class:

```php
use tommyknocker\pdodb\helpers\Db;

// In SELECT
$results = $db->find()
    ->from('users')
    ->select([
        'name',
        'email_upper' => Db::upper('email'),
        'age_years' => Db::floor(Db::dateDiff('now', 'birthdate') / 365)
    ])
    ->get();

// In WHERE
$results = $db->find()
    ->from('products')
    ->where(Db::like('name', '%laptop%'))
    ->andWhere(Db::between('price', 100, 1000))
    ->get();

// In ORDER BY
$results = $db->find()
    ->from('users')
    ->orderBy(Db::lower('name'), 'ASC')
    ->get();
```

## Running Examples

### SQLite (default)
```bash
php 01-string-helpers.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-string-helpers.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-string-helpers.php
```

## Cross-Dialect Compatibility

All helper functions produce dialect-specific SQL:

| Function | MySQL | PostgreSQL | SQLite |
|----------|-------|------------|--------|
| `concat()` | `CONCAT()` | `\|\|` operator | `\|\|` operator |
| `ilike()` | `LOWER() LIKE` | `ILIKE` | `LOWER() LIKE` |
| `now()` | `NOW()` | `NOW()` | `datetime('now')` |
| `true()` | `TRUE` | `TRUE` | `1` |

## Complete Reference

See [Helper Functions Reference](../../documentation/07-helper-functions/) for complete documentation of all 50+ helper functions.

## Next Steps

- [JSON Helpers](../04-json/) - JSON-specific operations
- [Export Helpers](../11-export-helpers/) - Data export functions
- [Real-World Examples](../06-real-world/) - Helper functions in context

