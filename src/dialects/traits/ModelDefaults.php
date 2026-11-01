<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectModelInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * Default implementation of the ActiveRecord support part of the dialect ({@see DialectModelInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait ModelDefaults
{
    /**
     * {@inheritDoc}
     */
    public function resolveAttributeKeyForRead(string $name, array $attributes): string
    {
        return array_key_exists($name, $attributes) ? $name : $name;
    }

    /**
     * {@inheritDoc}
     */
    public function requiresDateTimeImmutableCastValidation(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function validateDateTimeImmutableCastConnection(PdoDb $db): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function transformValueForBinding(mixed $value, string $columnName, ?string $table = null): mixed
    {
        // Default: no transformation
        return $value;
    }
}
