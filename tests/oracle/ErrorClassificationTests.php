<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use Throwable;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Oracle (ORA-) errors must end up in the right exception class and category.
 */
final class ErrorClassificationTests extends BaseOracleTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        self::$db->rawQuery('CREATE TABLE err_parent (id NUMBER(10) PRIMARY KEY, code VARCHAR2(10) NOT NULL, CONSTRAINT err_parent_code_uq UNIQUE (code))');
        self::$db->rawQuery('CREATE TABLE err_child (id NUMBER(10) PRIMARY KEY, parent_id NUMBER(10) NOT NULL, CONSTRAINT err_child_fk FOREIGN KEY (parent_id) REFERENCES err_parent(id))');
        self::$db->rawQuery("INSERT INTO err_parent (id, code) VALUES (1, 'A')");
        self::$db->rawQuery('INSERT INTO err_child (id, parent_id) VALUES (1, 1)');
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (['err_child', 'err_parent'] as $table) {
            try {
                self::$db->rawQuery("DROP TABLE {$table} CASCADE CONSTRAINTS PURGE");
            } catch (Throwable) {
                // not there yet
            }
        }
    }

    private function catch(string $sql): DatabaseException
    {
        try {
            self::$db->rawQuery($sql);
        } catch (Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e, get_class($e) . ': ' . $e->getMessage());

            return $e;
        }

        $this->fail("Expected an error from: {$sql}");
    }

    public function testUniqueViolationIsAConstraintViolation(): void
    {
        $e = $this->catch("INSERT INTO err_parent (id, code) VALUES (2, 'A')");

        $this->assertInstanceOf(ConstraintViolationException::class, $e);
        $this->assertSame('constraint', $e->getCategory());
        $this->assertFalse($e->isRetryable());
    }

    public function testMissingParentIsAConstraintViolation(): void
    {
        $e = $this->catch('INSERT INTO err_child (id, parent_id) VALUES (2, 99)');

        $this->assertInstanceOf(ConstraintViolationException::class, $e);
    }

    public function testDeletingAReferencedParentIsAConstraintViolation(): void
    {
        $e = $this->catch('DELETE FROM err_parent WHERE id = 1');

        $this->assertInstanceOf(ConstraintViolationException::class, $e);
    }

    public function testNullInANotNullColumnIsAConstraintViolation(): void
    {
        $e = $this->catch('INSERT INTO err_parent (id, code) VALUES (3, NULL)');

        $this->assertInstanceOf(ConstraintViolationException::class, $e);
    }

    public function testMissingTableIsAQueryError(): void
    {
        $e = $this->catch('SELECT * FROM err_no_such_table');

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame('query', $e->getCategory());
        $this->assertFalse($e->isRetryable());
    }

    public function testInvalidNumberIsAQueryError(): void
    {
        $e = $this->catch("SELECT * FROM err_parent WHERE id = 'not a number'");

        $this->assertInstanceOf(QueryException::class, $e);
    }
}
