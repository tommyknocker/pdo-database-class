<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * Oracle-specific tests for LATERAL JOIN functionality.
 */
final class LateralJoinTests extends BaseOracleTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Drop existing tables to ensure clean state
        try {
            self::$db->rawQuery('DROP TABLE test_orders_lateral CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        try {
            self::$db->rawQuery('DROP TABLE test_users_lateral CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        // Create test tables
        self::$db->rawQuery('
            CREATE TABLE test_users_lateral (
                id NUMBER PRIMARY KEY,
                name VARCHAR2(100),
                created_at DATE
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE test_orders_lateral (
                id NUMBER PRIMARY KEY,
                user_id NUMBER,
                amount NUMBER(10, 2),
                created_at DATE
            )
        ');

        self::$db->rawQuery('CREATE SEQUENCE test_users_lateral_seq START WITH 1 INCREMENT BY 1');
        self::$db->rawQuery('CREATE SEQUENCE test_orders_lateral_seq START WITH 1 INCREMENT BY 1');

        self::$db->rawQuery('
            CREATE OR REPLACE TRIGGER test_users_lateral_trigger
            BEFORE INSERT ON test_users_lateral
            FOR EACH ROW
            BEGIN
                IF :NEW.id IS NULL THEN
                    SELECT test_users_lateral_seq.NEXTVAL INTO :NEW.id FROM DUAL;
                END IF;
            END;
        ');

        self::$db->rawQuery('
            CREATE OR REPLACE TRIGGER test_orders_lateral_trigger
            BEFORE INSERT ON test_orders_lateral
            FOR EACH ROW
            BEGIN
                IF :NEW.id IS NULL THEN
                    SELECT test_orders_lateral_seq.NEXTVAL INTO :NEW.id FROM DUAL;
                END IF;
            END;
        ');

        // Insert test data
        self::$db->rawQuery("
            INSERT INTO test_users_lateral (name, created_at) VALUES
            ('Alice', DATE '2024-01-01'),
            ('Bob', DATE '2024-01-02'),
            ('Charlie', DATE '2024-01-03')
        ");

        self::$db->rawQuery("
            INSERT INTO test_orders_lateral (user_id, amount, created_at) VALUES
            (1, 100.00, DATE '2024-01-10'),
            (1, 200.00, DATE '2024-01-15'),
            (2, 150.00, DATE '2024-01-12'),
            (2, 300.00, DATE '2024-01-20'),
            (3, 50.00, DATE '2024-01-05')
        ");
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('TRUNCATE TABLE test_orders_lateral');
        self::$db->rawQuery('TRUNCATE TABLE test_users_lateral');

        // Re-insert test data
        self::$db->rawQuery("
            INSERT INTO test_users_lateral (name, created_at) VALUES
            ('Alice', DATE '2024-01-01'),
            ('Bob', DATE '2024-01-02'),
            ('Charlie', DATE '2024-01-03')
        ");

        self::$db->rawQuery("
            INSERT INTO test_orders_lateral (user_id, amount, created_at) VALUES
            (1, 100.00, DATE '2024-01-10'),
            (1, 200.00, DATE '2024-01-15'),
            (2, 150.00, DATE '2024-01-12'),
            (2, 300.00, DATE '2024-01-20'),
            (3, 50.00, DATE '2024-01-05')
        ");
    }

    public function testOracleSupportsLateralJoin(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $this->assertTrue($dialect->supportsLateralJoin());
        $this->assertEquals('oci', $dialect->getDriverName());
    }

    public function testLateralJoinLatestOrderPerUser(): void
    {
        // Test SQL generation for LATERAL JOIN with subquery
        // Oracle 12c+ supports LATERAL JOIN
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.id',
                'u.name',
                'latest.amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['amount'])
                  ->where('user_id', 'u.id')
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('LEFT', $query['sql']);
        $this->assertStringContainsString('latest', $query['sql']);
        $this->assertStringContainsString('ORDER BY', $query['sql']);
        $this->assertStringContainsString('FETCH NEXT', $query['sql']);
    }
}
