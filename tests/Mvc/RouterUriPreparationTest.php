<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * One URI-preparation step for every public entry point.
 *
 * `match()` used to decode segments itself while `getAllowedMethods()` did not, so
 * the two disagreed about encoded URLs. Worse, `match()` was internally
 * inconsistent: the combined regex matched the raw subject while the token scan
 * used the decoded one, so whether `/a%20b/7` matched depended on whether the
 * route happened to be inlinable.
 *
 * Every case here is asserted through both public methods, because the point is
 * that they cannot disagree.
 */
class RouterUriPreparationTest extends TestCase
{
    /** A route that is inlinable, one that is not, and a static literal, on the same path. */
    private function encodedTable(): Router
    {
        return (new Router())
            ->get('/a b/{id:int}', 'Inline::show')
            ->get('/a b/{id}', 'Untyped::show')
            ->get('/a b', 'Static::show');
    }

    public function testAnEncodedStaticLiteralMatches(): void
    {
        $router = new Router();
        $router->get('/a b', 'Static::show');

        // The literal was compared against the raw path, so "%20" never matched.
        $this->assertNotNull($router->match('/a%20b', 'GET'));
        $this->assertSame(['GET', 'HEAD'], $router->allowedMethods('/a%20b'));
    }

    public function testUnencodedAndEncodedFormsReachTheSameRoute(): void
    {
        $router = $this->encodedTable();

        $this->assertSame(
            $router->match('/a b/7', 'GET')['override'],
            $router->match('/a%20b/7', 'GET')['override'],
        );
    }

    public function testAnInlinableRouteMatchesItsEncodedForm(): void
    {
        $router = new Router();
        $router->get('/a b/{id:int}', 'Inline::show');

        // The combined regex matched the raw subject, where the space is "%20".
        $result = $router->match('/a%20b/7', 'GET');

        $this->assertNotNull($result);
        $this->assertSame('Inline', $result['override']['controller']);
    }

    public function testMatchAndAllowedMethodsNeverDisagreeOnTheCorpus(): void
    {
        $router = $this->encodedTable();

        $uris = ['/a%20b', '/a%20b/7', '/a b', '/a b/7', '/a%20b/nope', '/a%20b/7/x', '/nope'];

        foreach ($uris as $uri) {
            $matched = $router->match($uri, 'GET') !== null;
            $allowed = $router->allowedMethods($uri);

            if ($matched) {
                $this->assertNotNull($allowed, "match() hit {$uri} but allowedMethods() said 404");
                $this->assertContains(
                    'GET',
                    $allowed,
                    "match() hit {$uri} but Allow does not list GET",
                );
                continue;
            }

            $this->assertNotContains(
                'GET',
                $allowed ?? [],
                "match() missed {$uri} but Allow lists GET",
            );
        }
    }

    public function testADecodedSeparatorDoesNotInventASegment(): void
    {
        $router = new Router();
        $router->get('/f/{name}', 'F::x');

        // Decoding the whole path would split "a/b" into two segments and change
        // which routes could match. One param must capture "a/b".
        $result = $router->match('/f/a%2Fb', 'GET');

        $this->assertNotNull($result);
        $this->assertSame(['name' => 'a/b'], $result['vars']);
    }

    public function testADecodedSeparatorStillReportsTheRightMethods(): void
    {
        $router = new Router();
        $router->get('/f/{name}', 'F::x');
        $router->post('/f/{name}', 'F::p');

        $this->assertSame(['GET', 'POST', 'HEAD'], $router->allowedMethods('/f/a%2Fb'));
    }

    public function testADecodedSeparatorDoesNotMatchASegmentPerSlash(): void
    {
        $router = new Router();
        $router->get('/f/{a}/{b}', 'F::two');

        // "a/b" is one segment after decoding, so a two-segment route must not match.
        $this->assertNull($router->match('/f/a%2Fb', 'GET'));
    }

    public function testAPercentEncodedParamIsCapturedDecoded(): void
    {
        $router = new Router();
        $router->get('/tag/{name}', 'T::show');

        $this->assertSame(['name' => 'a b'], $router->match('/tag/a%20b', 'GET')['vars']);
    }

    public function testPlusIsNotDecodedAsSpace(): void
    {
        $router = new Router();
        $router->get('/tag/{name}', 'T::show');

        // Path segments use percent-encoding, not form encoding, so "+" is a plus.
        $this->assertSame(['name' => 'a+b'], $router->match('/tag/a+b', 'GET')['vars']);
    }

    public function testTheRootPathStillMatches(): void
    {
        $router = new Router();
        $router->get('/', 'Index::index');

        $this->assertNotNull($router->match('/', 'GET'));
        $this->assertNotNull($router->match('', 'GET'));
        $this->assertSame(['GET', 'HEAD'], $router->allowedMethods('/'));
    }
}
