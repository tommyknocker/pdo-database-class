<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\query\interfaces\ConnectionAwareInterface;
use tommyknocker\pdodb\query\interfaces\FileLoaderInterface;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class FileLoader implements FileLoaderInterface, ConnectionAwareInterface
{
    use TableManagementTrait;

    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;

    /** @var string|null table name */
    protected ?string $table = null;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
    }

    /**
     * {@inheritDoc}
     */
    public function rebindConnection(ConnectionInterface $connection): void
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
    }

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $filePath The path to the CSV file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadCsv(string $filePath, array $options = []): bool
    {
        return $this->runLoad(
            'loadCsv',
            fn (): iterable => $this->connection->getDialect()->buildLoadCsvSqlGenerator($this->prefix . $this->table, $filePath, $options),
            ['file' => $filePath]
        );
    }

    /**
     * Loads data from an XML file into a table.
     *
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     *
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        return $this->runLoad(
            'loadXml',
            fn (): iterable => $this->connection->getDialect()->buildLoadXMLGenerator(
                $this->prefix . $this->table,
                $filePath,
                ['rowTag' => $rowTag, 'linesToIgnore' => $linesToIgnore]
            ),
            ['file' => $filePath, 'rowTag' => $rowTag]
        );
    }

    /**
     * Loads data from a JSON file into a table.
     *
     * @param string $filePath The path to the JSON file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadJson(string $filePath, array $options = []): bool
    {
        return $this->runLoad(
            'loadJson',
            fn (): iterable => $this->connection->getDialect()->buildLoadJsonGenerator($this->prefix . $this->table, $filePath, $options),
            ['file' => $filePath]
        );
    }

    /**
     * Run the statements a dialect generates for a file, all in one transaction.
     *
     * A transaction the caller already holds is left to the caller: it is neither committed nor rolled back here.
     * One that this method starts is committed on success and rolled back on any failure, including a file that
     * cannot be read, so the connection is never left inside a transaction.
     *
     * @param callable(): iterable<string> $statements Produces the SQL batches
     * @param array<string, mixed> $context Details for the exception of a failed statement
     */
    protected function runLoad(string $operation, callable $statements, array $context): bool
    {
        $sql = null;
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->transaction();
        }

        try {
            foreach ($statements() as $batchSql) {
                $sql = $batchSql;
                $this->connection->prepare($sql)->execute();
            }

            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->commit();
            }

            return $this->connection->getExecuteState() !== false;
        } catch (PDOException $e) {
            $this->rollBackOwned($ownsTransaction);

            // Convert to specialized exception and re-throw
            throw ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => $operation] + $context
            );
        } catch (\Throwable $e) {
            $this->rollBackOwned($ownsTransaction);

            throw $e;
        }
    }

    private function rollBackOwned(bool $ownsTransaction): void
    {
        if ($ownsTransaction && $this->connection->inTransaction()) {
            $this->connection->rollback();
        }
    }
}
