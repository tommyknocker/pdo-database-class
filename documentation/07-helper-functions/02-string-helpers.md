# String Helper Functions

Use these helper functions for string operations across all databases.

## Db::concat() - Concatenate Strings

Concatenate multiple strings:

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select(['full_name' => Db::concat('first_name', ' ', 'last_name')])
    ->get();

// SQL: CONCAT(first_name, ' ', last_name)
```

### With Columns and Literals

```php
$users = $db->find()
    ->from('users')
    ->select([
        'email_with_name' => Db::concat('name', ' <', 'email', '>')
    ])
    ->get();
```

## Db::upper() - Uppercase

Convert string to uppercase:

```php
$users = $db->find()
    ->from('users')
    ->select(['name_upper' => Db::upper('name')])
    ->get();

// SQL: UPPER(name)
```

## Db::lower() - Lowercase

Convert string to lowercase:

```php
$users = $db->find()
    ->from('users')
    ->select(['email_lower' => Db::lower('email')])
    ->get();

// SQL: LOWER(email)
```

## Db::trim() - Trim Whitespace

Remove whitespace from both sides:

```php
$users = $db->find()
    ->from('users')
    ->select(['name_trimmed' => Db::trim('name')])
    ->get();

// SQL: TRIM(name)
```

## Db::length() - String Length

Get string length:

```php
$users = $db->find()
    ->from('users')
    ->select(['name_length' => Db::length('name')])
    ->get();

// SQL: LENGTH(name)
```

## Db::substring() - Extract Substring

Extract substring from string:

```php
$users = $db->find()
    ->from('users')
    ->select(['first_char' => Db::substring('name', 1, 1)])
    ->get();

// SQL: SUBSTRING(name, 1, 1)
```

### Extract Domain from Email

```php
$users = $db->find()
    ->from('users')
    ->select([
        'email',
        'domain' => Db::substring('email', Db::raw('LOCATE("@", email) + 1'))
    ])
    ->get();
```

## Db::replace() - Replace Text

Replace text in string:

```php
$users = $db->find()
    ->from('users')
    ->select(['clean_name' => Db::replace('name', 'Mr. ', '')])
    ->get();

// SQL: REPLACE(name, 'Mr. ', '')
```

## Common Patterns

### Extract Initials

```php
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'initials' => Db::concat(
            Db::substring('first_name', 1, 1),
            Db::substring('last_name', 1, 1)
        )
    ])
    ->get();
```

### Format Address

```php
$addresses = $db->find()
    ->from('addresses')
    ->select([
        'full_address' => Db::concat(
            'street',
            ', ',
            'city',
            ', ',
            'state',
            ' ',
            'zip'
        )
    ])
    ->get();
```

### Case-Insensitive Search

```php
$users = $db->find()
    ->from('users')
    ->where(Db::lower('email'), Db::lower('test@example.com'), '=')
    ->get();

// SQL: WHERE LOWER(email) = LOWER('test@example.com')
```

## Db::repeat() - Repeat String

Repeat a string multiple times:

```php
$users = $db->find()
    ->from('users')
    ->select(['banner' => Db::repeat('-', 5)])
    ->get();

// MySQL/PostgreSQL: REPEAT('-', 5)
// SQLite: Emulated using recursive CTE
```

## Db::reverse() - Reverse String

Reverse a string:

```php
$users = $db->find()
    ->from('users')
    ->select(['name_reversed' => Db::reverse('name')])
    ->get();

// MySQL/PostgreSQL: REVERSE(name)
// SQLite: Emulated using recursive CTE
```

## Db::padLeft() / Db::padRight() - Pad Strings

Pad strings to a specific length:

```php
$users = $db->find()
    ->from('users')
    ->select([
        'left_padded' => Db::padLeft('name', 10, ' '),
        'right_padded' => Db::padRight('name', 10, '.')
    ])
    ->get();

// MySQL/PostgreSQL: LPAD(name, 10, ' ') / RPAD(name, 10, '.')
// SQLite: Emulated using recursive CTE and SUBSTR
```

## Dialect Differences

### String Concatenation

**MySQL:**
```sql
CONCAT(first_name, ' ', last_name)
```

**PostgreSQL/SQLite:**
```sql
first_name || ' ' || last_name
```

PDOdb handles this automatically.

### REPEAT, REVERSE, LPAD, RPAD

**MySQL/PostgreSQL/MariaDB:**
These functions are natively supported.

**SQLite:**
These functions are emulated using recursive CTEs:
- `REPEAT`: Uses recursive CTE to concatenate the string multiple times
- `REVERSE`: Uses recursive CTE to reverse characters one by one
- `LPAD/RPAD`: Uses recursive CTE to generate padding, then concatenates and truncates

All functions work identically across all dialects.

## Best Practices

### 1. Use CONCAT for Readability

```php
// ✅ Clear - use concat helper
Db::concat('first_name', ' ', 'last_name')

// ❌ Less clear - avoid raw SQL for simple concatenation
Db::raw("first_name || ' ' || last_name")
```

### 2. Index Search Columns

```php
// For case-insensitive searches, create lowercase index
$db->connection->query('CREATE INDEX idx_users_email_lower ON users(LOWER(email))');

// Then use in queries
$users = $db->find()
    ->from('users')
    ->where(Db::lower('email'), Db::lower($email))
    ->get();
```

## Db::regexpMatch() - Pattern Matching

Check if a string matches a regular expression pattern:

```php
// Find valid email addresses
$users = $db->find()
    ->from('users')
    ->where(Db::regexpMatch('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$'))
    ->get();

