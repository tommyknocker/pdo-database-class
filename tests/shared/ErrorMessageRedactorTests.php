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
}
