<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;

/**
 * CLI tests for pdodb outbox work / purge.
 */
final class OutboxCommandCliTests extends BaseSharedTestCase
{
    protected string $testDbPath;

    protected string $publisherPath;

    protected string $table = 'cli_test_outbox';

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDbPath = sys_get_temp_dir() . '/pdodb_outbox_cli_' . uniqid() . '.sqlite';
        $this->publisherPath = sys_get_temp_dir() . '/pdodb_outbox_publisher_' . uniqid() . '.php';

        file_put_contents($this->publisherPath, <<<'PHP'
<?php
use tommyknocker\pdodb\outbox\OutboxMessage;
return static function (OutboxMessage $message): void {
};
PHP);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->testDbPath);
        putenv('PDODB_OUTBOX_TABLE=' . $this->table);
        putenv('PDODB_NON_INTERACTIVE=1');

        $_ENV['PDODB_DRIVER'] = 'sqlite';
        $_ENV['PDODB_PATH'] = $this->testDbPath;
        $_ENV['PDODB_OUTBOX_TABLE'] = $this->table;
        $_ENV['PDODB_NON_INTERACTIVE'] = '1';
    }

    protected function tearDown(): void
    {
        if (isset($this->publisherPath) && file_exists($this->publisherPath)) {
            unlink($this->publisherPath);
        }
        if (isset($this->testDbPath) && file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }

        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_OUTBOX_TABLE');

        unset($_ENV['PDODB_DRIVER'], $_ENV['PDODB_PATH'], $_ENV['PDODB_OUTBOX_TABLE']);

        parent::tearDown();
    }

    public function testOutboxHelp(): void
    {
        $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'outbox', '--help']);
        $this->assertSame(0, $run['code']);
        $this->assertStringContainsString('work', $run['stdout']);
        $this->assertStringContainsString('retry', $run['stdout']);
    }

    public function testWorkStopsAfterMaxRuntime(): void
    {
        $dbConfig = require __DIR__ . '/../../examples/config.sqlite.php';
        $dbConfig['path'] = $this->testDbPath;
        (new Outbox(new PdoDb('sqlite', $dbConfig), $this->table))->ensureSchema();

        $started = microtime(true);
        $run = CliTestCapture::runApplication(new Application(), [
            'pdodb',
            'outbox',
            'work',
            '--max-runtime=1',
            '--sleep=1',
            '--publisher=' . $this->publisherPath,
            '--table=' . $this->table,
        ]);

        $this->assertSame(0, $run['code'], $run['stdout'] . $run['stderr']);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function testWorkOnceRequiresPublisher(): void
    {
        $run = CliTestCapture::runApplication(
            new Application(),
            ['pdodb', 'outbox', 'work', '--once', '--ensure-schema'],
        );
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('publisher', strtolower($run['stderr']));
    }

    public function testWorkOnceProcessesPendingMessage(): void
    {
        $pdo = new \PDO('sqlite:' . $this->testDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbConfig = require __DIR__ . '/../../examples/config.sqlite.php';
        $dbConfig['path'] = $this->testDbPath;
        $db = new PdoDb('sqlite', $dbConfig);

        $outbox = new Outbox($db, $this->table);
        $outbox->ensureSchema();

        $db->transaction(function () use ($outbox): void {
            $outbox->add('cli.test', ['n' => 1]);
        });

        $run = CliTestCapture::runApplication(new Application(), [
            'pdodb',
            'outbox',
            'work',
            '--once',
            '--publisher=' . $this->publisherPath,
            '--table=' . $this->table,
        ]);

        $this->assertSame(0, $run['code'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('published=1', $run['stdout']);

        $status = $db->find()->table($this->table)->select(['status'])->getValue();
        $this->assertSame(OutboxStatuses::PUBLISHED, $status);
    }

    public function testPurgeDryRunDoesNotDelete(): void
    {
        $dbConfig = require __DIR__ . '/../../examples/config.sqlite.php';
        $dbConfig['path'] = $this->testDbPath;
        $db = new PdoDb('sqlite', $dbConfig);

        $outbox = new Outbox($db, $this->table);
        $outbox->ensureSchema();

        $old = gmdate('Y-m-d H:i:s', time() - 86400 * 10);
        $db->find()->table($this->table)->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'event_type' => 'old.event',
            'payload' => '{}',
            'status' => OutboxStatuses::PUBLISHED,
            'attempts' => 1,
            'available_at' => $old,
            'created_at' => $old,
            'published_at' => $old,
        ]);

        $run = CliTestCapture::runApplication(new Application(), [
            'pdodb',
            'outbox',
            'purge',
            '--older-than=7d',
            '--dry-run',
            '--table=' . $this->table,
        ]);

        $this->assertSame(0, $run['code']);
        $this->assertStringContainsString('Dry run', $run['stdout']);

        $remaining = (int) $db->rawQueryValue('SELECT COUNT(*) FROM ' . $this->table);
        $this->assertSame(1, $remaining);
    }

    public function testPurgeDeletesOldPublishedRows(): void
    {
        $dbConfig = require __DIR__ . '/../../examples/config.sqlite.php';
        $dbConfig['path'] = $this->testDbPath;
        $db = new PdoDb('sqlite', $dbConfig);

        $outbox = new Outbox($db, $this->table);
        $outbox->ensureSchema();

        $old = gmdate('Y-m-d H:i:s', time() - 86400 * 10);
        $recent = gmdate('Y-m-d H:i:s');
        $db->find()->table($this->table)->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'event_type' => 'old.event',
            'payload' => '{}',
            'status' => OutboxStatuses::PUBLISHED,
            'attempts' => 1,
            'available_at' => $old,
            'created_at' => $old,
            'published_at' => $old,
        ]);
        $db->find()->table($this->table)->insert([
            'id' => '33333333-3333-4333-8333-333333333333',
            'event_type' => 'new.event',
            'payload' => '{}',
            'status' => OutboxStatuses::PUBLISHED,
            'attempts' => 1,
            'available_at' => $recent,
            'created_at' => $recent,
            'published_at' => $recent,
        ]);

        $run = CliTestCapture::runApplication(new Application(), [
            'pdodb',
            'outbox',
            'purge',
            '--older-than=7d',
            '--table=' . $this->table,
        ]);

        $this->assertSame(0, $run['code']);
        $remaining = (int) $db->rawQueryValue('SELECT COUNT(*) FROM ' . $this->table);
        $this->assertSame(1, $remaining);
    }

    public function testStatusShowsCounts(): void
    {
        $dbConfig = require __DIR__ . '/../../examples/config.sqlite.php';
        $dbConfig['path'] = $this->testDbPath;
        $db = new PdoDb('sqlite', $dbConfig);

        $outbox = new Outbox($db, $this->table);
        $outbox->ensureSchema();

        $db->transaction(function () use ($outbox): void {
            $outbox->add('cli.test', ['n' => 1]);
        });

        $run = CliTestCapture::runApplication(new Application(), [
            'pdodb',
            'outbox',
            'status',
            '--table=' . $this->table,
        ]);

        $this->assertSame(0, $run['code']);
        $this->assertStringContainsString('pending: 1', $run['stdout']);
    }

    public function testRetryRequeuesFailedMessage(): void
    {
        $dbConfig = require __DIR__ . '/../../examples/config.sqlite.php';
        $dbConfig['path'] = $this->testDbPath;
        $db = new PdoDb('sqlite', $dbConfig);

        $outbox = new Outbox($db, $this->table);
        $outbox->ensureSchema();

        $id = '44444444-4444-4444-8444-444444444444';
        $db->find()->table($this->table)->insert([
            'id' => $id,
            'event_type' => 'failed.event',
            'payload' => '{}',
            'status' => OutboxStatuses::FAILED,
            'attempts' => 2,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => 'bad',
        ]);

        $run = CliTestCapture::runApplication(new Application(), [
            'pdodb',
            'outbox',
            'retry',
            '--id=' . $id,
            '--table=' . $this->table,
        ]);

        $this->assertSame(0, $run['code'], $run['stderr']);
        $status = $db->find()->table($this->table)->where('id', $id)->select(['status'])->getValue();
        $this->assertSame(OutboxStatuses::PENDING, $status);
    }
}
