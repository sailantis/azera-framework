<?php

namespace Azera\Tests\Mvc;

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The handler parse is cached across requests.
 *
 * `resolveHandler()` used to re-parse `'Ns\Controller::action'` on every match,
 * which was 77% of a static route's routing cost (0.41 µs of 0.53 µs). The
 * cache is keyed on the handler value, so these tests pin the property that
 * makes that sound: the result depends on nothing else, and two routes that
 * share a handler string must still resolve it the same way.
 *
 * The failure mode a cache introduces here is stale or crossed answers — a
 * route resolving to the previous route's controller — so every test below
 * asserts a *specific* answer rather than merely a non-null one.
 */
class RouterHandlerCacheTest extends TestCase
{
    public function testStaticRouteResolvesTheSameOnEveryMatch(): void
    {
        $router = new Router();
        $router->add('GET', '/license', 'App\\Controllers\\LicenseController::indexAction');

        $first  = $router->match('/license');
        $second = $router->match('/license');
        $third  = $router->match('/license');

        $expected = [
            'namespace'  => 'App\\Controllers',
            'controller' => 'LicenseController',
            'action'     => 'indexAction',
        ];

        $this->assertSame($expected, $first['override']);
        $this->assertSame($expected, $second['override']);
        $this->assertSame($expected, $third['override']);
    }

    public function testTwoRoutesSharingAHandlerStringResolveIdentically(): void
    {
        $router = new Router();
        $router->add('GET', '/a', 'App\\C\\SharedController::runAction');
        $router->add('GET', '/b', 'App\\C\\SharedController::runAction');

        $a = $router->match('/a');
        $b = $router->match('/b');

        $this->assertSame($a['override'], $b['override']);
        $this->assertSame('SharedController', $b['override']['controller']);
    }

    public function testDistinctHandlersDoNotCrossContaminate(): void
    {
        $router = new Router();
        $router->add('GET', '/one', 'App\\One\\FirstController::alphaAction');
        $router->add('GET', '/two', 'App\\Two\\SecondController::betaAction');

        // Interleaved, so a cache keyed on position rather than on the handler
        // would swap these.
        for ($i = 0; $i < 3; $i++) {
            $one = $router->match('/one');
            $two = $router->match('/two');

            $this->assertSame('App\\One', $one['override']['namespace']);
            $this->assertSame('FirstController', $one['override']['controller']);
            $this->assertSame('alphaAction', $one['override']['action']);

            $this->assertSame('App\\Two', $two['override']['namespace']);
            $this->assertSame('SecondController', $two['override']['controller']);
            $this->assertSame('betaAction', $two['override']['action']);
        }
    }

    /**
     * The `null` handler and the empty-string handler are different inputs and
     * must not share a cache slot: `null` means "no handler given" and yields an
     * empty override, while `''` is trimmed to nothing and yields no controller
     * either — but they arrive through different branches of the parse.
     */
    public function testNullAndEmptyHandlersDoNotShareASlot(): void
    {
        $router = new Router();
        $router->get('/null', null);
        $router->get('/empty', '');

        $null  = $router->match('/null');
        $empty = $router->match('/empty');

        $this->assertSame([], $null['override']);
        $this->assertSame([], $empty['override']);
    }

    /**
     * The array form of a handler must be cached per *value*, so two routes
     * whose array handlers differ only in one field stay distinct. Serialising
     * the array is what makes that true; an identity-keyed cache would alias
     * them.
     */
    public function testArrayHandlersAreCachedByValueNotIdentity(): void
    {
        $router = new Router();
        $router->add('GET', '/x', ['controller' => 'App\\X\\XController', 'action' => 'xAction']);
        $router->add('GET', '/y', ['controller' => 'App\\Y\\YController', 'action' => 'yAction']);

        $x = $router->match('/x');
        $y = $router->match('/y');

        $this->assertSame('XController', $x['override']['controller']);
        $this->assertSame('xAction', $x['override']['action']);
        $this->assertSame('YController', $y['override']['controller']);
        $this->assertSame('yAction', $y['override']['action']);
    }

    /**
     * A dynamic route re-reports different variables per request while its
     * handler parse stays the same — the cache stores the parse, never the
     * variables.
     */
    public function testDynamicRouteKeepsPerRequestVariables(): void
    {
        $router = new Router();
        $router->add('GET', '/user/{id:int}', 'App\\C\\UserController::showAction');

        $a = $router->match('/user/7');
        $b = $router->match('/user/8');

        $this->assertSame('UserController', $a['override']['controller']);
        $this->assertSame(['id' => '7'], $a['vars']);
        $this->assertSame('UserController', $b['override']['controller']);
        $this->assertSame(['id' => '8'], $b['vars']);
    }

    /**
     * Groups are per-route state and must survive the cache untouched: two
     * routes with the same handler but different middleware groups are
     * genuinely different routes.
     */
    public function testGroupsAreNotCachedWithTheHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/plain', 'App\\C\\SharedController::runAction');

        $router->middleware('session', function (Router $r) {
            $r->add('GET', '/grouped', 'App\\C\\SharedController::runAction');
        });

        $plain   = $router->match('/plain');
        $grouped = $router->match('/grouped');

        $this->assertSame($plain['override'], $grouped['override']);
        $this->assertSame([], $plain['groups']);
        $this->assertSame(['session'], $grouped['groups']);
    }
}
