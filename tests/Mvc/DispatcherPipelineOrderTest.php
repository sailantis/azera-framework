<?php
namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\AppContext;
use Azera\Core\Controller;
use Azera\Core\Dispatcher;
use Azera\Core\Exceptions\ActionNotFoundException;
use Azera\Core\Exceptions\ControllerNotFoundException;
use Azera\Core\MiddlewareInterface;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;

// --- Fixtures (top-level declarations) ---

/**
 * Rejects every request, exactly like an auth middleware would.
 */
class RejectingMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        return Response::text('rejected');
    }
}

/**
 * Appends a tag to the response on the way out, like DispatcherTest's helpers.
 */
class TaggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $tag)
    {
    }

    public function process(AppContext $context, callable $next): ?Response
    {
        $response = $next();
        if ($response instanceof Response) {
            $response->write($this->tag);
        }
        return $response;
    }
}

/**
 * Counts constructions so a test can prove the controller was never built.
 */
class ConstructionCountingController extends Controller
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    public function actAction()
    {
        return 'body';
    }
}

/**
 * Controller middleware that rejects; used to prove the action is never reflected.
 */
class ControllerRejectingController extends Controller
{
    public static int $constructions = 0;

    protected array $middlewares = [RejectingMiddleware::class];

    public function __construct()
    {
        self::$constructions++;
    }

    public function actAction()
    {
        return 'unreachable';
    }
}

class ParentMiddlewareController extends Controller
{
    protected array $middlewares = [TaggingMiddleware::class];
}

class ChildMiddlewareController extends ParentMiddlewareController
{
    /**
     * Redeclares the list. PHP property resolution replaces the parent's value,
     * so only this entry may run. A reflective hierarchy walk would have
     * accumulated it with the parent's entry instead.
     */
    protected array $middlewares = [[TaggingMiddleware::class, ['-child']]];

    public function actAction()
    {
        return 'core';
    }
}

/**
 * The ordering of the two pipeline stages must remain
 * global -> group -> controller -> action -> action method.
 */
class TwoStageOrderController extends Controller
{
    protected array $middlewares = [[TaggingMiddleware::class, ['-C']]];

    protected array $actionMiddlewares = [
        'actAction' => [[TaggingMiddleware::class, ['-A']]],
    ];

    public function actAction()
    {
        return 'core';
    }
}

class DispatcherPipelineOrderTest extends TestCase
{
    protected function setUp(): void
    {
        // A fresh container per test: AppContext caches resolved services, so
        // construction counts would otherwise leak between tests.
        AppContext::setInstance(new AppContext());

        ConstructionCountingController::$constructions     = 0;
        ControllerRejectingController::$constructions      = 0;
    }

    private function body(Response $response): string
    {
        $property = new \ReflectionProperty($response, 'body');

        return $property->getValue($response);
    }

    private function dispatcher(): Dispatcher
    {
        $dispatcher = new Dispatcher();
        $dispatcher->setBaseNamespace('\\Azera\\Tests\\Mvc');

        return $dispatcher;
    }

    /**
     * A route whose controller class exists but whose action does not.
     *
     * @return array<string, mixed>
     */
    private function route(string $controllerClass, string $action, array $groups = []): array
    {
        // Mirror what Router::parseHandler() produces: the namespace is split
        // off the class name, because the dispatcher prepends the override
        // namespace to the controller instead of treating it as fully qualified.
        $separator = strrpos($controllerClass, '\\');
        $namespace = $separator === false ? '' : substr($controllerClass, 0, $separator);

        return [
            'vars'     => [],
            'override' => [
                'namespace'  => $namespace,
                'controller' => $separator === false ? $controllerClass : substr($controllerClass, $separator + 1),
                'action'     => $action,
            ],
            'groups'   => $groups,
        ];
    }

    public function testGroupMiddlewareRejectsWithoutConstructingTheController(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->defineMiddlewareGroup('auth', [RejectingMiddleware::class]);

        $response = $dispatcher->dispatch(
            $this->route(ConstructionCountingController::class, 'actAction', ['auth'])
        );

        $this->assertSame('rejected', $this->body($response));
        $this->assertSame(
            0,
            ConstructionCountingController::$constructions,
            'group middleware must be able to reject before the controller is built'
        );
    }

    /**
     * The 404 oracle: an unknown action must not be distinguishable from a
     * rejected request when group middleware is what rejects it.
     */
    public function testGroupMiddlewareHidesUnknownActionNames(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->defineMiddlewareGroup('auth', [RejectingMiddleware::class]);

        $response = $dispatcher->dispatch(
            $this->route(ConstructionCountingController::class, 'doesNotExistAction', ['auth'])
        );

        $this->assertSame('rejected', $this->body($response));
        $this->assertSame(0, ConstructionCountingController::$constructions);
    }

    /**
     * Without middleware protecting it, an unknown action still raises the
     * dispatcher's own exception — the laziness must not swallow real errors.
     */
    public function testUnknownActionStillThrowsWithoutMiddleware(): void
    {
        $this->expectException(ActionNotFoundException::class);

        $this->dispatcher()->dispatch(
            $this->route(ConstructionCountingController::class, 'doesNotExistAction')
        );
    }

    /**
     * Controller middleware closes the oracle too: the controller is built (it
     * must be, to read its declaration) but the action is never reflected.
     */
    public function testControllerMiddlewareRejectsBeforeReflectingTheAction(): void
    {
        $dispatcher = $this->dispatcher();

        $response = $dispatcher->dispatch(
            $this->route(ControllerRejectingController::class, 'doesNotExistAction')
        );

        $this->assertSame('rejected', $this->body($response));
        $this->assertSame(
            1,
            ControllerRejectingController::$constructions,
            'controller-level middleware runs after construction, so it must be built exactly once'
        );
    }

    public function testUnknownControllerRaisesControllerNotFound(): void
    {
        $dispatcher = $this->dispatcher();

        $this->expectException(ControllerNotFoundException::class);

        $dispatcher->dispatch($this->route('NoSuchController', 'actAction'));
    }

    /**
     * Order must stay global -> group -> controller -> action -> action method.
     * Tags are appended post-order, so the body reads inner-most first.
     */
    public function testTwoStagePipelinePreservesDocumentedOrder(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->addMiddleware(new TaggingMiddleware('-G'));
        $dispatcher->defineMiddlewareGroup('grp', [[TaggingMiddleware::class, ['-GR']]]);

        $response = $dispatcher->dispatch(
            $this->route(TwoStageOrderController::class, 'actAction', ['grp'])
        );

        $this->assertSame('core-A-C-GR-G', $this->body($response));
    }

    /**
     * Pins override-wins inheritance. A reflective parent-walk would have run
     * the parent's TaggingMiddleware as well (with no tag argument, which would
     * also fatal), so this guards against reintroducing one.
     */
    public function testSubclassMiddlewareReplacesRatherThanExtendsTheParentList(): void
    {
        $response = $this->dispatcher()->dispatch(
            $this->route(ChildMiddlewareController::class, 'actAction')
        );

        $this->assertSame('core-child', $this->body($response));
    }
}
