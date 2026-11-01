<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\MassAssignmentException;
use tommyknocker\pdodb\orm\Model;

final class MassRulesUser extends Model
{
    public static function tableName(): string
    {
        return 'mass_users';
    }

    public static function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
            ['age', 'integer'],
            ['is_admin', 'integer'],
        ];
    }

    public static function unsafeAttributes(): array
    {
        return ['is_admin'];
    }
}

final class MassExplicitUser extends Model
{
    public static function tableName(): string
    {
        return 'mass_users';
    }

    public static function rules(): array
    {
        return [['name', 'required'], ['age', 'integer']];
    }

    public static function safeAttributes(): array
    {
        return ['name'];
    }
}

final class MassBareUser extends Model
{
    public static function tableName(): string
    {
        return 'mass_users';
    }
}

/**
 * Mass assignment sets only safe attributes unless told otherwise.
 */
final class MassAssignmentTests extends BaseSharedTestCase
{
    private const INPUT = ['name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30, 'is_admin' => 1, 'extra' => 'x'];

    protected function tearDown(): void
    {
        MassRulesUser::preventSilentlyDiscardingAttributes(false);
        parent::tearDown();
    }

    public function testAttributesMentionedInRulesAreSafe(): void
    {
        $user = new MassRulesUser();
        $user->setAttributes(self::INPUT);

        $this->assertSame(['name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30], $user->getAttributes());
    }

    public function testUnsafeAttributesAreValidatedButNeverMassAssigned(): void
    {
        $this->assertContains('is_admin', array_column(MassRulesUser::rules(), 0));
        $this->assertNotContains('is_admin', MassRulesUser::safeAttributeNames());
    }

    public function testExplicitSafeAttributesTakePrecedenceOverRules(): void
    {
        $user = new MassExplicitUser();
        $user->setAttributes(self::INPUT);

        $this->assertSame(['name' => 'Ann'], $user->getAttributes());
    }

    public function testForceFillSetsEveryKey(): void
    {
        $user = (new MassRulesUser())->forceFill(self::INPUT);

        $this->assertSame(self::INPUT, $user->getAttributes());
    }

    public function testSafeOnlyFalseSetsEveryKey(): void
    {
        $user = new MassRulesUser();
        $user->setAttributes(self::INPUT, false);

        $this->assertSame(self::INPUT, $user->getAttributes());
    }

    public function testSetAttributeIsNotFiltered(): void
    {
        $user = new MassRulesUser();
        $user->setAttribute('is_admin', 1);

        $this->assertSame(['is_admin' => 1], $user->getAttributes());
    }

    public function testAModelWithoutRulesOrSafeAttributesHasNothingSafe(): void
    {
        $this->expectException(MassAssignmentException::class);
        $this->expectExceptionMessage('forceFill()');
        (new MassBareUser())->setAttributes(['name' => 'x']);
    }

    public function testDiscardedAttributesAreIgnoredByDefault(): void
    {
        $this->assertFalse(MassRulesUser::discardingAttributesIsPrevented());

        $user = new MassRulesUser();
        $user->setAttributes(['name' => 'Ann', 'is_admin' => 1]);

        $this->assertSame(['name' => 'Ann'], $user->getAttributes());
    }

    public function testStrictModeNamesTheDiscardedAttributes(): void
    {
        MassRulesUser::preventSilentlyDiscardingAttributes();

        $this->expectException(MassAssignmentException::class);
        $this->expectExceptionMessage('is_admin, extra');
        (new MassRulesUser())->setAttributes(self::INPUT);
    }

    public function testStrictModeAllowsAnInputThatHasOnlySafeAttributes(): void
    {
        MassRulesUser::preventSilentlyDiscardingAttributes();

        $user = new MassRulesUser();
        $user->setAttributes(['name' => 'Ann', 'age' => 3]);

        $this->assertSame(['name' => 'Ann', 'age' => 3], $user->getAttributes());
    }

    public function testStrictModeIsPerModelClass(): void
    {
        MassRulesUser::preventSilentlyDiscardingAttributes();

        $this->assertFalse(MassExplicitUser::discardingAttributesIsPrevented());
    }
}
