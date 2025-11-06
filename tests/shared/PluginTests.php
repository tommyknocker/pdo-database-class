<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Symfony\Component\EventDispatcher\EventDispatcher;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\plugin\AbstractPlugin;
use tommyknocker\pdodb\plugin\PluginInterface;
use tommyknocker\pdodb\query\MacroRegistry;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * PluginTests for plugin system functionality.
 */
final class PluginTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Use self::$db from BaseSharedTestCase
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                status TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clear all macros after each test to avoid interference
        MacroRegistry::clear();
        // Clear all plugins after each test
        $plugins = self::$db->getPlugins();
        foreach (array_keys($plugins) as $name) {
            self::$db->unregisterPlugin($name);
        }
        // Clear all scopes after each test
        $scopes = self::$db->getScopes();
        foreach (array_keys($scopes) as $name) {
            self::$db->removeScope($name);
        }
    }

    public function testRegisterPlugin(): void
    {
        $plugin = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                // Empty plugin
            }
        };

        self::$db->registerPlugin($plugin);

        $this->assertTrue(self::$db->hasPlugin($plugin->getName()));
    }

    public function testHasPluginReturnsFalseForNonExistentPlugin(): void
    {
        $this->assertFalse(self::$db->hasPlugin('nonExistentPlugin'));
    }

    public function testGetPlugin(): void
    {
        $plugin = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                // Empty plugin
            }
        };

        self::$db->registerPlugin($plugin);
        $retrieved = self::$db->getPlugin($plugin->getName());

        $this->assertSame($plugin, $retrieved);
    }

    public function testGetPluginReturnsNullForNonExistentPlugin(): void
    {
        $this->assertNull(self::$db->getPlugin('nonExistentPlugin'));
    }

    public function testGetPlugins(): void
    {
        $plugin1 = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                // Empty plugin
            }
        };

        $plugin2 = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                // Empty plugin
            }
        };

        self::$db->registerPlugin($plugin1);
        self::$db->registerPlugin($plugin2);

        $plugins = self::$db->getPlugins();

        $this->assertCount(2, $plugins);
        $this->assertArrayHasKey($plugin1->getName(), $plugins);
        $this->assertArrayHasKey($plugin2->getName(), $plugins);
    }

    public function testUnregisterPlugin(): void
    {
        $plugin = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                // Empty plugin
            }
        };

        self::$db->registerPlugin($plugin);
        $this->assertTrue(self::$db->hasPlugin($plugin->getName()));

        self::$db->unregisterPlugin($plugin->getName());
        $this->assertFalse(self::$db->hasPlugin($plugin->getName()));
    }

    public function testPluginRegisterIsCalledOnRegistration(): void
    {
        $registered = false;
        $plugin = new class ($registered) implements PluginInterface {
            private bool $registered;

            public function __construct(bool &$registered)
            {
                $this->registered = &$registered;
            }

            public function register(PdoDb $db): void
            {
                $this->registered = true;
            }

            public function getName(): string
            {
                return 'test-plugin';
            }
        };

        self::$db->registerPlugin($plugin);

        $this->assertTrue($registered);
    }

    public function testPluginCanRegisterMacros(): void
    {
        $plugin = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                QueryBuilder::macro('active', function (QueryBuilder $query) {
                    return $query->where('status', 'active');
                });
            }
        };

        self::$db->registerPlugin($plugin);

        $this->assertTrue(QueryBuilder::hasMacro('active'));

        // Test that macro works
        $query = self::$db->find()->table('users');
        $result = $query->active();

        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testPluginCanRegisterScopes(): void
    {
        $plugin = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                $db->addScope('notDeleted', function (QueryBuilder $query) {
                    return $query->whereNull('deleted_at');
                });
            }
        };

        self::$db->registerPlugin($plugin);

        $scopes = self::$db->getScopes();
        $this->assertArrayHasKey('notDeleted', $scopes);

        // Insert test data
        self::$db->find()->table('users')->insert([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
        ]);

        // Test that scope is applied - execute query to trigger scope application
        $query = self::$db->find()->table('users');
        // Execute query to trigger scope application
        $query->get();
        $sql = $query->toSQL();
        $this->assertStringContainsString('deleted_at', $sql['sql']);
        $this->assertStringContainsString('IS NULL', $sql['sql']);
    }

    public function testPluginCanRegisterEventListeners(): void
    {
        $dispatcher = new EventDispatcher();
        self::$db->setEventDispatcher($dispatcher);

        $eventFired = ['value' => false];
        $plugin = new class ($eventFired) extends AbstractPlugin {
            private array $eventFired;

            public function __construct(array &$eventFired)
            {
                $this->eventFired = &$eventFired;
            }

            public function register(PdoDb $db): void
            {
                $dispatcher = $db->getEventDispatcher();
                if ($dispatcher !== null) {
                    $eventFiredRef = &$this->eventFired;
                    $dispatcher->addListener(
                        QueryExecutedEvent::class,
                        function () use (&$eventFiredRef) {
                            $eventFiredRef['value'] = true;
                        }
                    );
                }
            }
        };

        self::$db->registerPlugin($plugin);

        // Execute a query to trigger the event
        self::$db->find()->table('users')->get();

        $this->assertTrue($eventFired['value']);
    }

    public function testMultiplePluginsCanBeRegistered(): void
    {
        $plugin1 = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                QueryBuilder::macro('macro1', function (QueryBuilder $query) {
                    return $query;
                });
            }
        };

        $plugin2 = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                QueryBuilder::macro('macro2', function (QueryBuilder $query) {
                    return $query;
                });
            }
        };

        self::$db->registerPlugin($plugin1);
        self::$db->registerPlugin($plugin2);

        $this->assertTrue(self::$db->hasPlugin($plugin1->getName()));
        $this->assertTrue(self::$db->hasPlugin($plugin2->getName()));
        $this->assertTrue(QueryBuilder::hasMacro('macro1'));
        $this->assertTrue(QueryBuilder::hasMacro('macro2'));
    }

    public function testPluginWithCustomName(): void
    {
        $plugin = new class () implements PluginInterface {
            public function register(PdoDb $db): void
            {
                // Empty plugin
            }

            public function getName(): string
            {
                return 'custom-plugin-name';
            }
        };

        self::$db->registerPlugin($plugin);

        $this->assertTrue(self::$db->hasPlugin('custom-plugin-name'));
        $this->assertSame($plugin, self::$db->getPlugin('custom-plugin-name'));
    }

    public function testPluginCanRegisterMultipleFeatures(): void
    {
        $dispatcher = new EventDispatcher();
        self::$db->setEventDispatcher($dispatcher);

        $macroRegistered = ['value' => false];
        $scopeRegistered = ['value' => false];
        $eventListenerRegistered = ['value' => false];

        $plugin = new class ($macroRegistered, $scopeRegistered, $eventListenerRegistered) extends AbstractPlugin {
            private array $macroRegistered;
            private array $scopeRegistered;
            private array $eventListenerRegistered;

            public function __construct(array &$macroRegistered, array &$scopeRegistered, array &$eventListenerRegistered)
            {
                $this->macroRegistered = &$macroRegistered;
                $this->scopeRegistered = &$scopeRegistered;
                $this->eventListenerRegistered = &$eventListenerRegistered;
            }

            public function register(PdoDb $db): void
            {
                // Register macro
                $macroRef = &$this->macroRegistered;
                QueryBuilder::macro('testMacro', function (QueryBuilder $query) use (&$macroRef) {
                    $macroRef['value'] = true;
                    return $query;
                });

                // Register scope
                $scopeRef = &$this->scopeRegistered;
                $db->addScope('testScope', function (QueryBuilder $query) use (&$scopeRef) {
                    $scopeRef['value'] = true;
                    return $query;
                });

                // Register event listener
                $dispatcher = $db->getEventDispatcher();
                if ($dispatcher !== null) {
                    $eventRef = &$this->eventListenerRegistered;
                    $dispatcher->addListener(
                        QueryExecutedEvent::class,
                        function () use (&$eventRef) {
                            $eventRef['value'] = true;
                        }
                    );
                }
            }
        };

        self::$db->registerPlugin($plugin);

        // Test macro
        self::$db->find()->table('users')->testMacro();
        $this->assertTrue($macroRegistered['value']);

        // Test scope (applied automatically)
        self::$db->find()->table('users')->get();
        $this->assertTrue($scopeRegistered['value']);

        // Test event listener
        $this->assertTrue($eventListenerRegistered['value']);
    }

    public function testUnregisterPluginDoesNotRemoveMacrosAndScopes(): void
    {
        $plugin = new class () extends AbstractPlugin {
            public function register(PdoDb $db): void
            {
                QueryBuilder::macro('testMacro', function (QueryBuilder $query) {
                    return $query;
                });

                $db->addScope('testScope', function (QueryBuilder $query) {
                    return $query;
                });
            }
        };

        self::$db->registerPlugin($plugin);
        $this->assertTrue(QueryBuilder::hasMacro('testMacro'));
        $this->assertArrayHasKey('testScope', self::$db->getScopes());

        self::$db->unregisterPlugin($plugin->getName());

        // Macros and scopes should still exist
        $this->assertTrue(QueryBuilder::hasMacro('testMacro'));
        $this->assertArrayHasKey('testScope', self::$db->getScopes());
    }
}
