<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\ParameterBindingScenarios;

/**
 * Parameters are bound with their own PDO type on sqlite.
 */
final class ParameterBindingTests extends BaseSqliteTestCase
{
    use ParameterBindingScenarios;
}
