<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\RawValue;

class JsonQueryBuilder implements JsonQueryBuilderInterface
{
    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected ParameterManagerInterface $parameterManager;
    protected ConditionBuilderInterface $conditionBuilder;
    protected RawValueResolver $rawValueResolver;

    /** @var array<int, string> */
    protected array $select = [];

    /** @var array<int, string> ORDER BY expressions */
    protected array $order = [];

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ConditionBuilderInterface $conditionBuilder,
        RawValueResolver $rawValueResolver
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->parameterManager = $parameterManager;
        $this->conditionBuilder = $conditionBuilder;
        $this->rawValueResolver = $rawValueResolver;
    }

    /**
     * Add SELECT expression extracting JSON value by path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string|null $alias
     * @param bool $asText
     *
     * @return self
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self
    {
        $expr = $this->dialect->formatJsonGet($col, $path, $asText);
        if ($alias) {
            $this->select[] = $expr . ' AS ' . $this->dialect->quoteIdentifier($alias);
        } else {
            $this->select[] = $expr;
        }
        return $this;
    }

    /**
     * Add WHERE condition comparing JSON value at path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $operator
     * @param mixed $value
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonPath(string $col, array|string $path, string $operator, mixed $value, string $cond = 'AND'): self
    {
        $expr = $this->dialect->formatJsonGet($col, $path, true);

        if ($value instanceof RawValue) {
            $right = $this->resolveRawValue($value);
            $this->conditionBuilder->where($expr . " {$operator} " . $right);
            return $this;
        }

        $ph = $this->parameterManager->addParam('json_' . $col, $value);
        $this->conditionBuilder->whereRaw("{$expr} {$operator} {$ph}");
        return $this;
    }

    /**
     * Add WHERE JSON contains (col contains value).
     *
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonContains(string $col, mixed $value, array|string|null $path = null, string $cond = 'AND'): self
    {
        $res = $this->dialect->formatJsonContains($col, $value, $path);
        if (is_array($res)) {
            [$sql, $params] = $res;
            foreach ($params as $k => $v) {
                $old = str_starts_with($k, ':') ? $k : ':' . $k;
                $new = $this->parameterManager->makeParam('jsonc_' . ltrim($old, ':'));
                $this->parameterManager->setParam($new, $v);
                $sql = strtr($sql, [$old => $new]);
            }
            $this->conditionBuilder->whereRaw($sql);
            return $this;
        }
        $this->conditionBuilder->whereRaw($res);
        return $this;
    }

    /**
     * Update JSON field: set value at path (create missing).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return RawValue
     */
    public function jsonSet(string $col, array|string $path, mixed $value): RawValue
    {
        // ask dialect for expression and parameters
        [$expr, $params] = $this->dialect->formatJsonSet($col, $path, $value);

        // integrate params into RawValue with unique placeholders
        $paramMap = [];
        foreach ($params as $k => $v) {
            $old = str_starts_with($k, ':') ? $k : ':' . $k;
            $new = $this->parameterManager->makeParam('jsonset_' . ltrim($old, ':'));
            $paramMap[$old] = $new;
        }

        $sql = strtr($expr, $paramMap);
        $rawParams = [];
        foreach ($paramMap as $old => $new) {
            $key = ltrim($old, ':');
            if (isset($params[$key])) {
                $rawParams[$new] = $params[$key];
            } elseif (isset($params[$old])) {
                $rawParams[$new] = $params[$old];
            }
        }
        return new RawValue($sql, $rawParams);
    }

    /**
     * Remove JSON path from column (returns RawValue to use in update).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return RawValue
     */
    public function jsonRemove(string $col, array|string $path): RawValue
    {
        $expr = $this->dialect->formatJsonRemove($col, $path);
        return new RawValue($expr);
    }

    /**
     * Add ORDER BY expression based on JSON path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $direction
     *
     * @return self
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self
    {
        $expr = $this->dialect->formatJsonOrderExpr($col, $path);
        $this->order[] = $expr . ' ' . $direction;
        return $this;
    }

    /**
     * Check existence of JSON path (returns boolean condition).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self
    {
        $expr = $this->dialect->formatJsonExists($col, $path);
        $this->conditionBuilder->whereRaw($expr);
        return $this;
    }

    /**
     * Get JSON select expressions.
     *
     * @return array<int, string>
     */
    public function getJsonSelects(): array
    {
        return $this->select;
    }

    /**
     * Get JSON order expressions.
     *
     * @return array<int, string>
     */
    public function getJsonOrders(): array
    {
        return $this->order;
    }

    /**
     * Clear JSON select expressions.
     *
     * @return self
     */
    public function clearJsonSelects(): self
    {
        $this->select = [];
        return $this;
    }

    /**
     * Clear JSON order expressions.
     *
     * @return self
     */
    public function clearJsonOrders(): self
    {
        $this->order = [];
        return $this;
    }

    /**
     * Resolve RawValue instances.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    protected function resolveRawValue(string|RawValue $value): string
    {
        return $this->rawValueResolver->resolveRawValue($value);
    }
}
