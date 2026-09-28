<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Azera\Aop\Pipeline;
use Azera\Aop\PipelineHandler;
use Azera\Aop\RetryInterceptor;
use Azera\Aop\CacheInterceptor;
use Azera\Aop\LogInterceptor;
use Azera\Aop\Retry;
use Azera\Cache\ArrayCache;
use Psr\Log\LoggerInterface;
use Azera\AppContext;
use Throwable;

class PipelineTestLogger implements LoggerInterface
{
    public array $entries = [];

    public function emergency(string|\Stringable $m, array $c = []): void
    {
        $this->log('emergency', $m, $c);
    }
    public function alert(string|\Stringable $m, array $c = []): void
    {
        $this->log('alert', $m, $c);
    }
    public function critical(string|\Stringable $m, array $c = []): void
    {
        $this->log('critical', $m, $c);
    }
    public function error(string|\Stringable $m, array $c = []): void
    {
        $this->log('error', $m, $c);
    }
    public function warning(string|\Stringable $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }
    public function notice(string|\Stringable $m, array $c = []): void
    {
        $this->log('notice', $m, $c);
    }
    public function info(string|\Stringable $m, array $c = []): void
    {
        $this->log('info', $m, $c);
    }
    public function debug(string|\Stringable $m, array $c = []): void
    {
        $this->log('debug', $m, $c);
    }
    public function log($level, string|\Stringable $m, array $c = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => (string) $m, 'context' => $c];
    }
}

class PipelineTest extends TestCase
{
    public function testEmptyPipelineCallsCallableDirectly(): void
    {
        $result = (new Pipeline())->call(fn() => 42);
        $this->assertSame(42, $result);
    }

    public function testPipelineWithArgs(): void
    {
        $result = (new Pipeline())->call(fn(int $a, int $b) => $a + $b, [3, 7]);
        $this->assertSame(10, $result);
    }

    public function testThroughAddsInterceptors(): void
    {
        $logger   = new PipelineTestLogger();
        $pipeline = new Pipeline();
        $pipeline->through([new LogInterceptor($logger)]);

        $result = $pipeline->call(fn() => 'ok');

        $this->assertSame('ok', $result);
        $this->assertGreaterThanOrEqual(1, count($logger->entries));
    }

    public function testRetryInterceptorRetries(): void
    {
        $attempts = 0;
        $pipeline = new Pipeline([new RetryInterceptor(defaultTimes: 3, defaultBackoff: 0)]);

        $result = $pipeline->call(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('fail');
            }
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(3, $attempts);
    }

    public function testCacheInterceptorCaches(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);
        $calls    = 0;

        $callable = function (int $n) use (&$calls) {
            $calls++;
            return $n * 2;
        };

        $result1 = $pipeline->call($callable, [5]);
        $this->assertSame(10, $result1);
        $this->assertSame(1, $calls);

        // Second call should hit the cache
        $result2 = $pipeline->call($callable, [5]);
        $this->assertSame(10, $result2);
        $this->assertSame(1, $calls); // not incremented
    }

    public function testStackedInterceptors(): void
    {
        $logger = new PipelineTestLogger();
        $cache  = new ArrayCache();

        $pipeline = new Pipeline([
            new RetryInterceptor(defaultTimes: 3, defaultBackoff: 0),
            new CacheInterceptor($cache),
            new LogInterceptor($logger),
        ]);

        $attempts = 0;
        $result   = $pipeline->call(function () use (&$attempts) {
            $attempts++;
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $attempts);
        $this->assertNotEmpty($logger->entries);
    }

    public function testStaticWrap(): void
    {
        $result = Pipeline::wrap([], fn() => 'hello');
        $this->assertSame('hello', $result);
    }

    /**
     * Regression: the pipeline reflects a NAMED class, not an anonymous one.
     *
     * An anonymous class reports a name that embeds its declaring file path —
     * short on Windows ("Pipeline.php:150$5"), but the full absolute path on
     * Linux, where getShortName() has no backslash to split on. Because
     * CacheInterceptor derives its cache key from that name, an anonymous
     * class produced a key longer than the cache's 64-character limit on Linux
     * and every cached pipeline call threw InvalidArgumentException. CI ran on
     * Linux while local runs on Windows passed, which is what hid it.
     */
    public function testInterceptorsReceiveANamedDeclaringClass(): void
    {
        $spy = new class implements \Azera\Aop\InterceptorInterface {
            public ?string $className = null;

            public function intercept(
                object $target,
                \ReflectionMethod $method,
                array $args,
                callable $next
            ): mixed {
                $this->className = $method->getDeclaringClass()->getShortName();

                return $next($args);
            }
        };

        (new Pipeline([$spy]))->call(fn() => 'ok');

        $this->assertSame('PipelineHandler', $spy->className);
        $this->assertStringNotContainsString('.php', (string) $spy->className);
    }

    /**
     * A path-like declaring name (Linux anonymous class) must still yield a
     * cache key the cache accepts, and it must not collide across arguments.
     */
    public function testKeyForFoldsLongPathLikeClassNames(): void
    {
        $pathLike = '/home/runner/work/azera-framework/azera-framework/src/Aop/Pipeline.php:150$5';

        $keyA = CacheInterceptor::keyFor($pathLike, '__invoke', [5]);
        $keyB = CacheInterceptor::keyFor($pathLike, '__invoke', [6]);

        $this->assertLessThanOrEqual(64, strlen($keyA));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $keyA);

        // Different arguments must not collide.
        $this->assertNotSame($keyA, $keyB);

        // Short names keep the readable shape.
        $this->assertStringStartsWith(
            'CacheService.expensiveOperation.',
            CacheInterceptor::keyFor('CacheService', 'expensiveOperation', [5]),
        );
    }

    /**
     * And the key really round-trips through the cache the interceptor uses.
     */
    public function testPathLikeClassNameDoesNotBreakTheCache(): void
    {
        $cache = new ArrayCache();
        $key   = CacheInterceptor::keyFor(
            '/home/runner/work/azera-framework/azera-framework/src/Aop/Pipeline.php:150$5',
            '__invoke',
            [5],
        );

        // Would throw InvalidArgumentException before the fix.
        $this->assertTrue($cache->set($key, 'cached'));
        $this->assertSame('cached', $cache->get($key));
    }

    public function testAppContextPipeline(): void
    {
        AppContext::setInstance(new AppContext());
        $ctx = AppContext::instance();

        $logger = new PipelineTestLogger();
        $result = $ctx->pipeline([new LogInterceptor($logger)])->call(fn() => 42);

        $this->assertSame(42, $result);
        $this->assertNotEmpty($logger->entries);
    }

    public function testInvalidInterceptorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pipeline(['not-an-interceptor']);
    }
}