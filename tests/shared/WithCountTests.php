<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\orm\Model;

final class CountAuthor extends Model
{
    public static function tableName(): string
    {
        return 'count_authors';
    }

    public static function relations(): array
    {
        return [
            'books' => ['hasMany', 'modelClass' => CountBook::class, 'foreignKey' => 'author_id'],
            'profile' => ['hasOne', 'modelClass' => CountProfile::class, 'foreignKey' => 'author_id'],
            'prizes' => [
                'hasManyThrough',
                'modelClass' => CountPrize::class,
                'viaTable' => 'count_author_prize',
                'link' => ['id' => 'author_id'],
                'viaLink' => ['prize_id' => 'id'],
            ],
        ];
    }

    public static function rules(): array
    {
        return [['name', 'required']];
    }
}

final class CountBook extends Model
{
    public static function tableName(): string
    {
        return 'count_books';
    }

    public static function relations(): array
    {
        return ['author' => ['belongsTo', 'modelClass' => CountAuthor::class, 'foreignKey' => 'author_id', 'ownerKey' => 'id']];
    }
}

final class CountProfile extends Model
{
    public static function tableName(): string
    {
        return 'count_profiles';
    }
}

final class CountPrize extends Model
{
    public static function tableName(): string
    {
        return 'count_prizes';
    }
}

final class WithCountTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $db = self::$db;
        $db->rawQuery('CREATE TABLE count_authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $db->rawQuery('CREATE TABLE count_books (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL, published INTEGER NOT NULL DEFAULT 0)');
        $db->rawQuery('CREATE TABLE count_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL)');
        $db->rawQuery('CREATE TABLE count_prizes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
        $db->rawQuery('CREATE TABLE count_author_prize (author_id INTEGER NOT NULL, prize_id INTEGER NOT NULL)');
        foreach ([CountAuthor::class, CountBook::class, CountProfile::class, CountPrize::class] as $class) {
            $class::setDb($db);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['count_author_prize', 'count_prizes', 'count_profiles', 'count_books', 'count_authors'] as $table) {
            self::$db->rawQuery("DROP TABLE {$table}");
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = self::$db;
        foreach (['count_author_prize', 'count_prizes', 'count_profiles', 'count_books', 'count_authors'] as $table) {
            $db->rawQuery("DELETE FROM {$table}");
        }
        $db->rawQuery("INSERT INTO count_authors (id, name) VALUES (1, 'ada'), (2, 'bob'), (3, 'cy')");
        // ada has 3 books (2 published), bob 1, cy none
        $db->rawQuery('INSERT INTO count_books (author_id, published) VALUES (1, 1), (1, 1), (1, 0), (2, 0)');
        $db->rawQuery('INSERT INTO count_profiles (author_id) VALUES (1)');
        $db->rawQuery("INSERT INTO count_prizes (id, title) VALUES (1, 'x'), (2, 'y')");
        $db->rawQuery('INSERT INTO count_author_prize (author_id, prize_id) VALUES (1, 1), (1, 2), (2, 2)');
    }

    /**
     * @return list<string> statements executed while $work runs
     */
    private function statements(callable $work): array
    {
        $log = [];
        self::$db->setEventDispatcher(new class ($log) implements EventDispatcherInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof QueryExecutedEvent) {
                    $this->log[] = $event->getSql();
                }

                return $event;
            }
        });

        try {
            $work();
        } finally {
            self::$db->setEventDispatcher(null);
        }

        return $log;
    }

    /**
     * @param array<int, CountAuthor> $authors
     *
     * @return array<string, mixed>
     */
    private function column(array $authors, string $attribute): array
    {
        $values = [];
        foreach ($authors as $author) {
            $values[(string)$author->getAttribute('name')] = $author->getAttribute($attribute);
        }

        return $values;
    }

    public function testEachModelGetsItsCountWithOneQueryForAllOfThem(): void
    {
        $authors = [];
        $statements = $this->statements(function () use (&$authors): void {
            $authors = CountAuthor::find()->withCount('books')->orderBy('id')->all();
        });

        $this->assertSame(['ada' => 3, 'bob' => 1, 'cy' => 0], $this->column($authors, 'books_count'));
        $this->assertCount(2, $statements, 'the authors, and one grouped count for all of them');
        $this->assertStringContainsString('COUNT(*)', $statements[1]);
    }

    public function testSeveralRelationsAndTheirTypes(): void
    {
        $authors = CountAuthor::find()->withCount(['books', 'profile'])->orderBy('id')->all();

        $this->assertSame(['ada' => 3, 'bob' => 1, 'cy' => 0], $this->column($authors, 'books_count'));
        $this->assertSame(['ada' => 1, 'bob' => 0, 'cy' => 0], $this->column($authors, 'profile_count'), 'has-one is 0 or 1');
    }

    public function testAConstraintNarrowsWhatIsCounted(): void
    {
        $authors = CountAuthor::find()
            ->withCount(['books' => static fn ($query) => $query->where('published', 1)])
            ->orderBy('id')
            ->all();

        $this->assertSame(['ada' => 2, 'bob' => 0, 'cy' => 0], $this->column($authors, 'books_count'));
    }

    public function testRelationsWithoutAGroupedCountAreCountedFromTheLoadedRows(): void
    {
        $authors = CountAuthor::find()->withCount('prizes')->orderBy('id')->all();
        $this->assertSame(['ada' => 2, 'bob' => 1, 'cy' => 0], $this->column($authors, 'prizes_count'));

        $books = CountBook::find()->withCount('author')->orderBy('id')->all();
        $this->assertSame([1, 1, 1, 1], array_map(static fn (Model $b): mixed => $b->getAttribute('author_count'), $books), 'belongs-to is 0 or 1');
    }

    public function testACountIsNotAChangeAndIsNeverSaved(): void
    {
        $author = CountAuthor::find()->withCount('books')->where('id', 1)->one();

        $this->assertNotNull($author);
        $this->assertSame(3, $author->getAttribute('books_count'));
        $this->assertSame([], $author->getDirtyAttributes());

        $author->name = 'ada lovelace';
        $this->assertTrue($author->save(), 'books_count is not a column and must not be written');
        $this->assertSame('ada lovelace', self::$db->find()->from('count_authors')->select('name')->where('id', 1)->getValue());
    }

    public function testCountsComeWithPaginationAndWithEagerLoading(): void
    {
        $page = CountAuthor::find()->withCount('books')->with('books')->orderBy('id')->paginateModels(2, 1);

        $this->assertSame(['ada' => 3, 'bob' => 1], $this->column($page->items(), 'books_count'));
        $this->assertCount(3, $page->items()[0]->books);
    }

    public function testAnUnknownRelationAndABadConstraintAreRejected(): void
    {
        try {
            CountAuthor::find()->withCount('nothing')->all();
            $this->fail('unknown relation');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("'nothing'", $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        CountBook::find()->withCount(['author' => static fn ($query) => $query])->all();
    }

    public function testNoModelsNoQueries(): void
    {
        $statements = $this->statements(function (): void {
            $this->assertSame([], CountAuthor::find()->withCount('books')->where('id', 999)->all());
        });

        $this->assertCount(1, $statements);
    }
}
