<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

/**
 * Typical driver errors must land in the right category on every database, because retry decisions depend on it.
 */
final class ErrorClassificationTests extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: bool}>
     */
    public static function errors(): array
    {
        return [
            'mysql duplicate' => ['mysql', '23000', 1062, 'Duplicate entry', 'constraint', false],
            'mysql deadlock' => ['mysql', '40001', 1213, 'Deadlock found when trying to get lock', 'transaction', true],
            'mariadb duplicate' => ['mariadb', '23000', 1062, 'Duplicate entry', 'constraint', false],
            'pgsql unique' => ['pgsql', '23505', 7, 'duplicate key value violates unique constraint', 'constraint', false],
            'pgsql deadlock' => ['pgsql', '40P01', 7, 'deadlock detected', 'transaction', true],
            'sqlsrv unique' => ['sqlsrv', '23000', 2627, 'Violation of UNIQUE KEY constraint', 'constraint', false],
            'sqlsrv primary key' => ['sqlsrv', '23000', 2627, 'Violation of PRIMARY KEY constraint', 'constraint', false],
            'sqlsrv foreign key' => ['sqlsrv', '23000', 547, 'The INSERT statement conflicted with the FOREIGN KEY constraint', 'constraint', false],
            'sqlsrv null' => ['sqlsrv', '23000', 515, "Cannot insert the value NULL into column 'name'", 'constraint', false],
            'sqlsrv deadlock' => ['sqlsrv', '40001', 1205, 'Transaction (Process ID 52) was deadlocked on lock resources', 'transaction', true],
            'sqlsrv login' => ['sqlsrv', '28000', 18456, "Login failed for user 'app'", 'authentication', false],
            'sqlsrv unreachable' => ['sqlsrv', '08001', 53, 'Named Pipes Provider: Could not open a connection to SQL Server', 'connection', true],
            'oracle unique' => ['oci', 'HY000', 1, 'ORA-00001: unique constraint (APP.USERS_PK) violated', 'constraint', false],
            'oracle parent missing' => ['oci', 'HY000', 2291, 'ORA-02291: integrity constraint violated - parent key not found', 'constraint', false],
            'oracle child exists' => ['oci', 'HY000', 2292, 'ORA-02292: integrity constraint violated - child record found', 'constraint', false],
            'oracle null' => ['oci', 'HY000', 1400, 'ORA-01400: cannot insert NULL into ("APP"."USERS"."NAME")', 'constraint', false],
            'oracle deadlock' => ['oci', 'HY000', 60, 'ORA-00060: deadlock detected while waiting for resource', 'transaction', true],
            'oracle login' => ['oci', 'HY000', 1017, 'ORA-01017: invalid username/password; logon denied', 'authentication', false],
            'oracle no listener' => ['oci', 'HY000', 12541, 'ORA-12541: TNS:no listener', 'connection', true],
            'oracle connection lost' => ['oci', 'HY000', 3113, 'ORA-03113: end-of-file on communication channel', 'connection', true],
        ];
    }

    #[DataProvider('errors')]
    public function testDriverErrorsAreCategorized(string $driver, string $state, int $code, string $message, string $category, bool $retryable): void
    {
        $exception = new PDOException($message);
        $exception->errorInfo = [$state, $code, $message];

        $result = ExceptionFactory::createFromPdoException($exception, $driver, null, []);

        $this->assertSame($category, $result->getCategory());
        $this->assertSame($retryable, $result->isRetryable());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function nativeCodesWithNeutralText(): array
    {
        return [
            'sqlsrv unique index' => ['sqlsrv', '23000', 2601, 'constraint'],
            'sqlsrv primary key' => ['sqlsrv', '23000', 2627, 'constraint'],
            'sqlsrv not null' => ['sqlsrv', '23000', 515, 'constraint'],
            'sqlsrv foreign key' => ['sqlsrv', '23000', 547, 'constraint'],
            'sqlsrv deadlock' => ['sqlsrv', '40001', 1205, 'transaction'],
            'sqlsrv lock timeout' => ['sqlsrv', '37000', 1222, 'transaction'],
            'sqlsrv login' => ['sqlsrv', '28000', 18456, 'authentication'],
            'sqlsrv cannot open database' => ['sqlsrv', '42000', 4060, 'authentication'],
            'sqlsrv no server' => ['sqlsrv', '08001', 53, 'connection'],
            'sqlsrv out of memory' => ['sqlsrv', '42000', 701, 'resource'],
            'oracle unique' => ['oci', 'HY000', 1, 'constraint'],
            'oracle child record' => ['oci', 'HY000', 2292, 'constraint'],
            'oracle deadlock' => ['oci', 'HY000', 60, 'transaction'],
            'oracle resource busy' => ['oci', 'HY000', 54, 'transaction'],
            'oracle login' => ['oci', 'HY000', 1017, 'authentication'],
            'oracle no listener' => ['oci', 'HY000', 12541, 'connection'],
            'oracle end of file' => ['oci', 'HY000', 3113, 'connection'],
            'oracle tablespace' => ['oci', 'HY000', 1653, 'resource'],
        ];
    }

    #[DataProvider('nativeCodesWithNeutralText')]
    public function testNativeCodesClassifyWhateverTheMessageSays(string $driver, string $state, int $code, string $category): void
    {
        // A localized or reworded server message must not change the category
        $exception = new PDOException('Fehler');
        $exception->errorInfo = [$state, $code, 'Fehler'];

        $this->assertSame($category, ExceptionFactory::createFromPdoException($exception, $driver, null, [])->getCategory());
    }

    public function testANumberIsOnlyComparedWithTheCodesOfTheDriverThatRaisedIt(): void
    {
        // 1 is a unique violation for Oracle but SQLITE_ERROR (a plain SQL error) for SQLite; 60 and 54 are Oracle
        // lock errors but mean nothing special elsewhere
        foreach ([['sqlite', 'HY000', 1], ['mysql', 'HY000', 60], ['pgsql', 'HY000', 54], ['sqlsrv', 'HY000', 1]] as [$driver, $state, $code]) {
            $exception = new PDOException('syntax problem');
            $exception->errorInfo = [$state, $code, 'syntax problem'];

            $this->assertSame('query', ExceptionFactory::createFromPdoException($exception, $driver, null, [])->getCategory(), "{$driver} {$code}");
        }
    }
}
