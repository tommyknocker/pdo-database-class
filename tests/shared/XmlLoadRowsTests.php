<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

/**
 * Consecutive row elements must all be loaded, not every other one.
 */
final class XmlLoadRowsTests extends BaseSharedTestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS xml_rows');
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function load(int $skip): mixed
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS xml_rows');
        self::$db->rawQuery('CREATE TABLE xml_rows (id INTEGER, name TEXT)');
        $rows = '';
        foreach (range(1, 5) as $i) {
            $rows .= "<row><id>{$i}</id><name>Row {$i}</name></row>";
        }
        $this->file = sys_get_temp_dir() . '/xml_rows_' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($this->file, "<rows>{$rows}</rows>");

        self::$db->find()->table('xml_rows')->loadXml($this->file, '<row>', $skip);

        return array_column(self::$db->find()->from('xml_rows')->orderBy('id')->get(), 'id');
    }

    public function testEveryRowIsLoaded(): void
    {
        $this->assertEquals([1, 2, 3, 4, 5], $this->load(0));
    }

    public function testLeadingRowsCanBeSkipped(): void
    {
        $this->assertEquals([3, 4, 5], $this->load(2));
    }
}
