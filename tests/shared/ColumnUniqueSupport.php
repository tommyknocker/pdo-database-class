<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\PdoDb;

/**
 * A column declared with ->unique() in createTable() has to reject duplicates on every dialect.
 */
final class ColumnUniqueSupport
{
    /**
     * @return array{second: bool, duplicateRejected: bool, rows: int}
     */
    public static function run(PdoDb $db): array
    {
        $schema = $db->schema();
        $schema->dropTableIfExists('column_unique_probe');
        $schema->createTable('column_unique_probe', [
            'id' => $schema->primaryKey(),
            'email' => $schema->string(190)->unique(),
            'name' => $schema->string(100)->notNull(),
        ]);

        try {
            $db->find()->table('column_unique_probe')->insert(['email' => 'a@example.com', 'name' => 'first']);
            $second = $db->find()->table('column_unique_probe')->insert(['email' => 'b@example.com', 'name' => 'second']) > 0;

            $rejected = false;

            try {
                $db->find()->table('column_unique_probe')->insert(['email' => 'a@example.com', 'name' => 'duplicate']);
            } catch (ConstraintViolationException) {
                $rejected = true;
            }

            return [
                'second' => $second,
                'duplicateRejected' => $rejected,
                'rows' => (int)$db->rawQueryValue('SELECT COUNT(*) FROM column_unique_probe'),
            ];
        } finally {
            $schema->dropTableIfExists('column_unique_probe');
        }
    }

    /**
     * Add a unique column to a table that already holds a row, then try to repeat a value.
     *
     * @return bool Whether the duplicate was rejected
     */
    public static function addUniqueColumn(PdoDb $db): bool
    {
        $schema = $db->schema();
        $schema->dropTableIfExists('column_unique_added');
        $schema->createTable('column_unique_added', ['id' => $schema->primaryKey(), 'name' => $schema->string(100)]);

        try {
            $db->find()->table('column_unique_added')->insert(['name' => 'first']);
            $schema->addColumn('column_unique_added', 'code', $schema->string(50)->unique());

            $db->find()->table('column_unique_added')->insert(['name' => 'second', 'code' => 'X1']);

            try {
                $db->find()->table('column_unique_added')->insert(['name' => 'third', 'code' => 'X1']);
            } catch (ConstraintViolationException) {
                return true;
            }

            return false;
        } finally {
            $schema->dropTableIfExists('column_unique_added');
        }
    }
}
