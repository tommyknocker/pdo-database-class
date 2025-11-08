# Reliability Examples

Examples demonstrating reliability features: exception handling and connection retry.

## Examples

### 01-exception-handling.php
Exception handling: typed exceptions and error diagnostics.

**Topics covered:**
- Typed exceptions - Specific exception types for different errors
- Exception hierarchy - DatabaseException and subclasses
- Error diagnostics - Query context, sanitized parameters, debug information
- Error handling patterns - Best practices for error handling

### 02-connection-retry.php
Connection retry: automatic retry with exponential backoff.

**Topics covered:**
- Automatic retry - Retry failed connections automatically
- Exponential backoff - Increasing delay between retries
- Retry configuration - Configure retry attempts and delays
- Error handling - Handle retry failures gracefully

## Usage

```bash
# Run examples
php 01-exception-handling.php
php 02-connection-retry.php
```

## Reliability Features

- **Typed Exceptions** - Specific exception types for precise error handling
- **Error Diagnostics** - Query context and debug information in exceptions
- **Connection Retry** - Automatic retry with exponential backoff
- **Error Recovery** - Graceful handling of transient errors

## Related Examples

- [Transactions](../02-intermediate/04-transactions.php) - Transaction management
- [Architecture](../08-architecture/) - High availability patterns
