# Monitoring

Track and monitor database errors and performance.

## Error Tracking

### Track Errors

```php
class ErrorTracker {
    protected array $errors = [];
    
    public function track(\Exception $e): void {
        $this->errors[] = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ];
    }
    
    public function getErrors(): array {
        return $this->errors;
    }
}
```

## Performance Monitoring

### Track Query Time

```php
class PerformanceMonitor {
    protected array $metrics = [];
    
    public function startQuery(string $sql): void {
        $this->metrics[] = [
            'sql' => $sql,
            'start' => microtime(true)
        ];
    }
    
    public function endQuery(): void {
        $query = &$this->metrics[count($this->metrics) - 1];
        $query['time'] = microtime(true) - $query['start'];
    }
    
    public function getSlowQueries(float $threshold = 1.0): array {
        return array_filter($this->metrics, function($query) use ($threshold) {
            return $query['time'] > $threshold;
        });
    }
}
```

## Alerting

### Send Alerts on Errors

```php
function sendAlert(string $message, array $context): void {
    // Send email, SMS, or webhook
    mail('admin@example.com', 'Database Error', $message);
    // ...
}

try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    if ($e->getCode() >= 500) {
        sendAlert('Database error', [
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
    }
}
```

## Next Steps

- [Exception Hierarchy](exception-hierarchy.md) - Exception types
- [Logging](logging.md) - Configure logging
- [Retry Logic](retry-logic.md) - Build retry logic

