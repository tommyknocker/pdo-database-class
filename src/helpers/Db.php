<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

use tommyknocker\pdodb\helpers\traits\AggregateHelpersTrait;
use tommyknocker\pdodb\helpers\traits\BooleanHelpersTrait;
use tommyknocker\pdodb\helpers\traits\ComparisonHelpersTrait;
use tommyknocker\pdodb\helpers\traits\ConditionalHelpersTrait;
use tommyknocker\pdodb\helpers\traits\CoreHelpersTrait;
use tommyknocker\pdodb\helpers\traits\DateTimeHelpersTrait;
use tommyknocker\pdodb\helpers\traits\JsonHelpersTrait;
use tommyknocker\pdodb\helpers\traits\NullHelpersTrait;
use tommyknocker\pdodb\helpers\traits\NumericHelpersTrait;
use tommyknocker\pdodb\helpers\traits\StringHelpersTrait;
use tommyknocker\pdodb\helpers\traits\TypeHelpersTrait;

/**
 * Database helpers facade - delegates to specialized helper traits.
 */
class Db
{
    use AggregateHelpersTrait;
    use BooleanHelpersTrait;
    use ComparisonHelpersTrait;
    use ConditionalHelpersTrait;
    use CoreHelpersTrait;
    use DateTimeHelpersTrait;
    use JsonHelpersTrait;
    use NullHelpersTrait;
    use NumericHelpersTrait;
    use StringHelpersTrait;
    use TypeHelpersTrait;
}
