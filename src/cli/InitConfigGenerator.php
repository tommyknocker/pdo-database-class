<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Configuration file generator for init command.
 */
class InitConfigGenerator
{
    /**
     * Generate .env file.
     *
     * @param array<string, mixed> $config Database configuration
     * @param array<string, mixed> $structure Project structure
     * @param string $path Output file path
     */
    public static function generateEnv(array $config, array $structure, string $path): void
    {
        $lines = [
            '# Database Configuration',
            'PDODB_DRIVER=' . ($config['driver'] ?? 'mysql'),
        ];

        // Database connection settings
        if (isset($config['host'])) {
            $lines[] = 'PDODB_HOST=' . $config['host'];
        }
        if (isset($config['port'])) {
            $lines[] = 'PDODB_PORT=' . $config['port'];
        }
        if (isset($config['database'])) {
            $lines[] = 'PDODB_DATABASE=' . $config['database'];
        }
        if (isset($config['username'])) {
            $lines[] = 'PDODB_USERNAME=' . $config['username'];
        }
        if (isset($config['password'])) {
            $lines[] = 'PDODB_PASSWORD=' . $config['password'];
        }
        if (isset($config['charset'])) {
            $lines[] = 'PDODB_CHARSET=' . $config['charset'];
        }
        if (isset($config['path'])) {
            $lines[] = 'PDODB_PATH=' . $config['path'];
        }

        $lines[] = '';

        // Cache configuration
        if (isset($config['cache']) && is_array($config['cache']) && ($config['cache']['enabled'] ?? false)) {
            $lines[] = '# Cache Configuration';
            $lines[] = 'PDODB_CACHE_ENABLED=true';
            $cacheType = $config['cache']['type'] ?? 'filesystem';
            $lines[] = 'PDODB_CACHE_TYPE=' . $cacheType;
            $lines[] = 'PDODB_CACHE_TTL=' . ($config['cache']['default_lifetime'] ?? 3600);
            $lines[] = 'PDODB_CACHE_PREFIX=' . ($config['cache']['prefix'] ?? 'pdodb_');

            if ($cacheType === 'filesystem' && isset($config['cache']['directory'])) {
                $lines[] = 'PDODB_CACHE_DIRECTORY=' . $config['cache']['directory'];
            } elseif ($cacheType === 'redis') {
                $lines[] = 'PDODB_CACHE_REDIS_HOST=' . ($config['cache']['host'] ?? '127.0.0.1');
                $lines[] = 'PDODB_CACHE_REDIS_PORT=' . ($config['cache']['port'] ?? 6379);
                $lines[] = 'PDODB_CACHE_REDIS_DATABASE=' . ($config['cache']['database'] ?? 0);
                if (isset($config['cache']['password'])) {
                    $lines[] = 'PDODB_CACHE_REDIS_PASSWORD=' . $config['cache']['password'];
                }
            } elseif ($cacheType === 'memcached' && isset($config['cache']['servers'])) {
                $servers = [];
                foreach ($config['cache']['servers'] as $server) {
                    if (is_array($server) && count($server) >= 2) {
                        $servers[] = $server[0] . ':' . $server[1];
                    }
                }
                if (!empty($servers)) {
                    $lines[] = 'PDODB_CACHE_MEMCACHED_SERVERS=' . implode(',', $servers);
                }
            }
            $lines[] = '';
        }

        // AI configuration
        if (isset($config['ai']) && is_array($config['ai'])) {
            $lines[] = '# AI Configuration';
            if (isset($config['ai']['provider'])) {
                $lines[] = 'PDODB_AI_PROVIDER=' . $config['ai']['provider'];
            }

            $providers = ['openai', 'anthropic', 'google', 'microsoft', 'deepseek', 'yandex', 'ollama'];
            foreach ($providers as $provider) {
                $keyName = $provider . '_key';
                if (isset($config['ai'][$keyName])) {
                    $lines[] = 'PDODB_AI_' . strtoupper($provider) . '_KEY=' . $config['ai'][$keyName];
                }
            }

            // Yandex folder ID
            if (isset($config['ai']['providers']['yandex']['folder_id'])) {
                $lines[] = 'PDODB_AI_YANDEX_FOLDER_ID=' . $config['ai']['providers']['yandex']['folder_id'];
            }

            if (isset($config['ai']['ollama_url'])) {
                $lines[] = 'PDODB_AI_OLLAMA_URL=' . $config['ai']['ollama_url'];
            }

            // Provider-specific settings
            if (isset($config['ai']['providers']) && is_array($config['ai']['providers'])) {
                foreach ($config['ai']['providers'] as $provider => $settings) {
                    if (is_array($settings)) {
                        if (isset($settings['model'])) {
                            $lines[] = 'PDODB_AI_' . strtoupper($provider) . '_MODEL=' . $settings['model'];
                        }
                    }
                }
            }
            $lines[] = '';
        }

        // Project paths
        if (!empty($structure)) {
            $lines[] = '# Project Paths';
            if (isset($structure['migrations'])) {
                $lines[] = 'PDODB_MIGRATION_PATH=' . $structure['migrations'];
            }
            if (isset($structure['models'])) {
                $lines[] = 'PDODB_MODEL_PATH=' . $structure['models'];
            }
            if (isset($structure['repositories'])) {
                $lines[] = 'PDODB_REPOSITORY_PATH=' . $structure['repositories'];
            }
            if (isset($structure['services'])) {
                $lines[] = 'PDODB_SERVICE_PATH=' . $structure['services'];
            }
            if (isset($structure['seeds'])) {
                $lines[] = 'PDODB_SEED_PATH=' . $structure['seeds'];
            }
        }

        $content = implode("\n", $lines) . "\n";
        file_put_contents($path, $content);
    }

