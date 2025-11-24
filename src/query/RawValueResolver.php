<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\CurDateValue;
use tommyknocker\pdodb\helpers\values\CurTimeValue;
use tommyknocker\pdodb\helpers\values\DateOnlyValue;
use tommyknocker\pdodb\helpers\values\DayValue;
use tommyknocker\pdodb\helpers\values\EscapeValue;
use tommyknocker\pdodb\helpers\values\FilterValue;
use tommyknocker\pdodb\helpers\values\FulltextMatchValue;
use tommyknocker\pdodb\helpers\values\GreatestValue;
use tommyknocker\pdodb\helpers\values\GroupConcatValue;
use tommyknocker\pdodb\helpers\values\HourValue;
use tommyknocker\pdodb\helpers\values\IfNullValue;
use tommyknocker\pdodb\helpers\values\ILikeValue;
use tommyknocker\pdodb\helpers\values\IntervalValue;
use tommyknocker\pdodb\helpers\values\JsonContainsValue;
use tommyknocker\pdodb\helpers\values\JsonExistsValue;
use tommyknocker\pdodb\helpers\values\JsonGetValue;
use tommyknocker\pdodb\helpers\values\JsonKeysValue;
use tommyknocker\pdodb\helpers\values\JsonLengthValue;
use tommyknocker\pdodb\helpers\values\JsonPathValue;
use tommyknocker\pdodb\helpers\values\JsonRemoveValue;
use tommyknocker\pdodb\helpers\values\JsonReplaceValue;
use tommyknocker\pdodb\helpers\values\JsonSetValue;
use tommyknocker\pdodb\helpers\values\JsonTypeValue;
use tommyknocker\pdodb\helpers\values\LeastValue;
use tommyknocker\pdodb\helpers\values\LeftValue;
use tommyknocker\pdodb\helpers\values\LikeValue;
use tommyknocker\pdodb\helpers\values\MinuteValue;
use tommyknocker\pdodb\helpers\values\ModValue;
use tommyknocker\pdodb\helpers\values\MonthValue;
use tommyknocker\pdodb\helpers\values\NowValue;
use tommyknocker\pdodb\helpers\values\PadValue;
use tommyknocker\pdodb\helpers\values\PositionValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\RegexpExtractValue;
use tommyknocker\pdodb\helpers\values\RegexpMatchValue;
use tommyknocker\pdodb\helpers\values\RegexpReplaceValue;
use tommyknocker\pdodb\helpers\values\RepeatValue;
use tommyknocker\pdodb\helpers\values\ReverseValue;
use tommyknocker\pdodb\helpers\values\RightValue;
use tommyknocker\pdodb\helpers\values\SecondValue;
use tommyknocker\pdodb\helpers\values\SubstringValue;
use tommyknocker\pdodb\helpers\values\TimeOnlyValue;
use tommyknocker\pdodb\helpers\values\TruncValue;
use tommyknocker\pdodb\helpers\values\WindowFunctionValue;
use tommyknocker\pdodb\helpers\values\YearValue;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;

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
            // LikeValue is handled directly in ConditionBuilder with quoted column
            $value instanceof ILikeValue => $this->resolveRawValue($this->dialect->ilike($value->getValue(), (string)$value->getParams()[0])),
            $value instanceof EscapeValue => $this->connection->quote($value->getValue()) ?: "'" . str_replace("'", "''", $value->getValue()) . "'",
            $value instanceof FulltextMatchValue => $this->resolveFulltextMatchValue($value),
            $value instanceof ConfigValue => $this->dialect->config($value),
            $value instanceof ConcatValue => $this->dialect->concat($value),
            $value instanceof JsonGetValue => $this->dialect->formatJsonGet($value->getColumn(), $value->getPath(), $value->getAsText()),
            $value instanceof JsonLengthValue => $this->dialect->formatJsonLength($value->getColumn(), $value->getPath()),
            $value instanceof JsonKeysValue => $this->dialect->formatJsonKeys($value->getColumn(), $value->getPath()),
            $value instanceof JsonTypeValue => $this->dialect->formatJsonType($value->getColumn(), $value->getPath()),
            $value instanceof JsonPathValue => $this->resolveJsonPathValue($value),
            $value instanceof JsonContainsValue => $this->resolveJsonContainsValue($value),
            $value instanceof JsonExistsValue => $this->dialect->formatJsonExists($value->getColumn(), $value->getPath()),
            $value instanceof JsonSetValue => $this->resolveJsonSetValue($value),
            $value instanceof JsonRemoveValue => $this->resolveJsonRemoveValue($value),
            $value instanceof JsonReplaceValue => $this->resolveJsonReplaceValue($value),
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
            $value instanceof IntervalValue => $this->dialect->formatInterval(
                $value->getExpr(),
                $value->getIntervalValue(),
                $value->getUnit(),
                $value->isAdd()
            ),
            $value instanceof GroupConcatValue => $this->dialect->formatGroupConcat(
                $value->getColumn(),
                $value->getSeparator(),
                $value->isDistinct()
            ),
            $value instanceof TruncValue => $this->dialect->formatTruncate(
                $value->getTruncateValue(),
                $value->getPrecision()
            ),
            $value instanceof PositionValue => $this->dialect->formatPosition(
                $value->getSubstring(),
                $value->getSourceValue()
            ),
            $value instanceof LeftValue => $this->dialect->formatLeft(
                $value->getSourceValue(),
                $value->getLength()
            ),
            $value instanceof RightValue => $this->dialect->formatRight(
                $value->getSourceValue(),
                $value->getLength()
            ),
            $value instanceof RepeatValue => $this->dialect->formatRepeat(
                $value->getSourceValue(),
                $value->getCount()
            ),
            $value instanceof ReverseValue => $this->dialect->formatReverse(
                $value->getSourceValue()
            ),
            $value instanceof PadValue => $this->dialect->formatPad(
                $value->getSourceValue(),
                $value->getLength(),
                $value->getPadString(),
                $value->isLeft()
            ),
            $value instanceof RegexpMatchValue => $this->dialect->formatRegexpMatch(
                $value->getSourceValue(),
                $value->getPattern()
            ),
            $value instanceof RegexpReplaceValue => $this->dialect->formatRegexpReplace(
                $value->getSourceValue(),
                $value->getPattern(),
                $value->getReplacement()
            ),
            $value instanceof RegexpExtractValue => $this->dialect->formatRegexpExtract(
                $value->getSourceValue(),
                $value->getPattern(),
                $value->getGroupIndex()
            ),
            $value instanceof DateOnlyValue => $this->dialect->formatDateOnly(
                $value->getSourceValue()
            ),
            $value instanceof TimeOnlyValue => $this->dialect->formatTimeOnly(
                $value->getSourceValue()
            ),
            $value instanceof WindowFunctionValue => $this->resolveWindowFunctionValue($value),
            $value instanceof FilterValue => $this->resolveFilterValue($value),
            default => $value,
        };

        if ($result instanceof RawValue) { // Allow nested RawValue resolution
            $value = $result;
        } elseif ($value instanceof LikeValue) {
            // LikeValue returns SQL from formatLike(), need to handle parameters
            $sql = $result;
            $params = ['pattern' => $value->getPattern()];
        } else {
            return $result;
        }

        if (!isset($sql)) {
            $sql = $value->getValue();
        }
        if (!isset($params)) {
            $params = $value->getParams();
        }

        // Normalize DEFAULT keyword for dialect-specific handling
        $sql = $this->dialect->normalizeDefaultValue($sql);

        // Apply dialect-specific normalization
        $sql = $this->dialect->normalizeRawValue($sql);

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
        $expr = $this->dialect->formatJsonGet($value->getColumn(), $value->getPath());
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
                $old = str_starts_with($k, ':') ? $k : ':' . $k;
                $new = $this->parameterManager->makeParam('jsonc_' . ltrim($old, ':'));
                $this->parameterManager->setParam($new, $v);
                $sql = strtr($sql, [$old => $new]);
            }
            return $sql;
        }

        return $res;
    }

    /**
     * Resolve JsonSetValue - build JSON_SET expression.
     *
     * @param JsonSetValue $value
     *
     * @return string
     */
    protected function resolveJsonSetValue(JsonSetValue $value): string
    {
        [$expr, $params] = $this->dialect->formatJsonSet($value->getColumn(), $value->getPath(), $value->getSetValue());

        // Create map of old => new parameter names and merge params
        $paramMap = [];
        foreach ($params as $key => $val) {
            $keyStr = (string)$key;
            $oldParam = str_starts_with($keyStr, ':') ? $keyStr : ':' . $keyStr;
            $newParam = $this->parameterManager->makeParam('jsonset_' . ltrim($oldParam, ':'));
            $paramMap[$oldParam] = $newParam;
            $this->parameterManager->setParam($newParam, $val);
        }

        return strtr($expr, $paramMap);
    }

    /**
     * Resolve JsonRemoveValue - build JSON_REMOVE expression.
     *
     * @param JsonRemoveValue $value
     *
     * @return string
     */
    protected function resolveJsonRemoveValue(JsonRemoveValue $value): string
    {
        return $this->dialect->formatJsonRemove($value->getColumn(), $value->getPath());
    }

    /**
     * Resolve JsonReplaceValue - build JSON_REPLACE expression.
     *
     * @param JsonReplaceValue $value
     *
     * @return string
     */
    protected function resolveJsonReplaceValue(JsonReplaceValue $value): string
    {
        [$expr, $params] = $this->dialect->formatJsonReplace($value->getColumn(), $value->getPath(), $value->getReplaceValue());

        // Create map of old => new parameter names and merge params
        $paramMap = [];
        foreach ($params as $key => $val) {
            $keyStr = (string)$key;
            $oldParam = str_starts_with($keyStr, ':') ? $keyStr : ':' . $keyStr;
            $newParam = $this->parameterManager->makeParam('jsonreplace_' . ltrim($oldParam, ':'));
            $paramMap[$oldParam] = $newParam;
            $this->parameterManager->setParam($newParam, $val);
        }

        return strtr($expr, $paramMap);
    }

    /**
     * Resolve FulltextMatchValue - build full-text search expression.
     *
     * @param FulltextMatchValue $value
     *
     * @return string
     */
    protected function resolveFulltextMatchValue(FulltextMatchValue $value): string
    {
        $columns = $value->getColumns();
        $searchTerm = $value->getSearchTerm();
        $mode = $value->getMode();
        $withQueryExpansion = $value->isWithQueryExpansion();

        $res = $this->dialect->formatFulltextMatch($columns, $searchTerm, $mode, $withQueryExpansion);

        if (is_array($res)) {
            [$sql, $params] = $res;
            foreach ($params as $k => $v) {
                $old = str_starts_with($k, ':') ? $k : ':' . $k;
                $new = $this->parameterManager->makeParam('fulltext_' . ltrim($old, ':'));
                $this->parameterManager->setParam($new, $v);
                $sql = strtr($sql, [$old => $new]);
            }
            return $sql;
        }

        return $res;
    }

    /**
     * Resolve WindowFunctionValue - build window function expression.
     *
     * @param WindowFunctionValue $value
     *
     * @return string
     */
    protected function resolveWindowFunctionValue(WindowFunctionValue $value): string
    {
        return $this->dialect->formatWindowFunction(
            $value->getFunction(),
            $value->getArgs(),
            $value->getPartitionBy(),
            $value->getOrderBy(),
            $value->getFrameClause()
        );
    }

    /**
     * Resolve FilterValue - build aggregate function with FILTER clause.
     *
     * @param FilterValue $value
     *
     * @return string
     */
    protected function resolveFilterValue(FilterValue $value): string
    {
        $aggregateFunc = $value->getAggregateFunc();

        // Apply dialect-specific normalization to the aggregate function
        // This ensures CAST is converted to TRY_CAST for MSSQL, etc.
        $aggregateFunc = $this->dialect->normalizeRawValue($aggregateFunc);

        if (!$value->hasFilter()) {
            return $aggregateFunc;
        }

        $filterConditions = $value->getFilterConditions();
        $whereParts = [];

        foreach ($filterConditions as $condition) {
            $column = $this->dialect->quoteIdentifier($condition['column']);
            $operator = $condition['operator'];
            $paramKey = ':filter_' . uniqid();

            $this->parameterManager->setParam($paramKey, $condition['value']);
            $whereParts[] = "{$column} {$operator} {$paramKey}";
        }

        $whereClause = implode(' AND ', $whereParts);

        // Check if database supports FILTER clause
        if ($this->dialect->supportsFilterClause()) {
            return "{$aggregateFunc} FILTER (WHERE {$whereClause})";
        }

        // Fallback to CASE WHEN for databases without FILTER support (MySQL)
        return $this->buildCaseFallback($aggregateFunc, $whereClause);
    }

    /**
     * Build CASE WHEN fallback for FILTER clause.
     *
     * @param string $aggregateFunc Aggregate function.
     * @param string $whereClause WHERE condition.
     *
     * @return string
     */
    protected function buildCaseFallback(string $aggregateFunc, string $whereClause): string
    {
        // Extract function name and argument
        if (preg_match('/^(\w+)\((.*)\)$/', $aggregateFunc, $matches)) {
            $func = $matches[1];
            $arg = $matches[2];

            // For COUNT, we need to return 1 or NULL
            if (strtoupper($func) === 'COUNT') {
                return "{$func}(CASE WHEN {$whereClause} THEN 1 END)";
            }

            // For SUM, AVG, etc., use the original argument
            if ($arg === '*') {
                return "{$func}(CASE WHEN {$whereClause} THEN 1 END)";
            }

            return "{$func}(CASE WHEN {$whereClause} THEN {$arg} END)";
        }

        return $aggregateFunc;
    }
}
