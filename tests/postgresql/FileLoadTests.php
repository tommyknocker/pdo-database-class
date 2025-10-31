<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

/**
 * FileLoadTests tests for postgresql.
 */
final class FileLoadTests extends BasePostgreSQLTestCase
{
    public function testLoadCsv(): void
    {
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "4,Dave,new,30\n5,Eve,new,40\n");

        try {
            $ok = $db->find()->table('users')->loadCsv($tmpFile, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'status', 'age'],
            'header' => false,
            ]);

            $this->assertTrue($ok, 'loadData() returned false');

            $names = array_column($db->find()->from('users')->get(), 'name');
            $this->assertContains('Dave', $names);
            $this->assertContains('Eve', $names);
        } catch (\tommyknocker\pdodb\exceptions\DatabaseException $e) {
            // Check if it's the expected COPY permission/access issue
            if (str_contains($e->getMessage(), 'COPY') ||
            str_contains($e->getMessage(), 'could not open file') ||
            str_contains($e->getMessage(), 'No such file') ||
            str_contains($e->getMessage(), 'must be superuser') ||
            str_contains($e->getMessage(), 'permission denied') ||
            str_contains($e->getMessage(), 'Insufficient privilege')) {
                $this->markTestSkipped(
                    'PostgreSQL COPY FROM requires file access from database server. ' .
    'In Docker/CI environments, the file is on the host but PostgreSQL runs in container. ' .
    'This is a known limitation. Error: ' . $e->getMessage()
                );
            }

            // If it's not a file access issue, re-throw the exception
            throw $e;
        }

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
            $this->assertTrue($ok);

            $row = self::$db->find()->from('users')->where('name', 'XMLUser 2')->getOne();
            $this->assertEquals('XMLUser 2', $row['name']);
            $this->assertEquals(44, $row['age']);
        } catch (\tommyknocker\pdodb\exceptions\DatabaseException $e) {
            // Check if it's the expected COPY permission/access issue
            if (str_contains($e->getMessage(), 'COPY') ||
            str_contains($e->getMessage(), 'could not open file') ||
            str_contains($e->getMessage(), 'No such file') ||
            str_contains($e->getMessage(), 'must be superuser') ||
            str_contains($e->getMessage(), 'permission denied') ||
            str_contains($e->getMessage(), 'Insufficient privilege')) {
                $this->markTestSkipped(
                    'PostgreSQL COPY FROM requires file access from database server. ' .
    'In Docker/CI environments, the file is on the host but PostgreSQL runs in container. ' .
    'This is a known limitation. Error: ' . $e->getMessage()
                );
            }

            // If it's not a file access issue, re-throw the exception
            throw $e;
        }

        unlink($file);
    }
}
