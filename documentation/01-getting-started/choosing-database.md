# Choosing Your Database

A guide to help you choose the right database for your project.

## Quick Comparison

| Database | Best For | Setup Difficulty | Production Ready |
|----------|----------|------------------|------------------|
| **SQLite** | Development, testing, small apps | ⭐ Easy (no server) | ✅ Yes (for small scale) |
| **MySQL** | Web applications, WordPress, Laravel | ⭐⭐ Medium | ✅ Yes |
| **MariaDB** | MySQL alternative, open source | ⭐⭐ Medium | ✅ Yes |
| **PostgreSQL** | Complex queries, JSON, analytics | ⭐⭐⭐ Harder | ✅ Yes |
| **MSSQL** | Enterprise, Windows environments | ⭐⭐⭐ Harder | ✅ Yes |

## SQLite

**When to use SQLite:**
- ✅ Development and testing (no server setup needed)
- ✅ Small applications (< 100K requests/day)
- ✅ Embedded applications
- ✅ Prototyping and demos
- ✅ Single-user applications
- ✅ Mobile applications

**When NOT to use SQLite:**
- ❌ High-concurrency applications (multiple writers)
- ❌ Large datasets (> 100GB)
- ❌ Network access required
- ❌ Complex multi-user scenarios

**Advantages:**
- Zero configuration - just a file
- Perfect for development
- Fast for read-heavy workloads
- No server management
- Cross-platform

**Limitations:**
- Single writer at a time
- Limited concurrent access
- No user management
- File-based (can be slow on network drives)

**Example Use Cases:**
```php
// Perfect for development/testing
$db = new PdoDb('sqlite', ['path' => ':memory:']);

// Small web app
$db = new PdoDb('sqlite', ['path' => '/var/www/app.db']);
```

## MySQL / MariaDB

**When to use MySQL/MariaDB:**
- ✅ Web applications (most common choice)
- ✅ Content management systems (WordPress, Drupal)
- ✅ E-commerce platforms
- ✅ Blogging platforms
- ✅ General-purpose applications
- ✅ When you need proven stability

**When NOT to use MySQL/MariaDB:**
- ❌ Complex analytical queries
- ❌ Advanced JSON operations (PostgreSQL is better)
- ❌ Very large datasets requiring advanced partitioning
- ❌ When you need advanced data types

**Advantages:**
- Most popular, huge community
- Excellent documentation
- Great performance for web apps
- Easy to find hosting
- Well-optimized for common web patterns

**Limitations:**
- Less advanced than PostgreSQL
- Limited JSON support (compared to PostgreSQL)
- Some features require specific storage engines

**Example Use Cases:**
```php
// Web application
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'app_user',
    'password' => 'secure_password',
    'dbname' => 'myapp'
]);

// MariaDB (same driver, auto-detected)
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'app_user',
    'password' => 'secure_password',
    'dbname' => 'myapp'
]);
```

**Note:** MariaDB uses the same `'mysql'` driver. PDOdb automatically detects MariaDB and uses optimized SQL generation.

## PostgreSQL

**When to use PostgreSQL:**
- ✅ Complex queries and analytics
- ✅ Advanced JSON operations
- ✅ Applications requiring data integrity
- ✅ Geographic data (PostGIS extension)
- ✅ Full-text search requirements
- ✅ When you need advanced data types

**When NOT to use PostgreSQL:**
- ✅ Simple web applications (MySQL might be easier)
- ✅ When team is unfamiliar with PostgreSQL
- ✅ Limited hosting options in your region

**Advantages:**
- Most advanced open-source database
- Excellent JSON support (JSONB)
- Advanced indexing options
- Strong data integrity features
- Great for complex queries
- Excellent documentation

**Limitations:**
- Steeper learning curve
- More resource-intensive
- Less common in shared hosting
- Some operations can be slower than MySQL

**Example Use Cases:**
```php
// Analytics application
$db = new PdoDb('pgsql', [
    'host' => 'localhost',
    'username' => 'analyst',
    'password' => 'secure_password',
    'dbname' => 'analytics',
    'port' => 5432
]);

// Application with complex JSON requirements
$db = new PdoDb('pgsql', [
    'host' => 'localhost',
    'username' => 'app_user',
    'password' => 'secure_password',
    'dbname' => 'myapp'
]);
```

