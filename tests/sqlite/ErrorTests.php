<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * ErrorTests tests for sqlite.
 */
final class ErrorTests extends BaseSqliteTestCase
{
    public function testInvalidSqlLogsErrorAndException(): void
    {
    $sql = 'INSERT INTO users (non_existing_column) VALUES (:v)';
    $params = ['v' => 'X'];
    
    $this->expectException(\PDOException::class);
    
    $testHandler = new TestHandler();
    $logger = new Logger('test-db');
    $logger->pushHandler($testHandler);
    $db = new PdoDb('sqlite', ['path' => ':memory:'], [], $logger);
    
    try {
    $db->connection->prepare($sql)->execute();
    } finally {
    $hasOpError = false;
    foreach ($testHandler->getRecords() as $rec) {
    if (($rec['message'] ?? '') === 'operation.error'
    && ($rec['context']['operation'] ?? '') === 'prepare'
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
    $db = new PdoDb('sqlite', ['path' => ':memory:'], [], $logger);
    
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
