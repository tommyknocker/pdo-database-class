<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\CurDateValue;
use tommyknocker\pdodb\helpers\values\CurTimeValue;
use tommyknocker\pdodb\helpers\values\DayValue;
use tommyknocker\pdodb\helpers\values\HourValue;
use tommyknocker\pdodb\helpers\values\IntervalValue;
use tommyknocker\pdodb\helpers\values\MinuteValue;
use tommyknocker\pdodb\helpers\values\MonthValue;
use tommyknocker\pdodb\helpers\values\NowValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\SecondValue;
use tommyknocker\pdodb\helpers\values\YearValue;

/**
 * Trait for date/time operations.
 */
trait DateTimeHelpersTrait
{
    /**
     * Returns a NowValue instance representing the current timestamp with an optional difference.
     *
     * @param string|null $diff An optional time difference (e.g., '+1 day', '-2 hours').
     * @param bool $asTimestamp Whether to return as Unix timestamp.
     *
     * @return NowValue The NowValue instance.
     */
    public static function now(?string $diff = null, bool $asTimestamp = false): NowValue
    {
        return new NowValue($diff, $asTimestamp);
    }

    /**
     * Returns a NowValue instance representing the current timestamp as a Unix timestamp.
     *
     * @param string|null $diff An optional time difference (e.g., '+1 day', '-2 hours').
     *
     * @return NowValue The NowValue instance representing the timestamp.
     */
    public static function ts(?string $diff = null): NowValue
    {
        return new NowValue($diff, true);
    }

    /**
     * Returns current date (dialect-specific).
     *
     * @return CurDateValue The CurDateValue instance.
     */
    public static function curDate(): CurDateValue
    {
        return new CurDateValue();
    }

    /**
     * Returns current time (dialect-specific).
     *
     * @return CurTimeValue The CurTimeValue instance.
     */
    public static function curTime(): CurTimeValue
    {
        return new CurTimeValue();
    }

    /**
     * Extracts date part from datetime.
     *
     * @param string|RawValue $value The datetime value.
     *
     * @return RawValue The RawValue instance for DATE.
     */
    public static function date(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("DATE($val)");
    }

    /**
     * Extracts time part from datetime.
     *
     * @param string|RawValue $value The datetime value.
     *
     * @return RawValue The RawValue instance for TIME.
     */
    public static function time(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("TIME($val)");
    }

    /**
     * Extracts year from date (dialect-specific).
     *
     * @param string|RawValue $value The date value.
     *
     * @return YearValue The YearValue instance.
     */
    public static function year(string|RawValue $value): YearValue
    {
        return new YearValue($value);
    }

    /**
     * Extracts month from date (dialect-specific).
     *
     * @param string|RawValue $value The date value.
     *
     * @return MonthValue The MonthValue instance.
     */
    public static function month(string|RawValue $value): MonthValue
    {
        return new MonthValue($value);
    }

    /**
     * Extracts day from date (dialect-specific).
     *
     * @param string|RawValue $value The date value.
     *
     * @return DayValue The DayValue instance.
     */
    public static function day(string|RawValue $value): DayValue
    {
        return new DayValue($value);
    }

    /**
     * Extracts hour from time (dialect-specific).
     *
     * @param string|RawValue $value The time value.
     *
     * @return HourValue The HourValue instance.
     */
    public static function hour(string|RawValue $value): HourValue
    {
        return new HourValue($value);
    }

    /**
     * Extracts minute from time (dialect-specific).
     *
     * @param string|RawValue $value The time value.
     *
     * @return MinuteValue The MinuteValue instance.
     */
    public static function minute(string|RawValue $value): MinuteValue
    {
        return new MinuteValue($value);
    }

    /**
     * Extracts second from time (dialect-specific).
     *
     * @param string|RawValue $value The time value.
     *
     * @return SecondValue The SecondValue instance.
     */
    public static function second(string|RawValue $value): SecondValue
    {
        return new SecondValue($value);
    }

    /**
     * Adds an interval to a date/datetime expression (dialect-specific).
     *
     * @param string|RawValue $expr The date/datetime expression.
     * @param string $value Interval value (e.g., "1", "7").
     * @param string $unit Interval unit (e.g., "DAY", "MONTH", "YEAR", "HOUR", "MINUTE", "SECOND").
     *
     * @return IntervalValue The IntervalValue instance.
     */
    public static function addInterval(string|RawValue $expr, string $value, string $unit): IntervalValue
    {
        return new IntervalValue($expr, $value, $unit, true);
    }

    /**
     * Subtracts an interval from a date/datetime expression (dialect-specific).
     *
     * @param string|RawValue $expr The date/datetime expression.
     * @param string $value Interval value (e.g., "1", "7").
     * @param string $unit Interval unit (e.g., "DAY", "MONTH", "YEAR", "HOUR", "MINUTE", "SECOND").
     *
     * @return IntervalValue The IntervalValue instance.
     */
    public static function subInterval(string|RawValue $expr, string $value, string $unit): IntervalValue
    {
        return new IntervalValue($expr, $value, $unit, false);
    }
}
