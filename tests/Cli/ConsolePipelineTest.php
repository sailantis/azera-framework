<?php

namespace Azera\Tests\Cli;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/PipelineFixtureTask.php';
require_once __DIR__ . '/BareFixtureTask.php';

use Azera\AppContext;
use Azera\Aop\InterceptorInterface;
use Azera\Cli\Console;
use Azera\Core\MiddlewareInterface;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;

// --- Helper middleware / interceptors (top-level declarations) ---

/**
 * Records pipeline call order into a shared static trace so ordering can be
 * asserted without an HTTP Response (the CLI ignores the ?Response return).
 */
class TMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        ConsolePipelineTest::$trace[] = 'global';
        $next();
        ConsolePipelineTest::$trace[] = 'global-after';
        return null;
    }
}

class TaskMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        ConsolePipelineTest::$trace[] = 'task';
        $next();
        return null;
    }
}

class ActionMW implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        ConsolePipelineTest::$trace[] = 'action';
        $next();
        return null;
    }
}

class OuterITC implements InterceptorInterface
{
    public function intercept(\Azera\Aop\Invocation $invocation, callable $next): mixed
    {
        ConsolePipelineTest::$trace[] = 'itc-outer';
        $r = $next($invocation);
        ConsolePipelineTest::$trace[] = 'itc-outer-after';
        return $r;
    }
}

class InnerITC implements InterceptorInterface
{
    public function intercept(\Azera\Aop\Invocation $invocation, callable $next): mixed
    {
        ConsolePipelineTest::$trace[] = 'itc-inner';
        return $next($invocation);
    }
}

class ConsolePipelineTest extends TestCase
{
    /** @var string[] Shared trace across fixtures and the Console invocation. */
    public static array $trace = [];

    protected function freshConsole(): Console
    {
        $c = new Console('azera');
        // Fixture task classes are discovered from this directory.
        $c->addTaskPath(__DIR__, false);
        return $c;
    }

    public function testMiddlewarePipelineOrder(): void
    {
        self::$trace = [];
        $console = $this->freshConsole();
        $console->addMiddleware(TMW::class);
        $console->process(['pipeline-fixture:run']);

        // Expected outermost → innermost: global → task → action → itc-outer → itc-inner → core
        $this->assertSame(
            ['global', 'task', 'action', 'itc-outer', 'itc-inner', 'core', 'itc-outer-after', 'global-after'],
            self::$trace
        );
    }

    public function testActionScopedMiddlewareOnlyAppliesToThatAction(): void
    {
        self::$trace = [];
        $console = $this->freshConsole();
        // otherAction has no action-scoped middleware, but task-wide middleware
        // and interceptors still apply: task → itc-outer → itc-inner → other.
        $console->process(['pipeline-fixture:other']);

        $this->assertSame(['task', 'itc-outer', 'itc-inner', 'other', 'itc-outer-after'], self::$trace);
    }

    public function testNoMiddlewareTaskRunsBare(): void
    {
        self::$trace = [];
        $console = $this->freshConsole();
        $console->process(['bare-fixture']);

        $this->assertSame(['bare-core'], self::$trace);
    }

    public function testHooksNoLongerExposedAsActions(): void
    {
        $console = $this->freshConsole();
        // A single-action task stays single-action (proves beforeAction/
        // afterAction are gone and not miscounted by taskActions()).
        $this->assertSame('runAction', $console->singleActionMethod(BareFixtureTask::class));
    }
}