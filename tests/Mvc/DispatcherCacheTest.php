<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\AppContext;
use Azera\Core\Controller;
use Azera\Core\Dispatcher;
use Azera\Core\MiddlewareInterface;
use Azera\Core\ResolvedRoute;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Controller action returning a fixed body, so a dispatch assertion is about
 * the dispatcher rather than about a controller.
 */
class CacheProbeController extends Controller
{
    public function act(): Response
    {
        return Response::text('ok');
    }

    public function echoAction(string $id): Response
    {
        return Response::text('id=' . $id);
    }
}

/** Records that it ran, without changing the response. */
class CacheProbeMiddleware implements MiddlewareInterface
{
    public static int $calls = 0;

    public function process(AppContext $context, callable $next): Response
    {
        self::$calls++;
        return $next();
    }
}

/**
 * The dispatcher's per-request caches.
 *
 * Three things were recomputed on every request and are now reused: the
 * `ReflectionMethod` for an action, the `ResolvedRoute` object, and — for a
 * route with no middleware — the pipeline closure that only wraps the core
 * handler. Together they were 21% of an in-process dispatch and 8% of a served
 * 17-byte response.
 *
 * Each of these is a cache, so each test below is about what must *not* be
 * shared: a stale route for a different id, a middleware that silently stops
 * running, or an action resolved against the wrong controller.
 */
class DispatcherCacheTest extends TestCase
{
    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
        CacheProbeMiddleware::$calls = 0;
    }

    protected function tearDown(): void
    {
        AppContext::reset();
    }

    private function route(array $vars = [], array $groups = [], string $action = 'act'): array
    {
        $class = CacheProbeController::class;
        $pos   = strrpos($class, '\\');

        return [
            'vars'     => $vars,
            'override' => [
                'namespace'  => $pos === false ? '' : substr($class, 0, $pos),
                'controller' => $pos === false ? $class : substr($class, $pos + 1),
                'action'     => $action,
            ],
            'groups'   => $groups,
        ];
    }

    public function testDispatchReturnsTheControllerBody(): void
    {
        $dispatcher = new Dispatcher();

        $this->assertSame('ok', $dispatcher->dispatch($this->route())->getBody());
    }

    /**
     * The `ResolvedRoute` handed to the context is reused, so it must carry the
     * variables of the request being served and not of the first one that
     * built it.
     */
    public function testReusedRouteCarriesThisRequestsVariables(): void
    {
        $dispatcher = new Dispatcher();
        $context    = AppContext::instance();

        $first  = $this->route(['id' => '1'], [], 'echoAction');
        $second = $this->route(['id' => '2'], [], 'echoAction');

        $this->assertSame('id=1', $dispatcher->dispatch($first)->getBody());
        $this->assertInstanceOf(ResolvedRoute::class, $context->route());
        $this->assertSame(['id' => '1'], $context->route()->vars);

        $this->assertSame('id=2', $dispatcher->dispatch($second)->getBody());
        $this->assertSame(['id' => '2'], $context->route()->vars);
    }

    /**
     * The same route dispatched twice must produce the same object — that is
     * the cache — while still reporting the current request's variables.
     */
    public function testStaticRouteReusesOneResolvedRouteInstance(): void
    {
        $dispatcher = new Dispatcher();
        $context    = AppContext::instance();

        $dispatcher->dispatch($this->route());
        $first = $context->route();

        $dispatcher->dispatch($this->route());
        $second = $context->route();

        $this->assertSame($first, $second);
    }

    /**
     * A route with no middleware must not pay for a pipeline. Dropping the
     * chain of zero links is only correct if the handler still runs, which is
     * what this asserts — a body, not a skipped controller.
     */
    public function testRouteWithoutMiddlewareStillInvokesTheAction(): void
    {
        $dispatcher = new Dispatcher();

        $response = $dispatcher->dispatch($this->route());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ok', $response->getBody());
    }

    /**
     * The shortcut must not swallow middleware when middleware *is* present.
     */
    public function testGlobalMiddlewareStillRunsOncePerRequest(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addMiddleware(new CacheProbeMiddleware());

        $this->assertSame('ok', $dispatcher->dispatch($this->route())->getBody());
        $this->assertSame(1, CacheProbeMiddleware::$calls);

        $this->assertSame('ok', $dispatcher->dispatch($this->route())->getBody());
        $this->assertSame(2, CacheProbeMiddleware::$calls);
    }

    public function testGroupMiddlewareStillRuns(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->defineMiddlewareGroup('grp', [CacheProbeMiddleware::class]);

        $this->assertSame('ok', $dispatcher->dispatch($this->route([], ['grp']))->getBody());
        $this->assertSame(1, CacheProbeMiddleware::$calls);
    }

    /**
     * Two actions on one controller are two distinct entries; a cache keyed on
     * the controller alone would dispatch the wrong one.
     */
    public function testTwoActionsOnOneControllerAreNotConfused(): void
    {
        $dispatcher = new Dispatcher();

        $act = $dispatcher->dispatch($this->route([], [], 'act'));

        $echo = $dispatcher->dispatch($this->route(['id' => '9'], [], 'echoAction'));

        $this->assertSame('ok', $act->getBody());
        $this->assertSame('id=9', $echo->getBody());
    }

    /**
     * An action that does not exist must still raise the dispatcher's own
     * error, now that reflection is cached and the lookup moved out of the
     * argument-resolution loop.
     */
    public function testMissingActionStillRaisesActionNotFound(): void
    {
        $dispatcher = new Dispatcher();

        $this->expectException(\Azera\Core\Exceptions\ActionNotFoundException::class);

        $dispatcher->dispatch($this->route([], [], 'doesNotExistAction'));
    }
}
