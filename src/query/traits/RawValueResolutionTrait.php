<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\traits;

use tommyknocker\pdodb\helpers\RawValue;

trait RawValueResolutionTrait
{
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
