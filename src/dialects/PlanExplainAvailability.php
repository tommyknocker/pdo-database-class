<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

/**
 * Whether a dialect can collect an estimated execution plan for query inspection.
 */
final class PlanExplainAvailability
{
    /**
     * @param list<string> $unavailableReasons Shown when {@see $collectPlan} is false
     * @param list<string> $caveats Shown when a plan was collected but has dialect limits
     */
    public function __construct(
        public readonly bool $collectPlan,
        public readonly array $unavailableReasons = [],
        public readonly array $caveats = [],
        public readonly bool $realPlan = true,
        public readonly bool $boundExplain = true,
    ) {
    }

    /**
     * @return array{real_plan: bool, bound_explain: bool}
     */
    public function contextCapabilities(): array
    {
        return [
            'real_plan' => $this->realPlan,
            'bound_explain' => $this->boundExplain,
        ];
    }

    /**
     * @param list<string> $caveats
     */
    public static function available(array $caveats = [], bool $realPlan = true, bool $boundExplain = true): self
    {
        return new self(true, [], $caveats, $realPlan, $boundExplain);
    }

    /**
     * @param list<string> $reasons
     */
    public static function unavailable(array $reasons, bool $realPlan = false, bool $boundExplain = false): self
    {
        return new self(false, $reasons, [], $realPlan, $boundExplain);
    }
}
