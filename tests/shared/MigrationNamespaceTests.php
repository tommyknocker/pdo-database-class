<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\migrations\MigrationRunner;

/**
 * Migrations may live in the application's own namespace.
 */
final class MigrationNamespaceTests extends BaseSharedTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/pdodb_migration_ns_' . bin2hex(random_bytes(4));
        mkdir($this->path, 0755, true);
        self::$db->rawQuery('DROP TABLE IF EXISTS __migrations');
        self::$db->rawQuery('DROP TABLE IF EXISTS ns_marker');
    }

    protected function tearDown(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS ns_marker');
        self::$db->rawQuery('DROP TABLE IF EXISTS __migrations');
        array_map('unlink', glob($this->path . '/*') ?: []);
        rmdir($this->path);
        parent::tearDown();
    }

    public function testMigrationInTheApplicationNamespaceIsFound(): void
    {
        file_put_contents($this->path . '/m2026_01_01_000000_ns_marker.php', <<<'PHP'
            <?php

            namespace App\Database\Migrations;

            use tommyknocker\pdodb\migrations\Migration;

            class m20260101000000NsMarker extends Migration
            {
                public function up(): void
                {
                    $this->db->rawQuery('CREATE TABLE ns_marker (id INTEGER PRIMARY KEY)');
                }

                public function down(): void
                {
                    $this->db->rawQuery('DROP TABLE ns_marker');
                }
            }
            PHP);

        $runner = (new MigrationRunner(self::$db, $this->path))->setMigrationNamespace('\\App\\Database\\Migrations\\');
        $applied = $runner->migrate();

        $this->assertSame(['2026_01_01_000000_ns_marker'], $applied);
        $this->assertTrue(self::$db->schema()->tableExists('ns_marker'));
    }
}
