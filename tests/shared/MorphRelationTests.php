<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use RuntimeException;
use tommyknocker\pdodb\orm\relations\MorphMany;
use tommyknocker\pdodb\orm\relations\MorphMap;

final class MorphRelationTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MorphSupport::prepare(self::$db);
    }

    protected function tearDown(): void
    {
        MorphSupport::drop(self::$db);
        parent::tearDown();
    }

    public function testRelationsStoreClassNamesWithoutAMap(): void
    {
        MorphSupport::seed(self::$db, MorphPost::class, MorphVideo::class);

        $this->assertEquals(MorphSupport::expected(), MorphSupport::scenario(self::$db));
    }

    public function testRelationsWorkThroughAliases(): void
    {
        MorphMap::register(['post' => MorphPost::class, 'video' => MorphVideo::class]);
        MorphSupport::seed(self::$db, 'post', 'video');

        $this->assertEquals(MorphSupport::expected(), MorphSupport::scenario(self::$db));
        $this->assertSame('post', MorphMap::alias(MorphPost::class));
        $this->assertSame(MorphVideo::class, MorphMap::resolve('video'));
    }

    public function testAnOwnerWithoutATypeHasNoRelatedRow(): void
    {
        MorphSupport::seed(self::$db, MorphPost::class, MorphVideo::class);
        self::$db->find()->table('morph_comments')->insert(['id' => 9, 'commentable_type' => null, 'commentable_id' => null, 'body' => 'orphan']);

        $orphan = MorphComment::findOne(9);

        $this->assertNotNull($orphan);
        $this->assertNull($orphan->commentable);
        $this->assertNull(MorphComment::find()->with('commentable')->where('id', 9)->one()->commentable);
    }

    public function testAnUnknownTypeIsAnErrorNotASilentNull(): void
    {
        MorphSupport::seed(self::$db, 'App\\Gone\\Post', MorphVideo::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown morph type 'App\\Gone\\Post'");
        MorphComment::findOne(1)->commentable;
    }

    public function testEnforcingTheMapRefusesClassNamesFromTheDatabase(): void
    {
        MorphMap::register(['post' => MorphPost::class]);
        MorphMap::enforce();
        MorphSupport::seed(self::$db, 'post', MorphVideo::class);

        try {
            // the stored value names a real model class, but it is not in the map
            MorphComment::findOne(3)->commentable;
            $this->fail('A class name from the database must not be accepted when the map is enforced');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unknown morph type', $e->getMessage());
        }

        $this->assertSame('p1', MorphComment::findOne(1)->commentable->getAttribute('title'));
        $this->expectException(RuntimeException::class);
        MorphMap::alias(MorphVideo::class);
    }

    public function testTheMapRejectsAnythingButModels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MorphMap::register(['x' => \stdClass::class]);
    }

    public function testAMorphRelationNeedsItsName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MorphMany(MorphComment::class, []);
    }

    public function testNestedEagerLoadingContinuesFromEachResolvedClass(): void
    {
        MorphSupport::seed(self::$db, MorphPost::class, MorphVideo::class);

        $comments = MorphComment::find()->with(['commentable' => ['comments']])->orderBy('id')->all();

        // the owner of comment 1 is post 1, whose own comments were loaded in the same pass
        $owner = $comments[0]->commentable;
        $this->assertCount(2, $owner->comments);
        $this->assertCount(1, $comments[2]->commentable->comments, 'video 1 has one comment');
    }

    public function testQueryMethodSyntaxAppliesTheTypeToo(): void
    {
        MorphSupport::seed(self::$db, MorphPost::class, MorphVideo::class);

        $comments = MorphPost::findOne(1)->comments()->all();

        $this->assertCount(2, $comments, 'video 1 has the same id and one comment, which must not be counted');
    }
}
