<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;
use tommyknocker\pdodb\exceptions\parsers\ConstraintParser;
use tommyknocker\pdodb\exceptions\strategies\ErrorDetectionStrategyInterface;

/**
 * Factory for creating specialized database exceptions from PDOException.
 *
 * Uses strategy pattern to determine the appropriate specialized exception type
 * based on error codes and messages.
 */
class ExceptionFactory
{
    /** @var ErrorDetectionStrategyInterface[] */
    protected static array $strategies = [];

    protected static bool $initialized = false;

    /**
     * Create a specialized exception from PDOException.
     *
     * @param array<string, mixed> $context
     */
    public static function createFromPdoException(
        PDOException $e,
        string $driver,
        ?string $query = null,
        array $context = []
    ): DatabaseException {
        self::initialize();

        $code = self::extractErrorCode($e, $driver);
        $message = $e->getMessage();

        $strategy = self::findMatchingStrategy($code, $message);
        $exceptionClass = $strategy->getExceptionClass();

        return self::createException(
            $exceptionClass,
            $message,
            $code,
            $e,
            $driver,
            $query,
            $context
        );
    }

    /**
     * Initialize the error detection strategies.
     */
    protected static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$strategies = [
            new strategies\ConstraintViolationStrategy(),
            new strategies\AuthenticationStrategy(),
            new strategies\TimeoutStrategy(),
            new strategies\ResourceStrategy(),
            new strategies\TransactionStrategy(),
            new strategies\ConnectionStrategy(),
            new strategies\QueryStrategy(), // Default fallback
        ];

        // Sort by priority (highest first)
        usort(self::$strategies, fn ($a, $b) => $b->getPriority() <=> $a->getPriority());

        self::$initialized = true;
    }

    /**
     * Extract error code from PDOException, handling driver-specific cases.
     */
    protected static function extractErrorCode(PDOException $e, string $driver): string
    {
        // For PostgreSQL, get the SQLSTATE from errorInfo if available
        if ($driver === 'pgsql' && isset($e->errorInfo[0])) {
            return $e->errorInfo[0];
        }

        return (string) $e->getCode();
    }

    /**
     * Find the first strategy that matches the error code and message.
     */
    protected static function findMatchingStrategy(string $code, string $message): ErrorDetectionStrategyInterface
    {
        $messageLower = strtolower($message);

        foreach (self::$strategies as $strategy) {
            if ($strategy->isMatch($code, $messageLower)) {
                return $strategy;
            }
        }

        // If no strategy matches, return the QueryStrategy as fallback
        return new strategies\QueryStrategy();
    }

    /**
     * Create the appropriate exception instance.
     *
     * @param array<string, mixed> $context
     */
    protected static function createException(
        string $exceptionClass,
        string $message,
        string $code,
        PDOException $e,
        string $driver,
        ?string $query,
        array $context
    ): DatabaseException {
        // Handle special cases that need additional parsing
        if ($exceptionClass === ConstraintViolationException::class) {
            return self::createConstraintException($e, $driver, $query, $context, $code, $message);
        }

        /** @var DatabaseException $exception */
        $exception = new $exceptionClass($message, $code, $e, $driver, $query, $context);
        return $exception;
    }

    /**
     * Create a constraint violation exception with parsed details.
     *
     * @param array<string, mixed> $context
     */
    protected static function createConstraintException(
        PDOException $e,
        string $driver,
        ?string $query,
        array $context,
        string $code,
        string $message
    ): ConstraintViolationException {
        $parser = new ConstraintParser();
        $details = $parser->parse($message);

        return new ConstraintViolationException(
            $message,
            $code,
            $e,
            $driver,
            $query,
            $context,
            $details['constraintName'],
            $details['tableName'],
            $details['columnName']
        );
    }
}
