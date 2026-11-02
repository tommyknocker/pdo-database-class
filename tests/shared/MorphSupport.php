<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\orm\relations\MorphMap;
use tommyknocker\pdodb\PdoDb;

final class MorphPost extends Model
{
    public static function tableName(): string
    {
        return 'morph_posts';
    }

    public static function relations(): array
    {
        return [
            'comments' => ['morphMany', 'modelClass' => MorphComment::class, 'morphName' => 'commentable'],
            'cover' => ['morphOne', 'modelClass' => MorphImage::class, 'morphName' => 'imageable'],
        ];
    }
}

final class MorphVideo extends Model
{
    public static function tableName(): string
    {
        return 'morph_videos';
    }

    public static function relations(): array
    {
        return [
            'comments' => ['morphMany', 'modelClass' => MorphComment::class, 'morphName' => 'commentable'],
            'cover' => ['morphOne', 'modelClass' => MorphImage::class, 'morphName' => 'imageable'],
        ];
    }
}

final class MorphComment extends Model
{
    public static function tableName(): string
    {
        return 'morph_comments';
    }

    public static function relations(): array
    {
        return ['commentable' => ['morphTo', 'morphName' => 'commentable']];
    }
}

final class MorphImage extends Model
{
    public static function tableName(): string
    {
        return 'morph_images';
    }

    public static function relations(): array
    {
        return ['imageable' => ['morphTo', 'morphName' => 'imageable']];
    }
}

/**
 * Polymorphic relations on every database. Posts and videos share ids on purpose: the type column is the only thing
 * that tells their comments apart.
 */
final class MorphSupport
{
    public const TABLES = ['morph_images', 'morph_comments', 'morph_videos', 'morph_posts'];

    public static function prepare(PdoDb $db): void
    {
        $schema = $db->schema();
        self::drop($db);
        $schema->createTable('morph_posts', ['id' => $schema->integer()->notNull(), 'title' => $schema->string(50)]);
        $schema->createTable('morph_videos', ['id' => $schema->integer()->notNull(), 'title' => $schema->string(50)]);
        $schema->createTable('morph_comments', [
            'id' => $schema->integer()->notNull(),
            'commentable_type' => $schema->string(100),
            'commentable_id' => $schema->integer(),
            'body' => $schema->string(50),
        ]);
        $schema->createTable('morph_images', [
            'id' => $schema->integer()->notNull(),
            'imageable_type' => $schema->string(100),
            'imageable_id' => $schema->integer(),
            'url' => $schema->string(50),
        ]);
        foreach ([MorphPost::class, MorphVideo::class, MorphComment::class, MorphImage::class] as $class) {
            $class::setDb($db);
        }
        MorphMap::flush();
    }

    public static function drop(PdoDb $db): void
    {
        foreach (self::TABLES as $table) {
            $db->schema()->dropTableIfExists($table);
        }
        MorphMap::flush();
    }

    /**
     * Post 1 and video 1 share the id 1.
     */
    public static function seed(PdoDb $db, string $postType, string $videoType): void
    {
        $db->find()->table('morph_posts')->insert(['id' => 1, 'title' => 'p1']);
        $db->find()->table('morph_posts')->insert(['id' => 2, 'title' => 'p2']);
        $db->find()->table('morph_videos')->insert(['id' => 1, 'title' => 'v1']);
        $rows = [
            [1, $postType, 1, 'on post 1 (a)'],
            [2, $postType, 1, 'on post 1 (b)'],
            [3, $videoType, 1, 'on video 1'],
            [4, $postType, 2, 'on post 2'],
        ];
        foreach ($rows as [$id, $type, $ownerId, $body]) {
            $db->find()->table('morph_comments')->insert(['id' => $id, 'commentable_type' => $type, 'commentable_id' => $ownerId, 'body' => $body]);
        }
        $db->find()->table('morph_images')->insert(['id' => 1, 'imageable_type' => $postType, 'imageable_id' => 1, 'url' => 'post-cover']);
        $db->find()->table('morph_images')->insert(['id' => 2, 'imageable_type' => $videoType, 'imageable_id' => 1, 'url' => 'video-cover']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function scenario(PdoDb $db): array
    {
        $bodies = static fn (array $comments): array => array_map(static fn (Model $c): string => (string)$c->getAttribute('body'), $comments);
        $r = [];

        $post = MorphPost::findOne(1);
        $video = MorphVideo::findOne(1);
        $r['post_comments'] = $bodies($post->comments);
        $r['video_comments'] = $bodies($video->comments);
        $r['post_cover'] = $post->cover?->getAttribute('url');
        $r['video_cover'] = $video->cover?->getAttribute('url');

        $comment = MorphComment::findOne(3);
        $r['comment_owner_class'] = $comment->commentable::class;
        $r['comment_owner_title'] = $comment->commentable->getAttribute('title');

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
        $comments = MorphComment::find()->with('commentable')->orderBy('id')->all();
        $db->setEventDispatcher(null);
        $r['eager_owner_titles'] = array_map(static fn (Model $c): string => (string)$c->commentable->getAttribute('title'), $comments);
        $r['eager_owner_statements'] = $statements;

        $posts = MorphPost::find()->with('comments')->orderBy('id')->all();
        $r['eager_post_comments'] = array_map(static fn (Model $p): array => $bodies($p->comments), $posts);

        $posts = MorphPost::find()->withCount('comments')->orderBy('id')->all();
        $r['post_comment_counts'] = array_map(static fn (Model $p): int => (int)$p->getAttribute('comments_count'), $posts);

        return $r;
    }

    /**
     * @return array<string, mixed>
     */
    public static function expected(): array
    {
        return [
            'post_comments' => ['on post 1 (a)', 'on post 1 (b)'],
            'video_comments' => ['on video 1'],
            'post_cover' => 'post-cover',
            'video_cover' => 'video-cover',
            'comment_owner_class' => MorphVideo::class,
            'comment_owner_title' => 'v1',
            'eager_owner_titles' => ['p1', 'p1', 'v1', 'p2'],
            'eager_owner_statements' => 3,
            'eager_post_comments' => [['on post 1 (a)', 'on post 1 (b)'], ['on post 2']],
            'post_comment_counts' => [2, 1],
        ];
    }
}
