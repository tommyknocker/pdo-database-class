<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\MigrationCompletedEvent;
use tommyknocker\pdodb\events\MigrationRolledBackEvent;
use tommyknocker\pdodb\events\MigrationStartedEvent;
use tommyknocker\pdodb\events\SeedCompletedEvent;
use tommyknocker\pdodb\events\SeedRolledBackEvent;
use tommyknocker\pdodb\events\SeedStartedEvent;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\seeds\SeedRunner;

/**
 * Whatever way a migration or a seed is applied or rolled back, listeners see the same events.
 */
final class RunnerEventTests extends BaseSharedTestCase
{
    private string $path;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/pdodb_runner_events_' . bin2hex(random_bytes(4));
        mkdir($this->path, 0755, true);
        $this->events = [];
        self::$db->rawQuery('DROP TABLE IF EXISTS __migrations');
        self::$db->rawQuery('DROP TABLE IF EXISTS __seeds');

        $events = &$this->events;
        self::$db->setEventDispatcher(new class ($events) implements EventDispatcherInterface {
            /** @param list<object> $events */
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        });
    }

    protected function tearDown(): void
    {
        self::$db->setEventDispatcher(null);
        self::$db->rawQuery('DROP TABLE IF EXISTS __migrations');
        self::$db->rawQuery('DROP TABLE IF EXISTS __seeds');
        array_map('unlink', glob($this->path . '/*') ?: []);
        rmdir($this->path);
        parent::tearDown();
    }

    /**
     * @return list<string> short class names of the events seen since the last call
     */
    private function seen(): array
    {
        $names = array_values(array_filter(
            array_map(static fn (object $e): string => (new \ReflectionClass($e))->getShortName(), $this->events),
            static fn (string $name): bool => str_starts_with($name, 'Migration') || str_starts_with($name, 'Seed')
        ));
        $this->events = [];

        return $names;
    }

    private function writeMigration(string $version, string $class): void
    {
        file_put_contents($this->path . "/m{$version}.php", <<<PHP
            <?php

            use tommyknocker\\pdodb\\migrations\\Migration;

            class {$class} extends Migration
            {
                public function up(): void
                {
                }

                public function down(): void
                {
                }
            }
            PHP);
    }

    public function testEveryWayOfApplyingAndRollingBackAMigrationSendsTheSameEvents(): void
    {
        $this->writeMigration('2026_02_01_000000_first', 'm20260201000000First');
        $this->writeMigration('2026_02_01_000001_second', 'm20260201000001Second');
        $runner = new MigrationRunner(self::$db, $this->path);

        $started = MigrationStartedEvent::class;
        $completed = MigrationCompletedEvent::class;
        $rolledBack = MigrationRolledBackEvent::class;
        $short = static fn (string $class): string => (new \ReflectionClass($class))->getShortName();

        // migrate(): both migrations
        $runner->migrate();
        $this->assertSame(
            array_map($short, [$started, $completed, $started, $completed]),
            $this->seen()
        );

        // migrateDown(): used to send nothing
        $runner->migrateDown(1);
        $this->assertSame([$short($rolledBack)], $this->seen());

        // migrateUp(): used to send nothing
        $runner->migrateUp('2026_02_01_000001_second');
        $this->assertSame([$short($started), $short($completed)], $this->seen());

        // migrateTo(): going back one step
        $runner->migrateTo('2026_02_01_000000_first');
        $this->assertSame([$short($rolledBack)], $this->seen());
    }

    public function testRollingBackASeedSendsAnEventLikeRunningIt(): void
    {
        $name = 's20260201000000_events';
        file_put_contents($this->path . "/{$name}.php", <<<'PHP'
            <?php

            use tommyknocker\pdodb\seeds\Seed;

            class EventsSeed extends Seed
            {
                public function run(): void
                {
                }

                public function rollback(): void
                {
                }
            }
            PHP);
        $runner = new SeedRunner(self::$db, $this->path);

        $runner->run($name);
        $runner->rollback($name);

        $this->assertSame(
            [
                (new \ReflectionClass(SeedStartedEvent::class))->getShortName(),
                (new \ReflectionClass(SeedCompletedEvent::class))->getShortName(),
                (new \ReflectionClass(SeedRolledBackEvent::class))->getShortName(),
            ],
            $this->seen()
        );
    }
}
