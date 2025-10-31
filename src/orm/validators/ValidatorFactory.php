<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\validators;

use RuntimeException;

/**
 * Factory for creating validator instances.
 *
 * Provides registry of built-in validators and allows registration of custom validators.
 */
class ValidatorFactory
{
    /** @var array<string, class-string<ValidatorInterface>> Registered validators */
    protected static array $validators = [
        'required' => RequiredValidator::class,
        'email' => EmailValidator::class,
        'integer' => IntegerValidator::class,
        'string' => StringValidator::class,
    ];

    /**
     * Create validator instance.
     *
     * @param string|ValidatorInterface $validator Validator name or instance
     *
     * @return ValidatorInterface Validator instance
     * @throws RuntimeException If validator not found
     */
    public static function create(string|ValidatorInterface $validator): ValidatorInterface
    {
        if ($validator instanceof ValidatorInterface) {
            return $validator;
        }

        if (!isset(self::$validators[$validator])) {
            throw new RuntimeException("Validator '{$validator}' not found.");
        }

        $class = self::$validators[$validator];
        if (!is_subclass_of($class, ValidatorInterface::class)) {
            throw new RuntimeException("Validator class '{$class}' does not implement ValidatorInterface.");
        }

        return new $class();
    }

    /**
     * Register a custom validator.
     *
     * @param string $name Validator name
     * @param class-string<ValidatorInterface> $class Validator class
     */
    public static function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, ValidatorInterface::class)) {
            throw new RuntimeException("Validator class '{$class}' must implement ValidatorInterface.");
        }

        self::$validators[$name] = $class;
    }

    /**
     * Check if validator is registered.
     *
     * @param string $name Validator name
     *
     * @return bool True if registered
     */
    public static function isRegistered(string $name): bool
    {
        return isset(self::$validators[$name]);
    }
}
