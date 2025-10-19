<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\MySQLDialect;
use tommyknocker\pdodb\dialects\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\SqliteDialect;

/**
 * ConnectionFactory
 *
 * Build DSN, create PDO and wire Dialect -> Connection.
 */
class ConnectionFactory
{
    /**
     * Create a new Connection instance.
     *
     * @param array $config Configuration array.
     * @param LoggerInterface|null $logger Logger instance.
     * @return Connection The created Connection instance.
     * @throws InvalidArgumentException If the driver is not specified or unsupported.
     */
    public function create(array $config, ?LoggerInterface $logger): Connection
    {
        $driver = $config['driver'] ?? throw new InvalidArgumentException('driver is required');
        $dialect = $this->resolveDialect($driver);
        $pdo = $config['pdo'] ?? null;
        if (!$pdo) {
            $dsn = $dialect->buildDsn($config);
            $user = $config['username'] ?? '';
            $pass = $config['password'] ?? '';
            $options = $dialect->defaultPdoOptions() + ($config['options'] ?? []);
            $pdo = new PDO($dsn, $user, $pass, $options);
        }
        $dialect->setPdo($pdo);
        return new Connection($pdo, $dialect, $logger);
    }

    /**
     * Resolve the dialect based on the driver name.
     *
     * @param string $driver The driver name.
     * @return DialectInterface The resolved dialect instance.
     * @throws InvalidArgumentException If the driver is unsupported.
     */
    protected function resolveDialect(string $driver): DialectInterface
    {
        return match ($driver) {
            'mysql' => new MySQLDialect(),
            'sqlite' => new SqliteDialect(),
            'pgsql' => new PostgreSQLDialect(),
            default => throw new InvalidArgumentException('Unsupported driver: ' . $driver),
        };
    }
}
