<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class CrossAuthor extends Model
{
    public static function tableName(): string
    {
        return 'cross_authors';
    }

    public static function relations(): array
    {
        return [
            'books' => ['hasMany', 'modelClass' => CrossBook::class, 'foreignKey' => 'author_id'],
            'prizes' => [
                'hasManyThrough',
                'modelClass' => CrossPrize::class,
                'viaTable' => 'cross_author_prize',
                'link' => ['id' => 'author_id'],
                'viaLink' => ['prize_id' => 'id'],
            ],
        ];
    }
}

final class CrossBook extends Model
{
    public static function tableName(): string
    {
        return 'cross_books';
    }
}

final class CrossPrize extends Model
{
    public static function tableName(): string
    {
        return 'cross_prizes';
    }
}

/**
 * Relation counts must come out the same on every database: the grouped COUNT query, the constraint closure and the
 * counting of other relation kinds.
 */
final class WithCountSupport
{
    /**
     * @return array<string, mixed>
     */
    public static function expected(): array
    {
        return [
            'books' => ['ada' => 3, 'bob' => 1, 'cy' => 0],
            'published' => ['ada' => 2, 'bob' => 0, 'cy' => 0],
            'prizes' => ['ada' => 2, 'bob' => 1, 'cy' => 0],
            'eager_prizes' => ['ada' => 2, 'bob' => 1, 'cy' => 0],
            'statements' => 2,
            'books_count_is_dirty' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(PdoDb $db): array
    {
        $schema = $db->schema();
        foreach (['cross_author_prize', 'cross_prizes', 'cross_books', 'cross_authors'] as $table) {
            $schema->dropTableIfExists($table);
        }
        $schema->createTable('cross_authors', ['id' => $schema->integer()->notNull(), 'name' => $schema->string(50)]);
        $schema->createTable('cross_books', ['id' => $schema->integer()->notNull(), 'author_id' => $schema->integer()->notNull(), 'published' => $schema->integer()->notNull()]);
        $schema->createTable('cross_prizes', ['id' => $schema->integer()->notNull(), 'title' => $schema->string(50)]);
        $schema->createTable('cross_author_prize', ['author_id' => $schema->integer()->notNull(), 'prize_id' => $schema->integer()->notNull()]);
        foreach ([CrossAuthor::class, CrossBook::class, CrossPrize::class] as $class) {
            $class::setDb($db);
        }

        try {
            foreach ([[1, 'ada'], [2, 'bob'], [3, 'cy']] as [$id, $name]) {
                $db->find()->table('cross_authors')->insert(['id' => $id, 'name' => $name]);
            }
            foreach ([[1, 1, 1], [2, 1, 1], [3, 1, 0], [4, 2, 0]] as [$id, $author, $published]) {
                $db->find()->table('cross_books')->insert(['id' => $id, 'author_id' => $author, 'published' => $published]);
            }
            foreach ([[1, 'x'], [2, 'y']] as [$id, $title]) {
                $db->find()->table('cross_prizes')->insert(['id' => $id, 'title' => $title]);
            }
            foreach ([[1, 1], [1, 2], [2, 2]] as [$author, $prize]) {
                $db->find()->table('cross_author_prize')->insert(['author_id' => $author, 'prize_id' => $prize]);
            }

            $column = static function (array $authors, string $attribute): array {
                $out = [];
                foreach ($authors as $author) {
                    $out[(string)$author->getAttribute('name')] = $author->getAttribute($attribute);
                }

                return $out;
            };

            $statements = 0;
            $db->setEventDispatcher(new class ($statements) implements EventDispatcherInterface {
                public function __construct(private int &$statements)
                {
                }

                public function dispatch(object $event): object
                {
                    if ($event instanceof QueryExecutedEvent) {
                        $this->statements++;
                    }

                    return $event;
                }
            });
            $authors = CrossAuthor::find()->withCount('books')->orderBy('id')->all();
            $db->setEventDispatcher(null);

            $published = CrossAuthor::find()
                ->withCount(['books' => static fn ($query) => $query->where('published', 1)])
                ->orderBy('id')
                ->all();
            $prizes = CrossAuthor::find()->withCount('prizes')->orderBy('id')->all();
            $eager = [];
            foreach (CrossAuthor::find()->with('prizes')->orderBy('id')->all() as $author) {
                $eager[(string)$author->getAttribute('name')] = count($author->getRelationData()['prizes'] ?? []);
            }

            return [
                'books' => $column($authors, 'books_count'),
                'published' => $column($published, 'books_count'),
                'prizes' => $column($prizes, 'prizes_count'),
                'eager_prizes' => $eager,
                'statements' => $statements,
                'books_count_is_dirty' => $authors[0]->getDirtyAttributes() !== [],
            ];
        } finally {
            $db->setEventDispatcher(null);
            foreach (['cross_author_prize', 'cross_prizes', 'cross_books', 'cross_authors'] as $table) {
                $schema->dropTableIfExists($table);
            }
        }
    }
}
