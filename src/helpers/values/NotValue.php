<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Negation of a value whose SQL depends on the dialect (for example a regular expression match). The inner value is
 * resolved against the connection's dialect, then wrapped in NOT.
 */
class NotValue extends RawValue
{
    public function __construct(protected RawValue $inner)
    {
        parent::__construct('', $inner->getParams());
    }

    public function getInner(): RawValue
    {
        return $this->inner;
    }

    public function getValue(): string
    {
        return 'NOT (' . $this->inner->getValue() . ')';
    }
}
