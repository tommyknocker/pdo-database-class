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

## Best Practices

### 1. Use CONCAT for Readability

```php
// ✅ Clear
Db::concat('first_name', ' ', 'last_name')

// ❌ Less clear
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

## Next Steps

- [Numeric Helpers](numeric-helpers.md) - Math operations
- [Comparison Helpers](comparison-helpers.md) - WHERE conditions
- [Core Helpers](core-helpers.md) - Essential helpers
