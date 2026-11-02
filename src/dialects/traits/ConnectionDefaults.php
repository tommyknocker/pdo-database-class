<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use PDO;
use tommyknocker\pdodb\dialects\ConnectionInitHandler;
use tommyknocker\pdodb\dialects\roles\DialectConnectionInterface;

/**
 * Default implementation of the driver, PDO handle, DSN, environment configuration, error codes and statement normalisation part of the dialect ({@see DialectConnectionInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait ConnectionDefaults
{
    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function applyConfig(array $config): void
    {
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function requiredEnvVarKeys(): array
    {
        return ['PDODB_DATABASE', 'PDODB_USERNAME'];
    }

    public function connectionInitHandler(): ConnectionInitHandler
    {
        return ConnectionInitHandler::MysqlTcp;
    }

    public function examplesConfigBasename(): string
    {
        return $this->getDriverName();
    }

    public function canBuildConfigFromEnv(array $envVars): bool
    {
        foreach ($this->requiredEnvVarKeys() as $key) {
            if (!isset($envVars[$key])) {
                return false;
            }
        }

        return true;
    }

    public function isFileBasedConnection(): bool
    {
        return false;
    }

    public function defaultTcpPort(): int
    {
        return 3306;
    }

    public function mapsDatabaseToDbnameInInit(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function initWizardConnectionExtras(): array
    {
        return [];
    }

    public function initWizardDefaultCharset(): ?string
    {
        return null;
    }

    public function requiredPdoExtension(): string
    {
        return 'pdo_mysql';
    }

    /**
     * @return array<string, bool>
     */
    public function agentDevelopmentContextCapabilities(): array
    {
        return [
            'explain_analyze' => false,
            'execute_sql_tool' => false,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeSqlForExecution(string $sql): string
    {
        // Default: return SQL as-is (most dialects don't need normalization)
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorDescription(int|string $errorCode): string
    {
        // Default implementation
        // Dialects should override this method
        return 'Unknown error';
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeConfigParams(array $config): array
    {
        // Default implementation - normalize 'database' to 'dbname' if needed
        if (isset($config['database']) && !isset($config['dbname'])) {
            $config['dbname'] = $config['database'];
        }
        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function buildPingSql(): string
    {
        return 'SELECT 1';
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRowKeys(array $rows, array $options = []): array
    {
        // Default implementation returns rows unchanged
        return $rows;
    }
}
