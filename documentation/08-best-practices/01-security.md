# Security Best Practices

Learn how to use PDOdb securely to prevent SQL injection and other security issues.

## SQL Injection Prevention

### ✅ Always Use Prepared Statements

PDOdb automatically uses prepared statements for all queries:

```php
// ✅ Safe: Automatic parameter binding
$users = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->get();

// ❌ NEVER: Raw string concatenation
$users = $db->rawQuery("SELECT * FROM users WHERE id = $userId");
```

### Parameter Binding is Automatic

```php
// All values are automatically parameterized
$users = $db->find()
    ->from('users')
    ->where('email', $email)
    ->andWhere('age', $age)
    ->andWhere(Db::like('name', $name))
    ->get();

// SQL: SELECT * FROM users WHERE email = :email AND age = :age AND name LIKE :name
// Parameters: :email => $email, :age => $age, :name => $name
```

## Using Raw Queries Safely

### Always Use Parameter Binding

```php
// ✅ Safe: Parameter binding
$users = $db->rawQuery(
    'SELECT * FROM users WHERE id = :id AND email = :email',
    ['id' => $userId, 'email' => $email]
);

// ❌ NEVER: String interpolation
$users = $db->rawQuery(
    "SELECT * FROM users WHERE id = $userId AND email = '$email'"
);
```

### Placeholder Types

```php
// Named placeholders (recommended)
$users = $db->rawQuery(
    'SELECT * FROM users WHERE id = :id AND email = :email',
    ['id' => 123, 'email' => 'alice@example.com']
);

// Positional placeholders
$users = $db->rawQuery(
    'SELECT * FROM users WHERE id = ? AND email = ?',
    [123, 'alice@example.com']
);
```

## Input Validation

### Validate Before Database

```php
// ✅ Validate input
$userId = filter_var($_GET['id'], FILTER_VALIDATE_INT);

if (!$userId) {
    throw new InvalidArgumentException("Invalid user ID");
}

$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->getOne();
```

### Sanitize Strings

```php
// ✅ Sanitize
$email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException("Invalid email");
}

$db->find()->table('users')->insert([
    'email' => $email
]);
```

## Type Casting

### Cast Inputs

```php
// ✅ Cast to expected type
$userId = (int) $_GET['id'];
$age = (int) $_POST['age'];
$active = (bool) $_POST['active'];

$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'age' => $age,
        'active' => $active
    ]);
```

## SQL Injection in Different Contexts

### WHERE Clauses

```php
// ✅ Safe
$users = $db->find()
    ->from('users')
    ->where('name', $name)
    ->get();

// ❌ NEVER
$users = $db->rawQuery("SELECT * FROM users WHERE name = '$name'");
```

### LIKE Queries

```php
// ✅ Safe
$users = $db->find()
    ->from('users')
    ->where(Db::like('email', "%@example.com"))
    ->get();

// ❌ NEVER
$users = $db->rawQuery("SELECT * FROM users WHERE email LIKE '%@example.com'");
```

### IN Clauses

```php
// ✅ Safe
$ids = [1, 2, 3, 4, 5];
$users = $db->find()
    ->from('users')
    ->where(Db::in('id', $ids))
    ->get();

// ❌ NEVER
$idsStr = implode(',', $ids);
$users = $db->rawQuery("SELECT * FROM users WHERE id IN ($idsStr)");
```

### Table Names

```php
// ✅ Safe: Table names are properly quoted
$users = $db->find()->from('users')->get();

// ⚠️ Dangerous: Only use with user input after validation
$table = 'users';  // Must validate!
$users = $db->find()->from($table)->get();
```

## Column Names

### Validating Column Names

```php
// ✅ Validate column names against whitelist
$allowedColumns = ['id', 'name', 'email', 'age'];

$column = $_GET['sort'];
if (!in_array($column, $allowedColumns, true)) {
    $column = 'id';  // Default
}

$users = $db->find()
    ->from('users')
    ->orderBy($column, 'ASC')
    ->get();
```

## Database Configuration Security

### Connection Credentials

```php
// ✅ Use environment variables
$db = new PdoDb('mysql', [
    'host' => getenv('DB_HOST'),
    'username' => getenv('DB_USERNAME'),
    'password' => getenv('DB_PASSWORD'),
    'dbname' => getenv('DB_NAME')
]);

// ❌ NEVER hardcode
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'root',
    'password' => 'password123',
    'dbname' => 'mydb'
]);
```

