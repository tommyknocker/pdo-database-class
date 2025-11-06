<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\plugin;

use tommyknocker\pdodb\PdoDb;

/**
 * Plugin interface for extending PdoDb functionality.
 *
 * Plugins allow community to extend PdoDb with custom macros, scopes, event listeners, and more.
 */
interface PluginInterface
{
    /**
     * Register plugin with PdoDb instance.
     *
     * This method is called when plugin is registered via PdoDb::registerPlugin().
     * Use this method to:
     * - Register QueryBuilder macros via QueryBuilder::macro()
     * - Register global scopes via $db->addScope()
     * - Register event listeners via $db->getEventDispatcher()->addListener()
     * - Configure any plugin-specific settings
     *
     * @param PdoDb $db PdoDb instance to register with
     */
    public function register(PdoDb $db): void;

    /**
     * Get plugin name.
     *
     * Plugin name is used for identification and management (hasPlugin, getPlugin, unregisterPlugin).
     * Should be unique across all registered plugins.
     *
     * @return string Plugin name for identification
     */
    public function getName(): string;
}
