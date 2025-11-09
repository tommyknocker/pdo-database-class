<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * SchemaIntrospectionTests tests for MSSQL.
 */
final class SchemaIntrospectionTests extends BaseMSSQLTestCase
{
    public function testExplainSelectUsers(): void
    {
        $db = self::$db;
        $plan = $db->explain("SELECT * FROM [users] WHERE [status] = 'active'");
        $this->assertNotEmpty($plan);
        $this->assertIsArray($plan[0]);
    }

    public function testExplainAnalyzeSelectUsers(): void
    {
        $db = self::$db;
        $plan = $db->explainAnalyze('SELECT * FROM [users] WHERE [status] = \'active\'');
        $this->assertNotEmpty($plan);
    }

    public function testDescribeUsers(): void
    {
        $db = self::$db;
        $columns = $db->describe('users');
        $this->assertNotEmpty($columns);

        $columnNames = array_column($columns, 'COLUMN_NAME');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('company', $columnNames);
        $this->assertContains('age', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);

        $idColumn = null;
        foreach ($columns as $column) {
            if ($column['COLUMN_NAME'] === 'id') {
                $idColumn = $column;
                break;
            }
        }
        $this->assertNotNull($idColumn);
        $this->assertEquals('id', $idColumn['COLUMN_NAME']);
        $this->assertStringContainsString('int', strtolower($idColumn['DATA_TYPE']));
        $this->assertEquals('NO', $idColumn['IS_NULLABLE']);

        foreach ($columns as $column) {
            $this->assertArrayHasKey('COLUMN_NAME', $column);
            $this->assertArrayHasKey('DATA_TYPE', $column);
            $this->assertArrayHasKey('IS_NULLABLE', $column);
        }
    }

    public function testDescribeOrders(): void
    {
        $db = self::$db;
        $columns = $db->describe('orders');
        $this->assertNotEmpty($columns);

        $columnNames = array_column($columns, 'COLUMN_NAME');
        $this->assertContains('id', $columnNames);
        $this->assertContains('user_id', $columnNames);
        $this->assertContains('amount', $columnNames);

        $userIdColumn = null;
        foreach ($columns as $column) {
            if ($column['COLUMN_NAME'] === 'user_id') {
                $userIdColumn = $column;
                break;
            }
        }
        $this->assertNotNull($userIdColumn);
        $this->assertEquals('user_id', $userIdColumn['COLUMN_NAME']);
    }
}
