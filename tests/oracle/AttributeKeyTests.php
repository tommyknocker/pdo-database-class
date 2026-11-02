<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\orm\Model;

final class AttributeKeyTests extends BaseOracleTestCase
{
    public function testLowerCaseNamesFindUpperCaseColumnsAndStayFoundAcrossChanges(): void
    {
        $model = new class () extends Model {
            public static function tableName(): string
            {
                return 'attribute_key_probe';
            }
        };
        $model::setDb(self::$db);
        $model->populate(['ID' => 1, 'USER_NAME' => 'ann']);

        $this->assertSame('ann', $model->user_name);
        $this->assertSame('ann', $model->user_name, 'second read uses the remembered key');
        $model->user_name = 'bob';
        $this->assertSame('bob', $model->USER_NAME, 'a write through the lower-case name changed the real column');
        $this->assertSame(['ID' => 1, 'USER_NAME' => 'bob'], $model->getAttributes());

        unset($model->user_name);
        $this->assertNull($model->user_name);
        $this->assertFalse(isset($model->user_name));

        $model->populate(['USER_NAME' => 'cy']);
        $this->assertSame('cy', $model->user_name);
    }

    public function testAColumnAddedLaterMakesAMixedCaseNameAmbiguousAndNeverPicksOne(): void
    {
        $model = new class () extends Model {
            public static function tableName(): string
            {
                return 'attribute_key_probe';
            }
        };
        $model::setDb(self::$db);
        $model->populate(['STATE' => 'a']);
        $this->assertSame('a', $model->state);

        $model->populate(['State' => 'b']);

        // neither STATE nor State is guessed; the lower-case name matches no column exactly
        $this->assertNull($model->state);
        $this->assertSame('a', $model->STATE);
        $this->assertSame('b', $model->State);
    }
}
