# Architecture Overview

Understanding how PDOdb works under the hood.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Your Application                       │
│                                                             │
│  $db = new PdoDb('mysql', $config);                        │
│  $users = $db->find()->from('users')->get();               │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│                        PdoDb Class                          │
│  • Query execution                                          │
│  • Connection management                                    │
│  • Transaction handling                                     │
│  • Error handling                                           │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│                    Connection Layer                         │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │  Connection  │  │   Dialect    │  │     PDO     │     │
│  │              │  │              │  │              │     │
│  │ • PDO wrapper│  │ • SQL gen    │  │ • Database  │     │
│  │ • Pool mgmt  │  │ • Type conv  │  │   driver    │     │
│  │ • Retry logic│  │ • Functions  │  │ • Prepared  │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
└─────────────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│                    Query Builder Layer                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │   Select     │  │     DML      │  │     DDL      │     │
│  │  QueryBuilder│  │  QueryBuilder│  │  QueryBuilder│     │
│  │              │  │              │  │              │     │
│  │ • SELECT     │  │ • INSERT     │  │ • CREATE     │     │
│  │ • FROM       │  │ • UPDATE     │  │ • ALTER      │     │
│  │ • WHERE      │  │ • DELETE     │  │ • DROP       │     │
│  │ • JOIN       │  │ • REPLACE    │  │              │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
└─────────────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│                    Execution Engine                          │
│  • Parameter binding                                        │
│  • Statement execution                                      │
│  • Result fetching                                         │
│  • Error handling                                          │
└─────────────────────────────────────────────────────────────┘
```

## Component Flow

### 1. Query Building Flow

```
User Code
    │
    │ $db->find()->from('users')->where('id', 1)->get()
    │
    ▼
PdoDb::find()
    │
    │ Returns SelectQueryBuilder instance
    │
    ▼
SelectQueryBuilder
    │
    │ • Stores: table, columns, conditions, etc.
    │ • Methods: from(), where(), orderBy(), etc.
    │
    ▼
SelectQueryBuilder::get()
    │
    │ • Builds SQL using Dialect
    │ • Binds parameters
    │ • Executes via ExecutionEngine
    │
    ▼
ExecutionEngine::fetchAll()
    │
    │ • Executes prepared statement
    │ • Fetches all rows
    │ • Returns array
    │
    ▼
Result Array
```

### 2. SQL Generation Flow

```
QueryBuilder Method Call
    │
    │ ->where('age', 18, '>=')
    │
    ▼
QueryBuilder stores condition
    │
    │ ['column' => 'age', 'operator' => '>=', 'value' => 18]
    │
    ▼
get() called → buildSql()
    │
    │ • Iterates through stored conditions
    │ • Calls Dialect methods for SQL generation
    │
    ▼
Dialect::buildWhereClause()
    │
    │ • Generates: WHERE age >= ?
    │ • Returns SQL + parameters
    │
    ▼
Final SQL: SELECT * FROM users WHERE age >= ?
```

### 3. Connection Flow

```
new PdoDb('mysql', $config)
    │
    │ • Validates driver name
    │ • Resolves dialect class
    │
    ▼
ConnectionFactory::create()
    │
    │ • Creates PDO connection
    │ • Registers dialect
    │ • Sets up event listeners
    │
    ▼
Connection Object
    │
    │ • Wraps PDO
    │ • Provides dialect access
    │ • Manages connection pool
    │
    ▼
Ready for queries
```

## Key Components

### PdoDb Class

**Responsibilities:**
- Entry point for all operations
- Manages connections
- Provides query builder factory methods (`find()`, `schema()`, etc.)
- Handles transactions
- Error handling and logging

**Key Methods:**
```php
$db->find()           // Returns SelectQueryBuilder
$db->schema()         // Returns SchemaBuilder
$db->startTransaction()  // Begin transaction
$db->commit()         // Commit transaction
$db->rollback()       // Rollback transaction
```

### Connection Layer

**Connection Class:**
- Wraps PDO instance
- Provides dialect access
- Manages connection pool
- Handles retry logic

**Dialect Classes:**
- MySQLDialect
- MariaDBDialect
- PostgreSQLDialect
- SqliteDialect
- MSSQLDialect

**Responsibilities:**
- SQL generation (dialect-specific)
- Type conversion
- Function mapping (e.g., LENGTH → LEN for MSSQL)
- Identifier quoting
- Feature emulation

### Query Builder Layer

**SelectQueryBuilder:**
- Builds SELECT queries
- Handles: FROM, WHERE, JOIN, GROUP BY, HAVING, ORDER BY, LIMIT, OFFSET
- Supports subqueries and CTEs

**DmlQueryBuilder:**
- Builds INSERT, UPDATE, DELETE, REPLACE queries
- Handles bulk operations
- Supports UPSERT operations

**DdlQueryBuilder:**
- Builds CREATE, ALTER, DROP queries
- Schema introspection
- Index management

### Execution Engine

**Responsibilities:**
- Parameter binding
- Statement execution
- Result fetching
- Error handling
- Cursor management

**Key Features:**
- Automatic prepared statements
- Parameter sanitization
- Memory-efficient result fetching
- Error context preservation

## Data Flow Example

### Example: Simple SELECT Query

```php
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->limit(10)
    ->get();
