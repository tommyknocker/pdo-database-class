<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;

class ParameterManager implements ParameterManagerInterface
{
    /** @var array<string, string|int|float|bool|null> */
    protected array $params = [];

    /** @var int Parameter counter for generating unique param names */
    protected int $paramCounter = 0;

    /**
     * Make parameter name.
     *
     * @param string $name
     *
     * @return string
     */
    public function makeParam(string $name): string
    {
        $base = preg_replace('/[^a-z0-9_]/i', '_', $name) ?: 'p';
        $this->paramCounter++;
        return ':' . $base . '_' . $this->paramCounter;
    }

    /**
     * Create parameter and register it in params array.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return string The placeholder name
     */
    public function addParam(string $name, mixed $value): string
    {
        $ph = $this->makeParam($name);
        $this->params[$ph] = $value;
        return $ph;
    }

    /**
     * Set parameter directly.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return static
     */
    public function setParam(string $name, mixed $value): static
    {
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * Merge subquery parameters.
     *
     * @param array<string, string|int|float|bool|null> $subParams
     * @param string $prefix
     *
     * @return array<string, string>
     */
    public function mergeSubParams(array $subParams, string $prefix = 'sub'): array
    {
        $map = [];
        foreach ($subParams as $old => $val) {
            $key = ltrim($old, ':');
            $new = ':' . $prefix . '_' . $key . '_' . count($this->params);
            $this->params[$new] = $val;
            $map[$old] = $new;
        }
        return $map;
    }

    /**
     * Replace placeholders in SQL.
     *
     * @param string $sql
     * @param array<string, string> $map
     *
     * @return string
     */
    public function replacePlaceholdersInSql(string $sql, array $map): string
    {
        if (empty($map)) {
            return $sql;
        }
        uksort($map, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        return strtr($sql, $map);
    }

    /**
     * Normalize parameters.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int|string, string|int|float|bool|null>
     */
    public function normalizeParams(array $params): array
    {
        // Check if all keys are sequential integers starting from 0 (positional parameters)
        $keys = array_keys($params);
        if ($keys === range(0, count($keys) - 1)) {
            // Positional parameters - return as is
            return $params;
        }

        // Named parameters - normalize by adding : prefix if needed
        /** @var array<string, string|int|float|bool|null> $out */
        $out = [];
        foreach ($params as $k => $v) {
            if (is_string($k)) {
                $key = !str_starts_with($k, ':') ? ":$k" : $k;
            } else {
                $key = ':param_' . $k;
            }
            $out[$key] = $v;
        }
        return $out;
    }

    /**
     * Get all parameters.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Set parameters.
     *
     * @param array<string, string|int|float|bool|null> $params
     *
     * @return static
     */
    public function setParams(array $params): static
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Clear all parameters.
     *
     * @return static
     */
    public function clearParams(): static
    {
        $this->params = [];
        return $this;
    }
}
