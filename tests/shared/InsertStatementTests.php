<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\InsertStatement;

final class InsertStatementTests extends TestCase
{
    public function testReadsTableColumnsAndValues(): void
    {
        $insert = InsertStatement::parse('INSERT INTO "ZZ_T" ("ID", "AMOUNT", "NOTE") VALUES (:id, :amount, TO_DATE(:a, :b))');

        $this->assertNotNull($insert);
        $this->assertSame('ZZ_T', $insert->table);
        $this->assertSame('"ZZ_T"', $insert->qualifiedTable);
        $this->assertSame(['ID', 'AMOUNT', 'NOTE'], $insert->columns);
        $this->assertSame([':id', ':amount', 'TO_DATE(:a, :b)'], $insert->values);
        $this->assertSame(0, $insert->positionOf('id'));
        $this->assertNull($insert->positionOf('missing'));
    }

    public function testSchemaQualifiedAndTrailingClauses(): void
    {
        $insert = InsertStatement::parse('INSERT INTO "public"."orders" ("id", "note") VALUES (:id, \'a, (b)\') ON CONFLICT ("id") DO UPDATE SET "note" = EXCLUDED."note" RETURNING "id"');

        $this->assertNotNull($insert);
        $this->assertSame('orders', $insert->table);
        $this->assertSame('"public"."orders"', $insert->qualifiedTable);
        $this->assertSame([':id', "'a, (b)'"], $insert->values, 'quoted commas and parentheses stay inside their value');
    }

    public function testSuppliedValuesAreReadFromParametersOrNumbers(): void
    {
        $insert = InsertStatement::parse('INSERT INTO t (id, amount, code) VALUES (:id, 5, :code)');

        $this->assertNotNull($insert);
        $this->assertSame(42, $insert->suppliedId('id', [':id' => 42]));
        $this->assertSame(5, $insert->suppliedId('amount', []));
        $this->assertNull($insert->suppliedId('code', [':code' => 'abc']), 'a non-numeric key is not an integer id');
        $this->assertNull($insert->suppliedId('missing', []));
    }

    public function testStatementsItDoesNotReadAsOneRowAreRejected(): void
    {
        $this->assertNull(InsertStatement::parse('INSERT INTO t (a, b) VALUES (1, 2), (3, 4)'));
        $this->assertNull(InsertStatement::parse('INSERT INTO t SELECT * FROM u'));
        $this->assertNull(InsertStatement::parse('UPDATE t SET a = 1'));
        $this->assertNull(InsertStatement::parse('INSERT INTO t (a, b) VALUES (1)'));
    }
}
