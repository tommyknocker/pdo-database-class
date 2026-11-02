<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/**
 * Tokens a provider reports for one answer, or the sum over several answers. A provider that does not report a figure
 * leaves it null; sums skip null figures rather than counting them as zero.
 */
final class AiUsage
{
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
    ) {
    }

    /**
     * Build from the figures a provider gave; the total is the sum of input and output when only those are known.
     */
    public static function of(mixed $input, mixed $output, mixed $total = null): ?self
    {
        $input = is_numeric($input) ? (int)$input : null;
        $output = is_numeric($output) ? (int)$output : null;
        $total = is_numeric($total) ? (int)$total : null;
        if ($input === null && $output === null && $total === null) {
            return null;
        }
        if ($total === null && $input !== null && $output !== null) {
            $total = $input + $output;
        }

        return new self($input, $output, $total);
    }

    public function plus(self $other): self
    {
        return new self(
            self::sum($this->inputTokens, $other->inputTokens),
            self::sum($this->outputTokens, $other->outputTokens),
            self::sum($this->totalTokens, $other->totalTokens),
        );
    }

    /**
     * @return array{input_tokens: ?int, output_tokens: ?int, total_tokens: ?int}
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }

    private static function sum(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
    }
}
