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
