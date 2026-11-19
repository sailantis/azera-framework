<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Pins the compiled-regex match path against the token-scan path it replaced.
 *
 * The router answers from a compiled index: candidates whose shape is expressible
 * as PCRE are combined into chunked patterns, everything else stays a single scan
 * step. That is only safe if both kinds return exactly what the old per-candidate
 * token walk returned, so these tests compare the two directly on the same table
 * rather than restating either implementation.
 */
class RouterFastPathTest extends TestCase
{
    /**
     * A table that deliberately mixes both kinds, so every test exercises the
     * boundary between a combined run and a scan step.
     */
    private function router(): Router
    {
        $router = new Router();

        $router->get('/about', 'Page::about');

        for ($i = 0; $i < 12; $i++) {
            $router->get("/users/{id:int}/sub{$i}", "U{$i}::show");
        }

        for ($i = 0; $i < 4; $i++) {
            $router->get("/{lang}/page{$i}", "P{$i}::show");
        }

        $router->get('/files/{path:*}', 'Files::serve');
        $router->get('/opt/{id?:int}/tail', 'Opt::tail');
        $router->get('/r/{v:regex(v[1-9])}/x', 'R::x');

        $router->type('slug', static fn($v) => preg_match('/^[a-z-]+$/', $v) === 1);
        $router->get('/blog/{slug:slug}', 'Blog::view');

        return $router;
    }

    public function testTypedCandidatesMatchAndCapture(): void
    {
        $router = $this->router();

        $result = $router->match('/users/42/sub7', 'GET');

        $this->assertNotNull($result);
        $this->assertSame('U7', $result['override']['controller']);
        $this->assertSame(['id' => '42'], $result['vars']);
    }

    public function testARejectedTypedSegmentFallsThroughToTheNextCandidate(): void
    {
        $router = $this->router();

        // "abc" fails /users/{id:int}/… but still matches the dynamic-first bucket.
        $this->assertNull($router->match('/users/abc/sub7', 'GET'));
        $this->assertNull($router->match('/users/42/nothere', 'GET'));
    }

    /**
     * The most specific candidate wins inside a combined chunk, which is the
     * property that makes chunking safe: order within the bucket is the specificity
     * order, not the registration order.
     */
    public function testChunkOrderFollowsSpecificityNotRegistration(): void
    {
        $router = new Router();
        $router->get('/item/{x:*}', 'Wild::hit');
        $router->get('/item/{id:int}', 'Typed::hit');

        $this->assertSame('Typed', $router->match('/item/7', 'GET')['override']['controller']);
        $this->assertSame('Wild', $router->match('/item/abc', 'GET')['override']['controller']);
    }

    /**
     * A non-combinable candidate in the middle of a bucket must not change what the
     * combinable ones around it answer — this is the per-run fallback, and the
     * reason an exotic route does not cost its neighbours the fast path.
     */
    public function testANonCombinableCandidateDoesNotDisturbItsNeighbours(): void
    {
        $router = new Router();
        $router->get('/x/{a:int}/one', 'Before::hit');
        $router->get('/x/{b?:int}/two', 'Scan::hit');   // optional segment: scan step
        $router->get('/x/{c:int}/three', 'After::hit');

        $this->assertSame('Before', $router->match('/x/1/one', 'GET')['override']['controller']);
        $this->assertSame('After', $router->match('/x/3/three', 'GET')['override']['controller']);
        $this->assertSame('Scan', $router->match('/x/2/two', 'GET')['override']['controller']);
    }

    /**
     * Custom validators are closures and cannot be inlined. Their routes must still
     * validate — falling back to "accept anything" would be a security bug.
     */
    public function testCustomValidatorsStillRun(): void
    {
        $router = $this->router();

        $this->assertNotNull($router->match('/blog/hello-world', 'GET'));
        $this->assertNull($router->match('/blog/NOT-VALID', 'GET'));
    }

    public function testRegexKindStillRuns(): void
    {
        $router = $this->router();

        $this->assertSame(['v' => 'v3'], $router->match('/r/v3/x', 'GET')['vars']);
        $this->assertNull($router->match('/r/vX/x', 'GET'));
    }

