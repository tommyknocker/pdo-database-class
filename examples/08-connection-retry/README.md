# Connection Retry Examples

This directory contains examples demonstrating the connection retry functionality.

## Files

- `01-retry-examples.php` - Basic retry configuration and usage examples

## Features Demonstrated

- **Basic Retry Configuration**: Setting up retry with custom parameters
- **Normal Operations**: How retry works transparently with existing code
- **Query Builder Integration**: Retry works with all Query Builder methods
- **Different Retry Strategies**: Aggressive vs conservative retry configurations
- **Retry Disabled**: How to disable retry functionality
- **Default Behavior**: What happens without retry configuration

## Retry Configuration Options

```php
'retry' => [
    'enabled' => true,                    // Enable/disable retry
    'max_attempts' => 3,                  // Maximum retry attempts
    'delay_ms' => 1000,                   // Initial delay between retries (milliseconds)
    'backoff_multiplier' => 2,            // Exponential backoff multiplier
    'max_delay_ms' => 10000,              // Maximum delay between retries
    'retryable_errors' => [2002, 2003, 2006]  // Error codes to retry on
]
```

## Error Codes

### MySQL
- `2002` - Can't connect to local MySQL server
- `2003` - Can't connect to MySQL server
- `2006` - MySQL server has gone away
- `2013` - Lost connection to MySQL server

### PostgreSQL
- `57P01` - Admin shutdown
- `57P03` - Cannot connect now

## Usage

```bash
# Run the example
php examples/09-connection-retry/01-retry-examples.php
```

The example will demonstrate various retry configurations and show how the retry functionality integrates seamlessly with existing PdoDb operations.
