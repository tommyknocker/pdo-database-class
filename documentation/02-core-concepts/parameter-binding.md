# Parameter Binding

Learn how PDOdb uses prepared statements to prevent SQL injection.

## Automatic Parameter Binding

PDOdb automatically uses prepared statements for all queries, protecting you from SQL injection:

```php
$userId = 5;
$email = 'alice@example.com';

// Automatically uses prepared statements
$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->andWhere('email', $email)
    ->getOne();
```

## Generated SQL

This code generates the following SQL with proper parameter binding:

```sql
SELECT * FROM users WHERE id = :id AND email = :email
```

Parameters:
- `:id` => `5`
- `:email` => `'alice@example.com'`
- 
## Query Inspection

View the generated SQL and parameters:

```php
$query = $db->find()
    ->from('users')
    ->where('id', 5)
    ->andWhere('email', 'alice@example.com');

$result = $query->toSQL();
echo "SQL: {$result['sql']}\n";
print_r($result['params']);

// Output:
// SQL: SELECT * FROM users WHERE id = :id AND email = :email
// Array (
//     [:id] => 5
//     [:email] => alice@example.com
// )
```

### Formatted SQL Output

For better readability during debugging, you can format SQL with proper indentation and line breaks:

```php
$query = $db->find()
    ->from('users')
    ->join('orders', 'users.id = orders.user_id')
    ->where('users.status', 'active')
    ->where('orders.total', 100, '>')
    ->orderBy('users.name');

// Unformatted SQL (default)
$result = $query->toSQL(false);
echo $result['sql'];
// Output: SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id WHERE users.status = :param_1 AND orders.total > :param_2 ORDER BY users.name ASC

// Formatted SQL for debugging
$result = $query->toSQL(true);
echo $result['sql'];
// Output:
// SELECT *
// FROM users
//     INNER JOIN orders ON users.id = orders.user_id
// WHERE users.status = :param_1
//     AND orders.total > :param_2
// ORDER BY users.name ASC
```

The formatted output provides:
- Proper indentation for nested clauses
- Keywords on separate lines (SELECT, FROM, WHERE, JOIN, etc.)
- Clean formatting for AND/OR conditions
- Better readability for complex queries

## Safe Parameter Values

All values are automatically parameterized:

### Strings

```php
$name = "O'Reilly";
$users = $db->find()
    ->from('users')
    ->where('name', $name)
    ->get();

// SQL: SELECT * FROM users WHERE name = :name
// Safe: SQL injection attempt is neutralized
```

### Numbers

```php
$age = 30;
$users = $db->find()
    ->from('users')
    ->where('age', $age)
    ->get();

// SQL: SELECT * FROM users WHERE age = :age
```

### Arrays (IN clause)

```php
$ids = [1, 2, 3, 4, 5];
$users = $db->find()
    ->from('users')
    ->where(Db::in('id', $ids))
    ->get();

// SQL: SELECT * FROM users WHERE id IN (:in_0, :in_1, :in_2, :in_3, :in_4)
// Parameters:
//   :in_0 => 1
//   :in_1 => 2
//   :in_2 => 3
//   :in_3 => 4
//   :in_4 => 5
```

### NULL Values

```php
$users = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->get();

// SQL: SELECT * FROM users WHERE deleted_at IS NULL
```

### Booleans

```php
$active = true;
$users = $db->find()
    ->from('users')
    ->where('active', $active)
    ->get();

// SQL: SELECT * FROM users WHERE active = :active
// Parameters: :active => 1 (true) or 0 (false)
```

## Raw Queries with Binding

For raw SQL, always use parameter binding:

```php
// Safe: Parameterized query
$users = $db->rawQuery(
    'SELECT * FROM users WHERE id = :id AND email = :email',
    [
        'id' => 5,
        'email' => 'alice@example.com'
    ]
);

// ❌ NEVER do this - vulnerable to SQL injection
$unsafe = "O'Reilly";
$users = $db->rawQuery(
    "SELECT * FROM users WHERE name = '$unsafe'"  // DANGER!
);
```

## Multiple Parameters

### INSERT

```php
$data = [
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 30
];

$id = $db->find()->table('users')->insert($data);

// SQL: INSERT INTO users (name, email, age) VALUES (:name, :email, :age)
// Parameters:
//   :name => 'Alice'
//   :email => 'alice@example.com'
//   :age => 30
```

### UPDATE

