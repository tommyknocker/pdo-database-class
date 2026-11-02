<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use PDO;
use tommyknocker\pdodb\ai\AgentContextService;
use tommyknocker\pdodb\dialects\ConnectionInitHandler;
use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Driver, PDO handle, DSN and environment configuration, error codes and statement normalisation.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectConnectionInterface
{
    /**
     * Returns the driver name.
     *
     * @return string The driver name.
     */
    public function getDriverName(): string;

    /**
     * Set PDO instance.
     *
     * @param PDO $pdo
     */
    public function setPdo(PDO $pdo): void;

    /**
     * Take the dialect-specific settings from a connection's configuration array (for example MySQL's
     * `use_upsert_alias`). Keys a dialect does not know are ignored.
     *
     * @param array<string, mixed> $config
     */
    public function applyConfig(array $config): void;

    /**
     * Get PDO instance.
     *
     * @return PDO|null The PDO instance or null if not set
     */
    public function getPdo(): ?PDO;

    /**
     * Build dsn string.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function buildDsn(array $params): string;

    /**
     * Returns the default PDO options.
     *
     * @return array<int|string, mixed> The default PDO options.
     */
    public function defaultPdoOptions(): array;

    /**
     * Required PDODB_* env var keys besides PDODB_DRIVER (empty for SQLite file-only configs).
     *
     * @return list<string>
     */
    public function requiredEnvVarKeys(): array;

    /**
     * Init wizard / env bootstrap profile.
     */
    public function connectionInitHandler(): ConnectionInitHandler;

    /**
     * Basename for examples/config.{name}.php (oci → oracle).
     */
    public function examplesConfigBasename(): string;

    /**
     * Whether PDODB_* env vars are sufficient to build a connection config.
     *
     * @param array<string, string> $envVars
     */
    public function canBuildConfigFromEnv(array $envVars): bool;

    /**
     * File-based connection (SQLite) vs network credentials.
     */
    public function isFileBasedConnection(): bool;

    /**
     * Default TCP port for init wizard prompts.
     */
    public function defaultTcpPort(): int;

    /**
     * Copy wizard "database" into "dbname" for DSN building.
     */
    public function mapsDatabaseToDbnameInInit(): bool;

    /**
     * Extra connection keys for init wizard (e.g. sqlsrv encrypt flags).
     *
     * @return array<string, mixed>
     */
    public function initWizardConnectionExtras(): array;

    /**
     * Default charset prompt value, or null when charset is not collected.
     */
    public function initWizardDefaultCharset(): ?string;

    /**
     * Required PHP extension name (pdo_mysql, pdo_pgsql, …).
     */
    public function requiredPdoExtension(): string;

    /**
     * Capabilities for {@see AgentContextService} (excluding plan explain flags).
     *
     * @return array<string, bool>
     */
    public function agentDevelopmentContextCapabilities(): array;

    /**
     * Normalize raw SQL value for dialect-specific function replacements.
     * Replaces function names like LENGTH->LEN, CEIL->CEILING, TRUE/FALSE->1/0, etc.
     *
     * @param string $sql Raw SQL string
     *
     * @return string Normalized SQL string
     */
    public function normalizeRawValue(string $sql): string;

    /**
     * Normalize SQL statement before execution (prepare/query/exec).
     * This is called before SQL is sent to PDO for execution.
     * Dialects can use this to remove trailing semicolons, fix syntax, etc.
     *
     * @param string $sql SQL statement to normalize
     *
     * @return string Normalized SQL statement
     */
    public function normalizeSqlForExecution(string $sql): string;

    /**
     * Normalize row keys for dialect-specific requirements.
     * For example, Oracle may need to convert CLOB resources to strings and lowercase keys.
     *
     * @param array<int, array<string, mixed>> $rows Result rows
     * @param array<string, mixed> $options Connection options
     *
     * @return array<int, array<string, mixed>> Normalized rows
     */
    public function normalizeRowKeys(array $rows, array $options = []): array;

    /**
     * Get retryable error codes for this dialect.
     *
     * @return array<int|string> Array of error codes that can be retried
     */
    public function getRetryableErrorCodes(): array;

    /**
     * Get human-readable error description for an error code.
     *
     * @param int|string $errorCode Error code
     *
     * @return string Human-readable error description
     */
    public function getErrorDescription(int|string $errorCode): string;

    /**
     * Build configuration array from environment variables.
     *
     * @param array<string, string> $envVars Environment variables (PDODB_*)
     *
     * @return array<string, mixed> Configuration array
     */
    public function buildConfigFromEnv(array $envVars): array;

    /**
     * Normalize configuration parameters (e.g., 'database' -> 'dbname').
     *
     * @param array<string, mixed> $config Configuration array
     *
     * @return array<string, mixed> Normalized configuration array
     */
    public function normalizeConfigParams(array $config): array;

    /**
     * Build SQL for ping query.
     * Used to check database connection health.
     *
     * @return string SQL query for ping
     */
    public function buildPingSql(): string;
}
