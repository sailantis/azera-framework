<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Azera\Aop\Invocation;
use Azera\Aop\Pipeline;
use Azera\Aop\RetryInterceptor;
use Azera\Aop\CacheInterceptor;
use Azera\Aop\LogInterceptor;
use Azera\Cache\ArrayCache;
use Psr\Log\LoggerInterface;
use Azera\AppContext;

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

    public function testPipelineWithInterceptors(): void
    {
        $logger   = new PipelineTestLogger();
        $pipeline = new Pipeline([new LogInterceptor($logger)]);

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
     * An explicit call has no declaring class to reflect on — the spy sees the
     * identity derived from the closure (`file:line`).
     */
    public function testInterceptorsReceiveTheClosureIdentity(): void
    {
        $spy = new class implements \Azera\Aop\InterceptorInterface
        {
            public ?string $identity = null;
            public mixed $method = 'unset';

            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $this->identity = $invocation->identity;
                $this->method = $invocation->method;

                return $next($invocation);
            }
        };

        $fn = fn() => 'ok';
        (new Pipeline([$spy]))->call($fn);

        $expected = (new \ReflectionFunction($fn))->getFileName()
            . ':' . (new \ReflectionFunction($fn))->getStartLine();

        $this->assertSame($expected, $spy->identity);
        $this->assertNull($spy->method, 'explicit calls carry no method name');
    }

    /**
     * A path-like identity must still yield a cache key the cache accepts, and
     * it must not collide across arguments.
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
    }

    /**
     * The derived key is one fixed-size digest, not a readable prefix plus a digest.
     *
     * A readable prefix had to be sanitized, memoized, length-capped and folded into a
     * fallback digest to stay inside the backends' 64-character limit — and sanitizing
     * collapsed distinct identities onto one prefix. A single digest over the raw triple
     * removes the whole apparatus, so the key shape is constant and identity-independent.
     */
    public function testDerivedKeyIsAFixedSizeDigest(): void
    {
        $short = CacheInterceptor::keyFor('CacheService', 'expensiveOperation', [5]);

        $this->assertSame(32, strlen($short), 'an xxh128 digest is 32 hex characters');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $short);
        $this->assertStringNotContainsString(
            'CacheService',
            $short,
            'the readable prefix was deliberately dropped: it cost a memo lookup and collapsed distinct identities',
        );
    }

    /**
     * Identities that sanitized to the *same* readable prefix must no longer alias.
     *
     * The old prefix sanitizer mapped every non-alphanumeric character to `_`, so
     * `A\B` and `A/B` (or `a-b` and `a_b`) produced identical keys for equal arguments.
     * Hashing the raw identity removes that class of collision.
     */
    public function testIdentitiesThatSanitizeAlikeDoNotCollide(): void
    {
        $pairs = [
            ['A\\B', 'A/B'],
            ['A:B', 'A.B'],
            ['a-b', 'a_b'],
            ['Foo::bar', 'Foo..bar'],
        ];

        foreach ($pairs as [$left, $right]) {
            $this->assertNotSame(
                CacheInterceptor::keyFor($left, null, [5]),
                CacheInterceptor::keyFor($right, null, [5]),
                "distinct identities '{$left}' and '{$right}' must not share a cache entry",
            );
        }
    }

    /**
     * The digest covers the triple, so its parts cannot run into each other: without
     * serialization the pairs `('ab', 'c')` / `('a', 'bc')`, and `null` / `''` method
     * names, would concatenate to the same bytes.
     */
    public function testIdentityMethodAndArgsCannotRunTogether(): void
    {
        $this->assertNotSame(
            CacheInterceptor::keyFor('ab', 'c', [1]),
            CacheInterceptor::keyFor('a', 'bc', [1]),
        );

        $this->assertNotSame(
            CacheInterceptor::keyFor('Svc', null, [1]),
            CacheInterceptor::keyFor('Svc', '', [1]),
        );

        $this->assertNotSame(
            CacheInterceptor::keyFor('Svc', 'm', ['id' => 7]),
            CacheInterceptor::keyFor('Svc', 'm', [0 => 7]),
        );

        // Types stay distinct: 1, '1' and 1.0 are three different argument lists.
        $this->assertCount(3, array_unique([
            CacheInterceptor::keyFor('Svc', 'm', [1]),
            CacheInterceptor::keyFor('Svc', 'm', ['1']),
            CacheInterceptor::keyFor('Svc', 'm', [1.0]),
        ]));
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

        $this->assertTrue($cache->set($key, 'cached'));
        $this->assertSame('cached', $cache->get($key));
    }

    /**
     * Identities that differ only in their tail must not alias.
     *
     * Two closures in one file are identified by `file:line`, so they share every
     * character up to the line number. The old implementation truncated the readable
     * prefix to 31 characters and had to append a digest of the raw identity to keep
     * these apart; the flat digest distinguishes them without truncation at all.
     */
    public function testLongIdentitiesDifferingOnlyInTheTailDoNotCollide(): void
    {
        $dir = '/home/runner/work/azera-framework/azera-framework/src/Aop/Pipeline.php';

        $key238 = CacheInterceptor::keyFor($dir . ':238', null, [5]);
        $key239 = CacheInterceptor::keyFor($dir . ':239', null, [5]);

        $this->assertLessThanOrEqual(64, strlen($key238));
        $this->assertNotSame($key238, $key239, 'identities differing only in the line number must not share a key');
    }

    /**
     * The same identity must still produce the same key across calls — the derivation
     * must be stable, not merely unique.
     */
    public function testKeyForIsStableAcrossCalls(): void
    {
        $long = '/home/runner/work/azera-framework/azera-framework/src/Aop/Pipeline.php:238';

        $this->assertSame(
            CacheInterceptor::keyFor($long, '__invoke', [5]),
            CacheInterceptor::keyFor($long, '__invoke', [5]),
        );
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

    /**
     * Two different callables wrapping the same arguments must not share a
     * cache entry.
     */
    public function testCacheKeysAreDistinctPerCallable(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);

        $first  = $pipeline->call(fn(string $x) => "A:{$x}", ['k']);
        $second = $pipeline->call(fn(string $x) => "B:{$x}", ['k']);

        $this->assertSame('A:k', $first);
        $this->assertSame('B:k', $second, 'the second callable must not read the first one\'s cached value');
    }

    /**
     * The same callable called twice must still hit its own entry — the
     * identity has to be stable, not merely unique.
     */
    public function testCacheKeysAreStableForTheSameCallable(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);
        $calls    = 0;

        $callable = function (int $n) use (&$calls) {
            $calls++;
            return $n * 2;
        };

        $this->assertSame(10, $pipeline->call($callable, [5]));
        $this->assertSame(10, $pipeline->call($callable, [5]));
        $this->assertSame(1, $calls);
    }

    /**
     * A `{placeholder}` template on the explicit path must interpolate from named
     * args. Regression: the old code took `array_keys($args)` and then indexed the
     * args array *positionally*, so the lookup always missed, the placeholder became
     * empty, and every argument shared one cache entry — different ids returned the
     * first call's cached value, silently.
     */
    public function testExplicitCacheKeyTemplateInterpolatesFromNamedArgs(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);
        $advice   = [\Azera\Aop\Cache::class => new \Azera\Aop\Cache(ttl: 60, key: 'order_{id}')];

        $this->assertSame(
            'A7',
            $pipeline->call(fn(int $id) => "A{$id}", ['id' => 7], 'svc.get', $advice),
        );
        $this->assertSame(
            'B9',
            $pipeline->call(fn(int $id) => "B{$id}", ['id' => 9], 'svc.get', $advice),
            'a different id must not read another id\'s cached value',
        );
        $this->assertSame(
            'A7',
            $pipeline->call(fn(int $id) => "C{$id}", ['id' => 7], 'svc.get', $advice),
            'the same id must hit its own entry (returning the first computed value)',
        );

        $this->assertTrue($cache->has('order_7'));
        $this->assertTrue($cache->has('order_9'));
    }

    /**
     * And when the placeholder genuinely cannot be resolved (positional args), it
     * must fail rather than silently cache everything under one literal key.
     */
    public function testUnresolvableCacheKeyTemplateThrows(): void
    {
        $pipeline = new Pipeline([new CacheInterceptor(new ArrayCache())]);
        $advice   = [\Azera\Aop\Cache::class => new \Azera\Aop\Cache(ttl: 60, key: 'order_{id}')];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\{id\}/');

        $pipeline->call(fn(int $id) => "o{$id}", [7], 'svc.get', $advice);
    }

    /**
     * Log lines must name the wrapped callable rather than a shared handler
     * class, otherwise every pipeline entry in a log is indistinguishable.
     */
    public function testLogNamesTheWrappedCallable(): void
    {
        $logger = new PipelineTestLogger();

        (new Pipeline([new LogInterceptor($logger)]))->call(fn() => 'ok');

        $messages = implode(' | ', array_column($logger->entries, 'message'));

        $this->assertNotSame('', $messages);
        $this->assertStringContainsString(basename(__FILE__), $messages);
    }

    public function testInvalidInterceptorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pipeline(['not-an-interceptor']);
    }

    /**
     * A zero-interceptor pipeline derives no identity at all — nothing is there
     * to observe it.
     */
    public function testEmptyPipelineSkipsIdentityResolution(): void
    {
        $this->assertSame('ok', (new Pipeline())->call(fn() => 'ok'));
    }

    /**
     * A supplied identity is used verbatim, bypassing closure reflection.
     */
    public function testExplicitIdentityArgumentIsUsed(): void
    {
        $spy = new class implements \Azera\Aop\InterceptorInterface
        {
            public ?string $identity = null;

            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $this->identity = $invocation->identity;

                return $next($invocation);
            }
        };

        (new Pipeline([$spy]))->call(fn() => 'ok', [], 'billing.charge');

        $this->assertSame('billing.charge', $spy->identity);
    }

    /**
     * Interceptors receive one Invocation carrying the wrapped callable under
     * the documented attribute key, and the return value passes through.
     */
    public function testInterceptorSeesInvocation(): void
    {
        $spy = new class implements \Azera\Aop\InterceptorInterface
        {
            public int $seen = 0;

            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $this->seen++;
                if (!array_key_exists(Pipeline::ATTR_CALLABLE, $invocation->attributes)) {
                    throw new \RuntimeException('missing ATTR_CALLABLE attribute');
                }

                return 'wrapped';
            }
        };

        $this->assertSame('wrapped', (new Pipeline([$spy]))->call(fn() => 'inner'));
        $this->assertSame(1, $spy->seen);
    }

    /**
     * An interceptor that rewrites the argument list in place is observed by the
     * wrapped callable — the by-reference contract end to end.
     */
    public function testInterceptorCanRewriteArgumentsInPlace(): void
    {
        $rewriter = new class implements \Azera\Aop\InterceptorInterface
        {
            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $invocation->args[0] = 'rewritten';

                return $next($invocation);
            }
        };

        $result = (new Pipeline([$rewriter]))->call(fn(string $s) => $s, ['original']);

        $this->assertSame('rewritten', $result);
    }

    /**
     * The zero-interceptor fast path is a plain variadic call, so by-reference
     * parameters must still reach the callee as references.
     */
    public function testEmptyPipelinePreservesByReferenceArguments(): void
    {
        $bump = static function (int &$n): void {
            $n++;
        };

        $value = 1;
        (new Pipeline())->call($bump, [&$value]);

        $this->assertSame(2, $value);
    }

    /**
     * The fast path must not be reachable once an interceptor is present, even if
     * that interceptor does nothing but delegate.
     */
    public function testNonEmptyPipelineStillBuildsInvocation(): void
    {
        $spy = new class implements \Azera\Aop\InterceptorInterface
        {
            public bool $called = false;

            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $this->called = true;

                return $next($invocation);
            }
        };

        $this->assertSame('ok', (new Pipeline([$spy]))->call(fn() => 'ok'));
        $this->assertTrue($spy->called);
    }
}
