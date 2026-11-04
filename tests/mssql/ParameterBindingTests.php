<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\ParameterBindingScenarios;

/**
 * Parameters are bound with their own PDO type on mssql.
 */
final class ParameterBindingTests extends BaseMSSQLTestCase
{
    use ParameterBindingScenarios;
}
