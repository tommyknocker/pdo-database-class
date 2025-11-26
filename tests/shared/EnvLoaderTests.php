<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\connection\EnvLoader;

/**
 * Tests for EnvLoader.
 */
class EnvLoaderTests extends TestCase
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
            'PDODB_TEST',
            'PDODB_TEST_VALUE',
            'PDODB_QUOTED',
        ];
        foreach ($envVars as $var) {
            putenv($var);
            unset($_ENV[$var]);
        }
        parent::tearDown();
    }

    /**
     * Test load with default path.
     */
    public function testLoadWithDefaultPath(): void
    {
        // Create temporary .env file in current working directory
        $cwd = getcwd();
        $envFile = $cwd . '/.env';
        $backupExists = file_exists($envFile);
        $backupContent = $backupExists ? file_get_contents($envFile) : null;

        try {
            file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_TEST=value");

            $result = EnvLoader::load();

            $this->assertTrue($result);
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
            $this->assertEquals('value', getenv('PDODB_TEST'));
        } finally {
            if ($backupExists && $backupContent !== null) {
                file_put_contents($envFile, $backupContent);
            } elseif (!$backupExists && file_exists($envFile)) {
                unlink($envFile);
            }
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_TEST');
        }
    }

    /**
     * Test load with custom path.
     */
    public function testLoadWithCustomPath(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\nPDODB_TEST=value");

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
            $this->assertEquals('value', getenv('PDODB_TEST'));
        } finally {
            unlink($envFile);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_TEST');
        }
    }

    /**
     * Test load with non-existent file.
     */
    public function testLoadWithNonExistentFile(): void
    {
        $result = EnvLoader::load('/nonexistent/path/.env');

        $this->assertFalse($result);
    }

    /**
     * Test load parses key-value pairs.
     */
    public function testLoadParsesKeyValuePairs(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "KEY1=value1\nKEY2=value2\nKEY3=value3");

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            $this->assertEquals('value1', getenv('KEY1'));
            $this->assertEquals('value2', getenv('KEY2'));
            $this->assertEquals('value3', getenv('KEY3'));
        } finally {
            unlink($envFile);
            putenv('KEY1');
            putenv('KEY2');
            putenv('KEY3');
        }
    }

    /**
     * Test load ignores comments.
     */
    public function testLoadIgnoresComments(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "# This is a comment\nPDODB_DRIVER=sqlite\n# Another comment\nPDODB_PATH=:memory:");

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
            // Comments should not be set as environment variables
            $this->assertFalse(getenv('This'));
            $this->assertFalse(getenv('Another'));
        } finally {
            unlink($envFile);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test load removes quotes.
     */
    public function testLoadRemovesQuotes(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_PASSWORD=\"secret123\"\nPDODB_PATH=':memory:'\nPDODB_TEST=\"double\"\nPDODB_TEST2='single'");

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            $this->assertEquals('secret123', getenv('PDODB_PASSWORD'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
            $this->assertEquals('double', getenv('PDODB_TEST'));
            $this->assertEquals('single', getenv('PDODB_TEST2'));
        } finally {
            unlink($envFile);
            putenv('PDODB_PASSWORD');
            putenv('PDODB_PATH');
            putenv('PDODB_TEST');
            putenv('PDODB_TEST2');
        }
    }

    /**
     * Test load does not overwrite existing.
     */
    public function testLoadDoesNotOverwriteExisting(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_TEST=new_value");

        // Set existing environment variable
        putenv('PDODB_TEST=existing_value');
        $_ENV['PDODB_TEST'] = 'existing_value';

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            // Existing value should be preserved
            $this->assertEquals('existing_value', getenv('PDODB_TEST'));
        } finally {
            unlink($envFile);
            putenv('PDODB_TEST');
        }
    }

    /**
     * Test load overwrites when requested.
     */
    public function testLoadOverwritesWhenRequested(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_TEST=new_value");

        // Set existing environment variable
        putenv('PDODB_TEST=existing_value');
        $_ENV['PDODB_TEST'] = 'existing_value';

        try {
            $result = EnvLoader::load($envFile, true);

            $this->assertTrue($result);
            // New value should overwrite existing
            $this->assertEquals('new_value', getenv('PDODB_TEST'));
        } finally {
            unlink($envFile);
            putenv('PDODB_TEST');
        }
    }

    /**
     * Test loadAsArray.
     */
    public function testLoadAsArray(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "KEY1=value1\n# Comment\nKEY2=\"value2\"\nKEY3='value3'");

        try {
            $result = EnvLoader::loadAsArray($envFile);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('KEY1', $result);
            $this->assertEquals('value1', $result['KEY1']);
            $this->assertArrayHasKey('KEY2', $result);
            $this->assertEquals('value2', $result['KEY2']);
            $this->assertArrayHasKey('KEY3', $result);
            $this->assertEquals('value3', $result['KEY3']);
            // Comments should not be in array
            $this->assertArrayNotHasKey('Comment', $result);
        } finally {
            unlink($envFile);
        }
    }

    /**
     * Test loadAsArray with non-existent file.
     */
    public function testLoadAsArrayWithNonExistentFile(): void
    {
        $result = EnvLoader::loadAsArray('/nonexistent/path/.env');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getDefaultEnvPath.
     */
    public function testGetDefaultEnvPath(): void
    {
        // Test default path (current working directory)
        $expectedPath = getcwd() . '/.env';
        $path = EnvLoader::getDefaultEnvPath();
        $this->assertEquals($expectedPath, $path);
    }

    /**
     * Test getDefaultEnvPath with PDODB_ENV_PATH set.
     */
    public function testGetDefaultEnvPathWithCustomEnvPath(): void
    {
        $customPath = '/custom/path/.env';
        putenv("PDODB_ENV_PATH={$customPath}");

        try {
            $path = EnvLoader::getDefaultEnvPath();
            $this->assertEquals($customPath, $path);
        } finally {
            putenv('PDODB_ENV_PATH');
        }
    }

    /**
     * Test load with empty lines.
     */
    public function testLoadWithEmptyLines(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "KEY1=value1\n\nKEY2=value2\n\n\nKEY3=value3");

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            $this->assertEquals('value1', getenv('KEY1'));
            $this->assertEquals('value2', getenv('KEY2'));
            $this->assertEquals('value3', getenv('KEY3'));
        } finally {
            unlink($envFile);
            putenv('KEY1');
            putenv('KEY2');
            putenv('KEY3');
        }
    }

    /**
     * Test load with values containing equals sign.
     */
    public function testLoadWithValuesContainingEquals(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "KEY1=value=with=equals\nKEY2=normal_value");

        try {
            $result = EnvLoader::load($envFile);

            $this->assertTrue($result);
            $this->assertEquals('value=with=equals', getenv('KEY1'));
            $this->assertEquals('normal_value', getenv('KEY2'));
        } finally {
            unlink($envFile);
            putenv('KEY1');
            putenv('KEY2');
        }
    }
}

