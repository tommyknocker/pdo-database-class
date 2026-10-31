<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

class SafetyScopedModel extends Model
{
    public static int $tenant = 1;
    public static function tableName(): string
    {
        return 'records';
    }
    public static function globalScopes(): array
    {
        return ['tenant' => static fn ($q) => $q->where('tenant_id', self::$tenant)];
    }
}
class SafetyDefaultModel extends Model
{
}
class SafetyOtherModel extends Model
{
    public static function tableName(): string
    {
        return 'records';
    }
}

class SafetyOrScopeModel extends Model
{
    public static function tableName(): string
    {
        return 'records';
    }
    public static function globalScopes(): array
    {
        return ['tenant' => static fn ($q) => $q->where('tenant_id', 1)->orWhere('tenant_id', 3)];
    }
}

final class ActiveRecordSafetyTests extends TestCase
{
    private PdoDb $db;

    protected function setUp(): void
    {
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->db->rawQuery('CREATE TABLE records (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        $this->db->rawQuery("INSERT INTO records VALUES (1, 1, 'one'), (2, 2, 'two')");
        SafetyScopedModel::$tenant = 1;
        SafetyScopedModel::setDb($this->db);
    }

    public function testScopesApplyToTerminalOperationsAndReturnValues(): void
    {
        self::assertFalse(SafetyScopedModel::find()->where('id', 2)->exists());
        self::assertTrue(SafetyScopedModel::find()->where('id', 2)->notExists());
        self::assertSame(0, SafetyScopedModel::find()->where('id', 2)->update(['name' => 'bad']));
        self::assertSame(0, SafetyScopedModel::find()->where('id', 2)->delete());
        self::assertSame('two', $this->db->find()->from('records')->where('id', 2)->getOne()['name']);
        self::assertTrue(SafetyScopedModel::find()->withoutGlobalScope('tenant')->where('id', 2)->exists());
        self::assertSame(2, SafetyScopedModel::find()->withoutGlobalScope('tenant')->where('id', 2)->first()['id']);
        self::assertIsArray(SafetyScopedModel::find()->toSQL());
        self::assertCount(1, iterator_to_array(SafetyScopedModel::find()->stream()));
    }

    public function testObjectWriteUsesCurrentScope(): void
    {
        $model = SafetyScopedModel::findOne(1);
        self::assertNotNull($model);
        SafetyScopedModel::$tenant = 2;
        $model->name = 'changed';
        self::assertFalse($model->save());
        self::assertFalse($model->delete());
        self::assertSame('one', $this->db->find()->from('records')->where('id', 1)->getOne()['name']);
    }

    public function testScopedTruncateIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SafetyScopedModel::find()->truncate();
    }

    public function testScopeGroupsOrConditionsAndSurvivesQueryReuse(): void
    {
        $query = SafetyScopedModel::find()->where('id', 2)->orWhere('id', 1);
        self::assertTrue($query->exists());
        self::assertTrue($query->exists());
        self::assertSame(1, $query->delete());
        self::assertTrue($this->db->find()->from('records')->where('id', 2)->exists());
    }

    public function testOrInsideScopeIsGroupedWithUserPredicate(): void
    {
        SafetyOrScopeModel::setDb($this->db);
        self::assertFalse(SafetyOrScopeModel::find()->where('id', 2)->exists());
        self::assertTrue(SafetyOrScopeModel::find()->where('id', 1)->exists());
    }

    public function testModelConnectionsAreIndependentAndDefaultIsExplicit(): void
    {
        $other = new PdoDb('sqlite', ['path' => ':memory:']);
        SafetyOtherModel::setDb($other);
        self::assertSame($this->db, SafetyScopedModel::getDb());
        self::assertSame($other, SafetyOtherModel::getDb());
        SafetyOtherModel::setDb(null);
        self::assertNull(SafetyOtherModel::getDb());
    }

    public function testDefaultConnectionAndExplicitNullAreIndependent(): void
    {
        Model::setDb($this->db);

        try {
            self::assertSame($this->db, SafetyDefaultModel::getDb());
            SafetyDefaultModel::setDb(null);
            self::assertNull(SafetyDefaultModel::getDb());
            self::assertSame($this->db, SafetyScopedModel::getDb());
        } finally {
            Model::setDb(null);
        }
    }

    public function testNestedTransactionDoesNotCommitOuterAndAllowsInnerRollback(): void
    {
        try {
            $this->db->transaction(function (PdoDb $db): void {
                $db->transaction(fn (PdoDb $db) => $db->rawQuery("INSERT INTO records VALUES (3, 1, 'inner')"));

                throw new RuntimeException('outer');
            });
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('outer', $e->getMessage());
        }
        self::assertFalse($this->db->find()->from('records')->where('id', 3)->exists());
        $this->db->transaction(function (PdoDb $db): void {
            $db->rawQuery("INSERT INTO records VALUES (4, 1, 'outer')");

            try {
                $db->transaction(function (PdoDb $db): void {
                    $db->rawQuery("INSERT INTO records VALUES (5, 1, 'inner')");

                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException $e) {
                self::assertSame('inner', $e->getMessage());
            }
        });
        self::assertTrue($this->db->find()->from('records')->where('id', 4)->exists());
        self::assertFalse($this->db->find()->from('records')->where('id', 5)->exists());
    }
}
