<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;

/**
 * indexExists()/uniqueExists()/foreignKeyExists()/checkExists() read each dialect's catalog differently (column
 * names, letter case, constraint type codes), so every dialect runs the same scenario.
 */
final class ExistenceChecksSupport
{
    /**
     * @return array<string, bool>
     */
    public static function run(PdoDb $db, bool $alterTableConstraints = true): array
    {
        $schema = $db->schema();
        $schema->dropTableIfExists('exist_child');
        $schema->dropTableIfExists('exist_parent');
        $schema->createTable('exist_parent', ['id' => $schema->primaryKey(), 'email' => $schema->string(100), 'age' => $schema->integer()]);
        $schema->createTable('exist_child', ['id' => $schema->primaryKey(), 'parent_id' => $schema->integer()]);

        try {
            $schema->createIndex('idx_exist_age', 'exist_parent', 'age');
            $schema->addUnique('uq_exist_email', 'exist_parent', 'email');
            $result = [
                'index' => $schema->indexExists('idx_exist_age', 'exist_parent'),
                'indexUpperCase' => $schema->indexExists('IDX_EXIST_AGE', 'exist_parent'),
                'noSuchIndex' => $schema->indexExists('idx_exist_missing', 'exist_parent'),
                'unique' => $schema->uniqueExists('uq_exist_email', 'exist_parent'),
                'noSuchUnique' => $schema->uniqueExists('uq_exist_missing', 'exist_parent'),
                'plainIndexIsNotUnique' => $schema->uniqueExists('idx_exist_age', 'exist_parent'),
            ];
            if ($alterTableConstraints) {
                $schema->addForeignKey('fk_exist_parent', 'exist_child', 'parent_id', 'exist_parent', 'id');
                $schema->addCheck('ck_exist_age', 'exist_parent', 'age >= 0');
                $result['foreignKey'] = $schema->foreignKeyExists('fk_exist_parent', 'exist_child');
                $result['noSuchForeignKey'] = $schema->foreignKeyExists('fk_exist_missing', 'exist_child');
                $result['check'] = $schema->checkExists('ck_exist_age', 'exist_parent');
                $result['noSuchCheck'] = $schema->checkExists('ck_exist_missing', 'exist_parent');
            }

            return $result;
        } finally {
            $schema->dropTableIfExists('exist_child');
            $schema->dropTableIfExists('exist_parent');
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function expected(bool $alterTableConstraints = true): array
    {
        $expected = [
            'index' => true,
            'indexUpperCase' => true,
            'noSuchIndex' => false,
            'unique' => true,
            'noSuchUnique' => false,
            'plainIndexIsNotUnique' => false,
        ];
        if ($alterTableConstraints) {
            $expected += ['foreignKey' => true, 'noSuchForeignKey' => false, 'check' => true, 'noSuchCheck' => false];
        }

        return $expected;
    }
}
