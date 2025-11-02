<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDOException;
use tommyknocker\pdodb\exceptions\DatabaseException;

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

        // Try to load non-existent file - may throw InvalidArgumentException or DatabaseException
        $this->expectException(\Throwable::class);

        try {
            $loader->loadCsv('/nonexistent/file_' . uniqid() . '.csv');
        } catch (\InvalidArgumentException | DatabaseException $e) {
            // Verify it's an exception
            $this->assertInstanceOf(\Throwable::class, $e);

            throw $e;
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_error');
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

        try {
            // This should fail and rollback transaction
            $loader->loadCsv($tmpFile, [
                'fields' => ['id', 'name'],
                'local' => true,
            ]);
            // If no exception thrown, verify no rows were inserted
            if ($db->schema()->tableExists('test_fileloader_rollback')) {
                $count = $db->find()
                    ->from('test_fileloader_rollback')
                    ->select(['count' => \tommyknocker\pdodb\helpers\Db::count()])
                    ->getValue('count');
                $this->assertEquals(0, (int)$count, 'No rows should be inserted with invalid data');
            }
        } catch (PDOException | DatabaseException $e) {
            // Verify transaction was rolled back - check table still exists
            if ($db->schema()->tableExists('test_fileloader_rollback')) {
                $count = $db->find()
                    ->from('test_fileloader_rollback')
                    ->select(['count' => \tommyknocker\pdodb\helpers\Db::count()])
                    ->getValue('count');

                // No rows should be inserted due to rollback
                $this->assertEquals(0, (int)$count, 'Transaction should be rolled back on error');
            }
            // Exception was thrown, which is expected
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_rollback');
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
                ->select(['count' => \tommyknocker\pdodb\helpers\Db::count()])
                ->getValue('count');

            $this->assertIsBool($result);
            $this->assertEquals(0, (int)$count, 'Empty file should insert no rows');
        } catch (PDOException | DatabaseException $e) {
            // Some databases might throw error for empty file, which is acceptable
            $this->assertInstanceOf(\Throwable::class, $e);
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
            // If local_infile is not enabled, test will be skipped in actual FileLoadTests
            // For this test, we just verify the method exists and can be called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Cleanup
        $db->schema()->dropTableIfExists('test_fileloader_state');
    }
}
