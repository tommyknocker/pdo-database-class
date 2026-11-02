<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use InvalidArgumentException;
use RuntimeException;
use tommyknocker\pdodb\orm\Model;

/**
 * Names for the model classes stored in the `*_type` column of a polymorphic relation.
 *
 * Without a map the column holds the class name (`App\Models\Post`), which ties the data to the code layout. A map
 * stores a stable alias instead (`post`), so a class can be moved or renamed without a data migration:
 *
 * ```php
 * MorphMap::register(['post' => Post::class, 'video' => Video::class]);
 * ```
 *
 * Call {@see enforce()} to accept aliases only. The `*_type` value comes from the database; enforcing the map means
 * that value can only ever select a class you registered.
 */
final class MorphMap
{
    /** @var array<string, class-string<Model>> alias => class */
    private static array $map = [];

    private static bool $enforced = false;

    /**
     * Register aliases. Later calls add to the earlier ones.
     *
     * @param array<string, class-string<Model>> $map alias => model class
     */
    public static function register(array $map): void
    {
        foreach ($map as $alias => $class) {
            if (!is_a($class, Model::class, true)) {
                throw new InvalidArgumentException("Morph map entry '{$alias}' must name a Model class, got '{$class}'.");
            }
            self::$map[(string)$alias] = $class;
        }
    }

    /**
     * Accept only registered aliases when a type is turned into a class (and refuse to store unregistered classes).
     */
    public static function enforce(bool $enforce = true): void
    {
        self::$enforced = $enforce;
    }

    /**
     * Forget all aliases and the enforcement. Meant for tests.
     */
    public static function flush(): void
    {
        self::$map = [];
        self::$enforced = false;
    }

    /**
     * The value stored in the type column for a model class: its alias, or the class name when it has none.
     *
     * @param class-string<Model>|class-string $class
     */
    public static function alias(string $class): string
    {
        $alias = array_search($class, self::$map, true);
        if ($alias !== false) {
            return (string)$alias;
        }
        if (self::$enforced) {
            throw new RuntimeException("{$class} has no entry in the morph map, which is enforced. Register it with MorphMap::register().");
        }

        return $class;
    }

    /**
     * The model class for a stored type value.
     *
     * @return class-string<Model>
     */
    public static function resolve(string $type): string
    {
        if (isset(self::$map[$type])) {
            return self::$map[$type];
        }
        if (!self::$enforced && is_a($type, Model::class, true)) {
            return $type;
        }

        throw new RuntimeException(
            "Unknown morph type '{$type}'. Register it with MorphMap::register(), or fix the stored value."
        );
    }
}
