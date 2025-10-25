<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for conditional logic.
 */
trait ConditionalHelpersTrait
{
    /**
     * Returns a RawValue instance representing a CASE statement.
     *
     * @param array<string, string> $cases An associative array where keys are WHEN conditions and values are THEN results.
     * @param string|null $else An optional ELSE result.
     *
     * @return RawValue The RawValue instance for the CASE statement.
     */
    public static function case(array $cases, string|null $else = null): RawValue
    {
        $sql = 'CASE';
        foreach ($cases as $when => $then) {
            $sql .= " WHEN $when THEN $then";
        }
        if ($else !== null) {
            $sql .= " ELSE $else";
        }
        $sql .= ' END';

        return new RawValue($sql);
    }
}
