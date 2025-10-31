<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDO;
use PDOException;
use PDOStatement;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;

class ExecutionEngine implements ExecutionEngineInterface
{
    protected ConnectionInterface $connection;
    protected RawValueResolver $rawValueResolver;
    protected ParameterManagerInterface $parameterManager;
    protected int $fetchMode = PDO::FETCH_ASSOC;
    protected ?QueryProfiler $profiler = null;

    public function __construct(
        ConnectionInterface $connection,
        RawValueResolver $rawValueResolver,
        ParameterManagerInterface $parameterManager,
        ?QueryProfiler $profiler = null
    ) {
        $this->connection = $connection;
        $this->rawValueResolver = $rawValueResolver;
        $this->parameterManager = $parameterManager;
        $this->profiler = $profiler;
    }

    /**
     * Set query profiler.
     *
     * @param QueryProfiler|null $profiler
     *
     * @return self
     */
    public function setProfiler(?QueryProfiler $profiler): self
    {
        $this->profiler = $profiler;
        return $this;
    }

    /**
     * Get query profiler.
     *
     * @return QueryProfiler|null
     */
    public function getProfiler(): ?QueryProfiler
    {
        return $this->profiler;
    }

    /**
     * Execute statement.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return PDOStatement
     * @throws PDOException
     */
    public function executeStatement(string|RawValue $sql, array $params = []): PDOStatement
    {
        $sqlString = $this->resolveRawValue($sql);

        // Start profiling
        $queryId = $this->profiler?->startQuery($sqlString, $params) ?? -1;

        try {
            // Only normalize params if they're not from ParameterManager (empty array means use ParameterManager params)
            if (!empty($params)) {
                $params = $this->normalizeParams($params);
            } else {
                $params = $this->parameterManager->getParams();
            }
            $result = $this->connection->prepare($sqlString)->execute($params);
            $this->parameterManager->clearParams();

            // End profiling
            $this->profiler?->endQuery($queryId);

            return $result;
        } catch (PDOException $e) {
            // End profiling even on error
            $this->profiler?->endQuery($queryId);

            throw $e;
        }
    }

    /**
     * Fetch all rows.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function fetchAll(string|RawValue $sql, array $params = []): array
    {
        return $this->executeStatement($sql, $params)->fetchAll($this->fetchMode);
    }

    /**
     * Fetch column.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     * @throws PDOException
     */
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executeStatement($sql, $params)->fetchColumn();
    }

    /**
     * Fetch row.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     * @throws PDOException
     */
    public function fetch(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executeStatement($sql, $params)->fetch($this->fetchMode);
    }

    /**
     * Execute INSERT statement.
     *
     * @param string $sql
     * @param array<string, string|int|float|bool|null> $params
     * @param bool $isMulty
     *
     * @return int
     * @throws PDOException
     */
    public function executeInsert(string $sql, array $params, bool $isMulty = false): int
    {
        $stmt = $this->executeStatement($sql, $params);
        if ($isMulty) {
            return $stmt->rowCount();
        }

        try {
            $id = (int)$this->connection->getLastInsertId();
            return $id > 0 ? $id : 1;
        } catch (PDOException $e) {
            // PostgreSQL: lastval is not yet defined (no SERIAL column)
            // Convert to specialized exception for better error handling
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => 'getLastInsertId', 'fallback' => true]
            );

            // For this specific case, we return 1 as fallback instead of throwing
            // This maintains backward compatibility
            return 1;
        }
    }

    /**
     * Set fetch mode.
     *
     * @param int $fetchMode
     *
     * @return self
     */
    public function setFetchMode(int $fetchMode): self
    {
        $this->fetchMode = $fetchMode;
        return $this;
    }

    /**
     * Get fetch mode.
     *
     * @return int
     */
    public function getFetchMode(): int
    {
        return $this->fetchMode;
    }

    /**
     * Resolve RawValue instances.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    protected function resolveRawValue(string|RawValue $value): string
    {
        return $this->rawValueResolver->resolveRawValue($value);
    }

    /**
     * Normalize parameters.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int|string, string|int|float|bool|null>
     */
    protected function normalizeParams(array $params): array
    {
        // Check if all keys are sequential integers starting from 0 (positional parameters)
        $keys = array_keys($params);
        if ($keys === range(0, count($keys) - 1)) {
            // Positional parameters - return as is
            return $params;
        }

        // Named parameters - normalize by adding : prefix if needed
        /** @var array<string, string|int|float|bool|null> $out */
        $out = [];
        foreach ($params as $k => $v) {
            if (is_string($k)) {
                $key = !str_starts_with($k, ':') ? ":$k" : $k;
            } else {
                $key = ':param_' . $k;
            }
            $out[$key] = $v;
        }
        return $out;
    }
}
