<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\cache\CacheFactory;

/**
 * Tests for PdoDb::fromEnv().
 */
class PdoDbFromEnvTests extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure non-interactive mode for all tests
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        // Clean up all environment variables that might be set by tests
        $envVars = [
            'PDODB_NON_INTERACTIVE',
            'PHPUNIT',
            'PDODB_DRIVER',
            'PDODB_PATH',
            'PDODB_HOST',
            'PDODB_PORT',
            'PDODB_DATABASE',
            'PDODB_USERNAME',
            'PDODB_PASSWORD',
            'PDODB_CHARSET',
            'PDODB_ENV_PATH',
            'PDODB_PREFIX',
            'PDODB_UNIX_SOCKET',
            'PDODB_SSLCA',
            'PDODB_SSLCERT',
            'PDODB_SSLKEY',
            'PDODB_COMPRESS',
            'PDODB_SSLMODE',
            'PDODB_SSLROOTCERT',
            'PDODB_APPLICATION_NAME',
            'PDODB_CONNECT_TIMEOUT',
            'PDODB_HOSTADDR',
            'PDODB_SERVICE',
            'PDODB_TARGET_SESSION_ATTRS',
            'PDODB_OPTIONS',
            'PDODB_MODE',
            'PDODB_CACHE',
            'PDODB_ENABLE_REGEXP',
            'PDODB_TRUST_SERVER_CERTIFICATE',
            'PDODB_ENCRYPT',
            'PDODB_SERVICE_NAME',
            'PDODB_SID',
        ];
        foreach ($envVars as $var) {
            putenv($var);
            unset($_ENV[$var]);
        }
        parent::tearDown();
    }

    /**
     * Test fromEnv with SQLite.
     */
    public function testFromEnvWithSqlite(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // Test that connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with custom path.
     */
    public function testFromEnvWithCustomPath(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv throws exception when driver not set.
     */
    public function testFromEnvThrowsExceptionWhenDriverNotSet(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_PATH=:memory:");

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('PDODB_DRIVER not set in .env file');

            PdoDb::fromEnv($envFile);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with quoted values.
     */
    public function testFromEnvWithQuotedValues(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=\"sqlite\"\nPDODB_PATH=':memory:'");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with comments.
     */
    public function testFromEnvWithComments(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "# This is a comment\nPDODB_DRIVER=sqlite\n# Another comment\nPDODB_PATH=:memory:");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv does not overwrite existing env vars.
     */
    public function testFromEnvDoesNotOverwriteExistingEnvVars(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=mysql\nPDODB_PATH=:memory:");

        // Set existing environment variable
        putenv('PDODB_DRIVER=sqlite');
        $_ENV['PDODB_DRIVER'] = 'sqlite';

        try {
            $db = PdoDb::fromEnv($envFile);

            // Should use existing value (sqlite), not value from file (mysql)
            $this->assertInstanceOf(PdoDb::class, $db);
            // Verify it's using SQLite (should work with :memory:)
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with PDO options.
     */
    public function testFromEnvWithPdoOptions(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:");

        $pdoOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        try {
            $db = PdoDb::fromEnv($envFile, $pdoOptions);

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with logger.
     */
    public function testFromEnvWithLogger(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:");

        $logger = new NullLogger();

        try {
            $db = PdoDb::fromEnv($envFile, [], $logger);

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with cache.
     */
    public function testFromEnvWithCache(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:");

        $cacheConfig = [
            'type' => 'array',
            'enabled' => true,
        ];
        $cache = CacheFactory::create($cacheConfig);

        try {
            $db = PdoDb::fromEnv($envFile, [], null, $cache);

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with SQLite file path.
     */
    public function testFromEnvWithSqliteFilePath(): void
    {
        $dbPath = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.sqlite';
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH={$dbPath}");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // Test that connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv throws exception when required vars missing for non-SQLite.
     */
    public function testFromEnvThrowsExceptionWhenRequiredVarsMissing(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=mysql");

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('PDODB_DATABASE and PDODB_USERNAME must be set');

            PdoDb::fromEnv($envFile);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with invalid driver.
     */
    public function testFromEnvWithInvalidDriver(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=invalid_driver\nPDODB_PATH=:memory:");

        try {
            $this->expectException(InvalidArgumentException::class);

            PdoDb::fromEnv($envFile);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with default path (current directory).
     */
    public function testFromEnvWithDefaultPath(): void
    {
        $cwd = getcwd();
        $envFile = $cwd . '/.env';
        $backupExists = file_exists($envFile);
        $backupContent = $backupExists ? file_get_contents($envFile) : null;

        try {
            file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:");

            $db = PdoDb::fromEnv();

            $this->assertInstanceOf(PdoDb::class, $db);
        } finally {
            if ($backupExists && $backupContent !== null) {
                file_put_contents($envFile, $backupContent);
            } elseif (!$backupExists && file_exists($envFile)) {
                unlink($envFile);
            }
        }
    }

    /**
     * Test fromEnv with prefix option.
     */
    public function testFromEnvWithPrefix(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_PREFIX=test_");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            $this->assertEquals('test_', $db->prefix);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with SQLite mode option.
     */
    public function testFromEnvWithSqliteMode(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_MODE=rwc");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // Verify connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with SQLite cache option.
     */
    public function testFromEnvWithSqliteCache(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_CACHE=shared");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // Verify connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with SQLite enable_regexp option.
     */
    public function testFromEnvWithSqliteEnableRegexp(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_ENABLE_REGEXP=true");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // Verify connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with MySQL unix_socket option.
     */
    public function testFromEnvWithMysqlUnixSocket(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_UNIX_SOCKET=/tmp/mysql.sock");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, unix_socket is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with MySQL SSL options.
     */
    public function testFromEnvWithMysqlSslOptions(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_SSLCA=/path/ca.pem\nPDODB_SSLCERT=/path/cert.pem\nPDODB_SSLKEY=/path/key.pem");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, SSL options are ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with MySQL compress option.
     */
    public function testFromEnvWithMysqlCompress(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_COMPRESS=true");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, compress is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with PostgreSQL SSL options.
     */
    public function testFromEnvWithPostgresqlSslOptions(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_SSLMODE=require\nPDODB_SSLCERT=/path/cert.crt\nPDODB_SSLKEY=/path/key.key\nPDODB_SSLROOTCERT=/path/ca.crt");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, SSL options are ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with PostgreSQL application_name option.
     */
    public function testFromEnvWithPostgresqlApplicationName(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_APPLICATION_NAME=MyApp");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, application_name is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with PostgreSQL connect_timeout option.
     */
    public function testFromEnvWithPostgresqlConnectTimeout(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_CONNECT_TIMEOUT=5");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, connect_timeout is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with MSSQL trust_server_certificate option.
     */
    public function testFromEnvWithMssqlTrustServerCertificate(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_TRUST_SERVER_CERTIFICATE=false");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, trust_server_certificate is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with MSSQL encrypt option.
     */
    public function testFromEnvWithMssqlEncrypt(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_ENCRYPT=false");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, encrypt is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with Oracle service_name option.
     */
    public function testFromEnvWithOracleServiceName(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_SERVICE_NAME=XEPDB1");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, service_name is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with Oracle SID option.
     */
    public function testFromEnvWithOracleSid(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_SID=XE");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // For SQLite, SID is ignored, but should not cause errors
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv collects all PDODB_* variables.
     */
    public function testFromEnvCollectsAllPdodbVariables(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_PREFIX=test_\nPDODB_MODE=rwc\nPDODB_CACHE=shared\nPDODB_ENABLE_REGEXP=true\nPDODB_CUSTOM_OPTION=value");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            $this->assertEquals('test_', $db->prefix);
            // Verify connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test fromEnv with boolean values (true/false strings).
     */
    public function testFromEnvWithBooleanValues(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_COMPRESS=true\nPDODB_ENABLE_REGEXP=false\nPDODB_TRUST_SERVER_CERTIFICATE=1\nPDODB_ENCRYPT=0");

        try {
            $db = PdoDb::fromEnv($envFile);

            $this->assertInstanceOf(PdoDb::class, $db);
            // Verify connection works
            $result = $db->rawQuery('SELECT 1 as test');
            $this->assertTrue($result !== false);
        } finally {
            unlink($envFile);
        }
    }
}

