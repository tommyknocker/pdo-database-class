<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\OutboxMessageAddedEvent;
use tommyknocker\pdodb\events\OutboxMessageFailedEvent;
use tommyknocker\pdodb\events\OutboxMessagePublishedEvent;
use tommyknocker\pdodb\events\OutboxMessageRetryEvent;
use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxSerializer;
use tommyknocker\pdodb\PdoDb;

/**
 * Headers, partition keys, serializers and events of the outbox, run on every database.
 */
final class OutboxFeatureSupport
{
    /**
     * @return array<string, mixed>
     */
    public static function expected(): array
    {
        return [
            'headers' => ['trace' => 't-1', 'tenant' => 7],
            'partition' => 'order-1',
            'rounds' => [['a1', 'b1', 'n1'], ['a2'], ['a3']],
            'retry_blocks' => ['first' => ['r1'], 'while_waiting' => []],
            'failure_unblocks' => ['first' => ['f1'], 'second' => ['f2']],
            'events' => ['added' => 3, 'published' => 1, 'retry' => 1, 'failed' => 1],
            'envelope_round_trip' => ['n' => 5],
            'envelope_stored' => '{"v":1,"data":{"n":5}}',
            'unreadable_serializer' => ['handled' => ['readable'], 'published' => 1, 'failed' => 1, 'pending' => 0],
            'rejected' => ['header-name', 'header-size', 'key-empty', 'key-long', 'not-json'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(PdoDb $db, string $table): array
    {
        $r = [];
        $db->schema()->dropTableIfExists($table);
        $outbox = new Outbox($db, $table);
        $outbox->ensureSchema();

        // --- headers and the partition key reach the publisher
        $db->transaction(function () use ($outbox): void {
            $outbox->add('x', ['n' => 1], 0, ['trace' => 't-1', 'tenant' => 7], 'order-1');
        });
        $seen = [];
        $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$seen): void {
            $seen = [$m->headers, $m->partitionKey];
        });
        $r['headers'] = $seen[0] ?? null;
        $r['partition'] = $seen[1] ?? null;

        // --- one message per key at a time, in the order they were added
        self::clear($db, $table);
        $db->transaction(function () use ($outbox): void {
            $outbox->add('a1', [], 0, [], 'A');
            $outbox->add('a2', [], 0, [], 'A');
            $outbox->add('a3', [], 0, [], 'A');
            $outbox->add('b1', [], 0, [], 'B');
            $outbox->add('n1', []);
        });
        $r['rounds'] = [];
        for ($round = 0; $round < 3; $round++) {
            $types = [];
            $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$types): void {
                $types[] = $m->type;
            });
            sort($types);
            $r['rounds'][] = $types;
        }

