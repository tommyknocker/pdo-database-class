<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

/**
 * decimal(precision, scale) keeps its fraction: SQL Server once created DECIMAL(10) for decimal(10, 2), so 45.67 was
 * stored as 46.
 *
 * Used by the per-dialect DdlQueryBuilderTests, which provide `self::$db`.
 */
trait DecimalScaleScenarios
{
    public function testDecimalColumnsKeepTheirFraction(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('decimal_scale');

        try {
            $schema->createTable('decimal_scale', [
                'id' => $schema->primaryKey(),
                'price' => $schema->decimal(10, 2),
                'whole' => $schema->decimal(8),
                'fine' => $schema->decimal(12, 4),
            ]);
            $schema->addColumn('decimal_scale', 'added', $schema->decimal(10, 3));
            self::$db->find()->table('decimal_scale')->insert(['price' => 45.67, 'whole' => 12345, 'fine' => 1.2345, 'added' => 1.234]);

            $row = array_change_key_case(self::$db->find()->from('decimal_scale')->getOne());

            $this->assertEqualsWithDelta(45.67, (float)$row['price'], 0.00001);
            $this->assertEqualsWithDelta(12345.0, (float)$row['whole'], 0.00001);
            $this->assertEqualsWithDelta(1.2345, (float)$row['fine'], 0.00001);
            $this->assertEqualsWithDelta(1.234, (float)$row['added'], 0.00001);
        } finally {
            $schema->dropTableIfExists('decimal_scale');
        }
    }
}
