<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\tests\shared\ParameterBindingScenarios;

/**
 * Parameters are bound with their own PDO type on mysql.
 */
final class ParameterBindingTests extends BaseMySQLTestCase
{
    use ParameterBindingScenarios;
}