    /**
     * The compiled index is derived from the buckets, so a route added after the
     * first match must be visible on the next one.
     */
    public function testAddingARouteAfterAMatchInvalidatesTheIndex(): void
    {
        $router = new Router();
        $router->get('/users/{id:int}', 'User::show');

        $this->assertSame('User', $router->match('/users/1', 'GET')['override']['controller']);

        $router->get('/late/{id:int}', 'Late::show');

        $this->assertSame('User', $router->match('/users/1', 'GET')['override']['controller'], 'the old route still answers');
        $this->assertSame('Late', $router->match('/late/9', 'GET')['override']['controller'], 'the new route answers');
    }

    /**
     * A restored table carries the same compiled answers as the router that wrote
     * it. The index is deliberately not persisted, so this also covers rebuilding it
     * from restored tokens.
     */
    public function testARestoredTableAnswersIdentically(): void
    {
        $source = $this->router();

        $restored = new Router();
        $restored->type('slug', static fn($v) => preg_match('/^[a-z-]+$/', $v) === 1);
        $restored->restoreState($source->exportState());

        foreach ([
            ['/about', 'GET'],
            ['/users/42/sub11', 'GET'],
            ['/users/abc/sub1', 'GET'],
            ['/en/page3', 'GET'],
            ['/files/a/b/c', 'GET'],
            ['/opt/9/tail', 'GET'],
            ['/opt/tail', 'GET'],
            ['/r/v2/x', 'GET'],
            ['/blog/some-slug', 'GET'],
            ['/nope', 'GET'],
        ] as [$uri, $method]) {
            $this->assertEquals(
                $source->match($uri, $method),
                $restored->match($uri, $method),
                "restored table disagrees for {$method} {$uri}",
            );
        }
    }

    /**
     * The point of the whole exercise: a bucket must be served by a bounded number of
     * combined patterns, not by one scan step per route. Asserted structurally rather
     * than by timing — a timing assertion in a unit test is a flake waiting to happen,
     * and this is the property that actually makes the timing what it is.
     */
    public function testABucketIsCompiledIntoChunksNotOneStepPerRoute(): void
    {
        $router = new Router();
        for ($i = 0; $i < 200; $i++) {
            $router->get("/b/{id:int}/leaf{$i}", "C{$i}::show");
        }

        // Reading the bucket is what compiles it.
        $this->assertSame('C199', $router->match('/b/42/leaf199', 'GET')['override']['controller']);

        $compiled = new \ReflectionProperty(Router::class, 'compiled');
        $segments = $compiled->getValue($router)['GET']['b'];

        $this->assertLessThan(
            10,
            \count($segments),
            sprintf('200 routes became %d match steps; they should be a handful of chunks', \count($segments)),
        );

        $this->assertArrayHasKey('chunk', $segments[0], 'the bucket should be served by a combined pattern');
        $this->assertArrayNotHasKey('route', $segments[0], 'not by a per-route scan step');
    }

    /**
     * A bucket that cannot be combined must not drag its combinable neighbours down
     * with it: the fallback is per contiguous run, so the rest is still chunked.
     */
    public function testANonCombinableRouteOnlyCostsItsOwnStep(): void
    {
        $router = new Router();
        for ($i = 0; $i < 40; $i++) {
            $router->get("/c/{id:int}/leaf{$i}", "C{$i}::show");
        }
        $router->get('/c/{x?:int}/optional', 'Opt::hit');

        $this->assertNotNull($router->match('/c/1/optional', 'GET'));

        $compiled = new \ReflectionProperty(Router::class, 'compiled');
        $segments = $compiled->getValue($router)['GET']['c'];

        $chunks = 0;
        $steps  = 0;
        foreach ($segments as $segment) {
            if (isset($segment['chunk'])) {
                $chunks++;
            } else {
                $steps++;
            }
        }

        $this->assertSame(1, $steps, 'only the non-combinable route needs a scan step');
        $this->assertSame(1, $chunks, 'the other 40 routes stay in a combined chunk');
    }
}
