<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

use tommyknocker\pdodb\orm\Model;

/**
 * English naming helpers for ActiveRecord tables, model class names, and foreign keys.
 *
 * Inflection rules are adapted from common PHP conventions (similar to Yii2 Inflector).
 * Override {@see Model::tableName()} or relation foreignKey when your schema differs.
 */
final class Inflector
{
    /** @var array<string, string> Singular => plural */
    protected const IRREGULAR_PLURALS = [
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'child' => 'children',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'mouse' => 'mice',
        'goose' => 'geese',
        'index' => 'indices',
    ];

    /** @var list<string> */
    protected const UNCOUNTABLE = [
        'news',
        'series',
        'species',
        'sheep',
        'fish',
        'deer',
        'information',
    ];

    public static function camelToSnake(string $name): string
    {
        if ($name === '') {
            return $name;
        }

        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $name);

        return mb_strtolower($snake ?? $name, 'UTF-8');
    }

    public static function snakeToCamel(string $name): string
    {
        $parts = explode('_', $name);
        $result = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $result .= ucfirst(mb_strtolower($part, 'UTF-8'));
        }

        return $result;
    }

    /**
     * Plural table name from a model class basename (PascalCase, no namespace).
     */
    public static function tableize(string $classBasename): string
    {
        $snake = static::camelToSnake($classBasename);
        if ($snake === '') {
            return $snake;
        }

        $parts = explode('_', $snake);
        $lastIndex = count($parts) - 1;
        $parts[$lastIndex] = static::pluralize($parts[$lastIndex]);

        return implode('_', $parts);
    }

    /**
     * Model class basename from a database table name.
     */
    public static function classify(string $tableName): string
    {
        $name = static::snakeToCamel($tableName);
        if ($name === '') {
            return 'Related';
        }

        return static::singularizeClassBasename($name);
    }

    /**
     * Snake-case stem for a default foreign key column ({stem}_id).
     */
    public static function foreignKeyStem(string $modelClass): string
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            return static::camelToSnake(static::classBasename($modelClass));
        }

        /* @var class-string<Model> $modelClass */
        return static::singularizeSnakeTable($modelClass::tableName());
    }

    public static function classBasename(string $className): string
    {
        $pos = strrpos($className, '\\');

        return $pos === false ? $className : substr($className, $pos + 1);
    }

    public static function pluralize(string $word): string
    {
        if ($word === '') {
            return $word;
        }

        $lower = mb_strtolower($word, 'UTF-8');
        if (in_array($lower, static::UNCOUNTABLE, true)) {
            return $word;
        }

        if (isset(static::IRREGULAR_PLURALS[$lower])) {
            return static::preserveWordCase($word, static::IRREGULAR_PLURALS[$lower]);
        }

        if (preg_match('/[^aeiou]y$/i', $word) === 1) {
            return substr($word, 0, -1) . 'ies';
        }

        if (preg_match('/(sh|ch|ss|x|z|us)$/i', $word) === 1) {
            return $word . 'es';
        }

        if (preg_match('/s$/i', $word) === 1) {
            return $word;
        }

        return $word . 's';
    }

    public static function singularize(string $word): string
    {
        if ($word === '') {
            return $word;
        }

        $lower = mb_strtolower($word, 'UTF-8');
        if (in_array($lower, static::UNCOUNTABLE, true)) {
            return $word;
        }

        foreach (static::IRREGULAR_PLURALS as $singular => $plural) {
            if ($lower === $plural) {
                return static::preserveWordCase($word, $singular);
            }
        }

        if (preg_match('/[^aeiou]ies$/i', $word) === 1) {
            return substr($word, 0, -3) . 'y';
        }

        if (preg_match('/(sses|shes|ches|xes|zes|statuses|buses)$/i', $word) === 1) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($word, 's') && !preg_match('/(ss|us|is)$/i', $word) && strlen($word) > 1) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    protected static function singularizeSnakeTable(string $tableName): string
    {
        if ($tableName === '') {
            return $tableName;
        }

        $parts = explode('_', $tableName);
        $lastIndex = count($parts) - 1;
        $parts[$lastIndex] = static::singularize($parts[$lastIndex]);

        return implode('_', $parts);
    }

    protected static function singularizeClassBasename(string $name): string
    {
        $irregular = [
            'People' => 'Person',
            'Men' => 'Man',
            'Women' => 'Woman',
            'Children' => 'Child',
            'Teeth' => 'Tooth',
            'Feet' => 'Foot',
            'Mice' => 'Mouse',
            'Geese' => 'Goose',
            'Indices' => 'Index',
        ];
        foreach ($irregular as $plural => $singular) {
            if (str_ends_with($name, $plural)) {
                return substr($name, 0, -strlen($plural)) . $singular;
            }
        }

        if (in_array($name, ['News', 'Series', 'Species'], true)) {
            return $name;
        }

        if (preg_match('/[^aeiou]ies$/i', $name) === 1) {
            return substr($name, 0, -3) . 'y';
        }

        if (preg_match('/(sses|shes|ches|xes|zes|statuses|buses)$/i', $name) === 1) {
            return substr($name, 0, -2);
        }

        if (str_ends_with($name, 's') && !preg_match('/(ss|us|is)$/i', $name) && strlen($name) > 1) {
            return substr($name, 0, -1);
        }

        return $name;
    }

    protected static function preserveWordCase(string $original, string $replacement): string
    {
        if ($original === mb_strtoupper($original, 'UTF-8')) {
            return mb_strtoupper($replacement, 'UTF-8');
        }

        if ($original === ucfirst(mb_strtolower($original, 'UTF-8'))) {
            return ucfirst($replacement);
        }

        return $replacement;
    }
}
