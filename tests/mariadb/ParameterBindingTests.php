<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\tests\shared\ParameterBindingScenarios;

/**
 * Parameters are bound with their own PDO type on mariadb.
 */
final class ParameterBindingTests extends BaseMariaDBTestCase
{
    use ParameterBindingScenarios;
}
