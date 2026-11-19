<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Pins the registration-time contracts and the matching rules that changed when the
 * compiled fast path landed. Each test here would have failed (or thrown a fatal
 * error) before those changes.
 */
class RouterContractTest extends TestCase
{
    // -------------------------------------------------------------------------
    //  Wildcard required suffix
    // -------------------------------------------------------------------------

    public function testAWildcardMatchesAnyDepthAndZeroSegments(): void
    {
        $router = new Router();
        $router->get('/files/{path:*}', 'Files::serve');

        $this->assertSame([], $router->match('/files', 'GET')['vars']['path']);
        $this->assertSame(['a'], $router->match('/files/a', 'GET')['vars']['path']);
        $this->assertSame(['a', 'b', 'c'], $router->match('/files/a/b/c', 'GET')['vars']['path']);
    }

    /**
     * The reason this table exists: a token after a wildcard is a required suffix,
     * and the wildcard never captures it.
     */
    public function testTokensAfterAWildcardAreARequiredSuffix(): void
    {
        $router = new Router();
        $router->get('/files/{path:*}', 'Bare::serve');
        $router->get('/docs/{path:*}/edit', 'Docs::edit');

        $match = $router->match('/docs/a/b/edit', 'GET');
        $this->assertNotNull($match);
        $this->assertSame('Docs', $match['override']['controller']);
        $this->assertSame(['a', 'b'], $match['vars']['path'], 'the suffix is not part of the capture');

        $this->assertNull($router->match('/docs/a/b', 'GET'), 'the suffix is required');
        $this->assertNull($router->match('/docs/a/b/wrong', 'GET'), 'the suffix must match literally');
        $this->assertNull($router->match('/docs/edit', 'GET'), 'the suffix needs its own segment');
    }

    public function testAWildcardSuffixTakesTheLastOccurrence(): void
    {
        $router = new Router();
        $router->get('/docs/{path:*}/edit', 'Docs::edit');

        $this->assertSame(['a', 'edit'], $router->match('/docs/a/edit/edit', 'GET')['vars']['path']);
    }

    public function testTheBareWildcardAndSuffixedWildcardCoexist(): void
    {
        $router = new Router();
        $router->get('/files/{path:*}', 'Bare::serve');
        $router->get('/files/{path:*}/x2', 'Suffixed::serve');

        // The suffixed route is the more specific one and wins when it applies.
        $this->assertSame('Suffixed', $router->match('/files/a/x2', 'GET')['override']['controller']);

        // Everything else falls through to the bare wildcard.
        $this->assertSame('Bare', $router->match('/files/a/b', 'GET')['override']['controller']);
        $this->assertSame(['a', 'b'], $router->match('/files/a/b', 'GET')['vars']['path']);

        $this->assertSame('Suffixed', $router->match('/files/a/b/x2', 'GET')['override']['controller']);
        $this->assertSame(
            ['a', 'b'],
            $router->match('/files/a/b/x2', 'GET')['vars']['path'],
            'the suffix never appears in the capture',
        );
    }

    public function testADynamicSegmentAfterAWildcardIsRejectedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only static segments may follow a wildcard');

        (new Router())->get('/files/{path:*}/{other}', 'Bad::route');
    }

    // -------------------------------------------------------------------------
    //  uuid
    // -------------------------------------------------------------------------

    public function testUuidMatchesTheCanonicalFormOnly(): void
    {
        $router = new Router();
        $router->get('/u/{id:uuid}', 'U::show');

        $this->assertNotNull($router->match('/u/123e4567-e89b-12d3-a456-426614174000', 'GET'));
        $this->assertNull($router->match('/u/123e4567-e89b-12d3-a456-42661417400-', 'GET'), 'a stray hyphen is not a UUID');
        $this->assertNull($router->match('/u/123e4567e89b12d3a456426614174000-1234', 'GET'));
        $this->assertNull($router->match('/u/not-a-uuid', 'GET'));
    }

    // -------------------------------------------------------------------------
    //  Type names are checked at registration
    // -------------------------------------------------------------------------

    /**
     * "{id:int?}" means the type is literally "int?" — the "?" belongs on the name.
     * That used to register cleanly and then throw from match() inside a request.
     */
    public function testAnUnknownTypeIsRejectedWhenTheRouteIsAdded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown route parameter type "int?"');

        (new Router())->get('/users/{id:int?}', 'User::show');
    }

    public function testTheErrorNamesTheCorrectSpelling(): void
    {
        try {
            (new Router())->get('/users/{id:int?}', 'User::show');
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('{id?:int}', $e->getMessage());
            $this->assertStringContainsString('{id:int?}', $e->getMessage());
        }
    }

    public function testACustomTypeRegisteredBeforeTheRouteIsAccepted(): void
    {
        $router = new Router();
        $router->type('slug', static fn($v) => $v === 'ok');
        $router->get('/blog/{slug:slug}', 'Blog::view');

        $this->assertNotNull($router->match('/blog/ok', 'GET'));
        $this->assertNull($router->match('/blog/nope', 'GET'));
    }

    public function testTheOptionalSpellingOnTheNameStillWorks(): void
    {
        $router = new Router();
        $router->get('/archive/{date?:regex(\d{4})}', 'Archive::index');

        $this->assertNotNull($router->match('/archive', 'GET'));
        $this->assertSame(['date' => '2026'], $router->match('/archive/2026', 'GET')['vars']);
        $this->assertNull($router->match('/archive/nope', 'GET'));
    }

    // -------------------------------------------------------------------------
    //  Allowed methods / auto-OPTIONS
    // -------------------------------------------------------------------------

    public function testGetAllowedMethodsReportsEveryMatchingMethodIncludingHead(): void
    {
        $router = new Router();
        $router->get('/items', 'Items::index');
        $router->post('/items', 'Items::create');
        $router->get('/items/{id:int}', 'Items::show');

        $this->assertSame(['GET', 'POST', 'HEAD'], $router->getAllowedMethods('/items'));
        $this->assertSame(['GET', 'HEAD'], $router->getAllowedMethods('/items/7'));
        $this->assertSame([], $router->getAllowedMethods('/nope'));
    }

    /**
     * This used to exhaust memory: match() delegated an unmatched OPTIONS request to
     * getAllowedMethods(), which called match() again for OPTIONS, forever.
     */
    public function testAutoOptionsDoesNotRecurse(): void
    {
        $router = new Router();
        $router->get('/x', 'A::b');
        $router->autoOptions(true);

        $result = $router->match('/x', 'OPTIONS');

        $this->assertNotNull($result);
        $this->assertTrue($result['__auto_options']);
        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $result['__allowed_methods']);
    }

    // -------------------------------------------------------------------------
    //  revalidateFreq must not empty a live table
    // -------------------------------------------------------------------------

    /**
     * ensureFlushed() used to reach its rebuild branch with no units declared, which
     * emptied the table and ran nothing — so the first request matched and the second
     * 404ed. Reproduced before the fix on exactly this shape.
     */
    public function testRevalidationWithoutCachedUnitsKeepsTheTable(): void
    {
        $router = new Router();
        $router->get('/about', 'Page::about');
        $router->setRevalidateFreq(60);

        $this->assertNotNull($router->match('/about', 'GET'), 'first read');
        $this->assertNotNull($router->match('/about', 'GET'), 'second read must not lose the table');
        $this->assertNotNull($router->match('/about', 'GET'), 'and it stays');
    }
}
