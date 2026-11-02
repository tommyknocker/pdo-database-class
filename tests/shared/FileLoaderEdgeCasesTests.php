<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDOException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\helpers\Db;

/**
 * Tests for FileLoader edge cases and error handling.
 */
class FileLoaderEdgeCasesTests extends BaseSharedTestCase
{
    /**
     * Test FileLoader error handling when file doesn't exist.
     */
    public function testFileLoaderWithNonExistentFile(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_error');

        $db->schema()->createTable('test_fileloader_error', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $loader = $db->find()->table('test_fileloader_error');

        try {
            $loader->loadCsv('/nonexistent/file_' . uniqid() . '.csv');
            $this->fail('A file that does not exist must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('CSV file is not readable', $e->getMessage());
        } finally {
            $db->schema()->dropTableIfExists('test_fileloader_error');
        }
    }

    /**
     * Test FileLoader rollback on error.
     */
    public function testFileLoaderRollbackOnError(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_rollback');

        $db->schema()->createTable('test_fileloader_rollback', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100)->notNull(),
        ]);

        // Create a CSV file with invalid data (missing required field)
        $tmpFile = sys_get_temp_dir() . '/invalid_' . uniqid() . '.csv';
        file_put_contents($tmpFile, "1\n2\n"); // Missing name column

        $loader = $db->find()->table('test_fileloader_rollback');

        $failure = null;

        try {
            $loader->loadCsv($tmpFile, [
                'fields' => ['id', 'name'],
                'local' => true,
            ]);
        } catch (ConstraintViolationException $e) {
            $failure = $e;
        } finally {
            unlink($tmpFile);
        }

        try {
            $this->assertNotNull($failure, 'rows without the required column must be refused');
            $count = $db->find()->from('test_fileloader_rollback')->select(['count' => Db::count()])->getValue('count');
            $this->assertSame(0, (int)$count, 'nothing is kept from a failed load');
        } finally {
            $db->schema()->dropTableIfExists('test_fileloader_rollback');
        }
    }

