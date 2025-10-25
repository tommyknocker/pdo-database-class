<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for boolean values.
 */
trait BooleanHelpersTrait
{
    /**
     * Returns a RawValue instance representing SQL TRUE.
     *
     * @return RawValue The RawValue instance for TRUE.
     */
    public static function true(): RawValue
    {
        return new RawValue('TRUE');
    }

    /**
     * Returns a RawValue instance representing SQL FALSE.
     *
     * @return RawValue The RawValue instance for FALSE.
     */
    public static function false(): RawValue
    {
        return new RawValue('FALSE');
    }

    /**
     * Returns a RawValue instance representing SQL DEFAULT.
     *
     * @return RawValue The RawValue instance for DEFAULT.
     */
    public static function default(): RawValue
    {
        return new RawValue('DEFAULT');
    }
}
