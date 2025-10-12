# PDO Database Class

Lightweight PDO wrapper for PHP with query builder, cross‑database dialect support (MySQL, PostgreSQL, SQLite, MSSQL), 
and safe prepared statements.

This library provides a simple, consistent API for working with multiple SQL databases using PDO. It focuses on security, 
portability, and developer productivity, while staying lightweight and dependency‑free.
Inspired by https://github.com/ThingEngineer/PHP-MySQLi-Database-Class

---

## Features
* ✅ Cross‑database support: MySQL, PostgreSQL, SQLite
* ✅ Safe prepared statements everywhere
* ✅ Query builder for SELECT, INSERT, UPDATE, DELETE
* ✅ Dialect abstraction: database‑specific logic lives in dialect classes
* ✅ Bulk operations: loadData, bulkInsert, update with limit
* ✅ Transaction helpers (begin, commit, rollback)
* ✅ Debugging support: lastQuery, getParams, query logging

---

## Dialects

Database‑specific behavior is encapsulated in dialect classes:

* MysqlDialect
* PgsqlDialect
* SqliteDialect

This keeps PdoDb clean and makes it easy to extend for new databases.

---

## Testing

Run the test suite with:

```bash
vendor/bin/phpunit
```

Integration tests are included for multiple databases.

---

## Licence

MIT Licence. See [LICENCE](LICENSE) for details

## Usage

