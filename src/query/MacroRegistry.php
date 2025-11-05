<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

/**
 * MacroRegistry class.
 *
 * Manages custom query method macros registration and resolution.
 * Provides extensible macro system for adding custom methods to QueryBuilder.
 */
class MacroRegistry
{
    /**
     * @var array<string, callable> Registered macros
     *
     * @phpstan-var array<string, callable(QueryBuilder, mixed...): mixed>
     */
    protected static array $macros = [];

    /**
     * Register a new macro.
     *
     * @param string $name Macro name (method name)
     * @param callable $macro Macro callable that accepts QueryBuilder and optional arguments
     *
     * @phpstan-param callable(QueryBuilder, mixed...): mixed $macro
     */
    public static function register(string $name, callable $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Check if a macro exists.
     *
     * @param string $name Macro name
     *
     * @return bool True if macro exists, false otherwise
     */
    public static function has(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Get a macro by name.
     *
     * @param string $name Macro name
     *
     * @return callable|null Macro callable or null if not found
     *
     * @phpstan-return callable(QueryBuilder, mixed...): mixed|null
     */
    public static function get(string $name): ?callable
    {
        return static::$macros[$name] ?? null;
    }

    /**
     * Remove a macro by name.
     *
     * @param string $name Macro name
     */
    public static function remove(string $name): void
    {
        unset(static::$macros[$name]);
    }

    /**
     * Clear all registered macros.
     */
    public static function clear(): void
    {
        static::$macros = [];
    }

    /**
     * Get all registered macro names.
     *
     * @return array<string> Array of macro names
     */
    public static function getAll(): array
    {
        return array_keys(static::$macros);
    }
}
