# Real-World Examples

Complete application patterns and use cases demonstrating PDOdb in realistic scenarios.

## Overview

These examples show how to build complete features using PDOdb, combining multiple concepts from basic to advanced levels. Each example is a mini-application that demonstrates best practices and real-world patterns.

## Examples

### 01-blog-system.php
Complete blog system with posts, comments, and tags.

**Features implemented:**
- Multi-table schema (posts, comments, tags, post_tags)
- Creating posts with tags
- Adding comments to posts
- Querying posts with related data
- JOINs across multiple tables
- Aggregations (comment counts, tag usage)
- Filtering by tags and authors
- Pagination of results
- Full-text search in posts

**Concepts used:**
- Complex JOINs
- Many-to-many relationships
- GROUP BY with HAVING
- Subqueries
- Aggregate functions
- Conditional logic

### 02-user-auth.php
User authentication and authorization system.

**Features implemented:**
- User registration with password hashing
- User login with credential verification
- Role-based access control (RBAC)
- Session management
- Password updates
- User profile management
- Permission checking
- Account status tracking

**Concepts used:**
- Secure password handling
- WHERE conditions
- UPDATEs with conditions
- JOINs for roles and permissions
- Transactions for data consistency
- NULL handling

### 03-search-filters.php
Advanced search with multiple filter options.

**Features implemented:**
- Product catalog search
- Multiple filter criteria (category, price range, rating)
- Dynamic query building
- Sorting options
- Pagination of results
- Filter combination logic
- Search result highlighting
- Filter counts and statistics

**Concepts used:**
- Dynamic WHERE building
- BETWEEN for ranges
- IN for multiple values
- LIKE for text search
- Complex boolean logic
- ORDER BY with multiple columns
- COUNT aggregations

### 04-multi-tenant.php
Multi-tenant architecture with tenant isolation.

**Features implemented:**
- Tenant registration and management
- Tenant-specific data isolation
- Automatic tenant ID filtering
- Cross-tenant reporting (admin only)
- Tenant switching
- Tenant-specific configuration
- Data access control
- Tenant activity tracking

**Concepts used:**
- Global WHERE filters
- Transactions for tenant operations
- Complex JOINs with tenant context
- Subqueries for cross-tenant data
- Aggregate functions per tenant
- Security best practices

## Running Examples

### SQLite (default)
```bash
php 01-blog-system.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-blog-system.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-blog-system.php
```

## Key Patterns Demonstrated

### 1. Schema Design
- Proper normalization
- Foreign key relationships
- Many-to-many tables
- Indexes for performance

### 2. Query Optimization
- Efficient JOINs
- Strategic use of indexes
- Query result limiting
- Subquery vs JOIN decisions

### 3. Security
- SQL injection prevention (automatic with parameter binding)
- Password hashing
- Access control
- Input validation

### 4. Data Consistency
- Transaction usage
- Constraint handling
- Error recovery
- Atomic operations

### 5. Code Organization
- Separation of concerns
- Reusable query patterns
- Error handling
- Clean code practices

## Building Your Own Application

These examples provide templates for common application types:

- **Blog/CMS**: Start with `01-blog-system.php`
- **User Management**: Start with `02-user-auth.php`
- **E-commerce/Catalog**: Start with `03-search-filters.php`
- **SaaS Platform**: Start with `04-multi-tenant.php`

## Best Practices Highlighted

1. **Always use transactions** for multi-table operations
2. **Filter results early** with WHERE clauses
3. **Use JOINs efficiently** - avoid N+1 queries
4. **Implement pagination** for large result sets
5. **Index frequently queried columns**
6. **Use helper functions** for dialect compatibility
7. **Handle errors gracefully** with try-catch
8. **Validate input** before queries

## Next Steps

Apply these patterns in your projects:
- [Exception Handling](../09-exception-handling/) - Robust error handling
- [Batch Processing](../10-batch-processing/) - Large dataset processing
- [Full Documentation](../../documentation/) - Complete API reference

