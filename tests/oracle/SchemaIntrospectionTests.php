<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * describe/indexes/keys/constraints and EXPLAIN on Oracle (the data dictionary returns upper-case names).
 */
final class SchemaIntrospectionTests extends BaseOracleTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        self::$db->rawQuery('CREATE TABLE intro_users (id NUMBER(10) PRIMARY KEY, name VARCHAR2(100) NOT NULL, email VARCHAR2(200))');
        self::$db->rawQuery('CREATE UNIQUE INDEX intro_users_email_uq ON intro_users (email)');
        self::$db->rawQuery('CREATE TABLE intro_orders (id NUMBER(10) PRIMARY KEY, user_id NUMBER(10) NOT NULL, amount NUMBER(10,2) DEFAULT 0 NOT NULL, CONSTRAINT intro_orders_user_fk FOREIGN KEY (user_id) REFERENCES intro_users(id))');
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (['intro_orders', 'intro_users'] as $table) {
            try {
                self::$db->rawQuery("DROP TABLE {$table} CASCADE CONSTRAINTS PURGE");
            } catch (\Throwable) {
                // not there yet
            }
        }
    }

    public function testDescribeReportsTypesNullabilityAndDefaults(): void
    {
        $columns = [];
        foreach (self::$db->describe('intro_orders') as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        $this->assertSame(['ID', 'USER_ID', 'AMOUNT'], array_keys($columns));
        $this->assertSame('NUMBER', $columns['AMOUNT']['DATA_TYPE']);
        $this->assertEquals(2, $columns['AMOUNT']['DATA_SCALE']);
        $this->assertSame('N', $columns['USER_ID']['NULLABLE']);
        $this->assertSame('0', trim((string) $columns['AMOUNT']['DATA_DEFAULT']));
    }

    public function testTableExists(): void
    {
        $schema = self::$db->schema();

        $this->assertTrue($schema->tableExists('intro_users'));
        $this->assertFalse($schema->tableExists('intro_no_such_table'));
    }

    public function testIndexesListTheUniqueIndexAndThePrimaryKeyIndex(): void
    {
        $byColumn = [];
        foreach (self::$db->indexes('intro_users') as $row) {
            $byColumn[$row['COLUMN_NAME']] = $row['INDEX_NAME'];
        }

        $this->assertSame('INTRO_USERS_EMAIL_UQ', $byColumn['EMAIL']);
        $this->assertArrayHasKey('ID', $byColumn);
    }

    public function testKeysDescribeTheForeignKey(): void
    {
        $keys = self::$db->keys('intro_orders');

        $this->assertCount(1, $keys);
        $this->assertSame('INTRO_ORDERS_USER_FK', $keys[0]['CONSTRAINT_NAME']);
        $this->assertSame('USER_ID', $keys[0]['COLUMN_NAME']);
        $this->assertSame('INTRO_USERS', $keys[0]['REFERENCED_TABLE_NAME']);
        $this->assertSame('ID', $keys[0]['REFERENCED_COLUMN_NAME']);
    }

    public function testConstraintsIncludePrimaryAndForeignKeys(): void
    {
        $types = array_column(self::$db->constraints('intro_orders'), 'CONSTRAINT_TYPE');

        $this->assertContains('P', $types);
        $this->assertContains('R', $types);
    }

    public function testExplainProducesAPlan(): void
    {
        $plan = implode("\n", array_column(self::$db->explain('SELECT * FROM intro_users WHERE id = 1'), 'PLAN_TABLE_OUTPUT'));

        $this->assertStringContainsString('INTRO_USERS', $plan);
    }

    public function testExplainAnalyzeSubstitutesBoundValues(): void
    {
        $plan = implode("\n", array_column(
            self::$db->explainAnalyze('SELECT * FROM intro_users WHERE id = :id AND name = :i', ['id' => 1, 'i' => 'x']),
            'PLAN_TABLE_OUTPUT'
        ));

        $this->assertStringContainsString('INTRO_USERS', $plan);
    }
}
