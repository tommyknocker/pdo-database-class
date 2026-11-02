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

### 03-transactional-outbox.php
Transactional outbox: enqueue domain events in the same transaction as business writes, then publish with your own callback.

**Topics covered:**
- `Outbox::add()` inside `transaction()`
- `OutboxWorker::runOnce()` with a custom publisher
- Retryable vs permanent publish failures (see documentation)

### 04-outbox-cli.php
Same flow using `pdodb outbox work --once` and `examples/12-reliability/outbox/publisher.php`.

### 05-outbox-retry.php
Requeue a failed row with `pdodb outbox retry`, then process it with `outbox work --once`.

### 07-outbox-inbox-end-to-end.php
Two services and a broker that is down once and repeats a message: the order and its event commit together, the worker retries and keeps one order's events in order, the billing inbox ignores the repeat, and an unroutable message is fixed and requeued. Walkthrough: [Reliable events: outbox, worker, inbox](../../documentation/10-cookbook/06-reliable-events-outbox-inbox.md).

### outbox/publisher.php
Sample publisher file returned from PHP and loaded by the CLI.

### outbox/webhook-publisher.php and outbox/webhook-receiver.php
A publisher that POSTs each message to a signed webhook (HTTP status decides retry or failure, `Idempotency-Key` is the message id) and the receiver that applies each message once with the inbox. Run the receiver with `php -S 127.0.0.1:8080 examples/12-reliability/outbox/webhook-receiver.php`.

## Usage

```bash
# Run examples
php 01-exception-handling.php
php 02-connection-retry.php
php 03-transactional-outbox.php
php 04-outbox-cli.php
php 05-outbox-retry.php
php 07-outbox-inbox-end-to-end.php
```

## Reliability Features

- **Typed Exceptions** - Specific exception types for precise error handling
- **Error Diagnostics** - Query context and debug information in exceptions
- **Connection Retry** - Automatic retry with exponential backoff
- **Error Recovery** - Graceful handling of transient errors
- **Inbox** - Idempotent consumers are shown in `examples/09-active-record/06-model-services.php`

## Related Examples

- [Transactions](../02-intermediate/04-transactions.php) - Transaction management
- [Architecture](../08-architecture/) - High availability patterns
