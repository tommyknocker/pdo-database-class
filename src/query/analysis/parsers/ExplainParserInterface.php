<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis\parsers;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

/**
 * Interface for parsing EXPLAIN output from different database dialects.
 */
interface ExplainParserInterface
{
    /**
     * Parse EXPLAIN output into structured format.
     *
     * @param array<int, array<string, mixed>> $explainResults Raw EXPLAIN output
     *
     * @return ParsedExplainPlan Parsed execution plan
     */
    public function parse(array $explainResults): ParsedExplainPlan;
}
