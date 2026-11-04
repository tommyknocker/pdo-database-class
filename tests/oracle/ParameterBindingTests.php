<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\ParameterBindingScenarios;

/**
 * Parameters are bound with their own PDO type on oracle.
 */
final class ParameterBindingTests extends BaseOracleTestCase
{
    use ParameterBindingScenarios;
}
