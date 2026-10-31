<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * Migration dry-run must not execute migration PHP on MySQL (SQLite DDL rollback hides bugs).
 */
final class MigrationDryRunTests extends BaseMySQLTestCase
{
    protected string $testMigrationPath;
    protected string $probeTable;
    protected string $historyTable;

    public static function setUpBeforeClass(): void
    {
        self::$db = new PdoDb('mysql', [
            'host' => self::DB_HOST, 'port' => self::DB_PORT, 'username' => self::DB_USER,
            'password' => self::DB_PASSWORD, 'dbname' => self::DB_NAME,
        ]);
    }

    public function setUp(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $this->probeTable = 'pdr_probe_' . $suffix;
        $this->historyTable = 'pdr_hist_' . $suffix;
        $this->testMigrationPath = sys_get_temp_dir() . '/pdodb_mysql_dryrun_' . uniqid('', true);
        mkdir($this->testMigrationPath, 0755, true);
    }

    public function tearDown(): void
    {
        if (is_dir($this->testMigrationPath)) {
            foreach (glob($this->testMigrationPath . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testMigrationPath);
        }
        foreach ([$this->probeTable, $this->historyTable] as $table) {
            if (self::$db->schema()->tableExists($table)) {
                self::$db->schema()->dropTable($table);
            }
        }
    }

    public function testDryRunDoesNotCreateTableFromMigration(): void
    {
        $tableName = $this->probeTable;
        $migrationPath = $this->testMigrationPath;
        $testMigrationContent = <<<PHP
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20261101120000Dryrunprobe extends Migration {
    public function up(): void {
        \$this->schema()->createTable('{$tableName}', [
            'id' => \$this->schema()->primaryKey(),
        ]);
    }
    public function down(): void {
        \$this->schema()->dropTable('{$tableName}');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20261101120000_dryrun_probe.php', $testMigrationContent);

        $runner = new class (self::$db, $migrationPath, $this->historyTable) extends MigrationRunner {
            public function __construct(PdoDb $db, string $path, string $table)
            {
                $this->migrationTable = $table;
                parent::__construct($db, $path);
            }
        };
        $runner->setDryRun(true);
        $runner->migrate(0);

        $this->assertFalse(self::$db->schema()->tableExists($tableName));
        $this->assertFalse(self::$db->schema()->tableExists($this->historyTable));
        $queries = implode("\n", $runner->getCollectedQueries());
        $this->assertStringContainsString('Dry-run: migration PHP is not executed.', $queries);
    }
}
