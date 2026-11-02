<?php

declare(strict_types=1);

/**
 * ActiveRecord services: behaviors, transactional save, batch model reads,
 * many-to-many writes, and an idempotent inbox.
 *
 * Usage:
 *   php examples/09-active-record/06-model-services.php
 *   PDODB_DRIVER=mysql php examples/09-active-record/06-model-services.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\inbox\Inbox;
use tommyknocker\pdodb\orm\behaviors\PrimaryKeyBehavior;
use tommyknocker\pdodb\orm\behaviors\SoftDeleteBehavior;
use tommyknocker\pdodb\orm\behaviors\TimestampBehavior;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\orm\relations\HasManyThrough;
use tommyknocker\pdodb\outbox\Outbox;

final class ServiceArticle extends Model
{
    public string $headline {
        get => (string) $this->getAttribute('title');
        set {
            $this->setAttribute('title', $value);
        }
    }

    public static function tableName(): string
    {
        return 'service_articles';
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
            ['title', 'string', 'min' => 2, 'max' => 100],
            ['slug', 'unique'],
        ];
    }

    public static function relations(): array
    {
        return [
            'tags' => [
                'hasManyThrough',
                'modelClass' => ServiceTag::class,
                'viaTable' => 'service_article_tag',
                'link' => ['id' => 'article_id'],
                'viaLink' => ['tag_id' => 'id'],
            ],
        ];
    }

    public function hiddenAttributes(): array
    {
        return ['internal_note'];
    }

    protected function appendedAttributes(): array
    {
        return ['label' => $this->headline];
    }
}

final class ServiceTag extends Model
{
    public static function tableName(): string
    {
        return 'service_tags';
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$db = createExampleDb();
$driver = getCurrentDriver($db);
echo "=== ActiveRecord services (on {$driver}) ===\n\n";

$schema = $db->schema();
recreateTable($db, 'service_articles', [
    'id' => $schema->primaryKey(),
    'public_id' => $schema->string(26)->notNull(),
    'slug' => $schema->string(190)->notNull(),
    'title' => $schema->string(100)->notNull(),
    'views' => $schema->integer()->notNull()->defaultValue(0),
    'internal_note' => $schema->string(100)->null(),
    'created_at' => $schema->string(64)->null(),
    'updated_at' => $schema->string(64)->null(),
    'deleted_at' => $schema->string(64)->null(),
]);
recreateTable($db, 'service_tags', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(50)->notNull(),
]);
recreateTable($db, 'service_article_tag', [
    'article_id' => $schema->integer()->notNull(),
    'tag_id' => $schema->integer()->notNull(),
]);
// firstOrCreate and updateOrCreate conflict on this unique index.
$db->schema()->createIndex('service_articles_slug_uidx', 'service_articles', ['slug'], true);
$db->schema()->createIndex('service_article_tag_uidx', 'service_article_tag', ['article_id', 'tag_id'], true);

ServiceArticle::setDb($db);
ServiceTag::setDb($db);

$article = new ServiceArticle();
$article->headline = 'Hello';
$article->slug = 'hello';
$article->internal_note = 'secret';
$article->views = 0;
if (!$article->save()) {
    fail('Initial save failed: ' . json_encode($article->validationErrorPayload()));
}
if (strlen((string) $article->public_id) !== 26 || $article->created_at === null) {
    fail('Expected a ULID public id and a created_at timestamp.');
}
echo "Created {$article->headline} ({$article->public_id})\n";

$same = ServiceArticle::firstOrCreate(['slug' => 'hello'], ['title' => 'Other']);
if ((string) $same->id !== (string) $article->id || $same->title !== 'Hello') {
    fail('firstOrCreate changed an existing row.');
}
$renamed = ServiceArticle::updateOrCreate(['slug' => 'hello'], ['title' => 'Hello world']);
if ($renamed->title !== 'Hello world') {
    fail('updateOrCreate did not assign the new title.');
}
$renamed->increment('views');
if ((int) $renamed->views !== 1) {
    fail('increment did not add one view.');
}

$payload = $renamed->toArray();
if (array_key_exists('internal_note', $payload) || ($payload['label'] ?? null) !== 'Hello world') {
    fail('toArray did not apply hidden and appended attributes.');
}
echo "Serialized label: {$payload['label']}\n";

$seen = 0;
foreach (ServiceArticle::find()->orderBy('id')->each(1) as $loaded) {
    $seen++;
    if (!$loaded instanceof ServiceArticle) {
        fail('each() did not yield a model.');
    }
}
if ($seen !== 1) {
    fail('each() yielded the wrong number of models.');
}

$duplicate = new ServiceArticle();
$duplicate->headline = 'Hello world';
$duplicate->slug = 'hello';
if ($duplicate->save() || !isset($duplicate->validationErrorPayload()['errors']['slug'])) {
    fail('Unique validation should reject a duplicate slug.');
}
echo "Duplicate slug rejected.\n";

$published = false;
$before = $renamed->title;
try {
    $db->transaction(function () use ($renamed, $db, &$published): void {
        $renamed->title = 'Rolled back';
        if (!$renamed->save()) {
            fail('Save inside the outer transaction failed.');
        }
        $db->afterCommit(static function () use (&$published): void {
            $published = true;
        });
        throw new RuntimeException('rollback');
    });
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'rollback') {
        throw $exception;
    }
}
$stored = ServiceArticle::findOne($renamed->id);
$oldTitle = $renamed->getOldAttributes()['title'] ?? null;
if (
    $published
    || $stored === null
    || $stored->title !== $before
    || $oldTitle !== $before
    || $renamed->title !== 'Rolled back'
) {
    fail('Rollback committed the row, ran afterCommit, or kept the saved model snapshot.');
}
echo "Rolled back an outer transaction without publishing.\n";

// start from empty tables, so a table left by an earlier run (or an older version) cannot change the outcome
$db->schema()->dropTableIfExists('service_outbox');
$outbox = new Outbox($db, 'service_outbox');
$outbox->ensureSchema();
$messageId = $db->transaction(function () use ($db, $outbox, $article): string {
    $article->title = 'Published';
    if (!$article->save()) {
        fail('Could not save before the outbox write.');
    }
    $db->afterCommit(static function (): void {
        echo "afterCommit ran\n";
    });

    return $outbox->add('article.published', ['public_id' => (string) $article->public_id]);
});
echo "Outbox message {$messageId}\n";

$tag = new ServiceTag();
$tag->name = 'news';
if (!$tag->save(false)) {
    fail('Could not save a tag.');
}
$relation = $article->getRelationInstance('tags');
if (!$relation instanceof HasManyThrough) {
    fail('Expected a hasManyThrough relation.');
}
$relation->sync([(int) $tag->id => []]);
$relation->detach((int) $tag->id);
echo "Synced and detached a tag.\n";

if (!$article->delete() || ServiceArticle::findOne($article->id) !== null) {
    fail('Soft delete should hide the article.');
}
if (!$article->restore() || ServiceArticle::findOne($article->id) === null) {
    fail('restore() should make the article visible again.');
}
echo "Soft-deleted and restored.\n";

$db->schema()->dropTableIfExists('service_inbox');
$inbox = new Inbox($db, 'search', 'service_inbox');
$inbox->ensureSchema();
$handled = 0;
if (!$inbox->once((string) $article->public_id, static function () use (&$handled): void {
    $handled++;
})) {
    fail('Inbox should handle the first delivery.');
}
if ($inbox->once((string) $article->public_id, static function () use (&$handled): void {
    $handled++;
})) {
    fail('Inbox should ignore the duplicate delivery.');
}
if ($handled !== 1) {
    fail('Inbox handler ran the wrong number of times.');
}
echo "Inbox ignored the duplicate.\n";

echo "\nDone.\n";
