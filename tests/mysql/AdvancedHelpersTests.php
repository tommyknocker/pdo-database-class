<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * AdvancedHelpersTests tests for mysql.
 */
final class AdvancedHelpersTests extends BaseMySQLTestCase
{
    public function testAdvancedHelpers(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_advanced');
        $db->rawQuery('CREATE TABLE t_advanced (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), price DECIMAL(10,2), created_at DATETIME, text_col VARCHAR(255))');

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

        // Test Db::add() helper
        $row = $db->find()->table('t_advanced')
            ->select([
                'price',
                'price_plus_10' => Db::add('price', 10),
                'price_plus_price' => Db::add('price', 'price'),
                'constant_sum' => Db::add(5, 3),
                'price_plus_float' => Db::add('price', 0.5),
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals(109.99, round((float)$row['price_plus_10'], 2));
        $this->assertEquals(199.98, round((float)$row['price_plus_price'], 2));
        $this->assertEquals(8, (int)$row['constant_sum']);
        $this->assertEquals(100.49, round((float)$row['price_plus_float'], 2));
    }

    public function testAddHelperWithRawValue(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_add_test');
        $db->rawQuery('CREATE TABLE t_add_test (id INT AUTO_INCREMENT PRIMARY KEY, value INT, multiplier INT)');
        $db->find()->table('t_add_test')->insert([
            'value' => 10,
            'multiplier' => 2,
        ]);

        $row = $db->find()->table('t_add_test')
            ->select([
                'value',
                'multiplier',
                'computed' => Db::add('value', Db::raw('multiplier * 3')),
            ])
            ->getOne();

        $this->assertEquals(16, (int)$row['computed']);

        $db->rawQuery('DROP TABLE t_add_test');
    }
}
