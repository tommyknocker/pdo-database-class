<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\inbox\Inbox;
use tommyknocker\pdodb\PdoDb;

/**
 * CLI tests for pdodb inbox purge.
 */
final class InboxCommandCliTests extends BaseSharedTestCase
{
    protected string $testDbPath;

    protected string $table = 'cli_test_inbox';

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDbPath = sys_get_temp_dir() . '/pdodb_inbox_cli_' . uniqid() . '.sqlite';
        foreach (['PDODB_DRIVER' => 'sqlite', 'PDODB_PATH' => $this->testDbPath, 'PDODB_NON_INTERACTIVE' => '1'] as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        foreach (['PDODB_DRIVER', 'PDODB_PATH'] as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        parent::tearDown();
    }

    private function seed(): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => $this->testDbPath]);
        $inbox = new Inbox($db, 'billing', $this->table);
        $inbox->ensureSchema();
        foreach (['old-1', 'old-2', 'new-1'] as $id) {
            $inbox->once($id, static function (): void {
            });
        }
        $db->find()->table($this->table)->where('message_id', 'old-%', 'LIKE')->update([
            'processed_at' => new RawValue($db->connection->getDialect()->outboxNowPlusSecondsSql(-10 * 86400)),
        ]);

        return $db;
    }

    public function testHelp(): void
    {
        $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'inbox', '--help']);

        $this->assertSame(0, $run['code']);
        $this->assertStringContainsString('purge', $run['stdout']);
        $this->assertStringContainsString('--older-than', $run['stdout']);
    }

    public function testPurgeRequiresOlderThan(): void
    {
        $this->seed();
        $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'inbox', 'purge', '--table=' . $this->table]);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('--older-than', $run['stderr']);
    }

    public function testPurgeFailsForAMissingTable(): void
    {
        $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'inbox', 'purge', '--older-than=7d', '--table=no_such_inbox']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('does not exist', $run['stderr']);
    }

    public function testDryRunCountsWithoutDeleting(): void
    {
        $db = $this->seed();
        $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'inbox', 'purge', '--older-than=3d', '--dry-run', '--table=' . $this->table]);

        $this->assertSame(0, $run['code'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('would delete 2', $run['stdout']);
        $this->assertSame(3, (int) $db->rawQueryValue('SELECT COUNT(*) FROM ' . $this->table));
    }

    public function testPurgeDeletesOldIdsOnly(): void
    {
        $db = $this->seed();
        $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'inbox', 'purge', '--older-than=3d', '--table=' . $this->table]);

        $this->assertSame(0, $run['code'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('Deleted 2', $run['stdout']);
        $this->assertSame(1, (int) $db->rawQueryValue('SELECT COUNT(*) FROM ' . $this->table));
    }
}
