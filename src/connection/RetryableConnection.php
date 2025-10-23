<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\DbError;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

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
        
        $this->validateRetryConfig();
    }

    /**
     * Validates the retry configuration parameters.
     *
     * @throws \InvalidArgumentException If configuration is invalid.
     */
    protected function validateRetryConfig(): void
    {
        // Validate enabled flag
        if (!is_bool($this->retryConfig['enabled'])) {
            throw new \InvalidArgumentException('retry.enabled must be a boolean');
        }

        // Validate max_attempts
        if (!is_int($this->retryConfig['max_attempts']) || $this->retryConfig['max_attempts'] < 1) {
            throw new \InvalidArgumentException('retry.max_attempts must be a positive integer');
        }

        if ($this->retryConfig['max_attempts'] > 100) {
            throw new \InvalidArgumentException('retry.max_attempts cannot exceed 100');
        }

        // Validate delay_ms
        if (!is_int($this->retryConfig['delay_ms']) || $this->retryConfig['delay_ms'] < 0) {
            throw new \InvalidArgumentException('retry.delay_ms must be a non-negative integer');
        }

        if ($this->retryConfig['delay_ms'] > 300000) { // 5 minutes
            throw new \InvalidArgumentException('retry.delay_ms cannot exceed 300000ms (5 minutes)');
        }

        // Validate backoff_multiplier
        if (!is_numeric($this->retryConfig['backoff_multiplier']) || $this->retryConfig['backoff_multiplier'] < 1.0) {
            throw new \InvalidArgumentException('retry.backoff_multiplier must be a number >= 1.0');
        }

        if ($this->retryConfig['backoff_multiplier'] > 10.0) {
            throw new \InvalidArgumentException('retry.backoff_multiplier cannot exceed 10.0');
        }

        // Validate max_delay_ms
        if (!is_int($this->retryConfig['max_delay_ms']) || $this->retryConfig['max_delay_ms'] < 0) {
            throw new \InvalidArgumentException('retry.max_delay_ms must be a non-negative integer');
        }

        if ($this->retryConfig['max_delay_ms'] > 300000) { // 5 minutes
            throw new \InvalidArgumentException('retry.max_delay_ms cannot exceed 300000ms (5 minutes)');
        }

        // Validate retryable_errors
        if (!is_array($this->retryConfig['retryable_errors'])) {
            throw new \InvalidArgumentException('retry.retryable_errors must be an array');
        }

        foreach ($this->retryConfig['retryable_errors'] as $error) {
            if (!is_int($error) && !is_string($error)) {
                throw new \InvalidArgumentException('retry.retryable_errors must contain only integers or strings');
            }
        }

        // Validate logical constraints
        if ($this->retryConfig['max_delay_ms'] < $this->retryConfig['delay_ms']) {
            throw new \InvalidArgumentException('retry.max_delay_ms cannot be less than retry.delay_ms');
        }
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
        
        // Log initial attempt
        if ($this->logger) {
            $this->logger->info('connection.retry.start', [
                'method' => $method,
                'max_attempts' => $this->retryConfig['max_attempts'],
                'retry_enabled' => $this->retryConfig['enabled']
            ]);
        }
        
        while ($this->currentAttempt < $this->retryConfig['max_attempts']) {
            $this->currentAttempt++;
            
            // Log attempt start
            if ($this->logger) {
                $this->logger->info('connection.retry.attempt', [
                    'method' => $method,
                    'attempt' => $this->currentAttempt,
                    'max_attempts' => $this->retryConfig['max_attempts']
                ]);
            }
            
            try {
                $result = parent::$method(...$args);
                
                // Log successful attempt
                if ($this->logger) {
                    $this->logger->info('connection.retry.success', [
                        'method' => $method,
                        'attempt' => $this->currentAttempt,
                        'total_attempts' => $this->currentAttempt
                    ]);
                }
                
                return $result;
            } catch (PDOException $e) {
                // Convert PDOException to specialized exception
                $dbException = ExceptionFactory::createFromPdoException(
                    $e,
                    $this->getDialect()->getDriverName(),
                    $this->getLastQuery(),
                    ['method' => $method, 'attempt' => $this->currentAttempt]
                );
                
                // Log attempt failure
                if ($this->logger) {
                    $this->logger->warning('connection.retry.attempt_failed', [
                        'method' => $method,
                        'attempt' => $this->currentAttempt,
                        'max_attempts' => $this->retryConfig['max_attempts'],
                        'error_code' => $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'driver' => $this->getDialect()->getDriverName(),
                        'exception_type' => $dbException::class,
                        'category' => $dbException->getCategory(),
                        'retryable' => $dbException->isRetryable()
                    ]);
                }
                
                if (!$this->shouldRetry($e)) {
                    if ($this->logger) {
                        $this->logger->error('connection.retry.not_retryable', [
                            'method' => $method,
                            'attempt' => $this->currentAttempt,
                            'error_code' => $e->getCode(),
                            'error_message' => $e->getMessage(),
                            'exception_type' => $dbException::class,
                            'category' => $dbException->getCategory(),
                            'reason' => 'Error not in retryable list'
                        ]);
                    }
                    throw $dbException;
                }
                
                if ($this->currentAttempt >= $this->retryConfig['max_attempts']) {
                    if ($this->logger) {
                        $this->logger->error('connection.retry.exhausted', [
                            'method' => $method,
                            'total_attempts' => $this->currentAttempt,
                            'max_attempts' => $this->retryConfig['max_attempts'],
                            'error_code' => $e->getCode(),
                            'error_message' => $e->getMessage(),
                            'exception_type' => $dbException::class,
                            'category' => $dbException->getCategory(),
                            'final_error' => true
                        ]);
                    }
                    throw $dbException;
                }
                
                // Log retry decision and wait
                if ($this->logger) {
                    $this->logger->info('connection.retry.retrying', [
                        'method' => $method,
                        'attempt' => $this->currentAttempt,
                        'next_attempt' => $this->currentAttempt + 1,
                        'error_code' => $e->getCode(),
                        'retryable' => true
                    ]);
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
        $baseDelay = $this->retryConfig['delay_ms'];
        $multiplier = $this->retryConfig['backoff_multiplier'];
        $attempt = $this->currentAttempt - 1; // Previous attempt number
        
        $calculatedDelay = $baseDelay * pow($multiplier, $attempt);
        $delay = min($calculatedDelay, $this->retryConfig['max_delay_ms']);
        
        // Log delay calculation details
        if ($this->logger) {
            $this->logger->info('connection.retry.wait', [
                'attempt' => $this->currentAttempt,
                'base_delay_ms' => $baseDelay,
                'backoff_multiplier' => $multiplier,
                'calculated_delay_ms' => $calculatedDelay,
                'max_delay_ms' => $this->retryConfig['max_delay_ms'],
                'actual_delay_ms' => $delay,
                'delay_capped' => $calculatedDelay > $this->retryConfig['max_delay_ms']
            ]);
        }
        
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
