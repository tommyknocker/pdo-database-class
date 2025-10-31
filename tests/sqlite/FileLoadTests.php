<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

/**
 * FileLoadTests tests for sqlite.
 */
final class FileLoadTests extends BaseSqliteTestCase
{
    public function testLoadXml(): void
    {
        $file = sys_get_temp_dir() . '/users.xml';
        file_put_contents(
            $file,
            <<<XML
    <users>
    <user>
    <name>XMLUser 1</name>
    <age>45</age>
    </user>
    <user>
    <name>XMLUser 2</name>
    <age>44</age>
    </user>
    </users>
    XML
        );

        try {
            $ok = self::$db->find()->table('users')->loadXml($file, '<user>', 1);
            $this->assertTrue($ok);

            $row = self::$db->find()->from('users')->where('name', 'XMLUser 2')->getOne();
            $this->assertEquals('XMLUser 2', $row['name']);
            $this->assertEquals(44, $row['age']);
        } catch (\tommyknocker\pdodb\exceptions\DatabaseException $e) {
            // SQLite doesn't support LOAD XML, so this should be skipped
            $this->markTestSkipped(
                'SQLite does not support LOAD XML. Error: ' . $e->getMessage()
            );
        }

        unlink($file);
    }

    public function testLoadCsv(): void
    {
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "4,Dave,new,30\n5,Eve,new,40\n");

        try {
            $ok = $db->find()->table('users')->loadCsv($tmpFile, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'status', 'age'],
            'local' => true,
            ]);

            $this->assertTrue($ok, 'loadData() returned false');

            $names = $db->find()
            ->from('users')
            ->select(['name'])
            ->getColumn();

            $this->assertContains('Dave', $names);
            $this->assertContains('Eve', $names);
        } catch (\tommyknocker\pdodb\exceptions\DatabaseException $e) {
            // SQLite doesn't support LOAD DATA INFILE, so this should be skipped
            $this->markTestSkipped(
                'SQLite does not support LOAD DATA INFILE. Error: ' . $e->getMessage()
            );
        }

        unlink($tmpFile);
    }

    public function testLoadJson(): void
    {
        $file = sys_get_temp_dir() . '/users.json';
        file_put_contents(
            $file,
            json_encode([
                ['id' => 10, 'name' => 'JSONUser 1', 'age' => 25],
                ['id' => 11, 'name' => 'JSONUser 2', 'age' => 30],
            ], JSON_THROW_ON_ERROR)
        );

        try {
            $ok = self::$db->find()->table('users')->loadJson($file, [
                'fields' => ['id', 'name', 'age'],
            ]);

            $this->assertTrue($ok);

            $row = self::$db->find()->from('users')->where('name', 'JSONUser 1')->getOne();
            $this->assertEquals('JSONUser 1', $row['name']);
            $this->assertEquals(25, $row['age']);
        } catch (\tommyknocker\pdodb\exceptions\DatabaseException $e) {
            // SQLite doesn't support LOAD JSON, so this should be skipped
            $this->markTestSkipped(
                'SQLite does not support LOAD JSON. Error: ' . $e->getMessage()
            );
        }

        unlink($file);
    }
}
