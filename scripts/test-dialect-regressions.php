<?php

declare(strict_types=1);

/**
 * Runs the dialect regression tests (tests/integration/*RegressionTests.php) against each database they apply to.
 *
 * Usage: php scripts/test-dialect-regressions.php [database ...]   (sqlite mysql mariadb postgresql mssql oracle; all by default)
 *
 * Each test class takes one database from PDODB_TEST_DRIVER, PDODB_TEST_DSN, PDODB_TEST_USER and PDODB_TEST_PASSWORD. They are
 * built here from the same settings the dialect suites use (tests/<dialect>/Base*TestCase::connectionConfig()), so a local
 * run and a CI job connect alike. Exit status is non-zero when any run fails.
 */

use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\tests\mariadb\BaseMariaDBTestCase;
use tommyknocker\pdodb\tests\mssql\BaseMSSQLTestCase;
use tommyknocker\pdodb\tests\mysql\BaseMySQLTestCase;
use tommyknocker\pdodb\tests\oracle\BaseOracleTestCase;
use tommyknocker\pdodb\tests\postgresql\BasePostgreSQLTestCase;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const SELECT = 'SelectExecutionRegressionTests';
const READ_ONLY = 'ReadOnlyDialectRegressionTests';
const TEMPORARY = 'TemporaryDialectRegressionTests';

/**
 * Test classes apply to the databases below by design: the SELECT regressions create CREATE TEMPORARY TABLE tables (MySQL
 * syntax), the temporary-table regressions test SQL Server and Oracle behaviour.
 *
 * @var array<string, array{driver: string, settings: ?callable, files: list<string>}> $databases
 */
$databases = [
    'sqlite' => ['driver' => 'sqlite', 'settings' => null, 'files' => [SELECT, READ_ONLY]],
    'mysql' => ['driver' => 'mysql', 'settings' => [BaseMySQLTestCase::class, 'connectionConfig'], 'files' => [SELECT, READ_ONLY]],
    'mariadb' => ['driver' => 'mysql', 'settings' => [BaseMariaDBTestCase::class, 'connectionConfig'], 'files' => [SELECT, READ_ONLY]],
    'postgresql' => ['driver' => 'pgsql', 'settings' => [BasePostgreSQLTestCase::class, 'connectionConfig'], 'files' => [SELECT, READ_ONLY]],
    'mssql' => ['driver' => 'sqlsrv', 'settings' => [BaseMSSQLTestCase::class, 'connectionConfig'], 'files' => [READ_ONLY, TEMPORARY]],
    'oracle' => ['driver' => 'oci', 'settings' => [BaseOracleTestCase::class, 'connectionConfig'], 'files' => [READ_ONLY, TEMPORARY]],
];

$selected = array_slice($argv, 1) ?: array_keys($databases);
foreach ($selected as $name) {
    if (!isset($databases[$name])) {
        fwrite(STDERR, "Unknown database '{$name}'. Known: " . implode(', ', array_keys($databases)) . "\n");
        exit(2);
    }
}

$failed = [];
foreach ($selected as $name) {
    $database = $databases[$name];
    $config = $database['settings'] === null ? ['path' => ':memory:'] : ($database['settings'])();
    $dsn = DialectRegistry::resolve($database['driver'])->buildDsn($config + ['driver' => $database['driver']]);
    foreach ($database['files'] as $file) {
        echo "\n== {$name}: {$file}\n";
        $command = [PHP_BINARY, $root . '/vendor/bin/phpunit', '--no-progress', $root . '/tests/integration/' . $file . '.php'];
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, [
            'PDODB_TEST_DRIVER' => $database['driver'],
            'PDODB_TEST_DSN' => $dsn,
            'PDODB_TEST_USER' => (string)($config['username'] ?? ''),
            'PDODB_TEST_PASSWORD' => (string)($config['password'] ?? ''),
        ] + getenv());
        if (!is_resource($process) || proc_close($process) !== 0) {
            $failed[] = "{$name}: {$file}";
        }
    }
}

if ($failed !== []) {
    echo "\nFailed:\n  " . implode("\n  ", $failed) . "\n";
    exit(1);
}
echo "\nAll dialect regression tests passed.\n";
