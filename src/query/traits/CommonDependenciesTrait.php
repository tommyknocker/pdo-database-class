<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\traits;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
use tommyknocker\pdodb\query\RawValueResolver;

trait CommonDependenciesTrait
{
    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected ParameterManagerInterface $parameterManager;
    protected ExecutionEngineInterface $executionEngine;
    protected RawValueResolver $rawValueResolver;

    /**
     * Initialize common dependencies.
     *
     * @param ConnectionInterface $connection
     * @param ParameterManagerInterface $parameterManager
     * @param ExecutionEngineInterface $executionEngine
     * @param RawValueResolver $rawValueResolver
     */
    protected function initializeCommonDependencies(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        RawValueResolver $rawValueResolver
    ): void {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->parameterManager = $parameterManager;
        $this->executionEngine = $executionEngine;
        $this->rawValueResolver = $rawValueResolver;
    }
}
