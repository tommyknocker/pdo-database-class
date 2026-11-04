<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

/**
 * Values reach the database with their own type: `PDOStatement::execute($params)` would send every value as text,
 * which SQLite does not match against an integer from a column without a declared type, PostgreSQL rejects for a
 * boolean (`false` becomes ''), and Oracle does not match against 0.
 *
 * Used by the per-dialect ParameterBindingTests, which provide `self::$db`.
 */
trait ParameterBindingScenarios
{
    protected function tearDown(): void
    {
        self::$db->schema()->dropTableIfExists('param_binding');
        parent::tearDown();
    }

    private function createParameterTable(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('param_binding');
        $schema->createTable('param_binding', [
            'id' => $schema->primaryKey(),
            'qty' => $schema->integer()->notNull(),
            'active' => $schema->boolean()->notNull(),
            'note' => $schema->string(20),
        ]);
        self::$db->find()->table('param_binding')->insert(['qty' => 1, 'active' => false]);
        self::$db->find()->table('param_binding')->insert(['qty' => 2, 'active' => true]);
    }

    public function testBooleanParametersMatchBothValues(): void
    {
        $this->createParameterTable();

        $inactive = self::$db->find()->from('param_binding')->where('active', false)->get();
        $active = self::$db->find()->from('param_binding')->where('active', true)->get();

        $this->assertSame([1], array_map(static fn (array $r): int => (int)array_change_key_case($r)['qty'], $inactive));
        $this->assertSame([2], array_map(static fn (array $r): int => (int)array_change_key_case($r)['qty'], $active));
    }

    public function testIntegerParameterMatchesAnIntegerWithoutADeclaredColumnType(): void
    {
        $this->createParameterTable();

        // qty + 0 is an expression: a derived column has no declared type to coerce the parameter against
        $rows = self::$db->rawQuery(
            'SELECT x.k FROM (SELECT qty + 0 AS k FROM param_binding) x WHERE x.k = ?',
            [2]
        );

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int)array_change_key_case($rows[0])['k']);
    }

    public function testNamedAndPositionalParametersKeepTheirTypes(): void
    {
        $this->createParameterTable();

        $named = self::$db->find()->from('param_binding')->where('qty', 2)->where('active', true)->get();
        $this->assertCount(1, $named);

        $positional = self::$db->rawQuery('SELECT id FROM param_binding WHERE qty = ? AND active = ?', [1, false]);
        $this->assertCount(1, $positional);
    }

    public function testMultiRowInsertMixingWholeNumbersAndFractionsIsAccepted(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('param_binding');
        $schema->createTable('param_binding', ['id' => $schema->primaryKey(), 'price' => $schema->float()]);

        // SQL Server derives one type for the column of a VALUES list from all rows
        $affected = self::$db->find()->table('param_binding')->insertMulti([
            ['price' => 45.67],
            ['price' => 0],
            ['price' => 100.0],
            ['price' => -15.5],
        ]);

        $this->assertSame(4, $affected);
        $prices = array_map(static fn (array $r): float => (float)array_change_key_case($r)['price'], self::$db->find()->from('param_binding')->orderBy('id')->get());
        $this->assertEqualsWithDelta([45.67, 0.0, 100.0, -15.5], $prices, 0.001);
    }

    public function testNullParameterIsStoredAsNull(): void
    {
        $this->createParameterTable();
        self::$db->find()->table('param_binding')->where('qty', 1)->update(['note' => 'x']);

        self::$db->find()->table('param_binding')->where('qty', 1)->update(['note' => null]);

        $row = array_change_key_case(self::$db->find()->from('param_binding')->where('qty', 1)->getOne());
        $this->assertNull($row['note']);
    }
}