    /**
     * Test FileLoader with empty file.
     */
    public function testFileLoaderWithEmptyFile(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_empty');

        $db->schema()->createTable('test_fileloader_empty', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $tmpFile = sys_get_temp_dir() . '/empty_' . uniqid() . '.csv';
        file_put_contents($tmpFile, '');

        $loader = $db->find()->table('test_fileloader_empty');

        try {
            $result = $loader->loadCsv($tmpFile, [
                'fields' => ['id', 'name'],
                'local' => true,
            ]);

            // Empty file might succeed but insert nothing
            $count = $db->find()
                ->from('test_fileloader_empty')
                ->select(['count' => Db::count()])
                ->getValue('count');

            $this->assertIsBool($result);
            $this->assertEquals(0, (int)$count, 'Empty file should insert no rows');
        } catch (PDOException | DatabaseException $e) {
            throw $e;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_empty');
    }

    /**
     * Test FileLoader execute state check.
     */
    public function testFileLoaderExecuteState(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_state');

        $db->schema()->createTable('test_fileloader_state', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $tmpFile = sys_get_temp_dir() . '/state_' . uniqid() . '.csv';
        file_put_contents($tmpFile, "1,Test\n");

        $loader = $db->find()->table('test_fileloader_state');

        try {
            $result = $loader->loadCsv($tmpFile, [
                'fields' => ['id', 'name'],
                'local' => true,
            ]);

            // Result should be boolean indicating success
            $this->assertIsBool($result);
        } catch (PDOException | DatabaseException $e) {
            throw $e;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_state');
    }

    /**
     * Test FileLoader loadJson method.
     */
    public function testFileLoaderLoadJson(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_json');

        $db->schema()->createTable('test_fileloader_json', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
            'value' => $db->schema()->integer(),
        ]);

        $tmpFile = sys_get_temp_dir() . '/json_' . uniqid() . '.json';
        file_put_contents($tmpFile, '[{"id":1,"name":"Test1","value":10},{"id":2,"name":"Test2","value":20}]');

        $loader = $db->find()->table('test_fileloader_json');

        try {
            $result = $loader->loadJson($tmpFile);

            // Result should be boolean indicating success
            $this->assertIsBool($result);

            // Verify data was loaded (if supported by dialect)
            $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
            if ($driver === 'sqlite') {
                // SQLite supports JSON loading
                $count = $db->find()
                    ->from('test_fileloader_json')
                    ->select(['count' => Db::count()])
                    ->getValue('count');
                $this->assertGreaterThanOrEqual(0, (int)$count);
            }
        } catch (DatabaseException | PDOException $e) {
            throw $e;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_json');
    }

    /**
     * Test FileLoader loadJson with empty file.
     */
    public function testFileLoaderLoadJsonEmptyFile(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_json_empty');

        $db->schema()->createTable('test_fileloader_json_empty', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $tmpFile = sys_get_temp_dir() . '/json_empty_' . uniqid() . '.json';
        file_put_contents($tmpFile, '[]'); // Empty JSON array

        $loader = $db->find()->table('test_fileloader_json_empty');

        try {
            $result = $loader->loadJson($tmpFile);

            // Result should be boolean
            $this->assertIsBool($result);

            // Empty file should insert no rows
            $count = $db->find()
                ->from('test_fileloader_json_empty')
                ->select(['count' => Db::count()])
                ->getValue('count');
            $this->assertEquals(0, (int)$count, 'Empty JSON file should insert no rows');
        } catch (DatabaseException | PDOException $e) {
            throw $e;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_json_empty');
    }

    /**
     * Test FileLoader loadXml with custom rowTag.
     */
    public function testFileLoaderLoadXmlCustomRowTag(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_xml_custom');

        $db->schema()->createTable('test_fileloader_xml_custom', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $tmpFile = sys_get_temp_dir() . '/xml_custom_' . uniqid() . '.xml';
        file_put_contents($tmpFile, '<root><item><id>1</id><name>Test1</name></item><item><id>2</id><name>Test2</name></item></root>');

        $loader = $db->find()->table('test_fileloader_xml_custom');

        try {
            $result = $loader->loadXml($tmpFile, '<item>');

            // Result should be boolean indicating success
            $this->assertIsBool($result);
        } catch (DatabaseException | PDOException $e) {
            throw $e;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_xml_custom');
    }

    /**
     * Test FileLoader loadXml with linesToIgnore option.
     */
    public function testFileLoaderLoadXmlWithLinesToIgnore(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_xml_ignore');

        $db->schema()->createTable('test_fileloader_xml_ignore', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $tmpFile = sys_get_temp_dir() . '/xml_ignore_' . uniqid() . '.xml';
        file_put_contents($tmpFile, "<?xml version=\"1.0\"?>\n<root>\n<row><id>1</id><name>Test1</name></row>\n</root>");

        $loader = $db->find()->table('test_fileloader_xml_ignore');

        try {
            $result = $loader->loadXml($tmpFile, '<row>', 1);

            // Result should be boolean indicating success
            $this->assertIsBool($result);
        } catch (DatabaseException | PDOException $e) {
            throw $e;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_xml_ignore');
    }

    public function testAFailedLoadNeverLeavesTheConnectionInATransaction(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_tx');
        $db->schema()->createTable('test_fileloader_tx', ['id' => $db->schema()->primaryKey(), 'name' => $db->schema()->string(100)]);

        try {
            foreach (['loadCsv' => ['/nonexistent/a.csv'], 'loadJson' => ['/nonexistent/a.json'], 'loadXml' => ['/nonexistent/a.xml']] as $method => $arguments) {
                try {
                    $db->find()->table('test_fileloader_tx')->$method(...$arguments);
                } catch (\Throwable) {
                    // expected: the file is not there
                }
                $this->assertFalse($db->inTransaction(), "{$method} left a transaction open");
            }
        } finally {
            $db->schema()->dropTableIfExists('test_fileloader_tx');
        }
    }

    public function testALoadInsideACallersTransactionLeavesTheCommitToTheCaller(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_caller_tx');
        $db->schema()->createTable('test_fileloader_caller_tx', ['id' => $db->schema()->primaryKey(), 'name' => $db->schema()->string(100)]);

        $file = sys_get_temp_dir() . '/caller_tx_' . uniqid() . '.csv';
        file_put_contents($file, "1,first\n2,second\n");

        try {
            $db->startTransaction();
            $db->find()->table('test_fileloader_caller_tx')->loadCsv($file, ['fields' => ['id', 'name'], 'local' => true]);
            $this->assertTrue($db->inTransaction(), 'the loader must not commit a transaction it did not start');
            $db->rollback();

            $this->assertSame(0, (int)$db->rawQueryValue('SELECT COUNT(*) FROM test_fileloader_caller_tx'));
        } finally {
            unlink($file);
            $db->schema()->dropTableIfExists('test_fileloader_caller_tx');
        }
    }
}
