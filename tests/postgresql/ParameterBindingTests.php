<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\tests\shared\ParameterBindingScenarios;

/**
 * Parameters are bound with their own PDO type on postgresql.
 */
final class ParameterBindingTests extends BasePostgreSQLTestCase
{
    use ParameterBindingScenarios;
}
