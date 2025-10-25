<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ConnectionLogger trait.
 *
 * Provides centralized logging functionality for connection operations.
 * Extracts common logging patterns to reduce code duplication.
 */
trait ConnectionLogger
{
    /** @var LoggerInterface|null Logger instance for query logging */
    protected ?LoggerInterface $logger;

    /**
     * Log the start of an operation.
     *
     * @param string $operation The operation name
     * @param string|null $sql The SQL query (if applicable)
     * @param array<string, mixed> $context Additional context data
     */
    protected function logOperationStart(string $operation, ?string $sql = null, array $context = []): void
    {
        $this->logger?->debug('operation.start', array_merge([
            'operation' => $operation,
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true),
        ], $sql ? ['sql' => $sql] : [], $context));
    }

    /**
     * Log the successful end of an operation.
     *
     * @param string $operation The operation name
     * @param string|null $sql The SQL query (if applicable)
     * @param array<string, mixed> $context Additional context data
     */
    protected function logOperationEnd(string $operation, ?string $sql = null, array $context = []): void
    {
        $this->logger?->debug('operation.end', array_merge([
            'operation' => $operation,
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true),
        ], $sql ? ['sql' => $sql] : [], $context));
    }

    /**
     * Log an operation error.
     *
     * @param string $operation The operation name
     * @param Throwable $exception The exception that occurred
     * @param string|null $sql The SQL query (if applicable)
     * @param array<string, mixed> $context Additional context data
     */
    protected function logOperationError(string $operation, Throwable $exception, ?string $sql = null, array $context = []): void
    {
        $this->logger?->error('operation.error', array_merge([
            'operation' => $operation,
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true),
            'exception' => $exception,
        ], $sql ? ['sql' => $sql] : [], $context));
    }

    /**
     * Log retry-specific information.
     *
     * @param string $level The log level
     * @param string $message The log message
     * @param array<string, mixed> $context Additional context data
     */
    protected function logRetry(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, array_merge([
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true),
        ], $context));
    }
}