## Microsoft SQL Server (MSSQL)

**When to use MSSQL:**
- ✅ Enterprise environments
- ✅ Windows-based infrastructure
- ✅ Integration with Microsoft ecosystem
- ✅ Applications requiring advanced security
- ✅ When you need SQL Server-specific features
- ✅ Large enterprise applications

**When NOT to use MSSQL:**
- ✅ Small projects or startups
- ✅ Open-source focused projects
- ✅ Cross-platform applications (unless required)
- ✅ When cost is a concern (licensing)

**Advantages:**
- Enterprise-grade features
- Excellent Windows integration
- Strong security features
- Great tooling (SQL Server Management Studio)
- Advanced analytics capabilities
- Excellent performance

**Limitations:**
- Licensing costs (for production)
- Primarily Windows-focused
- Requires more setup than MySQL
- Less common in open-source projects

**Example Use Cases:**
```php
// Enterprise application
$db = new PdoDb('sqlsrv', [
    'host' => 'sqlserver.company.com',
    'username' => 'app_user',
    'password' => 'secure_password',
    'dbname' => 'enterprise_app',
    'port' => 1433,
    'trust_server_certificate' => true,
    'encrypt' => true
]);
```

## Decision Matrix

### For Development/Testing
**Choose:** SQLite
- No server setup required
- Fast iteration
- Perfect for unit tests

### For Small Web Applications
**Choose:** MySQL/MariaDB or SQLite
- MySQL/MariaDB: If you need multi-user support
- SQLite: If single-user or very small scale

### For Medium/Large Web Applications
**Choose:** MySQL/MariaDB or PostgreSQL
- MySQL/MariaDB: Standard choice, easy hosting
- PostgreSQL: If you need advanced features or JSON

### For Enterprise Applications
**Choose:** PostgreSQL or MSSQL
- PostgreSQL: Open-source, advanced features
- MSSQL: Windows environment, enterprise requirements

### For Analytics/Reporting
**Choose:** PostgreSQL
- Best for complex queries
- Excellent JSON support
- Advanced indexing

### For Mobile/Embedded Applications
**Choose:** SQLite
- No server required
- Lightweight
- Perfect for local storage

## Migration Between Databases

PDOdb makes it easy to switch databases - your code stays the same!

```php
// Same code works with any database
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->get();

// Just change the connection configuration
$db = new PdoDb('sqlite', ['path' => ':memory:']);  // Development
$db = new PdoDb('mysql', $mysqlConfig);            // Production
```

**Tip:** Start with SQLite for development, then switch to MySQL/PostgreSQL for production.

## Recommendations by Project Type

### Blog/Content Site
→ **MySQL/MariaDB** or **SQLite** (if small)

### E-commerce
→ **MySQL/MariaDB** or **PostgreSQL**

### SaaS Application
→ **PostgreSQL** (for advanced features) or **MySQL/MariaDB** (for simplicity)

### Analytics Dashboard
→ **PostgreSQL** (best for complex queries)

### Enterprise Application
→ **PostgreSQL** (open-source) or **MSSQL** (Windows/enterprise)

### Mobile App Backend
→ **MySQL/MariaDB** or **PostgreSQL**

### Prototype/MVP
→ **SQLite** (fastest to get started)

## Still Not Sure?

**Start with SQLite** for development - it's the easiest to set up and you can always migrate later. PDOdb makes switching databases trivial!

**For production**, choose based on:
1. **Team familiarity** - Use what your team knows
2. **Hosting options** - What's available in your region?
3. **Feature requirements** - Do you need advanced features?
4. **Scale** - How much traffic/data do you expect?

## Next Steps

- [Installation](installation.md) - Install PDOdb
- [First Connection](first-connection.md) - Connect to your chosen database
- [Configuration](configuration.md) - Configure your connection
- [Learning Path](learning-path.md) - Start learning PDOdb

