<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use Psr\Log\AbstractLogger;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\PdoDb;

final class ErrorLogRedactionTests extends BasePostgreSQLTestCase
{
    public function testBoundValuesOfAFailedInsertAreNotLogged(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        };
        $db = new PdoDb('pgsql', [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
        ], [], $logger);
        $db->rawQuery('DROP TABLE IF EXISTS log_redaction');
        $db->rawQuery('CREATE TABLE log_redaction (email VARCHAR(100) PRIMARY KEY, age INTEGER, born DATE)');

        try {
            $db->find()->table('log_redaction')->insert(['email' => 'artificial-secret@example.test']);

            try {
                $db->find()->table('log_redaction')->insert(['email' => 'artificial-secret@example.test']);
                $this->fail('the second insert must violate the primary key');
            } catch (DatabaseException) {
                // expected
            }
            foreach (['age', 'born'] as $column) {
                try {
                    $db->find()->table('log_redaction')->insert(['email' => 'other-' . $column, $column => 'artificial-secret-' . $column]);
                    $this->fail("a bad {$column} value must be refused");
                } catch (DatabaseException) {
                    // expected
                }
            }
        } finally {
            $db->rawQuery('DROP TABLE IF EXISTS log_redaction');
        }

        $logged = implode("\n", $logger->lines);
        $this->assertStringContainsString('operation.error', $logged);
        $this->assertStringContainsString('log_redaction', $logged, 'the log still says what failed');
        $this->assertStringNotContainsString('artificial-secret', $logged);
    }
}
