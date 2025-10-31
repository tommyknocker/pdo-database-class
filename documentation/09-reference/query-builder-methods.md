# Query Builder Methods

Complete reference for QueryBuilder methods.

## SELECT Methods

### `from(string $table): self`

Set FROM clause.

```php
$db->find()->from('users');
```

### `select(string|array $columns): self`

Set SELECT columns.

```php
$db->find()->select(['id', 'name', 'email']);
$db->find()->select('id, name, email');
```

### `distinct(bool $value = true): self`

Add DISTINCT.

```php
$db->find()->distinct();
```

### `where(string $column, $value, ?string $operator = '='): self`

Add WHERE condition.

```php
$db->find()->where('age', 18, '>');
$db->find()->where('email', 'user@example.com');
```

### `andWhere(string $column, $value, ?string $operator = '='): self`

Add AND WHERE condition.

```php
$db->find()
    ->where('age', 18, '>')
    ->andWhere('active', 1);
```

### `orWhere(string $column, $value, ?string $operator = '='): self`

Add OR WHERE condition.

```php
$db->find()
    ->where('status', 'active')
    ->orWhere('status', 'pending');
```

### `whereNull(string $column): self`

WHERE column IS NULL.

```php
$db->find()->whereNull('deleted_at');
```

### `whereNotNull(string $column): self`

WHERE column IS NOT NULL.

```php
$db->find()->whereNotNull('email');
```

### `whereBetween(string $column, $min, $max): self`

WHERE column BETWEEN values.

```php
$db->find()->whereBetween('age', 18, 65);
```

### `whereIn(string $column, array $values): self`

WHERE column IN values.

```php
$db->find()->whereIn('status', ['active', 'pending']);
```

### `orderBy(string $column, ?string $direction = 'ASC'): self`

Add ORDER BY.

```php
$db->find()->orderBy('created_at', 'DESC');
```

### `groupBy(string|array $columns): self`

Add GROUP BY.

```php
$db->find()->groupBy('status');
```

### `having(string $column, $value, ?string $operator = '='): self`

Add HAVING clause.

```php
$db->find()->having('COUNT(*)', 10, '>');
```

### `limit(int $limit): self`

Set LIMIT.

```php
$db->find()->limit(10);
```

### `offset(int $offset): self`

Set OFFSET.

```php
$db->find()->offset(20);
```

## JOIN Methods

### `join(string $table, string $first, string $second, ?string $operator = '='): self`

INNER JOIN.

```php
$db->find()
    ->from('users')
    ->join('profiles', 'users.id', 'profiles.user_id');
```

### `leftJoin(string $table, string $first, string $second, ?string $operator = '='): self`

LEFT JOIN.

```php
$db->find()
    ->from('users')
    ->leftJoin('profiles', 'users.id', 'profiles.user_id');
```

### `rightJoin(string $table, string $first, string $second, ?string $operator = '='): self`

RIGHT JOIN.

```php
$db->find()
    ->from('users')
    ->rightJoin('profiles', 'users.id', 'profiles.user_id');
```

## Execution Methods

### `get(): array`

Execute and return all rows.

```php
$users = $db->find()->from('users')->get();
```

### `getOne(): ?array`

Execute and return first row.

```php
$user = $db->find()->from('users')->where('id', 1)->getOne();
```

### `getValue(?string $column = null)`

Execute and return first column value.

```php
$count = $db->find()->from('users')->select('COUNT(*)')->getValue();
```

### `exists(): bool`

Check if result exists.

```php
$exists = $db->find()->from('users')->where('id', 1)->exists();
```

## Next Steps

- [API Reference](api-reference.md) - Complete API
- [PdoDb Methods](pdo-db-methods.md) - PdoDb methods
- [Helper Functions Reference](helper-functions-reference.md) - Helpers
