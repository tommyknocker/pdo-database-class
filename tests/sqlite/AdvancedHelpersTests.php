<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;

/**
 * AdvancedHelpersTests tests for sqlite.
 */
final class AdvancedHelpersTests extends BaseSqliteTestCase
{
    public function testAdvancedHelpers(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_advanced');
        $db->rawQuery('CREATE TABLE t_advanced (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL, created_at TEXT, text_col TEXT)');

        $id1 = $db->find()->table('t_advanced')->insert([
        'name' => 'Product 1',
        'price' => 99.99,
        'created_at' => '2025-01-15 10:00:00',
        'text_col' => 'Hello World',
        ]);

        // Test addInterval / subInterval
        $row = $db->find()->table('t_advanced')
        ->select([
        'future_date' => Db::addInterval('created_at', '7', 'DAY'),
        'past_date' => Db::subInterval('created_at', '3', 'DAY'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertNotEmpty($row['future_date']);
        $this->assertNotEmpty($row['past_date']);

        // Test advanced math functions
        $row = $db->find()->table('t_advanced')
        ->select([
        'ceil_price' => Db::ceil('price'),
        'floor_price' => Db::floor('price'),
        'power_price' => Db::power('price', '2'),
        'sqrt_price' => Db::sqrt('price'),
        'trunc_price' => Db::trunc('price'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals(100, (int)$row['ceil_price']);
        $this->assertEquals(99, (int)$row['floor_price']);
        $this->assertGreaterThan(0, (float)$row['power_price']);
        $this->assertGreaterThan(0, (float)$row['sqrt_price']);

        // Test advanced string functions
        $row = $db->find()->table('t_advanced')
        ->select([
        'left_text' => Db::left('text_col', 5),
        'right_text' => Db::right('text_col', 5),
        'position_text' => Db::position('World', 'text_col'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('Hello', $row['left_text']);
        $this->assertEquals('World', $row['right_text']);
        $this->assertGreaterThan(0, (int)$row['position_text']);
        // Unsupported functions should throw exceptions on SQLite
        $this->expectException(\RuntimeException::class);
        $db->find()->table('t_advanced')
        ->select(['x' => Db::padLeft('name', 10, '*')])
        ->getOne();

        // Test groupConcat
        $id2 = $db->find()->table('t_advanced')->insert(['name' => 'Product 2', 'price' => 49.99]);
        $id3 = $db->find()->table('t_advanced')->insert(['name' => 'Product 3', 'price' => 29.99]);

        $row = $db->find()->table('t_advanced')
        ->select([
        'names' => Db::groupConcat('name', ', '),
        ])
        ->getOne();
        $this->assertStringContainsString('Product 1', $row['names']);
        $this->assertStringContainsString('Product 2', $row['names']);
    }
}
