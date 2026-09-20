<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/Fixtures/Relations.php';

use Azera\Orm\Heap;
use Azera\Orm\HydrationMap;
use Azera\Orm\RowSplitter;
use Azera\Tests\Orm\Fixtures\Article;
use Azera\Tests\Orm\Fixtures\Author;
use Azera\Tests\Orm\Fixtures\Comment;
use Azera\Tests\Orm\Fixtures\Tag;
use PHPUnit\Framework\TestCase;
use stdClass;

class HydrationTest extends TestCase
{
    /* ----------------------------------------------- HydrationMap::build */

    public function testPlanRootEntryOnly(): void
    {
        $plan = HydrationMap::build(Article::class, []);

        $this->assertCount(1, $plan['entries']);
        $root = $plan['entries'][0];
        $this->assertSame(Article::class, $root['class']);
        $this->assertSame('article', $root['alias']);
        $this->assertSame([], $plan['secondQueries']);
        $this->assertArrayHasKey('id', $root['pk']);
    }

    public function testPlanBelongsToProducesJoinEntry(): void
    {
        $plan = HydrationMap::build(Comment::class, ['author']);

        $this->assertCount(2, $plan['entries']);
        $root   = $plan['entries'][0];
        $joined = $plan['entries'][1];

        $this->assertSame('comment', $root['alias']);
        // fields: field => [generated alias, raw column name]. The raw
        // name keys the heap snapshot so joins diff like find() reads.
        $this->assertSame(['comment__id', 'id'], $root['fields']['id']);
        $this->assertSame('comment_author', $joined['alias']);
        $this->assertSame(Author::class, $joined['class']);
        // belongsTo: parent FK = child ownerKey
        $this->assertSame('comment.author_id', $joined['joinOn']['left']);
        $this->assertSame('comment_author.id', $joined['joinOn']['right']);
        $this->assertSame([], $plan['secondQueries']);
    }

    public function testPlanHasManyGoesToSecondQueries(): void
    {
        // Tag declares HasMany(Article): to-many must NOT join — second query.
        $plan = HydrationMap::build(Tag::class, ['articles']);

        $this->assertCount(1, $plan['entries']);
        $this->assertSame('articles', $plan['secondQueries'][0]['relation']);
        $this->assertSame(Article::class, $plan['secondQueries'][0]['class']);
    }
}