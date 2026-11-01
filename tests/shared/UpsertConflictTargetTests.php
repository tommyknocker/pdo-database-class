<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

final class UpsertConflictTargetTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = self::$db->schema();
        $schema->dropTableIfExists('upsert_target');
        $schema->createTable('upsert_target', [
            'id' => $schema->primaryKey(),
            'tenant' => $schema->integer()->notNull(),
            'code' => $schema->string(20)->notNull(),
            'label' => $schema->string(50),
        ]);
        $schema->createIndex('upsert_target_tenant_code', 'upsert_target', ['tenant', 'code'], true);
    }

    protected function tearDown(): void
    {
        self::$db->schema()->dropTableIfExists('upsert_target');
        parent::tearDown();
    }

    public function testCompositeUniqueKeyCanBeTheConflictTarget(): void
    {
        $row = ['tenant' => 1, 'code' => 'A', 'label' => 'first'];
        self::$db->find()->table('upsert_target')->insert($row);

        self::$db->find()->table('upsert_target')
            ->onDuplicate(['label'], ['tenant', 'code'])
            ->insert(['tenant' => 1, 'code' => 'A', 'label' => 'second']);

        $rows = self::$db->find()->from('upsert_target')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('second', $rows[0]['label']);
    }

    public function testConflictTargetAlsoAppliesToBulkInserts(): void
    {
        self::$db->find()->table('upsert_target')->insert(['tenant' => 2, 'code' => 'B', 'label' => 'old']);

        self::$db->find()->table('upsert_target')
            ->onDuplicate(['label'], 'tenant,code')
            ->insertMulti([
                ['tenant' => 2, 'code' => 'B', 'label' => 'new'],
                ['tenant' => 2, 'code' => 'C', 'label' => 'added'],
            ]);

        $labels = array_column(self::$db->find()->from('upsert_target')->orderBy('code')->get(), 'label', 'code');
        $this->assertSame(['B' => 'new', 'C' => 'added'], $labels);
    }
}