```

**Step-by-step:**

1. **Query Building:**
   ```
   find() → SelectQueryBuilder instance
   from('users') → stores table name
   where('age', 18, '>=') → stores condition
   limit(10) → stores limit
   ```

2. **SQL Generation:**
   ```
   get() → buildSql()
   → Dialect::buildSelectSql()
   → Dialect::buildWhereClause()
   → Dialect::buildLimitClause()
   → Final SQL: "SELECT * FROM users WHERE age >= ? LIMIT 10"
   ```

3. **Parameter Binding:**
   ```
   Parameters: [18]
   → ExecutionEngine::bindParams()
   → PDO::prepare() + PDO::execute()
   ```

4. **Execution:**
   ```
   PDO executes prepared statement
   → Returns PDOStatement
   → ExecutionEngine::fetchAll()
   → Returns array of results
   ```

## Dialect Resolution

```
Driver Name ('mysql', 'pgsql', etc.)
    │
    ▼
DialectRegistry::resolve()
    │
    │ • Maps driver to dialect class
    │ • mysql → MySQLDialect
    │ • pgsql → PostgreSQLDialect
    │ • sqlsrv → MSSQLDialect
    │
    ▼
Dialect Instance
    │
    │ • Provides SQL generation methods
    │ • Handles type conversion
    │ • Maps functions
    │
    ▼
Used by QueryBuilder for SQL generation
```

## Error Handling Flow

```
Query Execution Error
    │
    ▼
PDOException thrown
    │
    ▼
ExecutionEngine catches exception
    │
    │ • Extracts error code
    │ • Gets error message
    │ • Preserves query context
    │
    ▼
ExceptionFactory::createFromPdoException()
    │
    │ • Determines exception type
    │ • Creates typed exception
    │ • Adds context information
    │
    ▼
Typed Exception (DatabaseException, QueryException, etc.)
    │
    ▼
Thrown to user code
```

## Performance Optimizations

### 1. Prepared Statement Pool

```
Query Execution
    │
    │ • Check if SQL already prepared
    │ • Reuse statement if found
    │ • Create new if not found
    │
    ▼
LRU Cache
    │
    │ • Stores prepared statements
    │ • Evicts least recently used
    │ • Capacity: 256 statements (default)
    │
    ▼
Faster subsequent queries
```

### 2. Query Compilation Cache

```
Query Building
    │
    │ • Check if SQL already compiled
    │ • Reuse compiled SQL if found
    │ • Compile if not found
    │
    ▼
Cache (optional)
    │
    │ • Stores compiled SQL strings
    │ • Key: query structure hash
    │ • Value: SQL string
    │
    ▼
Faster SQL generation
```

### 3. Connection Pooling

```
Multiple Connections
    │
    │ • Main connection (default)
    │ • Named connections (read/write splitting)
    │ • Shard connections
    │
    ▼
Connection Manager
    │
    │ • Reuses connections
    │ • Manages connection lifecycle
    │ • Handles failover
    │
    ▼
Efficient resource usage
```

## Extension Points

### 1. Plugins

```
Plugin Registration
    │
    │ • Macros (custom query methods)
    │ • Scopes (reusable query logic)
    │ • Event listeners
    │
    ▼
Plugin System
    │
    │ • Extends QueryBuilder
    │ • Adds custom functionality
    │ • Integrates with event system
    │
    ▼
Custom Features
```

### 2. Event System

```
Query Execution
    │
    │ • beforeQuery event
    │ • afterQuery event
    │ • onError event
    │
    ▼
Event Dispatcher (PSR-14)
    │
    │ • Notifies listeners
    │ • Allows modification
    │ • Enables logging/monitoring
    │
    ▼
Observable Behavior
```

## Next Steps

- [Connection Management](connection-management.md) - Learn about connections
- [Query Builder Basics](query-builder-basics.md) - Fluent API overview
- [Dialect Support](dialect-support.md) - Database differences

