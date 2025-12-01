<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * SubqueryTests tests for Oracle.
 */
final class SubqueryTests extends BaseOracleTestCase
{
    public function testSubQueryInWhere(): void
    {
        $db = self::$db;

        $rowCount = self::$db->find()
            ->table('users')
            ->insertMulti([
                ['name' => 'UserA', 'age' => 20],
                ['name' => 'UserB', 'age' => 20],
                ['name' => 'UserC', 'age' => 30],
                ['name' => 'UserD', 'age' => 40],
                ['name' => 'UserE', 'age' => 50],
            ]);
        $this->assertEquals(5, $rowCount);

        // prepare subquery that selects ids of users older than 30 and aliases it as u
        $sub = $db->find()->from('users u')
            ->select(['u.id'])
            ->where('age', 30, '>');

        // main query joins the subquery alias u to the main users table and returns up to 5 rows
        $rows = $db->find()
            ->from('users main')
            ->join('users u', 'u.id = main.id')
            ->select(['main.name', 'u.age'])
            ->where('u.id', $sub, 'IN')
            ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('IN (SELECT', $lastQuery);

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
    }

    public function testExistsAndNotExists(): void
    {
        $db = self::$db;

        // Insert one record
        $db->find()->table('users')->insert([
            'name' => 'Existy',
            'company' => 'CheckCorp',
            'age' => 42,
            'status' => 'active',
        ]);

        // Check exists() - should return true
        $exists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->exists();

        $this->assertTrue($exists, 'Expected exists() to return true for matching row');

        // Check notExists() - should return false
        $notExists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->notExists();

        $this->assertFalse($notExists, 'Expected notExists() to return false for matching row');

        // Check exists() - for non-existent value
        $noMatchExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->exists();

        $this->assertFalse($noMatchExists, 'Expected exists() to return false for non-matching row');

        // Check notExists() - for non-existent value
        $noMatchNotExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->notExists();

        $this->assertTrue($noMatchNotExists, 'Expected notExists() to return true for non-matching row');
    }

    public function testTableExists(): void
    {
        $this->assertTrue(self::$db->find()->table('users')->tableExists());
        $this->assertFalse(self::$db->find()->table('nonexistent')->tableExists());
    }
}
