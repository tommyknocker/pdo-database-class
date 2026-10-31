<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\helpers\Ulid;
use tommyknocker\pdodb\inbox\Inbox;
use tommyknocker\pdodb\orm\behaviors\PrimaryKeyBehavior;
use tommyknocker\pdodb\orm\behaviors\SoftDeleteBehavior;
use tommyknocker\pdodb\orm\behaviors\TimestampBehavior;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\orm\relations\HasManyThrough;

final class FeatureArticle extends Model
{
    public static function tableName(): string
    {
        return 'feature_articles';
    }

    public static function transactional(): array
    {
        return [self::OP_ALL];
    }

    public static function behaviors(): array
    {
        return [
            TimestampBehavior::class,
            SoftDeleteBehavior::class,
            PrimaryKeyBehavior::class => ['strategy' => PrimaryKeyBehavior::ULID, 'column' => 'public_id'],
        ];
    }

    public static function rules(): array
    {
        return [
            ['title', 'required'],
            ['title', 'string', 'min' => 2],
            ['status', 'in', 'range' => ['draft', 'published'], 'when' => static fn (Model $model): bool => $model->status !== null && $model->status !== ''],
            ['title', static fn (Model $model, string $attribute, mixed $value): bool => $value !== 'forbidden'],
            ['slug', 'unique'],
            ['views', 'number', 'min' => 0],
        ];
    }

    public function hiddenAttributes(): array
    {
        return ['internal_note'];
    }

    protected function appendedAttributes(): array
    {
        return ['label' => (string) $this->title];
    }
}

final class FeatureTag extends Model
{
    public static function tableName(): string
    {
        return 'feature_tags';
    }
}

final class FeatureArticleLink extends Model
{
    public static function tableName(): string
    {
        return 'feature_articles';
    }

    public static function relations(): array
    {
        return [
            'tags' => [
                'hasManyThrough',
                'modelClass' => FeatureTag::class,
                'viaTable' => 'feature_article_tag',
                'link' => ['id' => 'article_id'],
                'viaLink' => ['tag_id' => 'id'],
            ],
        ];
    }
}

