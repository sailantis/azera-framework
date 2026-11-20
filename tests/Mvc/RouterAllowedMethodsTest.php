<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * The 404-vs-405 contract, and the `Allow` list that goes with it.
 *
 * `allowedMethods()` returns null for a path that matches nothing and a non-empty
 * list for a path that exists under other methods. `getAllowedMethods()` keeps its
 * older `array` return, and must agree with the other method about everything
 * except the null.
 */
class RouterAllowedMethodsTest extends TestCase
{
    // -------------------------------------------------------------------------
    //  null = 404, list = 405
    // -------------------------------------------------------------------------

    public function testAnUnknownPathIsNull(): void
    {
        $router = new Router();
        $router->get('/x', 'X::a');

        $this->assertNull($router->allowedMethods('/nope'));
        $this->assertNull($router->allowedMethods('/nope/deeper'));
    }

    public function testAPathThatExistsUnderAnotherMethodReturnsThatMethod(): void
    {
        $router = new Router();
        $router->get('/x', 'X::a');
        $router->post('/x', 'X::b');

        $this->assertSame(['GET', 'POST', 'HEAD'], $router->allowedMethods('/x'));
    }

    public function testAllowedMethodsIsNeverAnEmptyArray(): void
    {
        $router = new Router();
        $router->get('/x', 'X::a');

        // A path either answers with a method or returns null. An empty list would
        // force every caller to write two branches for the same case.
        $this->assertNotSame([], $router->allowedMethods('/x'));
        $this->assertNotSame([], $router->allowedMethods('/nope'));
        $this->assertNull($router->allowedMethods('/nope'));
    }

    public function testAnUnknownPathStillReturnsAnEmptyArrayFromTheOlderMethod(): void
    {
        $router = new Router();
        $router->get('/x', 'X::a');

        $this->assertSame([], $router->getAllowedMethods('/nope'));
    }

    // -------------------------------------------------------------------------
    //  Non-standard methods must be reported
    // -------------------------------------------------------------------------

    public function testAMethodOutsideTheKnownListIsReported(): void
    {
        $router = new Router();
        $router->add('PROPFIND', '/dav', 'D::propfind');

        // The router serves it, so `Allow` has to name it. Iterating a fixed list of
        // seven methods reported [] here, which a caller would read as 404.
        $this->assertNotNull($router->match('/dav', 'PROPFIND'));
        $this->assertSame(['PROPFIND'], $router->getAllowedMethods('/dav'));
        $this->assertSame(['PROPFIND'], $router->allowedMethods('/dav'));
    }

    public function testKnownMethodsKeepTheirOrderAndUnknownOnesFollowAlphabetically(): void
    {
        $router = new Router();
        $router->post('/x', 'X::post');
        $router->get('/x', 'X::get');
        $router->add('ZETA', '/x', 'X::zeta');
        $router->add('ALPHA', '/x', 'X::alpha');

        $this->assertSame(
            ['GET', 'POST', 'HEAD', 'ALPHA', 'ZETA'],
            $router->getAllowedMethods('/x'),
        );
    }

    // -------------------------------------------------------------------------
    //  HEAD is fused to GET
    // -------------------------------------------------------------------------

    public function testHeadIsReportedForAGetRouteButNotForAPostOnlyRoute(): void
    {
        $router = new Router();
        $router->get('/get-only', 'A::a');
        $router->post('/post-only', 'A::b');

        $this->assertSame(['GET', 'HEAD'], $router->allowedMethods('/get-only'));
        $this->assertSame(['POST'], $router->allowedMethods('/post-only'));
    }

    // -------------------------------------------------------------------------
    //  autoOptions is synthesised in exactly one place
    // -------------------------------------------------------------------------

    public function testGetAllowedMethodsAndMatchAgreeWhenAutoOptionsIsOn(): void
    {
        $router = new Router();
        $router->autoOptions(true);
        $router->get('/x', 'X::a');

        // These used to disagree: match() appended OPTIONS itself, this did not.
        $this->assertSame(
            $router->getAllowedMethods('/x'),
            $router->match('/x', 'OPTIONS')['__allowed_methods'],
        );

        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $router->getAllowedMethods('/x'));
    }

    public function testAnExplicitOptionsRouteIsNotDuplicated(): void
    {
        $router = new Router();
        $router->autoOptions(true);
        $router->get('/x', 'X::a');
        $router->add('OPTIONS', '/x', 'X::opt');

        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $router->getAllowedMethods('/x'));
    }

    public function testAnOptionsOnlyRouteIsNotDuplicated(): void
    {
        $router = new Router();
        $router->autoOptions(true);
        $router->add('OPTIONS', '/x', 'X::opt');

        $this->assertSame(['OPTIONS'], $router->getAllowedMethods('/x'));
    }

    public function testAutoOptionsDoesNotInventMethodsForAnUnknownPath(): void
    {
        $router = new Router();
        $router->autoOptions(true);
        $router->get('/x', 'X::a');

        $this->assertSame([], $router->getAllowedMethods('/nope'));
        $this->assertNull($router->allowedMethods('/nope'));
    }

    public function testAutoOptionsOffLeavesOptionsOut(): void
    {
        $router = new Router();
        $router->get('/x', 'X::a');

        $this->assertSame(['GET', 'HEAD'], $router->getAllowedMethods('/x'));
    }

    // -------------------------------------------------------------------------
    //  The cheap existence path must never produce a false 404
    // -------------------------------------------------------------------------

    public function testALeadingParameterRouteIsNeverShortCircuited(): void
    {
        $router = new Router();
        $router->get('/{controller}/{action}', null);

        // Any first segment may match this, so the index cannot answer "no".
        $this->assertSame(['GET', 'HEAD'], $router->allowedMethods('/whatever/thing'));
        $this->assertNull($router->allowedMethods('/a'));
    }

    public function testAddingARouteInvalidatesTheIndex(): void
    {
        $router = new Router();
        $router->get('/a', 'A::a');

        $this->assertNull($router->allowedMethods('/b'));

        $router->post('/b', 'B::b');

        $this->assertSame(['POST'], $router->allowedMethods('/b'));
    }

    public function testAddingAStaticRouteInvalidatesTheIndex(): void
    {
        $router = new Router();
        $router->get('/a', 'A::a');

        $this->assertNull($router->allowedMethods('/b'));

        $router->post('/b', 'B::b');

        $this->assertSame(['POST'], $router->allowedMethods('/b'));
    }

    // -------------------------------------------------------------------------
    //  Cache round trip
    // -------------------------------------------------------------------------

    public function testARestoredTableAnswersIdentically(): void
    {
        $source = new Router();
        $source->autoOptions(true);
        $source->get('/x', 'X::a');
        $source->add('PROPFIND', '/dav', 'D::p');
        $source->post('/form', 'F::p');

        $restored = new Router();
        $restored->restoreState($source->exportState());

        foreach (['/x', '/dav', '/form', '/nope'] as $uri) {
            $this->assertSame(
                $source->allowedMethods($uri),
                $restored->allowedMethods($uri),
                "allowedMethods() differs for {$uri}",
            );
            $this->assertSame(
                $source->getAllowedMethods($uri),
                $restored->getAllowedMethods($uri),
                "getAllowedMethods() differs for {$uri}",
            );
        }
    }
}
