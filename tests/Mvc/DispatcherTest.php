<?php
namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/PhpThunder/AuthController.php';

use Azera\AppContext;
use Azera\Http\Session;
use Azera\Http\Response;
use Azera\Core\Controller;
use Azera\Core\Dispatcher;
use Azera\Core\MiddlewareInterface;
use Azera\Core\Router;
use PHPUnit\Framework\TestCase;
// --- Helper controllers / middleware (top-level declarations) ---

class DTResponseController extends Controller
{
    public function act()
    {
        return Response::text('response');
    }
}
class DTStringController extends Controller
{
    public function act()
    {
        return 'hello';
    }
}
class DTArrayController extends Controller
{
    public function act()
    {
        return ['a' => 1];
    }
}
class DTIntController extends Controller
{
    public function act()
    {
        return 201;
    }
}
class DTNullController extends Controller
{
    public function act()
    {
        return null;
    }
}

class GMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $r = $next();
        if ($r instanceof Response) {
            $r->write('-G');
        }
        return $r;
    }
}
class GRPMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $r = $next();
        if ($r instanceof Response) {
            $r->write('-GR');
        }
        return $r;
    }
}
class CMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $r = $next();
        if ($r instanceof Response) {
            $r->write('-C');
        }
        return $r;
    }
}
class AMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $r = $next();
        if ($r instanceof Response) {
            $r->write('-A');
        }
        return $r;
    }
}

class MWController extends Controller
{
    protected array $middlewares = [CMW::class];
    protected array $actionMiddlewares = ['act' => [AMW::class]];
    public function act()
    {
        return 'CORE';
    }
}

class DynamicTargetController extends Controller
{
    public function sampleAction()
    {
        return 'dynamic';
    }
}

class NullableSessionActionController extends Controller
{
    public function act(?Session $session = null)
    {
        return $session === null ? 'no-session' : 'has-session';
    }
}

class RoutingStateController extends Controller
{
    public function fromOverride($id = null, $args = null, ...$params)
    {
        $route = $this->context()->route();
        return [
            'controller' => $route->controller ?? null,
            'action'     => $route->action ?? null,
            'groups'     => $route->groups ?? null,
            'vars'       => $route->vars ?? null,
            // The resolved route carries the route's identity only; the invocation
            // argument list is rebuilt per request and is not part of it.
            'params'     => $route->params ?? null,
            // What the dispatcher INVOKED this action with. `func_get_args()`
            // includes the variadic tail and omits only trailing defaults the
            // dispatcher did not pass, which is exactly the observable wanted here:
            // route variables reaching the action.
            'received'   => \func_get_args(),
        ];
    }
}

class DispatcherTest extends TestCase
{
    private function routeWithOverride(string $controllerClass, string $action, array $params = [], array $groups = []): array
    {
        $pos        = strrpos($controllerClass, '\\');
        $namespace  = $pos === false ? '' : '\\' . substr($controllerClass, 0, $pos);
        $controller = $pos === false ? $controllerClass : substr($controllerClass, $pos + 1);

        return [
            'vars' => [
                'params' => $params,
            ],
            'override' => [
                'namespace'  => $namespace,
                'controller' => $controller,
                'action'     => $action,
            ],
            'groups' => $groups,
        ];
    }

    private function responseBody(Response $r): string
    {
        $rp = new \ReflectionProperty($r, 'body');
        return $rp->getValue($r);
    }

    private function responseStatus(Response $r): int
    {
        $rp = new \ReflectionProperty($r, 'status');
        return $rp->getValue($r);
    }

    private function responseHeaders(Response $r): array
    {
        $rp = new \ReflectionProperty($r, 'headers');
        return $rp->getValue($r);
    }

