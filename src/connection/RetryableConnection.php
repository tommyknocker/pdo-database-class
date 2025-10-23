<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\DbError;

/**
 * RetryableConnection
 *
 * Extends Connection with automatic retry functionality for connection errors.
 * Provides configurable retry logic with exponential backoff.
 */
class RetryableConnection extends Connection
{
    /** @var array<string, mixed> Retry configuration */
    protected array $retryConfig;
    
    /** @var int Current retry attempt */
    protected int $currentAttempt = 0;

    /**
     * RetryableConnection constructor.
     *
     * @param PDO $pdo The PDO instance to use.
     * @param DialectInterface $dialect The dialect to use.
     * @param LoggerInterface|null $logger The logger to use.
     * @param array<string, mixed> $retryConfig Retry configuration.
     */
    public function __construct(
        PDO $pdo, 
        DialectInterface $dialect, 
        ?LoggerInterface $logger = null, 
        array $retryConfig = []
    ) {
        parent::__construct($pdo, $dialect, $logger);
        
        $this->retryConfig = array_merge([
            'enabled' => false,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2,
            'max_delay_ms' => 10000,
            'retryable_errors' => []
        ], $retryConfig);
    }

    /**
     * Executes a SQL statement with retry logic.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     * @return PDOStatement The PDOStatement instance.
     */
    public function execute(array $params = []): PDOStatement
    {
        return $this->retryOperation('execute', [$params]);
    }

    /**
     * Executes a SQL query with retry logic.
     *
     * @param string $sql The SQL query to execute.
     * @return PDOStatement|false The PDOStatement instance or false on failure.
     */
    public function query(string $sql): PDOStatement|false
    {
        return $this->retryOperation('query', [$sql]);
    }

    /**
     * Prepares a SQL statement with retry logic.
     *
     * @param string $sql The SQL to prepare.
     * @param array<int|string, string|int|float|bool|null> $params
     * @return $this
     */
    public function prepare(string $sql, array $params = []): static
    {
        return $this->retryOperation('prepare', [$sql, $params]);
    }

    /**
     * Begins a transaction with retry logic.
     *
     * @return bool True on success, false on failure.
     */
    public function transaction(): bool
    {
        return $this->retryOperation('transaction', []);
    }

    /**
     * Executes an operation with retry logic.
     *
     * @param string $method The method to call.
     * @param array<mixed> $args The arguments to pass.
     * @return mixed The result of the operation.
     * @throws PDOException If all retry attempts fail.
     */
    protected function retryOperation(string $method, array $args): mixed
    {
        $this->currentAttempt = 0;
        
        while ($this->currentAttempt < $this->retryConfig['max_attempts']) {
            try {
                return parent::$method(...$args);
            } catch (PDOException $e) { // @phpstan-ignore-line
                if (!$this->shouldRetry($e)) {
                    throw $e;
                }
                
                $this->currentAttempt++;
                if ($this->currentAttempt >= $this->retryConfig['max_attempts']) {
                    $this->logger?->error('connection.retry.failed', [
                        'method' => $method,
                        'attempts' => $this->currentAttempt,
                        'max_attempts' => $this->retryConfig['max_attempts'],
                        'error_code' => $e->getCode(),
                        'error_message' => $e->getMessage()
                    ]);
                    throw $e;
                }
                
                $this->waitBeforeRetry();
            }
        }
        
        // This should never be reached, but PHP requires a return
        throw new PDOException('Retry logic failed unexpectedly');
    }

    /**
     * Determines if an exception should trigger a retry.
     *
     * @param PDOException $e The exception to check.
     * @return bool True if retry should be attempted.
     */
    protected function shouldRetry(PDOException $e): bool
    {
        if (!$this->retryConfig['enabled']) {
            return false;
        }
        
        $errorCode = $e->getCode();
        $driver = $this->getDialect()->getDriverName();
        
        // Use DbError constants if no custom retryable_errors are configured
        if (empty($this->retryConfig['retryable_errors'])) {
            return DbError::isRetryable($errorCode, $driver);
        }
        
        // Use custom retryable_errors if configured
        return in_array($errorCode, $this->retryConfig['retryable_errors'], true);
    }

    /**
     * Waits before the next retry attempt using exponential backoff.
     *
     * @return void
     */
    protected function waitBeforeRetry(): void
    {
        $delay = $this->retryConfig['delay_ms'] * 
                 pow($this->retryConfig['backoff_multiplier'], $this->currentAttempt - 1);
        
        $delay = min($delay, $this->retryConfig['max_delay_ms']);
        
        $this->logger?->info('connection.retry.attempt', [
            'attempt' => $this->currentAttempt,
            'max_attempts' => $this->retryConfig['max_attempts'],
            'delay_ms' => $delay,
            'next_attempt_in' => $delay . 'ms'
        ]);
        
        usleep($delay * 1000); // usleep accepts microseconds
    }

    /**
     * Gets the current retry configuration.
     *
     * @return array<string, mixed> The retry configuration.
     */
    public function getRetryConfig(): array
    {
        return $this->retryConfig;
    }

    /**
     * Gets the current retry attempt number.
     *
     * @return int The current attempt number.
     */
    public function getCurrentAttempt(): int
    {
        return $this->currentAttempt;
    }
}
