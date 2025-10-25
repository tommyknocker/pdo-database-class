<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\ConcatValue;
use tommyknocker\pdodb\helpers\ConfigValue;
use tommyknocker\pdodb\helpers\CurDateValue;
use tommyknocker\pdodb\helpers\CurTimeValue;
use tommyknocker\pdodb\helpers\DayValue;
use tommyknocker\pdodb\helpers\EscapeValue;
use tommyknocker\pdodb\helpers\GreatestValue;
use tommyknocker\pdodb\helpers\HourValue;
use tommyknocker\pdodb\helpers\IfNullValue;
use tommyknocker\pdodb\helpers\ILikeValue;
use tommyknocker\pdodb\helpers\JsonContainsValue;
use tommyknocker\pdodb\helpers\JsonExistsValue;
use tommyknocker\pdodb\helpers\JsonGetValue;
use tommyknocker\pdodb\helpers\JsonKeysValue;
use tommyknocker\pdodb\helpers\JsonLengthValue;
use tommyknocker\pdodb\helpers\JsonPathValue;
use tommyknocker\pdodb\helpers\JsonTypeValue;
use tommyknocker\pdodb\helpers\LeastValue;
use tommyknocker\pdodb\helpers\MinuteValue;
use tommyknocker\pdodb\helpers\ModValue;
use tommyknocker\pdodb\helpers\MonthValue;
use tommyknocker\pdodb\helpers\NowValue;
use tommyknocker\pdodb\helpers\RawValue;
use tommyknocker\pdodb\helpers\SecondValue;
use tommyknocker\pdodb\helpers\SubstringValue;
use tommyknocker\pdodb\helpers\YearValue;

class RawValueResolver
{
    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected ParameterManagerInterface $parameterManager;

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->parameterManager = $parameterManager;
    }

    /**
     * Resolve RawValue instances — return dialect-specific NOW() when NowValue provided.
     * Binds any parameters from RawValue into $this->params.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function resolveRawValue(string|RawValue $value): string
    {
        if (!$value instanceof RawValue) {
            return $value;
        }
        $result = match (true) {
            $value instanceof NowValue => $this->dialect->now($value->getValue(), $value->getAsTimestamp()),
            $value instanceof ILikeValue => $this->resolveRawValue($this->dialect->ilike($value->getValue(), (string)$value->getParams()[0])),
            $value instanceof EscapeValue => $this->connection->quote($value->getValue()) ?: "'" . addslashes((string)$value->getValue()) . "'",
            $value instanceof ConfigValue => $this->dialect->config($value),
            $value instanceof ConcatValue => $this->dialect->concat($value),
            $value instanceof JsonGetValue => $this->dialect->formatJsonGet($value->getColumn(), $value->getPath(), $value->getAsText()),
            $value instanceof JsonLengthValue => $this->dialect->formatJsonLength($value->getColumn(), $value->getPath()),
            $value instanceof JsonKeysValue => $this->dialect->formatJsonKeys($value->getColumn(), $value->getPath()),
            $value instanceof JsonTypeValue => $this->dialect->formatJsonType($value->getColumn(), $value->getPath()),
            $value instanceof JsonPathValue => $this->resolveJsonPathValue($value),
            $value instanceof JsonContainsValue => $this->resolveJsonContainsValue($value),
            $value instanceof JsonExistsValue => $this->dialect->formatJsonExists($value->getColumn(), $value->getPath()),
            $value instanceof IfNullValue => $this->dialect->formatIfNull($value->getExpr(), $value->getDefaultValue()),
            $value instanceof GreatestValue => $this->dialect->formatGreatest($value->getValues()),
            $value instanceof LeastValue => $this->dialect->formatLeast($value->getValues()),
            $value instanceof SubstringValue => $this->dialect->formatSubstring($value->getSource(), $value->getStart(), $value->getLength()),
            $value instanceof ModValue => $this->dialect->formatMod($value->getDividend(), $value->getDivisor()),
            $value instanceof CurDateValue => $this->dialect->formatCurDate(),
            $value instanceof CurTimeValue => $this->dialect->formatCurTime(),
            $value instanceof YearValue => $this->dialect->formatYear($value->getSource()),
            $value instanceof MonthValue => $this->dialect->formatMonth($value->getSource()),
            $value instanceof DayValue => $this->dialect->formatDay($value->getSource()),
            $value instanceof HourValue => $this->dialect->formatHour($value->getSource()),
            $value instanceof MinuteValue => $this->dialect->formatMinute($value->getSource()),
            $value instanceof SecondValue => $this->dialect->formatSecond($value->getSource()),
            default => $value,
        };

        if ($result instanceof RawValue) { // Allow nested RawValue resolution
            $value = $result;
        } else {
            return $result;
        }

        $sql = $value->getValue();
        $params = $value->getParams();

        if (empty($params)) {
            return $sql;
        }

        // Create map of old => new parameter names and merge params
        $paramMap = [];
        foreach ($params as $key => $val) {
            // Ensure parameter name starts with :
            $keyStr = (string)$key;
            $oldParam = str_starts_with($keyStr, ':') ? $keyStr : ':' . $keyStr;
            // Create new unique parameter name
            $newParam = $this->parameterManager->makeParam('raw_' . ltrim($oldParam, ':'));
            $paramMap[$oldParam] = $newParam;
            // Add parameter directly to avoid double makeParam call
            $this->parameterManager->setParam($newParam, $val);
        }

        // Replace old parameter names with new ones in SQL
        return strtr($sql, $paramMap);
    }

    /**
     * Resolve JsonPathValue - build comparison expression.
     *
     * @param JsonPathValue $value
     *
     * @return string
     */
    protected function resolveJsonPathValue(JsonPathValue $value): string
    {
        $expr = $this->dialect->formatJsonGet($value->getColumn(), $value->getPath(), true);
        $compareValue = $value->getCompareValue();

        if ($compareValue instanceof RawValue) {
            $right = $this->resolveRawValue($compareValue);
            return "{$expr} {$value->getOperator()} {$right}";
        }

        $ph = $this->parameterManager->addParam('jsonpath_' . $value->getColumn(), $compareValue);
        return "{$expr} {$value->getOperator()} {$ph}";
    }

    /**
     * Resolve JsonContainsValue - build contains check expression.
     *
     * @param JsonContainsValue $value
     *
     * @return string
     */
    protected function resolveJsonContainsValue(JsonContainsValue $value): string
    {
        $res = $this->dialect->formatJsonContains($value->getColumn(), $value->getSearchValue(), $value->getPath());

        if (is_array($res)) {
            [$sql, $params] = $res;
            foreach ($params as $k => $v) {
                $old = strpos($k, ':') === 0 ? $k : ':' . $k;
                $new = $this->parameterManager->makeParam('jsonc_' . ltrim($old, ':'));
                $this->parameterManager->setParam($new, $v);
                $sql = strtr($sql, [$old => $new]);
            }
            return $sql;
        }

        return $res;
    }
}