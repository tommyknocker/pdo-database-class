<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

/**
 * FileLoadTests tests for mysql.
 */
final class FileLoadTests extends BaseMySQLTestCase
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
}
