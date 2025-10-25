<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

/**
 * RetryableConnection.
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

    /** @var RetryStrategyInterface Retry strategy */
    protected RetryStrategyInterface $retryStrategy;

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
            'retryable_errors' => [],
            'driver' => $this->getDriverName(),
        ], $retryConfig);

        $this->validateRetryConfig();
        $this->retryStrategy = new ExponentialBackoffRetryStrategy();
    }

    /**
     * Validates the retry configuration parameters.
     *
     * @throws \InvalidArgumentException If configuration is invalid.
     */
    protected function validateRetryConfig(): void
    {
        $validator = new RetryConfigValidator();
        $validator->validate($this->retryConfig);
    }

    /**
     * Executes a SQL statement with retry logic.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     *
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
     *
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
     *
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
     *
     * @return mixed The result of the operation.
     * @throws PDOException If all retry attempts fail.
     */
    protected function retryOperation(string $method, array $args): mixed
    {
        $this->currentAttempt = 0;

        // Log initial attempt
        $this->logRetry('info', 'connection.retry.start', [
            'method' => $method,
            'max_attempts' => $this->retryConfig['max_attempts'],
            'retry_enabled' => $this->retryConfig['enabled'],
        ]);

        while ($this->currentAttempt < $this->retryConfig['max_attempts']) {
            $this->currentAttempt++;

            // Log attempt start
            $this->logRetry('info', 'connection.retry.attempt', [
                'method' => $method,
                'attempt' => $this->currentAttempt,
                'max_attempts' => $this->retryConfig['max_attempts'],
            ]);

            try {
                $result = parent::$method(...$args);

                // Log successful attempt
                $this->logRetry('info', 'connection.retry.success', [
                    'method' => $method,
                    'attempt' => $this->currentAttempt,
                    'total_attempts' => $this->currentAttempt,
                ]);

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
                $this->logRetry('warning', 'connection.retry.attempt_failed', [
                    'method' => $method,
                    'attempt' => $this->currentAttempt,
                    'max_attempts' => $this->retryConfig['max_attempts'],
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage(),
                    'driver' => $this->getDialect()->getDriverName(),
                    'exception_type' => $dbException::class,
                    'category' => $dbException->getCategory(),
                    'retryable' => $dbException->isRetryable(),
                ]);

                if (!$this->retryStrategy->shouldRetry($e, $this->currentAttempt, $this->retryConfig)) {
                    $this->logRetry('error', 'connection.retry.not_retryable', [
                        'method' => $method,
                        'attempt' => $this->currentAttempt,
                        'error_code' => $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'exception_type' => $dbException::class,
                        'category' => $dbException->getCategory(),
                        'reason' => 'Error not in retryable list',
                    ]);

                    throw $dbException;
                }

                if ($this->currentAttempt >= $this->retryConfig['max_attempts']) {
                    $this->logRetry('error', 'connection.retry.exhausted', [
                        'method' => $method,
                        'total_attempts' => $this->currentAttempt,
                        'max_attempts' => $this->retryConfig['max_attempts'],
                        'error_code' => $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'exception_type' => $dbException::class,
                        'category' => $dbException->getCategory(),
                        'final_error' => true,
                    ]);

                    throw $dbException;
                }

                // Log retry decision and wait
                $this->logRetry('info', 'connection.retry.retrying', [
                    'method' => $method,
                    'attempt' => $this->currentAttempt,
                    'next_attempt' => $this->currentAttempt + 1,
                    'error_code' => $e->getCode(),
                    'retryable' => true,
                ]);

                $this->waitBeforeRetry();
            }
        }

        // This should never be reached, but PHP requires a return
        throw new PDOException('Retry logic failed unexpectedly');
    }

    /**
     * Waits before the next retry attempt using exponential backoff.
     */
    protected function waitBeforeRetry(): void
    {
        $delay = $this->retryStrategy->calculateDelay($this->currentAttempt, $this->retryConfig);

        // Log delay calculation details
        $this->logRetry('info', 'connection.retry.wait', [
            'attempt' => $this->currentAttempt,
            'base_delay_ms' => $this->retryConfig['delay_ms'],
            'backoff_multiplier' => $this->retryConfig['backoff_multiplier'],
            'max_delay_ms' => $this->retryConfig['max_delay_ms'],
            'actual_delay_ms' => $delay,
            'delay_capped' => $delay >= $this->retryConfig['max_delay_ms'],
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