```php
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'name' => 'Alice Updated',
        'age' => 31
    ]);

// SQL: UPDATE users SET name = :name, age = :age WHERE id = :id
// Parameters:
//   :name => 'Alice Updated'
//   :age => 31
//   :id => 1
```

## Complex Queries

### Multiple WHERE Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 25, '>=')
    ->andWhere('email', '%@example.com', 'LIKE')
    ->get();

// SQL: SELECT * FROM users WHERE status = :status AND age >= :age AND email LIKE :email
// Parameters:
//   :status => 'active'
//   :age => 25
//   :email => '%@example.com'
```

### Subqueries

```php
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', 1000, '>');
    })
    ->get();

// The subquery is also fully parameterized
```

## Array Parameters

When working with arrays, each value is bound separately:

```php
$ids = [1, 2, 3, 4, 5];
$users = $db->find()
    ->from('users')
    ->where(Db::in('id', $ids))
    ->get();

// SQL: SELECT * FROM users WHERE id IN (:in_0, :in_1, :in_2, :in_3, :in_4)
// Parameters:
//   :in_0 => 1
//   :in_1 => 2
//   :in_2 => 3
//   :in_3 => 4
//   :in_4 => 5
```

### Multiple Rows

```php
$rows = [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
    ['name' => 'Carol', 'email' => 'carol@example.com']
];

$db->find()->table('users')->insertMulti($rows);

// Each row's values are bound separately:
// SQL: INSERT INTO users (name, email) VALUES
//      (:name_0, :email_0),
//      (:name_1, :email_1),
//      (:name_2, :email_2)
// Parameters:
//   :name_0 => 'Alice'
//   :email_0 => 'alice@example.com'
//   :name_1 => 'Bob'
//   :email_1 => 'bob@example.com'
//   ...
```

## Special Values

### Helper Functions

Helper functions generate safe SQL expressions:

```php
use tommyknocker\pdodb\helpers\Db;

// NOW() function
$db->find()->table('users')->update([
    'updated_at' => Db::now()
]);

// INCREMENT
$db->find()->table('users')->update([
    'age' => Db::inc()
]);

// JSON operations
$db->find()->table('users')->insert([
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30])
]);
```

### Raw SQL Expressions

For complex cases, use `Db::raw()`:

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->update([
    'counter' => Db::raw('counter + :inc', ['inc' => 1])
]);

// But prefer helper functions when available
$db->find()->table('users')->update([
    'counter' => Db::inc()  // Better!
]);
```

## PDO Mode

PDOdb uses prepared statements with these PDO attributes:

```php
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,  // Real prepared statements
];

$db = new PdoDb('mysql', $config, $pdoOptions);
```

### Key Settings

- **`ATTR_EMULATE_PREPARES => false`**: Uses real prepared statements on the database server
- **`ATTR_ERRMODE => EXCEPTION`**: Throws exceptions on errors
- **`ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`**: Returns associative arrays

## Security Best Practices

### 1. ✅ Always Use Parameter Binding

```php
// Safe
$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->getOne();
```

### 2. ❌ Never Concatenate User Input

```php
// NEVER do this!
$unsafe = $_GET['name'];
$db->rawQuery("SELECT * FROM users WHERE name = '$unsafe'");

// ✅ Do this instead
$safe = $_GET['name'];
$db->rawQuery("SELECT * FROM users WHERE name = :name", ['name' => $safe]);
```

### 3. ✅ Use Helper Functions

```php
// Good
$db->find()->table('users')->where(Db::like('email', '%@example.com'));

// Bad
$db->find()->table('users')->where('email', '%@example.com', 'LIKE');
```

### 4. ✅ Validate Input Before Binding

```php
$userId = (int) $_GET['id'];  // Cast to int

$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->getOne();
```

## Testing Parameter Binding

View the generated SQL for debugging:

```php
$query = $db->find()
    ->from('users')
    ->where('id', 5)
    ->andWhere('email', 'test@example.com');

$result = $query->toSQL();
var_dump($result);

// Output:
// array(2) {
//   ["sql"]=>
//   string(56) "SELECT * FROM users WHERE id = :id AND email = :email"
//   ["params"]=>
//   array(2) {
//     [":id"]=>
//     int(5)
//     [":email"]=>
//     string(16) "test@example.com"
//   }
// }
```

## Next Steps

- [Query Builder Basics](query-builder-basics.md) - Fluent API overview
- [Dialect Support](dialect-support.md) - Database differences
- [SELECT Operations](../03-query-builder/select-operations.md) - Detailed SELECT documentation