- **[Initialization](#initialization)**
- **[Insert Query](#insert-query)**
- **[Update Query](#update-query)**
- **[Select Query](#select-query)**
- **[Delete Query](#delete-query)**
  **[Insert Data](#insert-data)**  
  **[Insert XML](#insert-xml)**
- **[Pagination](#pagination)**
- **[Running raw SQL queries](#running-raw-sql-queries)**
- **[Query Keywords](#query-keywords)**
- **[Where Conditions](#where--having-methods)**
- **[Order Conditions](#ordering-method)**
- **[Group Conditions](#grouping-method)**
- **[Properties Sharing](#properties-sharing)**
- **[Joining Tables](#join-method)**
- **[Subqueries](#subqueries)**
- **[EXISTS / NOT EXISTS condition](#exists--not-exists-condition)**
- **[Has method](#has-method)**
- **[Helper Methods](#helper-methods)**
- **[Transaction Helpers](#transaction-helpers)**
- **[Error Helpers](#error-helpers)**
- **[Explain](#explain)**


## Installation

```bash
composer require tommyknocker/pdo-database-class
```

## Initialization

```php
$db = new PdoDb('mysql', [
    'pdo'         => null,                 // Optional. Existing PDO object. If specified, all other parameters (except prefix) are ignored.
    'host'        => '127.0.0.1',          // Required. MySQL host (e.g. 'localhost' or IP address).
    'username'    => 'testuser',           // Required. MySQL username.
    'password'    => 'testpass',           // Required. MySQL password.
    'dbname'      => 'testdb',             // Required. Database name.
    'port'        => 3306,                 // Optional. MySQL port (default is 3306).
    'prefix'      => 'my_',                // Optional. Table prefix (e.g. 'wp_').
    'charset'     => 'utf8mb4',            // Optional. Connection charset (recommended: 'utf8mb4').
    'unix_socket' => '/var/run/mysqld/mysqld.sock', // Optional. Path to Unix socket if used.
    'sslca'       => '/path/ca.pem',       // Optional. Path to SSL CA certificate.
    'sslcert'     => '/path/client-cert.pem', // Optional. Path to SSL client certificate.
    'sslkey'      => '/path/client-key.pem',  // Optional. Path to SSL client key.
    'compress'    => true                  // Optional. Enable protocol compression.
]);
```

```php
$db = new PdoDb('pgsql', [
    'pdo'              => null,            // Optional. Existing PDO object. If specified, all other parameters (except prefix) are ignored.
    'host'             => '127.0.0.1',     // Required. PostgreSQL host.
    'username'         => 'testuser',      // Required. PostgreSQL username.
    'password'         => 'testpass',      // Required. PostgreSQL password.
    'dbname'           => 'testdb',        // Required. Database name.
    'port'             => 5432,            // Optional. PostgreSQL port (default is 5432).
    'prefix'           => 'pg_',           // Optional. Table prefix.
    'options'          => '--client_encoding=UTF8', // Optional. Extra options (e.g. client encoding).
    'sslmode'          => 'require',       // Optional. SSL mode: disable, allow, prefer, require, verify-ca, verify-full.
    'sslkey'           => '/path/client.key',   // Optional. Path to SSL private key.
    'sslcert'          => '/path/client.crt',   // Optional. Path to SSL client certificate.
    'sslrootcert'      => '/path/ca.crt',       // Optional. Path to SSL root certificate.
    'application_name' => 'MyApp',         // Optional. Application name(visible in pg_stat_activity).
    'connect_timeout'  => 5,               // Optional. Connection timeout in seconds.
    'hostaddr'         => '192.168.1.10',  // Optional. Direct IP address (bypasses DNS).
    'service'          => 'myservice',     // Optional. Service name from pg_service.conf.
    'target_session_attrs' => 'read-write' // Optional. For clusters: any, read-write.
]);
```

```php
$db = new PdoDb('sqlite', [
    'pdo'   => null,                       // Optional. Existing PDO object. If specified, all other parameters (except prefix) are ignored.
    'path'  => '/path/to/database.sqlite', // Required. Path to SQLite database file.
                                           // Use ':memory:' for an in-memory database.
    'prefix'=> 'sq_',                      // Optional. Table prefix.
    'mode'  => 'rwc',                      // Optional. Open mode: ro (read-only), rw (read/write), rwc (create if not exists), memory.
    'cache' => 'shared'                    // Optional. Cache mode: shared or private.
]);
```

It's possible to set table prefix later with a separate call:

```php
$db->setPrefix('my_');
```

## Insert Query

Simple example

```php
$data = [
    "login" => "admin",
    "firstName" => "John",
    "lastName" => 'Doe'
];
$id = $db->insert('users', $data);
if ($id) {
    echo 'User was created. Id=' . $id;
}
```

## Insert with functions use

```php
$data = [
	'login' => 'admin',
    'active' => true,
	'firstName' => 'John',
	'lastName' => 'Doe',
	'password' => $db->func('SHA1(?)', ["secretpassword+salt"]),
	// password = SHA1('secretpassword+salt')
	'createdAt' => $db->now(),
	// createdAt = NOW()
	'expires' => $db->now('+1Y')
	// expires = NOW() + interval 1 year
	// Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
];

$id = $db->insert('users', $data);
if ($id) {
    echo 'User was created. Id=' . $id;
} else {
    echo 'Insert failed: ' . $db->getLastError();
}
```

## Insert with on duplicate key update

```php
$data = ["login" => "admin",
         "firstName" => "John",
         "lastName" => 'Doe',
         "createdAt" => $db->now(),
         "updatedAt" => $db->now(),
];
$updateColumns = ["updatedAt"];
$lastInsertId = "id";
$db->onDuplicate($updateColumns, $lastInsertId);
$id = $db->insert('users', $data);
```

## Replace Query

<a href='https://dev.mysql.com/doc/refman/8.0/en/replace.html'>Replace()</a> method implements same API as insert();

Insert multiple datasets at once
```php
$data = [
    ["login" => "admin",
        "firstName" => "John",
        "lastName" => 'Doe'
    ],
    ["login" => "other",
        "firstName" => "Another",
        "lastName" => 'User',
        "password" => "very_cool_hash"
    ]
];
$ids = $db->insertMulti('users', $data);
if (!$ids) {
    echo 'insert failed: ' . $db->getLastError();
} else {
    echo 'new users inserted with following id\'s: ' . implode(', ', $ids);
}
```

If all datasets only have the same keys, it can be simplified

```php
$data = [
    ["admin", "John", "Doe"],
    ["other", "Another", "User"]
];
$keys = ["login", "firstName", "lastName"];

$ids = $db->insertMulti('users', $data, $keys);
if (!$ids) {
    echo 'insert failed: ' . $db->getLastError();
} else {
    echo 'new users inserted with following id\'s: ' . implode(', ', $ids);
}
```

## Update Query

```php
$data = ['firstName' => 'Bobby',
	    'lastName' => 'Tables',
	    'editCount' => $db->inc(2),
	    // editCount = editCount + 2;
	    'active' => $db->not()
	    // active = !active;
];
$db->where('id', 1);
if ($db->update('users', $data)) {
    echo $db->getRowCount() . ' records were updated';
} else {
    echo 'update failed: ' . $db->getLastError();
}
```

`update()` also support limit parameter:

```php
$db->update('users', $data, 10);
// Gives: UPDATE users SET ... LIMIT 10
```

## Select Query

```php
$users = $db->get('users'); //contains an array of all users 
$users = $db->get('users', 10); //contains an array 10 users
```

### Select with custom columns set. Functions also could be used

```php
$cols = ["id", "name", "email"];
$users = $db->get("users", null, $cols);
if ($users) {
    foreach ($users as $user) { 
        print_r($user);
    }
}
```

### Select just one row

```php
$db->where("id", 1);
$user = $db->getOne("users");
echo $user['id'];

$stats = $db->getOne("users", "SUM(id), COUNT(*) as cnt");
echo "total ".$stats['cnt']. "users found";
```

### Select single row value or function result

```php
$count = $db->getValue("users", "COUNT(*)");
echo "{$count} users found";
```

### Select column value or function result from multiple rows:

```php
$logins = $db->getColumn("users", "login");
// select login from users
$logins = $db->getColumn("users", "login", 5); // limit query
// select login from users limit 5
foreach ($logins as $login) {
    echo $login;
}
```

## Insert Data

You can also load .CSV or .XML data into a specific table.
To insert .csv data, use the following syntax:

```php
$path_to_file = "/home/john/file.csv";
$db->loadData("users", $path_to_file);
```

This will load a .csv file called **file.csv** in the folder **/home/john/** (John's home directory.)
You can also attach an optional array of options.
Valid options are:

```php
[
    "local" => false,         // LOCAL
	"fieldChar" => ';', 	  // Char which separates the data
	"lineChar" => '\r\n', 	  // Char which separates the lines
	"linesToIgnore" => 1	  // Amount of lines to ignore at the beginning of the import
    'fieldEnclosure' => null, // field enclosure char
    'fields' => [],           // table fields
    'lineStarting' => null    // Char wich start the lines
];
```

Attach them using

```php
$options = ["fieldChar" => ';', "lineChar" => '\r\n', "linesToIgnore" => 1];
$db->loadData("users", "/home/john/file.csv", $options);
// LOAD DATA ...
```

## Insert XML

To load XML data into a table, you can use the method **loadXML**.
The syntax is similar to the loadData syntax.

```php
$path_to_file = "/home/john/file.xml";
$db->loadXML("users", $path_to_file);
```

You can also add optional parameters.
Valid parameters:

```php
	$rowTag 		// Amount of lines / rows to ignore at the beginning of the import
	$linesToIgnore	// The tag which marks the beginning of an entry

```

Usage:

```php
$path_to_file = "/home/john/file.xml";
$db->loadXML("users", $path_to_file, '<br>', 1);
```

## Pagination

Use paginate() instead of get() to fetch paginated result
```php
$page = 1;
// set page limit to 2 results per page. 20 by default
$db->pageLimit = 2;
$products = $db->paginate("products", $page);
echo "showing $page out of " . $db->totalPages;

```

## Result transformation / map
Instead of getting a pure array of results its possible to get result in an associative array with a needed key. If only 2 fields to fetch will be set in get(),
method will return result in `[$k => $v]` and array `[$k => array []$v, $v]]` in rest of the cases.

```php
$user = $db->map ('login')->ObjectBuilder()->getOne('users', 'login, id');
[
    [user1] => 1
]

$user = $db->map ('login')->ObjectBuilder()->getOne('users', 'id,login,createdAt');
Array
(
    [user1] => stdClass Object
        (
            [id] => 1
            [login] => user1
            [createdAt] => 2015-10-22 22:27:53
        )

)
```

## Defining a return type

PdoDb can return result in 3 different formats: array of arrays, array of abjects and a JSON string. 
To select a return type use `ArrayBuilder()`, `ObjectBuilder()` and `JsonBuilder()` methods. 
Note that `ArrayBuilder()` is a default return type.

```php
// Array return type
$u= $db->getOne("users");
echo $u['login'];
// Object return type
$u = $db->ObjectBuilder()->getOne("users");
echo $u->login;
// Json return type
$json = $db->JsonBuilder()->getOne("users");
```

### Running raw SQL queries

```php
$users = $db->rawQuery('SELECT * FROM users WHERE id >= ?', [10]);
foreach ($users as $user) {
    print_r($user);
}

$users = $db->rawQuery('SELECT * FROM users WHERE name=:name', ['name' => 'user1']);
foreach ($users as $user) {
    print_r($user);
}
```
To avoid long if checks there are couple helper functions to work with raw query select results.

Get 1 row of results:

```php
$user = $db->rawQueryOne('SELECT * FROM users WHERE id=:id', [id => 10]);
echo $user['login'];
// Object return type
$user = $db->setReturnType(PDO::FETCH_OBJ)->rawQueryOne('SELECT * FROM users WHERE id=?', [10]);
echo $user->login;
```

Get 1 column value as a string:

```php
$password = $db->rawQueryValue('SELECT password FROM users WHERE id=? LIMIT 1', [10]);
echo "Password is {$password}";
NOTE: for a rawQueryValue() to return string instead of an array 'limit 1' should be added to the end of the query.
```

Get 1 column value from multiple rows:

```php
$logins = $db->rawQueryValue('SELECT login FROM users LIMIT 10');
foreach ($logins as $login) {
    echo $login;
}
```
More advanced examples:

```php
$params = [1, 'admin'];
$users = $db->rawQuery("SELECT id, firstName, lastName FROM users WHERE id = ? AND login = ?", $params);
print_r($users); // contains Array of returned rows

// will handle any SQL query
$params = [10, 1, 10, 11, 2, 10];
$query = "(
    SELECT a FROM t1
        WHERE a = ? AND B = ?
        ORDER BY a LIMIT ?
) UNION (
    SELECT a FROM t2 
        WHERE a = ? AND B = ?
        ORDER BY a LIMIT ?
)";
$result = $db->rawQuery($query, $params);
print_r($result); // contains array of returned rows
```

## Where / Having Methods

`where()`, `orWhere()`, `having()` and `orHaving()` methods allows you to specify where and having conditions of the query. All conditions supported by where() are supported by having() as well.

WARNING: In order to use column to column comparisons only raw where conditions should be used as column name or functions cant be passed as a bind variable.

Regular == operator with variables:
```php
$db->where('id', 1);
$db->where('login', 'admin');
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE id=1 AND login='admin';
```

```php
$db->where('id', 1);
$db->having('login', 'admin');
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE id=1 HAVING login='admin';
```

Regular == operator with column to column comparison:

```php
// WRONG
$db->where('lastLogin', 'createdAt');
// CORRECT
$db->where('lastLogin = createdAt');
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE lastLogin = createdAt;
```

```php
$db->where('id', 50, ">=");
// or $db->where('id', ['>=' => 50]);
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE id >= 50;
```

BETWEEN / NOT BETWEEN:

```php
$db->where('id', [4, 20], 'BETWEEN');

$results = $db->get('users');
// Gives: SELECT * FROM users WHERE id BETWEEN 4 AND 20
```

IN / NOT IN:

```php
$db->where('id', [1, 5, 27, -1, 'd'], 'IN');
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE id IN (1, 5, 27, -1, 'd');
```

OR CASE

```php
$db->where('firstName', 'John');
$db->orWhere('firstName', 'Peter');
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE firstName='John' OR firstName='peter'
```

```php
$db->where('firstName', 'John');
$db->orWhere('firstName', 'Peter');
$results = $db->get('users');
// Gives: SELECT * FROM users WHERE firstName='John' OR firstName='peter'
```
NULL comparison:

```php
$db->where("lastName", NULL, 'IS NOT');
$results = $db->get("users");
// Gives: SELECT * FROM users where lastName IS NOT NULL
```

LIKE comparison:

```php
$db->where("fullName", 'John%', 'like');
$results = $db->get("users");
// Gives: SELECT * FROM users where fullName like 'John%'
```

You can use raw where conditions:

```php
$db->where("id != companyId");
$db->where("DATE(createdAt) = DATE(lastLogin)");
$results = $db->get("users");
```

Or raw condition with variables:

```php
$db->where("(id = ? OR id = ?)", [6,2]);
$db->where("login","mike")
$res = $db->get("users");
// Gives: SELECT * FROM users WHERe(id = 6 OR id = 2) AND login='mike';
```

Find the total number of rows matched. Simple pagination example:

```php
$offset = 10;
$count = 15;
$users = $db->withTotalCount()->get('users', [$offset, $count]);
echo "Showing {$count} from {$db->totalCount}";
```

## Query Keywords

To add LOW PRIORITY | DELAYED | HIGH PRIORITY | IGNORE and the rest of the mysql keywords to INSERT (), REPLACe(), get(), UPDATE(), DELETE() method or FOR UPDATE | LOCK IN SHARE MODE into SELECT ():

```php
$db->setQueryOption('LOW_PRIORITY')->insert($table, $param);
// GIVES: INSERT LOW_PRIORITY INTO table ...
```

```php
$db->setQueryOption('FOR UPDATE')->get('users');
// GIVES: SELECT * FROM USERS FOR UPDATE;
```

You can use an array of keywords:

```php
$db->setQueryOption(['LOW_PRIORITY', 'IGNORE'])->insert($table,$param);
// GIVES: INSERT LOW_PRIORITY IGNORE INTO table ...
```
Same way keywords could be used in SELECT queries as well:

```php
$db->setQueryOption('SQL_NO_CACHE');
$db->get("users");
// GIVES: SELECT SQL_NO_CACHE * FROM USERS;
```

Optionally you can use method chaining to call where multiple times without referencing your object over an over:

```php
$results = $db
	->where('id', 1)
	->where('login', 'admin')
	->get('users');
```

## Delete Query

```php
$db->where('id', 1);
if ($db->delete('users')) {
    echo 'successfully deleted';
}
```

## Ordering method

```php
$db->orderBy("id","ASC");
$db->orderBy("login","DESC");
$db->orderBy("RAND ()");
$results = $db->get('users');
// Gives: SELECT * FROM users ORDER BY id ASC,login DESC, RAND ();
```

Order by values example:

```php
$db->orderBy('userGroup', 'ASC', ['superuser', 'admin', 'users']);
$db->get('users');
// Gives: SELECT * FROM users ORDER BY FIELD (userGroup, 'superuser', 'admin', 'users') ASC;
```

If you are using setPrefix () functionality and need to use table names in orderBy() method make sure that table names are escaped with ``.

```php
$db->setPrefix("t_");
$db->orderBy("users.id","ASC");
$results = $db->get('users');
// WRONG: That will give: SELECT * FROM t_users ORDER BY users.id ASC;

$db->setPrefix("t_");
$db->orderBy("`users`.id", "ASC");
$results = $db->get('users');
// CORRECT: That will give: SELECT * FROM t_users ORDER BY t_users.id ASC;
```

## Grouping method

```php
$db->groupBy("name");
$results = $db->get('users');
// Gives: SELECT * FROM users GROUP BY name;
```

Join table products with table users with LEFT JOIN by tenantID

## JOIN method

```php
$db->join("users u", "p.tenantID=u.tenantID", "LEFT");
$db->where("u.id", 6);
$products = $db->get("products p", null, "u.name, p.productName");
print_r($products);
```

## Join Conditions

Add AND condition to join statement

```php
$db->join("users u", "p.tenantID=u.tenantID", "LEFT");
$db->joinWhere("users u", "u.tenantID", 5);
$products = $db->get("products p", null, "u.name, p.productName");
print_r($products);
// Gives: SELECT  u.name, p.productName FROM products p LEFT JOIN users u ON (p.tenantID=u.tenantID AND u.tenantID = 5)
```

Add OR condition to join statement

```php
$db->join("users u", "p.tenantID=u.tenantID", "LEFT");
$db->joinOrWhere("users u", "u.tenantID", 5);
$products = $db->get("products p", null, "u.name, p.productName");
print_r($products);
// Gives: SELECT  u.login, p.productName FROM products p LEFT JOIN users u ON (p.tenantID=u.tenantID OR u.tenantID = 5)
```

## Properties sharing

Its is also possible to copy properties

```php
$db->where("agentId", 10);
$db->where("active", true);

$customers = $db->copy();
$res = $customers->get("customers", [10, 10]);
// SELECT * FROM customers where agentId = 10 and active = 1 limit 10, 10

$cnt = $db->getValue("customers", "COUNT(id)");
echo "total records found: " . $cnt;
// SELECT COUNT(id) FROM users WHERE agentId = 10 AND active = 1
```

## Subqueries

Subquery init without an alias to use in inserts/updates/where E.g. (select * from users)

```php
$sq = $db->subQuery();
$sq->get("users");
```
 
A subquery with an alias specified to use in JOINs . E.g. (select * from users) sq

```php
$sq = $db->subQuery("sq");
$sq->get("users");
```

Subquery in selects:

```php
$ids = $db->subQuery();
$ids->where("qty", 2, ">");
$ids->get("products", null, "userId");

$db->where("id", $ids, 'in');
$res = $db->get("users");
// Gives SELECT * FROM users WHERE id IN (SELECT userId FROM products WHERE qty > 2)
```

Subquery in inserts:
```php
$userIdQ = $db->subQuery();
$userIdQ->where("id", 6);
$userIdQ->getOne("users", "name");

$data = ["productName" => "test product",
         "userId" => $userIdQ,
         "lastUpdated" => $db->now()
];
$id = $db->insert("products", $data);
// Gives INSERT INTO PRODUCTS (productName, userId, lastUpdated) values ("test product", (SELECT name FROM users WHERE id = 6), NOW());
```

Subquery in joins:
```php
$usersQ = $db->subQuery("u");
$usersQ->where("active", 1);
$usersQ->get("users");

$db->join($usersQ, "p.userId=u.id", "LEFT");
$products = $db->get("products p", null, "u.login, p.productName");
print_r($products);
// SELECT u.login, p.productName FROM products p LEFT JOIN (SELECT * FROM t_users WHERE active = 1) u on p.userId=u.id;
```

## EXISTS / NOT EXISTS condition

```php
$sub = $db->subQuery();
$sub->where("company", 'testCompany');
$sub->get("users", null, 'userId');
$db->where(null, $sub, 'EXISTS');
$products = $db->get("products");
// Gives SELECT * FROM products WHERE EXISTS (select userId from users where company='testCompany')
```

## Has method

A convenient function that returns TRUE if exists at least an element that satisfy the where condition specified calling the "where" method before this one.
```php
$db->where("user", $user);
$db->where("password", md5($password));
if ($db->has("users")) {
    return "You are logged";
} else {
    return "Wrong user/password";
}
```

## Helper methods

Get last executed SQL query:
Please note that function returns SQL query only for debugging purposes as its execution most likely will fail due missing quotes around char variables.
```php
$db->get('users');
echo "Last executed query was ". $db->getLastQuery();
```

Check if table exists:
```php
if ($db->tableExists('users')) {
    echo "hooray";
}
```

PDO::quote() wrapper:
```php
$escaped = $db->escape("' and 1=1");
```

### Transaction helpers

Please keep in mind that transactions are working on innoDB tables.
Rollback transaction if insert fails:
```php
$db->startTransaction();
...
if (!$db->insert('myTable', $insertData)) {
    //Error while saving, cancel new record
    $db->rollback();
} else {
    //OK
    $db->commit();
}
```

## Error helpers

After you executed a query you have options to check if there was an error. You can get the MySQL error string or the error code for the last executed query. 
```php
$db->where('login', 'admin')->update('users', ['firstName' => 'Jack']);

if ($db->getLastErrNo() === 0) {
    echo 'Update successful';
} else {
    echo 'Update failed. Error: '. $db->getLastError();
}
```
## Query execution time benchmarking
To track query execution time setTrace() function should be called.
```php
$db->setTrace(true);
// As a second parameter it is possible to define prefix of the path which should be striped from filename
// $db->setTrace(true, $_SERVER['SERVER_ROOT']);
$db->get("users");
$db->get("test");
print_r($db->trace);
```

```
    [0] => Array
        (
            ['query'] => SELECT  * FROM t_users ORDER BY `id` ASC
            ['params] => MysqliDb->get() >>  file "/avb/work/PHP-MySQLi-Database-Class/tests.php" line #151
            ['time'] => 0.0010669231414795            
        )

    [1] => Array
        (
            ['query'] => SELECT  * FROM t_test
            ['params'] => MysqliDb->get() >>  file "/avb/work/PHP-MySQLi-Database-Class/tests.php" line #152
            ['time'] => 0.00069189071655273
        )

```

## Table Locking

To lock tables, you can use the **lock** method together with **setLockMethod**.
The following example will lock the table **users** for **write** access.

```php
$db->setLockMethod("WRITE")->lock("users");
```

Calling another **->lock()** will remove the first lock.

You can also use

```php
$db->unlock();
```
to unlock the previous locked tables.
To lock multiple tables, you can use an array.

Example:

```php
$db->setLockMethod("READ")->lock(["users", "log"]);
```

This will lock the tables **users** and **log** for **READ** access only.
Make sure you use **unlock()* afterward or your tables will remain locked!

## Explain

You can use explain(), explainAnalyze(), describe() methods to get query execution plan.

```php
$db->explain("SELECT * FROM users WHERE status = 'active'");
$db->explainAnalyze("SELECT * FROM users WHERE status = 'active'");
$db->describe("users");
```