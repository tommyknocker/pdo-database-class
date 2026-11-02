<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\ModelAfterFindEvent;
use tommyknocker\pdodb\orm\Model;

final class LifecycleAuthor extends Model
{
    /** @var list<string> */
    public static array $log = [];

    public int $initCalls = 0;

    public static function tableName(): string
    {
        return 'lifecycle_authors';
    }

    public static function relations(): array
    {
        return [
            'books' => ['hasMany', 'modelClass' => LifecycleBook::class, 'foreignKey' => 'author_id'],
        ];
    }

    public static function rules(): array
    {
        return [['name', 'required']];
    }

    protected function init(): void
    {
        parent::init();
        $this->initCalls++;
        self::$log[] = 'init';
    }

    protected function afterFind(): void
    {
        parent::afterFind();
        // a value derived from the row, the typical use
        $this->setAttribute('shout', strtoupper((string)$this->getAttribute('name')));
        self::$log[] = 'afterFind:' . ($this->getIsNewRecord() ? 'new' : 'existing');
    }
}

final class LifecycleBook extends Model
{
    public static function tableName(): string
    {
        return 'lifecycle_books';
    }

    protected function afterFind(): void
    {
        parent::afterFind();
        LifecycleAuthor::$log[] = 'book:' . $this->getAttribute('title');
    }
}

final class ModelLifecycleTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->rawQuery('CREATE TABLE lifecycle_authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        self::$db->rawQuery('CREATE TABLE lifecycle_books (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL, title TEXT NOT NULL)');
        LifecycleAuthor::setDb(self::$db);
        LifecycleBook::setDb(self::$db);
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->rawQuery('DROP TABLE lifecycle_books');
        self::$db->rawQuery('DROP TABLE lifecycle_authors');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM lifecycle_books');
        self::$db->rawQuery('DELETE FROM lifecycle_authors');
        self::$db->rawQuery("INSERT INTO lifecycle_authors (id, name) VALUES (1, 'ada'), (2, 'bob')");
        self::$db->rawQuery("INSERT INTO lifecycle_books (author_id, title) VALUES (1, 'A1'), (1, 'A2'), (2, 'B1')");
        LifecycleAuthor::$log = [];
    }

    public function testInitRunsOnceWhenAModelIsCreatedButAfterFindDoesNot(): void
    {
        $author = new LifecycleAuthor();

        $this->assertSame(1, $author->initCalls);
        $this->assertSame(['init'], LifecycleAuthor::$log);
    }

    public function testAfterFindRunsForEveryRowOfAQuery(): void
    {
        $authors = LifecycleAuthor::find()->orderBy('id')->all();

        $this->assertSame(['ADA', 'BOB'], array_map(static fn (LifecycleAuthor $a): string => (string)$a->getAttribute('shout'), $authors));
        $this->assertSame(['init', 'afterFind:existing', 'init', 'afterFind:existing'], LifecycleAuthor::$log, 'the model is a known record when the hook runs');
    }

    public function testAfterFindRunsForASingleLookup(): void
    {
        $author = LifecycleAuthor::findOne(2);

        $this->assertNotNull($author);
        $this->assertSame('BOB', $author->getAttribute('shout'));
    }

    public function testDerivedValuesDoNotMakeTheLoadedModelDirtyOrReachTheDatabase(): void
    {
        $author = LifecycleAuthor::findOne(1);

        $this->assertNotNull($author);
        $this->assertFalse($author->getIsNewRecord());
        $this->assertSame([], $author->getDirtyAttributes());

        // `shout` is not a column; an update of a real change must not try to write it
        $author->name = 'ada lovelace';
        $this->assertTrue($author->save());
        $this->assertSame('ada lovelace', self::$db->find()->from('lifecycle_authors')->select('name')->where('id', 1)->getValue());
    }

    public function testRelatedModelsGoThroughTheSameHookWhetherLazyOrEagerlyLoaded(): void
    {
        LifecycleAuthor::find()->with('books')->orderBy('id')->all();
        $eager = array_values(array_filter(LifecycleAuthor::$log, static fn (string $l): bool => str_starts_with($l, 'book:')));
        sort($eager);
        $this->assertSame(['book:A1', 'book:A2', 'book:B1'], $eager);

        LifecycleAuthor::$log = [];
        $author = LifecycleAuthor::findOne(1);
        $this->assertNotNull($author);
        $books = $author->books;
        $this->assertCount(2, $books);
        $this->assertContains('book:A1', LifecycleAuthor::$log);
    }

    public function testASavedNewModelIsNotAFoundModel(): void
    {
        $author = new LifecycleAuthor();
        $author->name = 'cy';
        LifecycleAuthor::$log = [];

        $this->assertTrue($author->save());
        $this->assertSame([], LifecycleAuthor::$log, 'saving does not run afterFind');
    }

    public function testListenersSeeEachLoadedModel(): void
    {
        $seen = [];
        self::$db->setEventDispatcher(new class ($seen) implements EventDispatcherInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof ModelAfterFindEvent) {
                    $this->seen[] = (string)$event->getModel()->getAttribute('name');
                }

                return $event;
            }
        });

        try {
            LifecycleAuthor::find()->orderBy('id')->all();
        } finally {
            self::$db->setEventDispatcher(null);
        }

        $this->assertSame(['ada', 'bob'], $seen);
    }
}
