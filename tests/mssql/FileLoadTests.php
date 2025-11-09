<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * FileLoadTests tests for MSSQL.
 */
final class FileLoadTests extends BaseMSSQLTestCase
{
    public function testLoadCsv(): void
    {
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "Dave,new\nEve,new\n");

        try {
            // MSSQL uses BULK INSERT
            $ok = $db->find()->table('users')->loadCsv($tmpFile, [
            'fieldChar' => ',',
            'fields' => ['name', 'status'],
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'LoadCsv test requires MSSQL configured with proper permissions. ' .
                'Error: ' . $e->getMessage()
            );
        }

        $this->assertTrue($ok, 'loadCsv() returned false');

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
            // MSSQL uses OPENXML
            $ok = self::$db->find()->table('users')->loadXml($file, '<user>', 1);
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'LoadXml test requires MSSQL configured with proper permissions. ' .
                'Error: ' . $e->getMessage()
            );
        }

        $this->assertTrue($ok);

        $row = self::$db->find()->from('users')->where('name', 'XMLUser 2')->getOne();
        $this->assertEquals('XMLUser 2', $row['name']);
        $this->assertEquals(44, $row['age']);

        unlink($file);
    }
}
