<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\exceptions\DatabaseException;

/**
 * FileLoadTests tests for mariadb.
 */
final class FileLoadTests extends BaseMariaDBTestCase
{
    public function testLoadCsv(): void
    {
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "4,Dave,new\n5,Eve,new\n");

        try {
            $ok = $db->find()->table('users')->loadCsv($tmpFile, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'status'],
            'local' => true,
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'LoadCsv test requires MySQL configured with local_infile enabled. ' .
    'Error: ' . $e->getMessage()
            );
        }

        $this->assertTrue($ok, 'loadData() returned false');

        $names = array_column($db->find()->from('users')->get(), 'name');
        $this->assertContains('Dave', $names);
        $this->assertContains('Eve', $names);

        unlink($tmpFile);
    }

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
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'LoadXml test requires MySQL configured with local_infile enabled. ' .
    'Error: ' . $e->getMessage()
            );
        }

        $this->assertTrue($ok);

        $row = self::$db->find()->from('users')->where('name', 'XMLUser 2')->getOne();
        $this->assertEquals('XMLUser 2', $row['name']);
        $this->assertEquals(44, $row['age']);

        unlink($file);
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
        } catch (\PDOException | DatabaseException $e) {
            // Check if it's actually a local_infile issue
            if (str_contains($e->getMessage(), 'local_infile') ||
                str_contains($e->getMessage(), 'LOAD DATA') ||
                str_contains($e->getMessage(), 'The used command is not allowed')) {
                $this->markTestSkipped(
                    'LoadJson test requires MySQL configured with local_infile enabled. ' .
                    'Error: ' . $e->getMessage()
                );
            }

            // If it's a different error, re-throw it
            throw $e;
        }

        unlink($file);
    }
}
