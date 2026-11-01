<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * File imports on Oracle: no multi-row VALUES and no trailing semicolon, so a batch is one INSERT ALL.
 */
final class FileLoadTests extends BaseOracleTestCase
{
    private const TABLE = 'file_load_probe';

    private string $file = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTable();
        self::$db->rawQuery('CREATE TABLE ' . self::TABLE . ' (id NUMBER(10) PRIMARY KEY, name VARCHAR2(100), age NUMBER(5))');
    }

    public function tearDown(): void
    {
        $this->dropTable();
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function dropTable(): void
    {
        try {
            self::$db->rawQuery('DROP TABLE ' . self::TABLE . ' PURGE');
        } catch (\Throwable) {
            // not there yet
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => array_change_key_case($row, CASE_LOWER),
            self::$db->find()->from(self::TABLE)->orderBy('id')->get()
        );
    }

    private function tempFile(string $extension, string $contents): string
    {
        $this->file = sys_get_temp_dir() . '/oracle_load_' . bin2hex(random_bytes(4)) . '.' . $extension;
        file_put_contents($this->file, $contents);

        return $this->file;
    }

    public function testLoadCsvInsertsEveryRow(): void
    {
        $file = $this->tempFile('csv', "3,Dave,30\n4,Eve,40\n5,O'Neil,50\n");

        $this->assertTrue(self::$db->find()->table(self::TABLE)->loadCsv($file, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'age'],
            'header' => false,
        ]));

        $rows = $this->rows();
        $this->assertSame(['Dave', 'Eve', "O'Neil"], array_column($rows, 'name'));
        $this->assertEquals([30, 40, 50], array_column($rows, 'age'));
    }

    public function testLoadCsvInBatchesLargerThanOneStatement(): void
    {
        $lines = '';
        for ($i = 1; $i <= 1200; $i++) {
            $lines .= "{$i},User {$i}," . ($i % 90) . "\n";
        }
        $file = $this->tempFile('csv', $lines);

        $this->assertTrue(self::$db->find()->table(self::TABLE)->loadCsv($file, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'age'],
            'header' => false,
        ]));

        $this->assertSame(1200, (int) self::$db->find()->from(self::TABLE)->select(['COUNT(*)'])->getValue());
    }

    public function testLoadXml(): void
    {
        $file = $this->tempFile('xml', '<users><user><id>1</id><name>XMLUser 1</name><age>45</age></user><user><id>2</id><name>XMLUser 2</name><age>44</age></user></users>');

        $this->assertTrue(self::$db->find()->table(self::TABLE)->loadXml($file, '<user>', 0));

        $this->assertSame(['XMLUser 1', 'XMLUser 2'], array_column($this->rows(), 'name'));
    }

    public function testLoadJson(): void
    {
        $file = $this->tempFile('json', json_encode([
            ['id' => 10, 'name' => 'JSONUser 1', 'age' => 25],
            ['id' => 11, 'name' => 'JSONUser 2', 'age' => 30],
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue(self::$db->find()->table(self::TABLE)->loadJson($file, ['fields' => ['id', 'name', 'age']]));

        $rows = $this->rows();
        $this->assertSame(['JSONUser 1', 'JSONUser 2'], array_column($rows, 'name'));
        $this->assertEquals([25, 30], array_column($rows, 'age'));
    }
}