final class ModelFeatureTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->rawQuery('CREATE TABLE feature_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            status TEXT NULL,
            views INTEGER NOT NULL DEFAULT 0,
            internal_note TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL,
            deleted_at TEXT NULL
        )');
        self::$db->rawQuery('CREATE TABLE feature_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');
        self::$db->rawQuery('CREATE TABLE feature_article_tag (
            article_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY (article_id, tag_id)
        )');
        FeatureArticle::setDb(self::$db);
        FeatureTag::setDb(self::$db);
        FeatureArticleLink::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM feature_article_tag');
        self::$db->rawQuery('DELETE FROM feature_tags');
        self::$db->rawQuery('DELETE FROM feature_articles');
    }

    public function testBehaviorsUpsertBatchAndSerialization(): void
    {
        $created = FeatureArticle::firstOrCreate(
            ['slug' => 'hello'],
            ['title' => 'Hello', 'status' => 'draft', 'views' => 0, 'internal_note' => 'secret']
        );
        $again = FeatureArticle::firstOrCreate(['slug' => 'hello'], ['title' => 'Other']);
        $this->assertSame($created->id, $again->id);
        $this->assertSame('Hello', $again->title);
        $this->assertNotEmpty($created->created_at);
        $this->assertSame(26, strlen((string) $created->public_id));
        $this->assertTrue(Ulid::generate() !== '');

        $updated = FeatureArticle::updateOrCreate(['slug' => 'hello'], ['title' => 'Hello world']);
        $this->assertSame('Hello world', $updated->title);

        $updated->increment('views', 2);
        $this->assertSame(2, (int) $updated->views);
        $updated->decrement('views');
        $this->assertSame(1, (int) $updated->views);

        $array = $updated->toArray();
        $this->assertArrayNotHasKey('internal_note', $array);
        $this->assertSame('Hello world', $array['label']);

        $ids = [];
        foreach (FeatureArticle::find()->orderBy('id')->each(1) as $article) {
            $ids[] = $article->id;
        }
        $this->assertSame([(int) $created->id], array_map('intval', $ids));

        $chunks = 0;
        FeatureArticle::find()->chunk(1, function (array $models) use (&$chunks): null {
            $chunks += count($models);
            return null;
        });
        $this->assertSame(1, $chunks);

        $cursorCount = 0;
        foreach (FeatureArticle::find()->cursor() as $article) {
            $this->assertInstanceOf(FeatureArticle::class, $article);
            $cursorCount++;
        }
        $this->assertSame(1, $cursorCount);
    }

    public function testSoftDeleteBulkWritesAndValidation(): void
    {
        $article = new FeatureArticle();
        $article->slug = 'keep';
        $article->title = 'Keep';
        $article->status = 'draft';
        $article->views = 1;
        $this->assertTrue($article->save());
        $this->assertTrue($article->delete());
        $this->assertFalse($article->getIsNewRecord());
        $this->assertNull(FeatureArticle::findOne($article->id));
        $this->assertNotNull(FeatureArticle::find()->withoutGlobalScope(SoftDeleteBehavior::SCOPE)->where('id', $article->id)->one());
        $this->assertTrue($article->restore());
        $this->assertNotNull(FeatureArticle::findOne($article->id));

        $second = FeatureArticle::firstOrCreate(
            ['slug' => 'second'],
            ['title' => 'Second', 'status' => 'draft', 'views' => 0]
        );
        $this->assertSame(2, FeatureArticle::updateAll(['status' => 'published'], ['status' => 'draft']));
        $this->assertSame(2, FeatureArticle::deleteAll());
        $this->assertSame([], FeatureArticle::find()->all());
        $this->assertSame(2, FeatureArticle::forceDeleteAll());
        $this->assertSame(0, (int) self::$db->find()->from('feature_articles')->select(['COUNT(*)'])->getValue());
        $this->assertNotNull($second->id);

        $invalid = new FeatureArticle();
        $invalid->slug = 'x';
        $invalid->title = 'forbidden';
        $invalid->status = 'nope';
        $invalid->views = -1;
        $this->assertFalse($invalid->save());
        $payload = $invalid->validationErrorPayload();
        $this->assertSame('The given data was invalid.', $payload['message']);
        $this->assertArrayHasKey('title', $payload['errors']);
        $this->assertArrayHasKey('status', $payload['errors']);
        $this->assertArrayHasKey('views', $payload['errors']);

        $skipped = new FeatureArticle();
        $skipped->slug = 'ok-slug';
        $skipped->title = 'Fine';
        $skipped->status = '';
        $skipped->views = 0;
        $this->assertTrue($skipped->save());
    }

    public function testTransactionalSaveRollsBackMemoryAndDefersAfterCommit(): void
    {
        $ran = false;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$ran) {
            if ($event instanceof ModelAfterSaveEvent) {
                $event->getModel()->afterCommit(static function () use (&$ran): void {
                    $ran = true;
                });
            }

            return $event;
        });
        self::$db->connection->setEventDispatcher($dispatcher);

        $article = new FeatureArticle();
        $article->slug = 'tx';
        $article->title = 'Tx';
        $article->views = 0;

        try {
            self::$db->transaction(function () use ($article): void {
                $this->assertTrue($article->save());
                $this->assertFalse($article->getIsNewRecord());

                throw new RuntimeException('rollback');
            });
            $this->fail('The transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertTrue($article->getIsNewRecord());
        $this->assertNull($article->id ?? null);
        $this->assertFalse($ran);
        $this->assertSame(0, (int) self::$db->find()->from('feature_articles')->select(['COUNT(*)'])->getValue());

        $this->assertTrue($article->save());
        $this->assertTrue($ran);
        self::$db->connection->setEventDispatcher(null);
    }

    public function testManyToManySyncAndInbox(): void
    {
        $article = new FeatureArticleLink();
        $article->setAttributes([
            'public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'slug' => 'linked',
            'title' => 'Linked',
            'views' => 0,
        ]);
        $this->assertTrue($article->save(false));

        $alpha = new FeatureTag();
        $alpha->name = 'alpha';
        $beta = new FeatureTag();
        $beta->name = 'beta';
        $this->assertTrue($alpha->save(false));
        $this->assertTrue($beta->save(false));

        $relation = $article->getRelationInstance('tags');
        $this->assertInstanceOf(HasManyThrough::class, $relation);
        $relation->sync([(int) $alpha->id, (int) $beta->id]);
        $relation->sync([(int) $beta->id]);
        $this->assertSame(1, (int) self::$db->find()->from('feature_article_tag')->select(['COUNT(*)'])->getValue());

        $inbox = new Inbox(self::$db, 'billing', 'feature_inbox');
        $inbox->ensureSchema();
        $handled = 0;
        $this->assertTrue($inbox->once('message-1', function () use (&$handled): void {
            $handled++;
        }));
        $this->assertFalse($inbox->once('message-1', function () use (&$handled): void {
            $handled++;
        }));
        $this->assertSame(1, $handled);
        $this->assertTrue($inbox->processed('message-1'));
    }
}
