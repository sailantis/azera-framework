<?php

namespace Azera\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use Azera\AppContext;
use Azera\Core\Engines\ClarityEngine;
use Azera\Http\Session;
use Azera\Core\ViewEngine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AppContextTestService
{
}

class AppContextCallableFactory
{
    public int $calls = 0;

    public function create(): AppContextTestService
    {
        $this->calls++;
        return new AppContextTestService();
    }
}

class AppContextInvokableFactory
{
    public int $calls = 0;

    public function __invoke(): AppContextTestService
    {
        $this->calls++;
        return new AppContextTestService();
    }
}

class AppContextNullableSessionConsumer
{
    public function __construct(public ?Session $session) {}
}

class AppContextTestViewEngine extends ViewEngine
{
    public function __construct()
    {
        parent::__construct();
        $this->setExtension('php');
    }

    public function render(string $view, array $vars = []): string
    {
        return '';
    }

    public function renderPartial(string $view, array $vars = []): string
    {
        return '';
    }

    public function renderLayout(string $layout, string $content, array $vars = []): string
    {
        return $content;
    }
}

/** Test double for view factories; records how many times the factory ran. */
class AppContextCountingViewEngine extends AppContextTestViewEngine
{
    public function __construct(public int &$counter)
    {
        parent::__construct();
    }
}

class AppContextTest extends TestCase
{
    public function testRegisteredObjectInstanceIsReturnedAsIs(): void
    {
        $ctx     = new AppContext();
        $service = new AppContextTestService();

        $ctx->set(AppContextTestService::class, $service);

        $this->assertSame($service, $ctx->get(AppContextTestService::class));
        $this->assertSame($service, $ctx->getOrNull(AppContextTestService::class));
    }

    public function testClosureFactoryIsResolvedOnceAndCached(): void
    {
        $ctx   = new AppContext();
        $calls = 0;

        $ctx->set(AppContextTestService::class, function () use (&$calls): AppContextTestService {
            $calls++;
            return new AppContextTestService();
        });

        $first  = $ctx->get(AppContextTestService::class);
        $second = $ctx->get(AppContextTestService::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function testCallableArrayFactoryIsResolvedOnceAndCached(): void
    {
        $ctx     = new AppContext();
        $factory = new AppContextCallableFactory();

        $ctx->set(AppContextTestService::class, [$factory, 'create']);

        $first  = $ctx->get(AppContextTestService::class);
        $second = $ctx->get(AppContextTestService::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $factory->calls);
    }

    public function testInvokableObjectRegistrationIsTreatedAsFactory(): void
    {
        $ctx     = new AppContext();
        $factory = new AppContextInvokableFactory();

        $ctx->set(AppContextTestService::class, $factory);

        $first  = $ctx->get(AppContextTestService::class);
        $second = $ctx->get(AppContextTestService::class);

        $this->assertInstanceOf(AppContextTestService::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $factory->calls);
    }

    public function testFactoryReturningNonObjectThrows(): void
    {
        $ctx = new AppContext();
        $ctx->set('bad-factory', fn() => 'bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service factory for bad-factory did not return an object');

        $ctx->get('bad-factory');
    }

    public function testGetOrNullDoesNotAutowireExistingClass(): void
    {
        $ctx = new AppContext();

        $this->assertNull($ctx->getOrNull(AppContextTestService::class));
    }

    public function testTryGetAutowiresExistingClass(): void
    {
        $ctx = new AppContext();

        $service = $ctx->tryGet(AppContextTestService::class);

        $this->assertInstanceOf(AppContextTestService::class, $service);
        $this->assertSame($service, $ctx->get(AppContextTestService::class));
    }

    public function testRegisteredNullableSessionResolvesToNullUntilSet(): void
    {
        $ctx = new AppContext();

        $this->assertTrue($ctx->has(Session::class));
        $this->assertNull($ctx->getOrNull(Session::class));
        $this->assertNull($ctx->tryGet(Session::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service factory for ' . Session::class . ' did not return an object');

        $ctx->get(Session::class);
    }

    public function testBuildFallsBackToNullForRegisteredNullableService(): void
    {
        $ctx = new AppContext();

        $consumer = $ctx->get(AppContextNullableSessionConsumer::class);

        $this->assertNull($consumer->session);
    }

    public function testSetViewSynchronizesContainer(): void
    {
        $ctx  = new AppContext();
        $view = new AppContextTestViewEngine();

        $ctx->setView($view);

        $this->assertSame($view, $ctx->view());
        $this->assertSame($view, $ctx->get(ViewEngine::class));
    }

    /**
     * Boot-cost guard: the default view factory must NOT run until view() is
     * first called. Boot only registers factories/config; it never builds.
     */
    public function testViewFactoryNotInvokedUntilViewRequested(): void
    {
        $ctx = new AppContext();

        $counter = 0;
        $ctx->set(ViewEngine::class, function () use (&$counter) {
            $counter++;
            return new AppContextTestViewEngine();
        });

        // Boot-style registration must not trigger construction.
        $this->assertSame(0, $counter);

        $view = $ctx->view();

        // Built exactly once, then memoized.
        $this->assertSame(1, $counter);
        $this->assertSame($view, $ctx->view());
        $this->assertSame(1, $counter, 'memoized: factory must not re-run');
        $this->assertSame($view, $ctx->get(ViewEngine::class));
    }

    public function testViewFactoryReturningNonEngineFailsWithTypeError(): void
    {
        $ctx = new AppContext();

        $ctx->set(ViewEngine::class, fn() => new \stdClass());

        $this->expectException(\TypeError::class);

        $ctx->view();
    }

    public function testSetViewOverridesEarlierFactory(): void
    {
        $ctx   = new AppContext();
        $early = new AppContextTestViewEngine();

        $ctx->set(ViewEngine::class, fn() => new AppContextTestViewEngine());
        $ctx->setView($early);

        $this->assertSame($early, $ctx->view());
        $this->assertSame($early, $ctx->get(ViewEngine::class));
    }

    public function testDefaultViewFactoryBuildsClarityEngineLazily(): void
    {
        $ctx = new AppContext();

        $view = $ctx->view();

        $this->assertInstanceOf(ClarityEngine::class, $view);
        $this->assertSame($view, $ctx->get(ViewEngine::class));
    }

    public function testSetSessionSynchronizesContainer(): void
    {
        $ctx     = new AppContext();
        $store   = [];
        $session = new Session($store);

        $ctx->setSession($session);

        $this->assertSame($session, $ctx->session());
        $this->assertSame($session, $ctx->get(Session::class));
    }
}