// MySQL/MariaDB: (email REGEXP '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$')
// PostgreSQL: (email ~ '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$')
// SQLite: (email REGEXP '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$')
//         REGEXP functions are automatically registered by PDOdb (can be disabled via enable_regexp config)
```

### Using in WHERE Clause

```php
// Find users with phone numbers containing dashes
$users = $db->find()
    ->from('users')
    ->where(Db::regexpMatch('phone', '-'))
    ->get();

// With negation
$users = $db->find()
    ->from('users')
    ->where(Db::not(Db::regexpMatch('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$')))
    ->get();
```

## Db::regexpReplace() - Pattern-Based Replacement

Replace all occurrences of a pattern with a replacement string:

```php
// Replace dashes with spaces in phone numbers
$users = $db->find()
    ->from('users')
    ->select([
        'phone',
        'formatted' => Db::regexpReplace('phone', '-', ' ')
    ])
    ->get();

// MySQL/MariaDB: REGEXP_REPLACE(phone, '-', ' ')
// PostgreSQL: regexp_replace(phone, '-', ' ', 'g')
// SQLite: regexp_replace(phone, '-', ' ')
//         REGEXP functions are automatically registered by PDOdb
```

### Multiple Replacements

```php
// Remove all non-alphanumeric characters
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'clean' => Db::regexpReplace('name', '[^a-zA-Z0-9]', '')
    ])
    ->get();
```

## Db::regexpExtract() - Extract Matched Substrings

Extract matched substring or capture group from a string:

```php
// Extract domain from email
$users = $db->find()
    ->from('users')
    ->select([
        'email',
        'domain' => Db::regexpExtract('email', '@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})', 1)
    ])
    ->get();

// MySQL/MariaDB: REGEXP_SUBSTR(email, '@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})', 1, 1, NULL, 1)
// PostgreSQL: (regexp_match(email, '@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})'))[2]
// SQLite: regexp_extract(email, '@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})', 1)
//         REGEXP functions are automatically registered by PDOdb
```

### Extract Full Match

```php
// Extract username from email (full match)
$users = $db->find()
    ->from('users')
    ->select([
        'email',
        'username' => Db::regexpExtract('email', '^([a-zA-Z0-9._%+-]+)@', 1)
    ])
    ->get();
```

### Extract Multiple Groups

```php
// Extract area code and number from phone
$users = $db->find()
    ->from('users')
    ->select([
        'phone',
        'area_code' => Db::regexpExtract('phone', '\\+1-([0-9]{3})', 1),
        'number' => Db::regexpExtract('phone', '\\+1-[0-9]{3}-([0-9-]+)', 1)
    ])
    ->get();
```

## Dialect Differences

### REGEXP Support

**MySQL/MariaDB:**
- `REGEXP` operator for matching
- `REGEXP_REPLACE()` function for replacement
- `REGEXP_SUBSTR()` function for extraction (MySQL 8.0+, MariaDB 10.0.5+)

**PostgreSQL:**
- `~` operator for case-sensitive matching (`~*` for case-insensitive)
- `regexp_replace()` function for replacement
- `regexp_match()` function returns array, use array indexing for groups

**SQLite:**
- `REGEXP` operator requires extension to be loaded
- `regexp_replace()` and `regexp_extract()` functions require REGEXP extension
- If extension is not available, operations will fail at runtime

### Pattern Syntax

All dialects use POSIX extended regular expressions, but there are some differences:

- **MySQL/MariaDB**: Uses MySQL regex syntax (similar to POSIX)
- **PostgreSQL**: Uses POSIX extended regex syntax
- **SQLite**: Uses POSIX extended regex syntax (REGEXP functions are automatically registered by PDOdb)

### Group Indexing

- **MySQL/MariaDB**: Group index starts at 0 (0 = full match, 1+ = capture groups)
- **PostgreSQL**: Array indexing starts at 1 (1 = first group)
- **SQLite**: Group index starts at 0 (0 = full match, 1+ = capture groups)

PDOdb handles these differences automatically.

## Best Practices

### 1. Validate Email Addresses

```php
// ✅ Use regexpMatch for validation
$validEmails = $db->find()
    ->from('users')
    ->where(Db::regexpMatch('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$'))
    ->get();
```

### 2. Extract Structured Data

```php
// Extract components from structured strings
$users = $db->find()
    ->from('users')
    ->select([
        'phone',
        'country_code' => Db::regexpExtract('phone', '^\\+([0-9]+)', 1),
        'area_code' => Db::regexpExtract('phone', '\\+[0-9]+-([0-9]+)', 1)
    ])
    ->get();
```

### 3. Normalize Data

```php
// Normalize phone numbers by removing formatting
$users = $db->find()
    ->from('users')
    ->select([
        'phone',
        'normalized' => Db::regexpReplace(
            Db::regexpReplace('phone', '[^0-9]', ''),
            '^1',
            ''
        )
    ])
    ->get();
```

### 4. SQLite REGEXP Functions

PDOdb automatically registers REGEXP functions (`REGEXP`, `regexp_replace`, `regexp_extract`) for SQLite connections using PHP's `preg_*` functions. This happens automatically when creating a SQLite connection.

To disable automatic REGEXP registration:

```php
$db = new PdoDb('sqlite', [
    'path' => '/path/to/database.sqlite',
    'enable_regexp' => false  // Disable automatic REGEXP registration
]);
```

**Note**: If you disable automatic registration, you must manually register REGEXP functions or load a REGEXP extension. The automatic registration uses PHP's `preg_match`, `preg_replace`, and `preg_match` functions, so no external extensions are required.

## Next Steps

- [Numeric Helpers](03-numeric-helpers.md) - Math operations
- [Comparison Helpers](06-comparison-helpers.md) - WHERE conditions
- [Core Helpers](01-core-helpers.md) - Essential helpers
