<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/Fixtures/Relations.php';

use Azera\Orm\Heap;
use Azera\Orm\HydrationMap;
use Azera\Orm\RowSplitter;
use Azera\Tests\Orm\Fixtures\Comment;
use PHPUnit\Framework\TestCase;

/**
 * RowSplitter: one flat row -> root + joined entities.
 */
class RowSplitterTest extends TestCase
{
    private function plan(): array
    {
        return HydrationMap::build(Comment::class, ['author']);
    }

    public function testSplitCreatesRootAndJoined(): void
    {
        $heap = new Heap();
        $rs   = new RowSplitter($heap);

        $row = [
            'comment__id'          => 1,
            'comment__body'        => 'Nice',
            'comment__article_id'  => 5,
            'comment__author_id'   => 2,
            'comment_author__id'   => 2,
            'comment_author__name' => 'Ada',
        ];

        [$root, $related] = $rs->split($row, $this->plan());

        $this->assertInstanceOf(Comment::class, $root);
        $this->assertSame(1, $root->id);
        $this->assertSame('Ada', $related['author']->name);
    }

    public function testOrphanGuardJoinedPkNull(): void
    {
        $heap = new Heap();
        $rs   = new RowSplitter($heap);
        $row  = [
            'comment__id'          => 1,
            'comment__body'        => 'X',
            'comment__article_id'  => 5,
            'comment__author_id'   => null,
            'comment_author__id'   => null,
            'comment_author__name' => null,
        ];

        [$root, $related] = $rs->split($row, $this->plan());

        $this->assertNotNull($root);
        $this->assertNull($related['author']);
    }

    public function testHeapDedupSameIdentitySameObject(): void
    {
        $heap = new Heap();
        $rs   = new RowSplitter($heap);

        $mk = fn(int $aid, int $aid2, ?string $name = null) => [
            'comment__id'          => $aid,
            'comment__body'        => 'X',
            'comment__article_id'  => 5,
            'comment__author_id'   => $aid2,
            'comment_author__id'   => $aid2,
            'comment_author__name' => $name,
        ];

        $r1 = $mk(1, 2, 'Ada');
        $r2 = $mk(2, 2, 'Ada'); // same author identity, different comment

        [$a, $ra] = $rs->split($r1, $this->plan());
        [$b, $rb] = $rs->split($r2, $this->plan());

        $this->assertSame($ra['author'], $rb['author'], 'same identity = same object');
    }

    /**
     * The join path used to assign raw row values with no cast and a
     * snapshot keyed by FIELD name, holding driver strings. Two bugs in
     * one: numeric strings in the snapshot (phantom UPDATEs on the next
     * flush) and a snapshot the diff engine cannot read at all (it is
     * column-name keyed).
     */
    public function testSnapshotIsColumnKeyed(): void
    {
        $heap = new Heap();
        $rs   = new RowSplitter($heap);

        $row = [
            'comment__id'          => '1',
            'comment__body'        => 'Nice',
            'comment__article_id'  => '5',
            'comment__author_id'   => '2',
            'comment_author__id'   => '2',
            'comment_author__name' => 'Ada',
        ];

        [$root] = $rs->split($row, $this->plan());

        $node = $heap->find($root);

        // Snapshot keyed by the metadata COLUMN name — the same key
        // extractData() emits, so diff() can actually read the baseline.
        // (Comment's relations are declared on `article`/`author`, so its
        // columns are just id + body; the FK aliases are not columns.)
        $this->assertSame(
            ['id', 'body'],
            array_keys($node->data)
        );
        $this->assertSame('1', $node->data['id']);
        $this->assertSame('Nice', $node->data['body']);
    }
}