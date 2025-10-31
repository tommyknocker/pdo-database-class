<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\helpers\Db;

/**
 * AdvancedHelpersTests tests for postgresql.
 */
final class AdvancedHelpersTests extends BasePostgreSQLTestCase
{
    public function testAdvancedHelpers(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_advanced');
        $db->rawQuery('CREATE TABLE t_advanced (id SERIAL PRIMARY KEY, name VARCHAR(255), price NUMERIC(10,2), created_at TIMESTAMP, text_col VARCHAR(255))');

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
        'repeat_text' => Db::repeat('Hi', 3),
        'reverse_text' => Db::reverse('text_col'),
        'pad_left' => Db::padLeft('name', 15, '*'),
        'pad_right' => Db::padRight('name', 15, '*'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('Hello', $row['left_text']);
        $this->assertEquals('World', $row['right_text']);
        $this->assertGreaterThan(0, (int)$row['position_text']);
        $this->assertEquals('HiHiHi', $row['repeat_text']);
        $this->assertEquals('dlroW olleH', $row['reverse_text']);

        // Test groupConcat (STRING_AGG in PostgreSQL)
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
