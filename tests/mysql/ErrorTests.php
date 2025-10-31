<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use PHPUnit\Framework\TestCase;
use StdClass;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * ErrorTests tests for mysql.
 */
final class ErrorTests extends BaseMySQLTestCase
{
    public function testInvalidSqlLogsErrorAndException(): void
    {
    $sql = 'INSERT INTO users (non_existing_column) VALUES (:v)';
    $params = ['v' => 'X'];
    
    $this->expectException(PDOException::class);
    
    $testHandler = new TestHandler();
    $logger = new Logger('test-db');
    $logger->pushHandler($testHandler);
    $db = new PdoDb(
    'mysql',
    [
    'host' => self::DB_HOST,
    'port' => self::DB_PORT,
    'username' => self::DB_USER,
    'password' => self::DB_PASSWORD,
    'dbname' => self::DB_NAME,
    'charset' => self::DB_CHARSET,
    ],
    [],
    $logger
    );
    
    try {
    $connection = $db->connection;
    assert($connection !== null);
    $connection->prepare($sql)->execute($params);
    } finally {
    $hasOpError = false;
    foreach ($testHandler->getRecords() as $rec) {
    $context = $rec['context'] ?? [];
    assert(is_array($context));
    if (($rec['message'] ?? '') === 'operation.error'
    && ($context['operation'] ?? '') === 'prepare'
    && ($context['exception'] ?? new StdClass()) instanceof PDOException
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
    $db = new PdoDb(
    'mysql',
    [
    'host' => self::DB_HOST,
    'port' => self::DB_PORT,
    'username' => self::DB_USER,
    'password' => self::DB_PASSWORD,
    'dbname' => self::DB_NAME,
    'charset' => self::DB_CHARSET,
    ],
    [],
    $logger
    );
    
    // Begin
    $db->startTransaction();
    $foundBegin = false;
    foreach ($testHandler->getRecords() as $rec) {
    $context = $rec['context'] ?? [];
    assert(is_array($context));
    if (($rec['message'] ?? '') === 'operation.start'
    && ($context['operation'] ?? '') === 'transaction.begin'
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
    $context = $rec['context'] ?? [];
    assert(is_array($context));
    if (($rec['message'] ?? '') === 'operation.end'
    && ($context['operation'] ?? '') === 'transaction.commit'
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
    $context = $rec['context'] ?? [];
    assert(is_array($context));
    if (($rec['message'] ?? '') === 'operation.end'
    && ($context['operation'] ?? '') === 'transaction.rollback'
    ) {
    $foundRollback = true;
    break;
    }
    }
    $this->assertTrue($foundRollback, 'transaction.rollback not logged');
    }
}
