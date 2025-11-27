<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * Tests for LIKE operations with CLOB columns in Oracle.
 */
class LikeClobTests extends BaseOracleTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Create test table with CLOB column
        self::$db->schema()->dropTableIfExists('test_like_clob');
        self::$db->schema()->createTable('test_like_clob', [
            'id' => self::$db->schema()->primaryKey(),
            'email' => self::$db->schema()->text(),
        ]);
    }

    public function testLikeWithClobColumn(): void
    {
        // Insert test data
        self::$db->find()->table('test_like_clob')->insert([
            'email' => 'test@example.com',
        ]);

        // Test LIKE with CLOB column
        $result = self::$db->find()
            ->table('test_like_clob')
            ->where(Db::like('email', '%@example.com'))
            ->get();

        $this->assertCount(1, $result);
        $emailValue = $result[0]['EMAIL'];
        // CLOB columns may be returned as stream or string depending on Oracle driver version
        $emailString = is_resource($emailValue) ? stream_get_contents($emailValue) : (string)$emailValue;
        $this->assertEquals('test@example.com', $emailString);
    }

    public function testLikeWithClobColumnNoMatch(): void
    {
        // Insert test data
        self::$db->find()->table('test_like_clob')->insert([
            'email' => 'test@example.com',
        ]);

        // Test LIKE with CLOB column - no match
        $result = self::$db->find()
            ->table('test_like_clob')
            ->where(Db::like('email', '%@other.com'))
            ->get();

        $this->assertCount(0, $result);
    }
}