    /**
     * Generate config/db.php file.
     *
     * @param array<string, mixed> $config Database configuration
     * @param string $path Output file path
     */
    public static function generateConfigPhp(array $config, string $path): void
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'return [',
        ];

        // Generate config array
        $configArray = static::buildConfigArray($config);
        $indented = static::arrayToString($configArray, 1);
        $lines[] = $indented;

        $lines[] = '];';
        $lines[] = '';

        $content = implode("\n", $lines);
        file_put_contents($path, $content);
    }

    /**
     * Build configuration array from config data.
     *
     * @param array<string, mixed> $config Configuration data
     *
     * @return array<string, mixed>
     */
    protected static function buildConfigArray(array $config): array
    {
        $result = [];

        // Check if multiple connections
        if (isset($config['connections']) && is_array($config['connections'])) {
            $result['default'] = $config['default'] ?? array_key_first($config['connections']);
            $result['connections'] = [];
            foreach ($config['connections'] as $name => $connConfig) {
                $result['connections'][$name] = static::buildSingleConnectionArray($connConfig);
            }
            return $result;
        }

        // Single connection
        return static::buildSingleConnectionArray($config);
    }

    /**
     * Build single connection configuration array.
     *
     * @param array<string, mixed> $config Configuration data
     *
     * @return array<string, mixed>
     */
    protected static function buildSingleConnectionArray(array $config): array
    {
        $result = [];

        // Basic connection settings
        if (isset($config['driver'])) {
            $result['driver'] = $config['driver'];
        }
        if (isset($config['host'])) {
            $result['host'] = $config['host'];
        }
        if (isset($config['port'])) {
            $result['port'] = $config['port'];
        }
        if (isset($config['database'])) {
            $result['database'] = $config['database'];
        }
        // All dialects (except SQLite) require 'dbname' parameter for DSN
        // Set from 'database' if not explicitly provided
        if (isset($config['dbname'])) {
            $result['dbname'] = $config['dbname'];
        } elseif (isset($config['database']) && ($config['driver'] ?? '') !== 'sqlite') {
            $result['dbname'] = $config['database'];
        }
        if (isset($config['username'])) {
            $result['username'] = $config['username'];
        }
        if (isset($config['password'])) {
            $result['password'] = $config['password'];
        }
        if (isset($config['charset'])) {
            $result['charset'] = $config['charset'];
        }
        if (isset($config['path'])) {
            $result['path'] = $config['path'];
        }
        if (isset($config['trust_server_certificate'])) {
            $result['trust_server_certificate'] = $config['trust_server_certificate'];
        }
        if (isset($config['encrypt'])) {
            $result['encrypt'] = $config['encrypt'];
        }

        // Prefix
        if (isset($config['prefix'])) {
            $result['prefix'] = $config['prefix'];
        }

        // Cache configuration
        if (isset($config['cache']) && is_array($config['cache'])) {
            $result['cache'] = $config['cache'];
        }

        // Compilation cache
        if (isset($config['compilation_cache']) && is_array($config['compilation_cache'])) {
            $result['compilation_cache'] = $config['compilation_cache'];
        }

        // Statement pool
        if (isset($config['stmt_pool']) && is_array($config['stmt_pool'])) {
            $result['stmt_pool'] = $config['stmt_pool'];
        }

        // Retry configuration
        if (isset($config['retry']) && is_array($config['retry'])) {
            $result['retry'] = $config['retry'];
        }

        // REGEXP (default true, but can be explicitly set)
        if (isset($config['enable_regexp'])) {
            $result['enable_regexp'] = $config['enable_regexp'];
        }

        // AI configuration
        if (isset($config['ai']) && is_array($config['ai'])) {
            $result['ai'] = $config['ai'];
        }

        return $result;
    }

    /**
     * Convert array to PHP code string with indentation.
     *
     * @param array<string, mixed> $array Array to convert
     * @param int $indentLevel Current indentation level
     *
     * @return string
     */
    protected static function arrayToString(array $array, int $indentLevel = 0): string
    {
        $indent = str_repeat('    ', $indentLevel);
        $lines = [];

        foreach ($array as $key => $value) {
            $keyStr = is_string($key) ? "'{$key}'" : $key;

            if (is_array($value)) {
                $valueStr = "[\n" . static::arrayToString($value, $indentLevel + 1) . "\n{$indent}    ]";
            } elseif (is_string($value)) {
                $valueStr = "'" . addslashes($value) . "'";
            } elseif (is_bool($value)) {
                $valueStr = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $valueStr = 'null';
            } else {
                $valueStr = (string)$value;
            }

            $lines[] = "{$indent}    {$keyStr} => {$valueStr},";
        }

        return implode("\n", $lines);
    }
}
