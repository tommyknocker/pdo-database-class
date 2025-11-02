<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

use tommyknocker\pdodb\helpers\traits\AggregateHelpersTrait;
use tommyknocker\pdodb\helpers\traits\BooleanHelpersTrait;
use tommyknocker\pdodb\helpers\traits\ComparisonHelpersTrait;
use tommyknocker\pdodb\helpers\traits\ConditionalHelpersTrait;
use tommyknocker\pdodb\helpers\traits\CoreHelpersTrait;
use tommyknocker\pdodb\helpers\traits\DateTimeHelpersTrait;
use tommyknocker\pdodb\helpers\traits\ExportHelpersTrait;
use tommyknocker\pdodb\helpers\traits\FulltextSearchHelpersTrait;
use tommyknocker\pdodb\helpers\traits\JsonHelpersTrait;
use tommyknocker\pdodb\helpers\traits\NullHelpersTrait;
use tommyknocker\pdodb\helpers\traits\NumericHelpersTrait;
use tommyknocker\pdodb\helpers\traits\StringHelpersTrait;
use tommyknocker\pdodb\helpers\traits\TypeHelpersTrait;
use tommyknocker\pdodb\helpers\traits\WindowHelpersTrait;
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
use tommyknocker\pdodb\helpers\values\JsonTypeValue;
use tommyknocker\pdodb\helpers\values\LeastValue;
use tommyknocker\pdodb\helpers\values\LeftValue;
use tommyknocker\pdodb\helpers\values\MinuteValue;
use tommyknocker\pdodb\helpers\values\ModValue;
use tommyknocker\pdodb\helpers\values\MonthValue;
use tommyknocker\pdodb\helpers\values\NowValue;
use tommyknocker\pdodb\helpers\values\PadValue;
use tommyknocker\pdodb\helpers\values\PositionValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\RepeatValue;
use tommyknocker\pdodb\helpers\values\ReverseValue;
use tommyknocker\pdodb\helpers\values\RightValue;
use tommyknocker\pdodb\helpers\values\SecondValue;
use tommyknocker\pdodb\helpers\values\SubstringValue;
use tommyknocker\pdodb\helpers\values\TimeOnlyValue;
use tommyknocker\pdodb\helpers\values\TruncValue;
use tommyknocker\pdodb\helpers\values\WindowFunctionValue;
use tommyknocker\pdodb\helpers\values\YearValue;