        // --- a message waiting for a retry holds its key back
        self::clear($db, $table);
        $db->transaction(function () use ($outbox): void {
            $outbox->add('r1', [], 0, [], 'R');
            $outbox->add('r2', [], 0, [], 'R');
        });
        $first = [];
        $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$first): void {
            $first[] = $m->type;

            throw new OutboxRetryableException('later');
        });
        $waiting = [];
        $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$waiting): void {
            $waiting[] = $m->type;
        });
        $r['retry_blocks'] = ['first' => $first, 'while_waiting' => $waiting];

        // --- a message that failed for good does not
        self::clear($db, $table);
        $db->transaction(function () use ($outbox): void {
            $outbox->add('f1', [], 0, [], 'F');
            $outbox->add('f2', [], 0, [], 'F');
        });
        $first = [];
        $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$first): void {
            $first[] = $m->type;

            throw new OutboxPermanentFailureException('never');
        });
        $second = [];
        $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$second): void {
            $second[] = $m->type;
        });
        $r['failure_unblocks'] = ['first' => $first, 'second' => $second];

        // --- events
        self::clear($db, $table);
        $counts = ['added' => 0, 'published' => 0, 'retry' => 0, 'failed' => 0];
        $db->setEventDispatcher(new class ($counts) implements EventDispatcherInterface {
            /** @param array<string, int> $counts */
            public function __construct(private array &$counts)
            {
            }

            public function dispatch(object $event): object
            {
                $name = match (true) {
                    $event instanceof OutboxMessageAddedEvent => 'added',
                    $event instanceof OutboxMessagePublishedEvent => 'published',
                    $event instanceof OutboxMessageRetryEvent => 'retry',
                    $event instanceof OutboxMessageFailedEvent => 'failed',
                    default => null,
                };
                if ($name !== null) {
                    $this->counts[$name]++;
                }

                return $event;
            }
        });

        try {
            $db->transaction(function () use ($outbox): void {
                $outbox->add('ok', []);
                $outbox->add('retry', []);
                $outbox->add('fail', []);
            });
            $outbox->worker('w')->runOnce(function (OutboxMessage $m): void {
                if ($m->type === 'retry') {
                    throw new OutboxRetryableException('again');
                }
                if ($m->type === 'fail') {
                    throw new OutboxPermanentFailureException('no');
                }
            });
        } finally {
            $db->setEventDispatcher(null);
        }
        $r['events'] = $counts;

        // --- a serializer wraps the payload in an envelope; the stored text is what it produced
        self::clear($db, $table);
        $envelope = new class () implements OutboxSerializer {
            public function serialize(array $payload): string
            {
                return json_encode(['v' => 1, 'data' => $payload], JSON_THROW_ON_ERROR);
            }

            public function deserialize(string $stored): array
            {
                return json_decode($stored, true, 512, JSON_THROW_ON_ERROR)['data'];
            }
        };
        $custom = new Outbox($db, $table, $envelope);
        $db->transaction(function () use ($custom): void {
            $custom->add('env', ['n' => 5]);
        });
        $payload = null;
        $custom->worker('w')->runOnce(function (OutboxMessage $m) use (&$payload): void {
            $payload = $m->payload;
        });
        $stored = $db->rawQueryValue('SELECT payload FROM ' . $db->schema()->getDialect()->quoteTable($table));
        if (is_resource($stored)) {
            $stored = stream_get_contents($stored);
        }
        $r['envelope_round_trip'] = $payload;
        // PostgreSQL's JSONB normalizes the text it stores, so compare as data there
        $r['envelope_stored'] = json_encode(json_decode((string)$stored, true), JSON_THROW_ON_ERROR);

        // --- a serializer that refuses a payload fails that message only, not the queue
        self::clear($db, $table);
        $versioned = static fn (int $version, int $accept): OutboxSerializer => new class ($version, $accept) implements OutboxSerializer {
            public function __construct(private int $version, private int $accept)
            {
            }

            public function serialize(array $payload): string
            {
                return json_encode(['v' => $this->version, 'data' => $payload], JSON_THROW_ON_ERROR);
            }

            public function deserialize(string $stored): array
            {
                $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
                if ($decoded['v'] !== $this->accept) {
                    throw new \RuntimeException('Unsupported payload version ' . $decoded['v']);
                }

                return $decoded['data'];
            }
        };
        $old = new Outbox($db, $table, $versioned(2, 1));
        $current = new Outbox($db, $table, $versioned(1, 1));
        $db->transaction(function () use ($old, $current): void {
            $old->add('too-new', ['n' => 1]);
            $current->add('readable', ['n' => 2]);
        });
        $handled = [];
        $result = $current->worker('w')->runOnce(function (OutboxMessage $m) use (&$handled): void {
            $handled[] = $m->type;
        });
        $r['unreadable_serializer'] = [
            'handled' => $handled,
            'published' => $result->published,
            'failed' => $result->markedFailed,
            'pending' => (int)$db->rawQueryValue('SELECT COUNT(*) FROM ' . $db->schema()->getDialect()->quoteTable($table) . " WHERE status = 'pending'"),
        ];

        // --- what is rejected
        $rejected = [];
        $notJson = new Outbox($db, $table, new class () implements OutboxSerializer {
            public function serialize(array $payload): string
            {
                return 'not json';
            }

            public function deserialize(string $stored): array
            {
                return [];
            }
        });
        $db->transaction(function () use ($outbox, $notJson, &$rejected): void {
            $try = static function (string $name, callable $call) use (&$rejected): void {
                try {
                    $call();
                } catch (OutboxException) {
                    $rejected[] = $name;
                }
            };
            $try('header-name', fn () => $outbox->add('x', [], 0, [0 => 'v']));
            $try('header-size', fn () => $outbox->add('x', [], 0, ['k' => str_repeat('v', 5000)]));
            $try('key-empty', fn () => $outbox->add('x', [], 0, [], ''));
            $try('key-long', fn () => $outbox->add('x', [], 0, [], str_repeat('k', 191)));
            $try('not-json', fn () => $notJson->add('x', ['a' => 1]));
        });
        $r['rejected'] = $rejected;

        return $r;
    }

    /**
     * A table made before headers and partition keys existed is brought up to date by ensureSchema(), and keeps its rows.
     *
     * @return array{old_row_type: string, old_row_headers: array<string, mixed>, old_row_key: ?string, new_row_headers: array<string, mixed>|null, new_row_key: ?string}
     */
    public static function upgradeOldTable(PdoDb $db, string $table): array
    {
        $schema = $db->schema();
        $schema->dropTableIfExists($table);
        $schema->createTable($table, [
            'id' => $schema->string(36),
            'event_type' => $schema->string(255)->notNull(),
            'payload' => $schema->text()->notNull(),
            'status' => $schema->string(32)->notNull(),
            'attempts' => $schema->integer()->notNull(),
            'available_at' => $schema->timestamp(6)->notNull(),
            'created_at' => $schema->timestamp(6)->notNull(),
            'locked_by' => $schema->string(128),
            'locked_until' => $schema->timestamp(6),
            'last_error' => $schema->string(4000),
            'published_at' => $schema->timestamp(6),
        ]);

        try {
            $quoted = $db->schema()->getDialect()->quoteTable($table);
            $past = gmdate('Y-m-d H:i:s', time() - 60);
            $db->find()->table($table)->insert([
                'id' => 'old-row', 'event_type' => 'old.event', 'payload' => '{"n":1}', 'status' => 'pending',
                'attempts' => 0, 'available_at' => $past, 'created_at' => $past,
            ]);

            $outbox = new Outbox($db, $table);
            $outbox->ensureSchema();
            $outbox->ensureSchema(); // twice: nothing left to add

            $db->transaction(function () use ($outbox): void {
                $outbox->add('new.event', ['n' => 2], 0, ['trace' => 't'], 'key-1');
            });
            $seen = [];
            $outbox->worker('w')->runOnce(function (OutboxMessage $m) use (&$seen): void {
                $seen[$m->type] = [$m->headers, $m->partitionKey];
            });

            return [
                'old_row_type' => isset($seen['old.event']) ? 'old.event' : 'missing',
                'old_row_headers' => $seen['old.event'][0] ?? ['?'],
                'old_row_key' => isset($seen['old.event']) ? $seen['old.event'][1] : 'missing',
                'new_row_headers' => $seen['new.event'][0] ?? null,
                'new_row_key' => isset($seen['new.event']) ? $seen['new.event'][1] : 'missing',
            ];
        } finally {
            $schema->dropTableIfExists($table);
        }
    }

    private static function clear(PdoDb $db, string $table): void
    {
        $db->rawQuery('DELETE FROM ' . $db->schema()->getDialect()->quoteTable($table));
    }
}
