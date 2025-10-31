<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;
use RuntimeException;

/**
 * MiscTests for mysql.
 */
final class MiscTests extends BaseMySQLTestCase
{
    public function testEscape(): void
    {
        $id = self::$db->find()
            ->table('users')
            ->insert([
                'name' => Db::escape("O'Reilly"),
                'age' => 30,
            ]);
        $this->assertIsInt($id);

        $row = self::$db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
        $this->assertEquals("O'Reilly", $row['name']);
        $this->assertEquals(30, $row['age']);
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

    public function testFulltextMatchHelper(): void
    {
        $fulltext = Db::fulltextMatch('title, content', 'search term', 'natural');
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\FulltextMatchValue::class, $fulltext);
    }

    // Edge case: DISTINCT ON not supported on MySQL
    public function testDistinctOnThrowsExceptionOnMySQL(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DISTINCT ON is not supported');

        self::$db->find()
            ->from('test_users')
            ->distinctOn('email')
            ->get();
    }
}