/**
 * Database helpers facade - delegates to specialized helper traits.
 *
 * Available helper methods organized by category:
 *
 * @method static RawValue raw(string $sql, array<int|string, string|int|float|bool|null> $params = []) Returns a raw SQL value with optional parameters.
 * @method static EscapeValue escape(string $str) Escapes a string for use in SQL query.
 * @method static ConfigValue config(string $key, mixed $value, bool $useEqualSign = true, bool $quoteValue = false) Returns a SET/PRAGMA statement value.
 *
 * Aggregate Functions:
 * @method static FilterValue count(string|RawValue $expr = '*') Returns COUNT expression.
 * @method static FilterValue sum(string|RawValue $column) Returns SUM expression.
 * @method static FilterValue avg(string|RawValue $column) Returns AVG expression.
 * @method static FilterValue min(string|RawValue $column) Returns MIN expression.
 * @method static FilterValue max(string|RawValue $column) Returns MAX expression.
 * @method static GroupConcatValue groupConcat(string|RawValue $column, string $separator = ',', bool $distinct = false) Returns GROUP_CONCAT/STRING_AGG expression.
 * @method static RawValue arrayAgg(string|RawValue $column) Returns ARRAY_AGG expression (PostgreSQL).
 * @method static RawValue jsonArrayAgg(string|RawValue $column) Returns JSON_ARRAYAGG expression.
 *
 * Boolean Values:
 * @method static RawValue true() Returns SQL TRUE.
 * @method static RawValue false() Returns SQL FALSE.
 * @method static RawValue default() Returns SQL DEFAULT.
 *
 * Comparison Operations:
 * @method static RawValue like(string $column, string $pattern) Returns LIKE condition.
 * @method static ILikeValue ilike(string $column, string $pattern) Returns case-insensitive LIKE condition.
 * @method static RawValue not(RawValue $value) Inverses a condition using NOT.
 * @method static RawValue between(string $column, mixed $min, mixed $max) Returns BETWEEN condition.
 * @method static RawValue notBetween(string $column, mixed $min, mixed $max) Returns NOT BETWEEN condition.
 * @method static RawValue in(string $column, array<int, string|int|float|bool|null> $values) Returns IN condition.
 * @method static RawValue notIn(string $column, array<int, string|int|float|bool|null> $values) Returns NOT IN condition.
 *
 * Conditional Logic:
 * @method static RawValue case(array<string, string> $cases, string|null $else = null) Returns CASE statement.
 *
 * Date/Time Operations:
 * @method static NowValue now(?string $diff = null, bool $asTimestamp = false) Returns current timestamp with optional difference.
 * @method static NowValue ts(?string $diff = null) Returns current timestamp as Unix timestamp.
 * @method static CurDateValue curDate() Returns current date.
 * @method static CurTimeValue curTime() Returns current time.
 * @method static DateOnlyValue date(string|RawValue $value) Extracts date part from datetime.
 * @method static TimeOnlyValue time(string|RawValue $value) Extracts time part from datetime.
 * @method static YearValue year(string|RawValue $value) Extracts year from date.
 * @method static MonthValue month(string|RawValue $value) Extracts month from date.
 * @method static DayValue day(string|RawValue $value) Extracts day from date.
 * @method static HourValue hour(string|RawValue $value) Extracts hour from time.
 * @method static MinuteValue minute(string|RawValue $value) Extracts minute from time.
 * @method static SecondValue second(string|RawValue $value) Extracts second from time.
 * @method static IntervalValue addInterval(string|RawValue $expr, string $value, string $unit) Adds interval to date/datetime.
 * @method static IntervalValue subInterval(string|RawValue $expr, string $value, string $unit) Subtracts interval from date/datetime.
 *
 * Export Operations:
 * @method static string toJson(array<array<string, mixed>> $data, int $flags, int $depth) Export array data to JSON format.
 * @method static string toCsv(array<array<string, mixed>> $data, string $delimiter, string $enclosure, string $escapeCharacter) Export array data to CSV format.
 * @method static string toXml(array<array<string, mixed>> $data, string $rootElement, string $itemElement, string $encoding) Export array data to XML format.
 *
 * Full-Text Search:
 * @method static FulltextMatchValue match(string|array<string> $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false) Returns full-text search condition.
 *
 * JSON Operations:
 * @method static JsonPathValue jsonPath(string $column, array<int, string|int>|string $path, string $operator, mixed $value) Returns JSON path comparison condition.
 * @method static JsonContainsValue jsonContains(string $column, mixed $value, array<int, string|int>|string|null $path = null) Returns JSON contains condition.
 * @method static JsonExistsValue jsonExists(string $column, array<int, string|int>|string $path) Returns JSON path existence condition.
 * @method static JsonGetValue jsonGet(string $column, array<int, string|int>|string $path, bool $asText = true) Returns JSON value extraction.
 * @method static JsonGetValue jsonExtract(string $column, array<int, string|int>|string $path, bool $asText = true) Alias for jsonGet().
 * @method static JsonLengthValue jsonLength(string $column, array<int, string|int>|string|null $path = null) Returns JSON length/size.
 * @method static JsonKeysValue jsonKeys(string $column, array<int, string|int>|string|null $path = null) Returns JSON object keys.
 * @method static JsonTypeValue jsonType(string $column, array<int, string|int>|string|null $path = null) Returns JSON value type.
 * @method static string jsonArray(mixed ...$values) Returns JSON-encoded array string.
 * @method static string jsonObject(array<string, mixed> $pairs) Returns JSON-encoded object string.
 *
 * NULL Handling:
 * @method static RawValue null() Returns SQL NULL.
 * @method static RawValue isNull(string $column) Returns IS NULL condition.
 * @method static RawValue isNotNull(string $column) Returns IS NOT NULL condition.
 * @method static RawValue coalesce(...$values) Returns first non-NULL value.
 * @method static IfNullValue ifNull(string $expr, mixed $default) Returns IFNULL/COALESCE for NULL replacement.
 * @method static RawValue nullIf(mixed $expr1, mixed $expr2) Returns NULL if two expressions are equal.
 *
 * Numeric Operations:
 * @method static array<string, string|int|float> inc(int|float $num = 1) Returns increment operation array.
 * @method static array<string, string|int|float> dec(int|float $num = 1) Returns decrement operation array.
 * @method static RawValue abs(string|RawValue $value) Returns absolute value.
 * @method static RawValue round(string|RawValue $value, int $precision = 0) Returns rounded value.
 * @method static ModValue mod(string|RawValue $dividend, string|RawValue $divisor) Returns modulo operation.
 * @method static RawValue ceil(string|RawValue $value) Returns ceiling value.
 * @method static RawValue floor(string|RawValue $value) Returns floor value.
 * @method static RawValue power(string|RawValue $value, string|int|float|RawValue $exponent) Returns power operation.
 * @method static RawValue sqrt(string|RawValue $value) Returns square root.
 * @method static RawValue exp(string|RawValue $value) Returns exponential function.
 * @method static RawValue ln(string|RawValue $value) Returns natural logarithm.
 * @method static RawValue log(string|RawValue $value, string|int|float|RawValue|null $base = null) Returns logarithm.
 * @method static TruncValue trunc(string|RawValue $value, int $precision = 0) Returns truncated value.
 *
 * String Operations:
 * @method static ConcatValue concat(string|int|float|RawValue ...$args) Returns concatenation of values.
 * @method static RawValue upper(string|RawValue $value) Converts string to uppercase.
 * @method static RawValue lower(string|RawValue $value) Converts string to lowercase.
 * @method static RawValue trim(string|RawValue $value) Trims whitespace from both sides.
 * @method static RawValue ltrim(string|RawValue $value) Trims whitespace from left side.
 * @method static RawValue rtrim(string|RawValue $value) Trims whitespace from right side.
 * @method static RawValue length(string|RawValue $value) Returns string length.
 * @method static SubstringValue substring(string|RawValue $value, int $start, ?int $length = null) Returns substring.
 * @method static RawValue replace(string|RawValue $value, string $search, string $replace) Returns string with replacements.
 * @method static LeftValue left(string|RawValue $value, int $length) Returns left part of string.
 * @method static RightValue right(string|RawValue $value, int $length) Returns right part of string.
 * @method static PositionValue position(string|RawValue $substring, string|RawValue $value) Returns position of substring.
 * @method static RepeatValue repeat(string|RawValue $value, int $count) Returns repeated string.
 * @method static ReverseValue reverse(string|RawValue $value) Returns reversed string.
 * @method static PadValue padLeft(string|RawValue $value, int $length, string $padString = ' ') Returns string padded on left.
 * @method static PadValue padRight(string|RawValue $value, int $length, string $padString = ' ') Returns string padded on right.
 * @method static PadValue lpad(string|RawValue $value, int $length, string $padString = ' ') Alias for padLeft().
 * @method static PadValue rpad(string|RawValue $value, int $length, string $padString = ' ') Alias for padRight().
 * @method static RawValue regexpMatch(string|RawValue $value, string $pattern) Returns regexp match result.
 *
 * Type Conversion & Comparison:
 * @method static RawValue cast(mixed $value, string $type) Returns CAST expression.
 * @method static GreatestValue greatest(string|int|float|RawValue ...$values) Returns greatest value.
 * @method static LeastValue least(string|int|float|RawValue ...$values) Returns least value.
 *
 * Window Functions:
 * @method static WindowFunctionValue rowNumber() Returns ROW_NUMBER() window function.
 * @method static WindowFunctionValue rank() Returns RANK() window function.
 * @method static WindowFunctionValue denseRank() Returns DENSE_RANK() window function.
 * @method static WindowFunctionValue ntile(int $buckets) Returns NTILE() window function.
 * @method static WindowFunctionValue lag(string|RawValue $column, int $offset = 1, mixed $default = null) Returns LAG() window function.
 * @method static WindowFunctionValue lead(string|RawValue $column, int $offset = 1, mixed $default = null) Returns LEAD() window function.
 * @method static WindowFunctionValue firstValue(string|RawValue $column) Returns FIRST_VALUE() window function.
 * @method static WindowFunctionValue lastValue(string|RawValue $column) Returns LAST_VALUE() window function.
 * @method static WindowFunctionValue nthValue(string|RawValue $column, int $n) Returns NTH_VALUE() window function.
 * @method static WindowFunctionValue windowAggregate(string $function, string|RawValue $column) Returns aggregate function as window function.
 */
class Db
{
    use AggregateHelpersTrait;
    use BooleanHelpersTrait;
    use ComparisonHelpersTrait;
    use ConditionalHelpersTrait;
    use CoreHelpersTrait;
    use DateTimeHelpersTrait;
    use ExportHelpersTrait;
    use FulltextSearchHelpersTrait;
    use JsonHelpersTrait;
    use NullHelpersTrait;
    use NumericHelpersTrait;
    use StringHelpersTrait;
    use TypeHelpersTrait;
    use WindowHelpersTrait;
}
