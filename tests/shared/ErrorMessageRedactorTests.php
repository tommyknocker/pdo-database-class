<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\connection\ErrorMessageRedactor;

final class ErrorMessageRedactorTests extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function messages(): array
    {
        return [
            'postgresql' => [
                "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(secret@example.test) already exists.",
                'secret@example.test',
            ],
            'mysql' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'secret@example.test' for key 'users.email'", 'secret@example.test'],
            'sql server' => ['SQLSTATE[23000]: [Microsoft][SQL Server] Cannot insert duplicate key row in object \'dbo.users\' with unique index \'ix_email\'. The duplicate key value is (secret@example.test).', 'secret@example.test'],
            'postgresql integer' => ["SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: \"secret@example.test\"\nCONTEXT:  unnamed portal parameter $1 = '...'", 'secret@example.test'],
            'postgresql date' => ['SQLSTATE[22007]: Invalid datetime format: 7 ERROR:  invalid input syntax for type date: "secret@example.test"', 'secret@example.test'],
            'postgresql enum' => ['SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input value for enum mood: "secret@example.test"', 'secret@example.test'],
            'postgresql range' => ['SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  value "99999secret@example.test" is out of range for type smallint', 'secret@example.test'],
            'postgresql array' => ["SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  malformed array literal: \"secret@example.test\"\nDETAIL:  Array value must start with", 'secret@example.test'],
            'postgresql failing row' => ["SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"t\" violates check constraint \"t_ck\"\nDETAIL:  Failing row contains (null, -5, secret@example.test).", 'secret@example.test'],
            'mysql double' => ["SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'secret@example.test'", 'secret@example.test'],
            'sql server conversion' => ["SQLSTATE[22018]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Conversion failed when converting the nvarchar value 'secret@example.test' to data type int.", 'secret@example.test'],
            'sql server overflow' => ["SQLSTATE[22003]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]The conversion of the nvarchar value 'secret@example.test' overflowed an int column.", 'secret@example.test'],
            'sql server truncated' => ["SQLSTATE[42000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]String or binary data would be truncated in table 'testdb.dbo.t', column 'c'. Truncated value: 'secret@example.test'.", 'secret@example.test'],
            'column value' => ["SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'secret' for column 'age' at row 1", 'secret'],
        ];
    }

    #[DataProvider('messages')]
    public function testValuesAreRemovedAndTheErrorStaysReadable(string $message, string $secret): void
    {
        $redacted = ErrorMessageRedactor::redact($message);

        $this->assertStringNotContainsString($secret, $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
        $this->assertMatchesRegularExpression('/SQLSTATE\[\w+\]/', $redacted);
    }

    public function testAMessageWithoutValuesIsUnchanged(): void
    {
        $message = 'SQLSTATE[HY000]: General error: 1 no such table: users';

        $this->assertSame($message, ErrorMessageRedactor::redact($message));
    }

    public function testNamesOfObjectsStayReadable(): void
    {
        $message = 'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "orders" violates check constraint "orders_total_check"';
        $this->assertSame($message, ErrorMessageRedactor::redact($message));

        $message = 'SQLSTATE[42703]: Undefined column: 7 ERROR:  column "totl" of relation "orders" does not exist';
        $this->assertSame($message, ErrorMessageRedactor::redact($message));

        $message = "SQLSTATE[42000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid column name 'totl'.";
        $this->assertSame($message, ErrorMessageRedactor::redact($message));
    }
}