### SSL/TLS

```php
// ✅ Use SSL for production
$db = new PdoDb('mysql', [
    'host' => 'mysql.example.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'sslca' => '/path/to/ca.pem',
    'sslcert' => '/path/to/client-cert.pem',
    'sslkey' => '/path/to/client-key.pem'
]);
```

## File System Security

### CSV Loading

```php
// ✅ Validate file path
$filePath = $_POST['file_path'];
$allowedDir = '/var/import/';

// Resolve to absolute path
$realPath = realpath($allowedDir . $filePath);

// Check if within allowed directory
if (strpos($realPath, realpath($allowedDir)) !== 0) {
    throw new SecurityException("File path not allowed");
}

$db->find()->table('import_data')->loadCsv($realPath);
```

## Privilege Separation

### Use Database User Privileges

Create separate database users with limited privileges:

```sql
-- User for reads only
CREATE USER 'app_read'@'localhost';
GRANT SELECT ON mydb.* TO 'app_read'@'localhost';

-- User for writes
CREATE USER 'app_write'@'localhost';
GRANT INSERT, UPDATE, DELETE ON mydb.* TO 'app_write'@'localhost';

-- User for admin
CREATE USER 'app_admin'@'localhost';
GRANT ALL ON mydb.* TO 'app_admin'@'localhost';
```

### Use Appropriate User

```php
// Read-only connection
$readDb = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'app_read',
    'password' => 'read_password',
    'dbname' => 'mydb'
]);

// Write connection
$writeDb = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'app_write',
    'password' => 'write_password',
    'dbname' => 'mydb'
]);
```

## Error Message Security

### Don't Expose Sensitive Information

```php
// ❌ Bad: Exposes database details
try {
    $users = $db->find()->from('users')->get();
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}";  // Exposes DB structure
}

// ✅ Good: Generic error for user, log details
try {
    $users = $db->find()->from('users')->get();
} catch (\Exception $e) {
    error_log("Database error: {$e->getMessage()}");
    echo "An error occurred. Please try again later.";
}
```

## Preventing Common Vulnerabilities

### 1. Mass Assignment

```php
// ✅ Whitelist allowed fields
$allowedFields = ['name', 'email', 'age'];
$data = array_intersect_key($_POST, array_flip($allowedFields));

$db->find()->table('users')->insert($data);
```

### 2. Race Conditions

```php
// ✅ Use transactions and locking
$db->startTransaction();

try {
    $user = $db->find()
        ->from('users')
        ->where('email', $email)
        ->getOne();
    
    if (!$user) {
        $db->find()->table('users')->insert(['email' => $email]);
    }
    
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    throw $e;
}
```

### 3. Sensitive Data

```php
// ✅ Hash passwords
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);

$db->find()->table('users')->insert([
    'username' => $_POST['username'],
    'password' => $password  // Hashed, not plain text
]);
```

## PDO Error Mode

### Use Exception Mode

```php
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

$db = new PdoDb('mysql', $config, $pdoOptions);
```

## Regular Security Updates

### 1. Keep Dependencies Updated

```bash
composer update tommyknocker/pdo-database-class
```

### 2. Monitor Security Advisories

Check the project's security page regularly.

### 3. Review Query Logs

```php
// Enable query logging in development
$logger = new Logger('database');
$logger->pushHandler(new StreamHandler('queries.log'));

$db = new PdoDb('mysql', $config, [], $logger);
```

## Summary Checklist

- [ ] Always use parameter binding (automatic in PDOdb)
- [ ] Validate and sanitize all input
- [ ] Use prepared statements for all queries
- [ ] Store credentials in environment variables
- [ ] Use SSL/TLS for production connections
- [ ] Implement privilege separation
- [ ] Don't expose sensitive error messages
- [ ] Hash sensitive data (passwords)
- [ ] Use transactions for atomic operations
- [ ] Keep dependencies updated

## Next Steps

- [Performance](performance.md) - Optimize query performance
- [Code Organization](code-organization.md) - Structure your code
- [Common Pitfalls](common-pitfalls.md) - Mistakes to avoid
