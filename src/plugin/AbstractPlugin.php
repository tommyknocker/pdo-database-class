<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\plugin;

/**
 * Abstract base class for plugins.
 *
 * Provides default implementation of getName() method.
 * Extend this class for convenience when creating custom plugins.
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * Get plugin name (defaults to class name).
     *
     * Override this method to provide a custom plugin name.
     *
     * @return string Plugin name (defaults to class name)
     */
    public function getName(): string
    {
        return static::class;
    }
}
