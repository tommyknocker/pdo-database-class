<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use stdClass;
use Stringable;

final class SelectExpressionTests extends BaseSharedTestCase
{
    public function testSelectAcceptsStringableExpressions(): void
    {
        self::$db->find()->table('test_coverage')->insert(['value' => 7]);
        $column = new class () implements Stringable {
            public function __toString(): string
            {
                return 'value';
            }
        };

        $row = self::$db->find()->from('test_coverage')->select([$column])->getOne();
        $this->assertSame(7, $row['value']);

        $row = self::$db->find()->from('test_coverage')->select(['total' => $column])->getOne();
        $this->assertSame(7, $row['total']);
    }

    public function testSelectRejectsNonStringableObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$db->find()->from('test_coverage')->select([new stdClass()]);
    }

    public function testSelectRejectsAliasedNonStringableObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$db->find()->from('test_coverage')->select(['total' => new stdClass()]);
    }
}
