<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * The router defers its specificity sort to the first match()/allRoutes() that
 * reads a bucket, instead of re-sorting the whole bucket after every insert
 * (that was O(n^2 log n) for a table and a measurable share of a per-request
 * boot: 100 filler routes cost ~190 us, ~55 us of it sort).
 *
 * The deferral is only safe because
 *   - a route's specificity is fixed at insert time, and
 *   - PHP >= 8.0 sorts are stable,
 * so one stable sort of the finished bucket reaches the same order that
 * stable-sorting after every insert converges to.
 *
 * These tests pin that contract. They deliberately do NOT re-implement
 * calculateSpecificity(); they compare the router against ITSELF under
 * different interleavings, which is exactly the property the deferral adds.
 */
class RouterDeferredSortTest extends TestCase
{
    /**
     * Routes that all share the first segment "blog", so they land in ONE
     * bucket. Specificities collide on purpose (two pairs) to exercise the
     * stability the deferral relies on.
     *
     * @return list<string>
     */
    private function oneBucketPatterns(): array
    {
        return [
            '/blog/{slug}',                           // param with implicit '*'
            '/blog/{a:*}',                            // wildcard
            '/blog/{id:int}',                         // typed param
            '/blog/{n:alpha}',                        // typed param (same score as id:int)
            '/blog/{id:int}/edit',                    // most segments
            '/blog/{year:int}/{month:int}/{day:int}', // longest typed
        ];
    }

    /**
     * Register the given patterns in order and return the resulting bucket
     * order, identified by the handler each route was registered with.
     *
     * @param list<string> $patterns
     * @return list<string>
     */
    private function bucketOrder(array $patterns, bool $matchInTheMiddle = false): array
    {
        $router = new Router();

        foreach ($patterns as $i => $pattern) {
            $router->add('GET', $pattern, "C{$i}::hit");

            if ($matchInTheMiddle && $i === 1) {
                // Materialise the bucket while it is still incomplete: this is
                // what a real app doing route lookups during registration (or
                // an OPTIONS probe) would trigger.
                $router->match('/blog/anything');
            }
        }

        $order = [];
        foreach ($router->allRoutes() as $route) {
            $order[] = $route['handler'];
        }

        return $order;
    }

    public function testSortingIsNotSkipped(): void
    {
        // Registered least specific first: without any ordering the wildcard
        // would stay in front and win.
        $router = new Router();
        $router->add('GET', '/blog/{a:*}', 'Wild::hit');
        $router->add('GET', '/blog/{id:int}', 'Typed::hit');

        $result = $router->match('/blog/42');

        $this->assertNotNull($result);
        $this->assertSame('Typed', $result['override']['controller']);
    }

    public function testMostSpecificWinsRegardlessOfRegistrationOrder(): void
    {
        foreach ([[false, 'least first'], [true, 'most first']] as [$reversed, $label]) {
            $patterns = $this->oneBucketPatterns();
            if ($reversed) {
                $patterns = array_reverse($patterns);
            }

            $router = new Router();
            foreach ($patterns as $pattern) {
                $router->add('GET', $pattern, 'C::hit');
            }

            // /blog/42 matches "/blog/{id:int}", "/blog/{n:alpha}" and
            // "/blog/{slug}" and "/blog/{a:*}"; a typed param must be picked.
            $result = $router->match('/blog/42');
            $this->assertNotNull($result, $label);
            $this->assertTrue(
                isset($result['vars']['id']) || isset($result['vars']['n']),
                $label . ': expected a typed param to win, got ' . json_encode($result['vars'])
            );
        }
    }

    public function testMidRegistrationMatchDoesNotChangeTheFinalOrder(): void
    {
        $patterns = $this->oneBucketPatterns();

        $this->assertSame(
            $this->bucketOrder($patterns),
            $this->bucketOrder($patterns, matchInTheMiddle: true),
            'a match() part-way through registration must not change the order'
        );
    }

    public function testAllRoutesMaterialisesTheOrderWithoutAPrecedingMatch(): void
    {
        $patterns = $this->oneBucketPatterns();

        // No match() call anywhere: allRoutes() must still be sorted.
        $withoutMatch = $this->bucketOrder($patterns);

        // Same set, but each route is matched immediately after registering.
        $router = new Router();
        foreach ($patterns as $i => $pattern) {
            $router->add('GET', $pattern, "C{$i}::hit");
            $router->match('/blog/anything');
        }
        $withMatch = [];
        foreach ($router->allRoutes() as $route) {
            $withMatch[] = $route['handler'];
        }

        $this->assertSame($withMatch, $withoutMatch);
    }

    public function testRepeatedMatchesAreStableAndKeepWinning(): void
    {
        $router = new Router();
        $router->add('GET', '/blog/{a:*}', 'Wild::hit');
        $router->add('GET', '/blog/{id:int}', 'Typed::hit');

        $first  = $router->match('/blog/42');
        $second = $router->match('/blog/42');

        $this->assertNotNull($first);
        $this->assertSame('Typed', $first['override']['controller']);
        $this->assertSame($first['override'], $second['override']);
    }

    public function testStaticRoutesStillWinOverDynamicOnes(): void
    {
        $router = new Router();
        $router->add('GET', '/blog/{a:*}', 'Wild::hit');
        $router->add('GET', '/blog/archive', 'Static::hit');

        $result = $router->match('/blog/archive');

        $this->assertNotNull($result);
        $this->assertSame('Static', $result['override']['controller']);
    }
}
