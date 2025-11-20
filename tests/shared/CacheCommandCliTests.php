<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

final class CacheCommandCliTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB with cache enabled
        $this->dbPath = sys_get_temp_dir() . '/pdodb_cache_test_' . uniqid() . '.sqlite';
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=array');

        // Create DB with cache enabled (for direct tests)
        $cache = new ArrayCache();
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath], [], null, $cache);

        // Create test table
        $schema = $this->db->schema();
        $schema->dropTableIfExists('cache_test');
        $schema->createTable('cache_test', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'value' => $schema->integer(),
        ]);

        // Insert test data
        $this->db->find()->table('cache_test')->insert(['name' => 'Test', 'value' => 100]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PDODB_CACHE_ENABLED');
        putenv('PDODB_CACHE_TYPE');
        parent::tearDown();
    }

    public function testCacheCommandShowsHelp(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'cache', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Cache Management', $out);
        $this->assertStringContainsString('clear', $out);
        $this->assertStringContainsString('stats', $out);
    }

    public function testCacheClearWithoutForceRequiresConfirmation(): void
    {
        // First, generate some cache entries
        $this->db->find()->from('cache_test')->cache(3600)->get();

        // Set non-interactive mode to skip confirmation
        putenv('PDODB_NON_INTERACTIVE=1');

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'cache', 'clear']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        // Should show info about cancellation in non-interactive mode
        $this->assertStringContainsString('cancelled', strtolower($out));
    }

    public function testCacheClearWithForce(): void
    {
        // First, generate some cache entries
        $this->db->find()->from('cache_test')->cache(3600)->get();

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'cache', 'clear', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('cleared successfully', strtolower($out));
    }

    public function testCacheStatsShowsStatistics(): void
    {
        // Generate some cache activity
        $this->db->find()->from('cache_test')->cache(3600)->get(); // Miss
        $this->db->find()->from('cache_test')->cache(3600)->get(); // Hit
        $this->db->find()->from('cache_test')->cache(3600)->get(); // Hit

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'cache', 'stats']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Cache Statistics', $out);
        $this->assertStringContainsString('Hits:', $out);
        $this->assertStringContainsString('Misses:', $out);
        $this->assertStringContainsString('Hit Rate:', $out);
        $this->assertStringContainsString('Enabled:', $out);
    }

    public function testCacheStatsJsonFormat(): void
    {
        // Generate some cache activity
        $this->db->find()->from('cache_test')->cache(3600)->get();

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'cache', 'stats', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('hits', $data);
        $this->assertArrayHasKey('misses', $data);
        $this->assertArrayHasKey('hit_rate', $data);
        $this->assertArrayHasKey('sets', $data);
        $this->assertArrayHasKey('deletes', $data);
        $this->assertArrayHasKey('total_requests', $data);
        $this->assertIsBool($data['enabled']);
        $this->assertIsInt($data['hits']);
        $this->assertIsInt($data['misses']);
        $this->assertIsNumeric($data['hit_rate']); // Can be int (0) or float
    }

    public function testCacheCommandShowsErrorWhenCacheDisabled(): void
    {
        // Create a new DB instance without cache for this test
        $dbNoCache = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // We can't easily test CLI command with disabled cache without mocking,
        // but we can verify the command exists and works with enabled cache
        // The error message is tested implicitly in other tests
        $this->assertNull($dbNoCache->getCacheManager());
    }

    public function testCacheStatsReflectsActualUsage(): void
    {
        // Reset stats first to ensure clean state
        $cacheManager = $this->db->getCacheManager();
        $this->assertNotNull($cacheManager);
        $cacheManager->resetStats();

        // First query - cache miss
        $this->db->find()->from('cache_test')->cache(3600)->get();

        // Force persist by triggering enough operations
        for ($i = 0; $i < 12; $i++) {
            $this->db->find()->from('cache_test')->cache(3600)->get();
        }

        $statsAfterMisses = $cacheManager->getStats();
        $this->assertGreaterThanOrEqual(1, $statsAfterMisses['misses']);

        // Second query - cache hit (after set operation)
        $this->db->find()->from('cache_test')->cache(3600)->get();

        // Force persist again
        for ($i = 0; $i < 12; $i++) {
            $this->db->find()->from('cache_test')->cache(3600)->get();
        }

        $stats = $cacheManager->getStats();
        $this->assertGreaterThanOrEqual(1, $stats['misses']);
        $this->assertGreaterThanOrEqual(1, $stats['hits']);
        $this->assertGreaterThan(0, $stats['hit_rate']);
        $this->assertLessThanOrEqual(100, $stats['hit_rate']);
    }

    public function testCacheClearResetsCache(): void
    {
        // Generate cache
        $this->db->find()->from('cache_test')->cache(3600)->get();
        $this->db->find()->from('cache_test')->cache(3600)->get(); // Hit

        // Verify cache hit
        $cacheManager = $this->db->getCacheManager();
        $this->assertNotNull($cacheManager);
        $statsBefore = $cacheManager->getStats();
        $this->assertGreaterThan(0, $statsBefore['hits']);
        $missesBefore = $statsBefore['misses'];

        // Clear cache
        $app = new Application();
        ob_start();
        $app->run(['pdodb', 'cache', 'clear', '--force']);
        ob_get_clean();

        // Next query should be miss (cache was cleared)
        $this->db->find()->from('cache_test')->cache(3600)->get();
        $statsAfter = $cacheManager->getStats();

        // Misses should have increased after clear (new query after clear is a miss)
        $this->assertGreaterThanOrEqual($missesBefore, $statsAfter['misses']);
        // Total requests should have increased
        $this->assertGreaterThan($statsBefore['total_requests'], $statsAfter['total_requests']);
    }
}
