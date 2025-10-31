<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use tommyknocker\pdodb\PdoDb;

/**
 * ErrorTests tests for postgresql.
 */
final class ErrorTests extends BasePostgreSQLTestCase
{
    public function testInvalidSqlLogsErrorAndException(): void
    {
        $sql = 'INSERT INTO users (non_existing_column) VALUES (:v)';
        $params = ['v' => 'X'];

        $this->expectException(\PDOException::class);

        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);
        $db = self::$db = new PdoDb(
            'pgsql',
            [
    'host' => self::DB_HOST,
    'port' => self::DB_PORT,
    'username' => self::DB_USER,
    'password' => self::DB_PASSWORD,
    'dbname' => self::DB_NAME,
    ],
            [],
            $logger
        );

        try {
            $db->connection->prepare($sql)->execute($params);
        } finally {
            $hasOpError = false;
            foreach ($testHandler->getRecords() as $rec) {
                if (($rec['message'] ?? '') === 'operation.error'
                && ($rec['context']['operation'] ?? '') === 'execute'
                && ($rec['context']['exception'] ?? new \StdClass()) instanceof \PDOException
                ) {
                    $hasOpError = true;
                }
            }
            $this->assertTrue($hasOpError, 'Expected operation.error for invalid SQL');
        }
    }

    public function testTransactionBeginCommitRollbackLogging(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);
        $db = self::$db = new PdoDb(
            'pgsql',
            [
    'host' => self::DB_HOST,
    'port' => self::DB_PORT,
    'username' => self::DB_USER,
    'password' => self::DB_PASSWORD,
    'dbname' => self::DB_NAME,
    ],
            [],
            $logger
        );

        // Begin
        $db->startTransaction();
        $foundBegin = false;
        foreach ($testHandler->getRecords() as $rec) {
            if (($rec['message'] ?? '') === 'operation.start'
            && ($rec['context']['operation'] ?? '') === 'transaction.begin'
            ) {
                $foundBegin = true;
                break;
            }
        }
        $this->assertTrue($foundBegin, 'transaction.begin not logged');

        // Commit
        $db->commit();
        $foundCommit = false;
        foreach ($testHandler->getRecords() as $rec) {
            if (($rec['message'] ?? '') === 'operation.end'
            && ($rec['context']['operation'] ?? '') === 'transaction.commit'
            ) {
                $foundCommit = true;
                break;
            }
        }
        $this->assertTrue($foundCommit, 'transaction.commit not logged');

        // Rollback
        $db->startTransaction();
        $db->rollback();
        $foundRollback = false;
        foreach ($testHandler->getRecords() as $rec) {
            if (($rec['message'] ?? '') === 'operation.end'
            && ($rec['context']['operation'] ?? '') === 'transaction.rollback'
            ) {
                $foundRollback = true;
                break;
            }
        }
        $this->assertTrue($foundRollback, 'transaction.rollback not logged');
    }
}
