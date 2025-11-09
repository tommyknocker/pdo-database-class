<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use StdClass;
use tommyknocker\pdodb\PdoDb;

/**
 * ErrorTests tests for MSSQL.
 */
final class ErrorTests extends BaseMSSQLTestCase
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
            'sqlsrv',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'trust_server_certificate' => true,
                'encrypt' => true,
            ],
            [],
            $logger
        );

        try {
            $connection = $db->connection;
            assert($connection !== null);
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
        } finally {
            $hasOpError = false;
            foreach ($testHandler->getRecords() as $rec) {
                $context = $rec['context'] ?? [];
                assert(is_array($context));
                if (($rec['message'] ?? '') === 'operation.error'
                && (($context['operation'] ?? '') === 'execute' || ($context['operation'] ?? '') === 'prepare')
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
            'sqlsrv',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'trust_server_certificate' => true,
                'encrypt' => true,
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

    public function testUniqueConstraintViolation(): void
    {
        $db = self::$db;

        // Insert first row
        $db->find()->table('users')->insert(['name' => 'UniqueUser', 'age' => 25]);

        // Try to insert duplicate - should throw exception
        $this->expectException(PDOException::class);
        $db->find()->table('users')->insert(['name' => 'UniqueUser', 'age' => 30]);
    }

    public function testForeignKeyViolation(): void
    {
        $db = self::$db;

        // Try to insert order with non-existent user_id
        $this->expectException(PDOException::class);
        $db->find()->table('orders')->insert(['user_id' => 99999, 'amount' => 100.00]);
    }

    public function testUndefinedTable(): void
    {
        $db = self::$db;

        $this->expectException(PDOException::class);
        $db->rawQuery('SELECT * FROM non_existing_table');
    }

    public function testUndefinedColumn(): void
    {
        $db = self::$db;

        $this->expectException(PDOException::class);
        $db->find()
            ->from('users')
            ->select(['non_existing_column'])
            ->get();
    }
}