    public function testReturnTypeConversions(): void
    {
        $disp = new Dispatcher();

        // response passthrough
        $res = $disp->dispatch($this->routeWithOverride(DTResponseController::class, 'act'));
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals('response', $this->responseBody($res));
        $this->assertEquals('text/plain; charset=utf-8', $this->responseHeaders($res)['Content-Type']);

        // string -> text
        $res = $disp->dispatch($this->routeWithOverride(DTStringController::class, 'act'));
        $this->assertInstanceOf(Response::class, $res);
        $this->assertEquals('hello', $this->responseBody($res));
        $this->assertEquals('text/html; charset=utf-8', $this->responseHeaders($res)['Content-Type']);

        // array -> json
        $res = $disp->dispatch($this->routeWithOverride(DTArrayController::class, 'act'));
        $this->assertEquals('application/json', $this->responseHeaders($res)['Content-Type']);
        $this->assertEquals(json_encode(['a' => 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->responseBody($res));

        // int -> status
        $res = $disp->dispatch($this->routeWithOverride(DTIntController::class, 'act'));
        $this->assertEquals(201, $this->responseStatus($res));

        // null -> 204
        $res = $disp->dispatch($this->routeWithOverride(DTNullController::class, 'act'));
        $this->assertEquals(204, $this->responseStatus($res));
    }

    public function testMiddlewarePipelineOrder(): void
    {
        $disp = new Dispatcher();

        $disp->addMiddleware(new GMW());
        $disp->defineMiddlewareGroup('grp', [GRPMW::class]);

        $res = $disp->dispatch($this->routeWithOverride(MWController::class, 'act', [], ['grp']));
        $this->assertEquals('CORE-A-C-GR-G', $this->responseBody($res));
    }

    public function testNamespaceOnlyRouteFallsBackToDefaultControllerAndAction(): void
    {
        $context = new AppContext();
        AppContext::setInstance($context);

        $router = new Router();
        $router->prefix('/phpthunder', function (Router $router): void {
            $router->namespace('PhpThunder');
            $router->add('GET', '/');
        });

        $route = $router->match('/phpthunder');

        $this->assertNotNull($route);
        $this->assertSame(['namespace' => 'PhpThunder'], $route['override']);

        $disp = new Dispatcher();
        $disp->setBaseNamespace('\\Azera\\Tests\\Mvc');

        $res = $disp->dispatch($route);

        $this->assertEquals('php-thunder-home', $this->responseBody($res));
    }

    public function testScopedControllerInsideNamespaceResolvesToRequestedController(): void
    {
        $context = new AppContext();
        AppContext::setInstance($context);

        $router = new Router();
        $router->prefix('/phpthunder', function (Router $router): void {
            $router->namespace('PhpThunder');
            $router->controller('AuthController', function (Router $router): void {
                $router->add(['GET', 'POST'], '/login', '::loginAction');
                $router->add('GET', '/logout', '::logoutAction');
            });
        });

        $route = $router->match('/phpthunder/login', 'POST');

        $this->assertNotNull($route);
        $this->assertSame([
            'namespace'  => 'PhpThunder',
            'controller' => 'AuthController',
            'action'     => 'loginAction',
        ], $route['override']);

        $disp = new Dispatcher();
        $disp->setBaseNamespace('\\Azera\\Tests\\Mvc');

        $res = $disp->dispatch($route);

        $this->assertEquals('auth-login', $this->responseBody($res));
    }

    public function testDynamicVarsAddSuffixesButOverrideDoesNot(): void
    {
        $disp = new Dispatcher();
        $disp->setBaseNamespace('\\Azera\\Tests\\Mvc');

        $res = $disp->dispatch([
            'vars' => [
                'controller' => 'dynamic-target',
                'action'     => 'sample',
                'params'     => [],
            ],
            'override' => [],
            'groups'   => [],
        ]);

        $this->assertEquals('dynamic', $this->responseBody($res));

        $res = $disp->dispatch([
            'vars' => [
                'controller' => 'ignored-dynamic',
                'action'     => 'ignored',
            ],
            'override' => [
                'controller' => 'RoutingStateController',
                'action'     => 'fromOverride',
                'namespace'  => '\\Azera\\Tests\\Mvc',
            ],
            'groups' => [],
        ]);

        $this->assertEquals('application/json', $this->responseHeaders($res)['Content-Type']);
        $payload = json_decode($this->responseBody($res), true);
        $this->assertEquals('\\Azera\\Tests\\Mvc\\RoutingStateController', $payload['controller']);
        $this->assertEquals('fromOverride', $payload['action']);
    }

    public function testDispatcherStoresNormalizedRoutingInContext(): void
    {
        $context = new AppContext();
        AppContext::setInstance($context); // Ensure singleton instance is used
        $disp = new Dispatcher();
        $disp->setBaseNamespace('\\Azera\\Tests\\Mvc');

        $res = $disp->dispatch([
            'vars' => [
                'id'         => 42,
                'controller' => 'routing-state',
                'action'     => 'from-override',
                'args'       => ['x', 'y'],
            ],
            'override' => [
                'controller' => 'RoutingStateController',
                'action'     => 'fromOverride',
                'namespace'  => '\\Azera\\Tests\\Mvc',
            ],
        ]);

        $this->assertEquals('application/json', $this->responseHeaders($res)['Content-Type']);
        $payload = json_decode($this->responseBody($res), true);
        $this->assertEquals([], $payload['groups']);
        $this->assertEquals(42, $payload['vars']['id']);

        $stored = $context->route();
        $this->assertNotNull($stored);
        $this->assertSame([], $stored->groups);
        $this->assertSame('\\Azera\\Tests\\Mvc\\RoutingStateController', $stored->controller);
        $this->assertSame('fromOverride', $stored->action);
        $this->assertSame(['x', 'y'], $stored->vars['args']);

        // The route's identity is what is cached and shared across requests, so it
        // carries no argument list. The arguments are per-request (they hold
        // whatever the container injected) and are rebuilt for each dispatch.
        $this->assertSame([], $stored->params, 'the cached route must not carry the invocation arguments');

        // What matters is what the ACTION received: the route variables, cast, in
        // signature order.
        $this->assertSame([42, ['x', 'y']], $payload['received']);
    }

    public function testOnlyParamsWildcardIsPassedToActionParameters(): void
    {
        $context = new AppContext();
        AppContext::setInstance($context); // Ensure singleton instance is used
        $disp = new Dispatcher();

        $res = $disp->dispatch([
            'vars' => [
                'id'         => 42,
                'controller' => 'routing-state',
                'action'     => 'from-override',
                'params'     => ['x', 'y'],
            ],
            'override' => [
                'controller' => 'RoutingStateController',
                'action'     => 'fromOverride',
                'namespace'  => '\\Azera\\Tests\\Mvc',
            ],
        ]);

        $this->assertEquals('application/json', $this->responseHeaders($res)['Content-Type']);
        $payload = json_decode($this->responseBody($res), true);

        $stored = $context->route();
        $this->assertNotNull($stored);
        $this->assertSame([], $stored->params, 'the cached route must not carry the invocation arguments');
        $this->assertSame(['x', 'y'], $stored->vars['params']);

        // The leading 42 is the `$id` route variable and the null is the unfilled
        // `$args` parameter; what the action actually received is the whole list.
        $this->assertSame([42, null, 'x', 'y'], $payload['received']);
    }

    public function testNullableRegisteredServiceFallsBackToNullInActionInjection(): void
    {
        $context = new AppContext();
        AppContext::setInstance($context);
        $disp = new Dispatcher();

        $res = $disp->dispatch($this->routeWithOverride(NullableSessionActionController::class, 'act'));

        $this->assertEquals('no-session', $this->responseBody($res));
    }
}