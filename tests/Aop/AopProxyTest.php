<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Azera\AppContext;
use Azera\Aop\Advised;
use Azera\Aop\Advice;
use Azera\Aop\Retry;
use Azera\Aop\RetryInterceptor;
use Azera\Aop\Cache;
use Azera\Aop\CacheInterceptor;
use Azera\Aop\Log;
use Azera\Aop\LogInterceptor;
use Azera\Aop\InterceptorInterface;
use Azera\Cache\ArrayCache;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

// --- Test fixtures ---

#[Advised]
class RetryService
{
    public int $attempts = 0;

    #[Retry(times: 3, backoff: 0)]
    public function flakyOperation(): string
    {
        $this->attempts++;
        if ($this->attempts < 3) {
            throw new \RuntimeException("Attempt {$this->attempts} failed");
        }
        return 'success';
    }

    #[Retry(times: 2, backoff: 0)]
    public function alwaysFails(): void
    {
        $this->attempts++;
        throw new \RuntimeException("Always fails");
    }

    public function nonAdvisedMethod(): string
    {
        return 'raw';
    }
}

#[Advised]
class CacheService
{
    public int $calls = 0;

    #[Cache(ttl: 300)]
    public function expensiveOperation(int $n): int
    {
        $this->calls++;
        return $n * 2;
    }

    #[Cache(ttl: 300, key: 'user_{userId}_profile')]
    public function getProfile(int $userId): array
    {
        $this->calls++;
        return ['id' => $userId, 'name' => 'User ' . $userId];
    }
}

#[Advised]
class LogService
{
    #[Log(level: 'info', logArgs: true)]
    public function doWork(string $task): string
    {
        return "done: {$task}";
    }

    #[Log(level: 'info')]
    public function failingWork(): void
    {
        throw new \RuntimeException('work failed');
    }
}

class NonAdvisedService
{
    public function doSomething(): string
    {
        return 'result';
    }
}

#[Advised]
class FinalMethodService
{
    #[Retry(times: 2, backoff: 0)]
    final public function finalAdvised(): string
    {
        return 'ok';
    }
}

#[Advised]
final class FinalClassService
{
    #[Retry(times: 2, backoff: 0)]
    public function advised(): string
    {
        return 'ok';
    }
}

class TestLogger implements LoggerInterface
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

#[Advised]
class AdviceVariants
{
    public int $flaky = 0;

    #[Retry(times: 2, backoff: 0)]
    public function flakyOperation(): string
    {
        $this->flaky++;
        if ($this->flaky < 2) {
            throw new \RuntimeException('first attempt fails');
        }
        return 'ok';
    }

    #[Log]
    public function logOperation(): string
    {
        return 'logged';
    }
}
class AopProxyTest extends TestCase
{
    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
    }

    public function testNonAdvisedClassReturnsRawObject(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $service = $ctx->get(NonAdvisedService::class);

        // Should be the raw class, not a proxy
        $this->assertInstanceOf(NonAdvisedService::class, $service);
        $this->assertSame('result', $service->doSomething());
    }

    public function testNoInterceptorsReturnsRawObject(): void
    {
        $ctx = AppContext::instance();
        // No interceptors registered

        $service = $ctx->get(RetryService::class);

        $this->assertInstanceOf(RetryService::class, $service);
        $this->assertSame('raw', $service->nonAdvisedMethod());
    }

    public function testAdvisedClassIsProxied(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $service = $ctx->get(RetryService::class);

        // The proxy extends RetryService, so it IS an instance
        $this->assertInstanceOf(RetryService::class, $service);
    }

    /**
     * A late registration must reach a service that was resolved BEFORE it.
     *
     * This is the guarantee docs/16-AOP.md states ("Late registration is
     * tolerated — resolved #[Advised] definitions are evicted and rebuilt on the
     * next resolve"). It broke once when the eviction was removed as "unused";
     * the service kept running its raw body forever.
     */
    public function testLateRegistrationEvictsAnAlreadyResolvedAdvisedService(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);

        // Resolve with NO matching interceptor: stored raw, and the #[Advised]
        // verdict is memoised as "not advised".
        $raw = $ctx->get(RetryService::class);
        $this->assertSame(RetryService::class, $raw::class);

        // Now register — after resolution.
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $proxied = $ctx->get(RetryService::class);

        $this->assertNotSame($raw, $proxied, 'the raw instance must be forgotten');
        $this->assertNotSame(RetryService::class, $proxied::class, 'the new instance must be proxied');

        $proxied->attempts = 0;
        $this->assertSame('success', $proxied->flakyOperation(), 'the advice must now run');
    }

    /**
/**
     * Eviction drops the INSTANCE, never the recipe.
     *
     * The container keeps `$serviceDefinitions[$id]` (how to build) apart from
     * `$serviceInstances[$id]` (what was built). Eviction used to unset both, so a
     * factory-registered service lost its factory and the next get() fell through
     * to autowiring — a different object than the application had registered.
     *
     * A factory's return value is used as-is (it is not proxied), so the only
     * observable guarantee here is WHICH mechanism rebuilt the instance. The
     * counter is the discriminator: if the recipe survived, the factory runs
     * again; if it was destroyed, reflection does the rebuild and the counter
     * stays at 1.
     */
    public function testEvictionKeepsAFactoryRegistrationIntact(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);

        $calls = 0;
        $ctx->set(RetryService::class, function () use (&$calls) {
            $calls++;
            return new RetryService();
        });

        $first = $ctx->get(RetryService::class);
        $this->assertSame(1, $calls, 'the factory builds the first instance');

        // RetryService is #[Advised], so this evicts the instance above.
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $second = $ctx->get(RetryService::class);

        $this->assertInstanceOf(RetryService::class, $second);
        $this->assertNotSame($first, $second, 'the resolved instance must have been evicted');
        $this->assertSame(2, $calls, 'the FACTORY must be the recipe that rebuilt it, not autowiring');
    }

    /**
     * A service the container did not build must not be evicted by registration.
     *
     * Only `#[Advised]` instances depend on the interceptor map, so everything
     * else — loggers, caches, connections — has to survive. A walk that evicted
     * indiscriminately would quietly rebuild stateful services (a logger that had
     * buffered entries, a connection pool) on every registration.
     */
    public function testRegistrationLeavesNonAdvisedServicesAlone(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);

        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $plain = $ctx->get(NonAdvisedService::class);
        $this->assertSame(NonAdvisedService::class, $plain::class);

        $ctx->registerInterceptor(Azera\Aop\Log::class, new LogInterceptor(new TestLogger()));

        $this->assertSame($plain, $ctx->get(NonAdvisedService::class), 'a non-advised service must not be evicted');
    }

    
    /**
     * Registering a second interceptor must reach a class the first one already
     * covered partly.
     *
     * This is the memo-staleness case, and it is subtle enough to have been the
     * original reason hasAdvisedMethods() was un-memoised. The verdict is per
     * CLASS, not per method: once ANY method has a matching advice, the class is
     * advisory and the proxy is built. Registering a second interceptor therefore
     * has to change which METHODS the proxy overrides — which means the verdict
     * and the proxy both have to be recomputed, not reused.
     */
    public function testASecondRegistrationRebuildsAPartlyCoveredProxy(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);

        // Only Retry: the proxy overrides flakyOperation, not logOperation.
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());
        $first = $ctx->get(AdviceVariants::class);

        $firstOverride = (new ReflectionMethod($first, 'flakyOperation'))->getDeclaringClass()->getName();
        $this->assertNotSame(AdviceVariants::class, $firstOverride, 'Retry must be intercepted');

        // Adding Log covers the second method: the proxy must be rebuilt.
        $ctx->registerInterceptor(Log::class, new LogInterceptor(new TestLogger()));
        $second = $ctx->get(AdviceVariants::class);

        $this->assertNotSame($first, $second, 'a second registration must evict, even when the class was already advisory');

        $secondOverride = (new ReflectionMethod($second, 'logOperation'))->getDeclaringClass()->getName();
        $this->assertNotSame(
            AdviceVariants::class,
            $secondOverride,
            'the rebuilt proxy must now override the #[Log] method as well — a memoised verdict would have skipped it'
        );
    }
    /**
     * Registering before resolving must produce the same result as registering after.
     *
     * The eviction is gated on something having been resolved, so a boot-time
     * registration skips the walk entirely. That gate is only sound if it changes
     * nothing observable — and it does not: the verdict was never memoised for this
     * class, nothing had been constructed, so there was nothing to invalidate.
     */
    public function testBootTimeRegistrationMatchesLateRegistration(): void
    {
        // Sequence A: register, then resolve.
        $ctxA = new AppContext();
        AppContext::setInstance($ctxA);
        $ctxA->setAopCacheDir(null);
        $ctxA->registerInterceptor(Retry::class, new RetryInterceptor());
        $early = $ctxA->get(RetryService::class);
        $earlyClass = $early::class;

        // Sequence B: resolve, then register.
        $ctxB = new AppContext();
        AppContext::setInstance($ctxB);
        $ctxB->setAopCacheDir(null);
        $ctxB->get(RetryService::class);
        $ctxB->registerInterceptor(Retry::class, new RetryInterceptor());
        $late = $ctxB->get(RetryService::class);
        $lateClass = $late::class;

        $this->assertSame($earlyClass, $lateClass, 'the two orderings must yield the same proxy class');
        $this->assertNotSame(RetryService::class, $earlyClass, 'and it must be a proxy');

        foreach ([$early, $late] as $svc) {
            $svc->attempts = 0;
            $this->assertSame('success', $svc->flakyOperation(), 'advice must run in both orderings');
        }
    }
    /** The explicit Pipeline never generated a proxy, so it is unaffected. */
    public function testTheExplicitPipelineIsIndependentOfRegistration(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);

        $calls = 0;
        $result = $ctx->pipeline([new RetryInterceptor()])->call(function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new \RuntimeException('transient');
            }
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $calls);
    }
    public function testRetrySucceedsAfterFailures(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $service = $ctx->get(RetryService::class);
        $service->attempts = 0;

        $result = $service->flakyOperation();

        $this->assertSame('success', $result);
        $this->assertSame(3, $service->attempts);
    }

    public function testRetryExhaustsAndRethrows(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $service = $ctx->get(RetryService::class);
        $service->attempts = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Always fails');

        $service->alwaysFails();
    }

    public function testRetryCountsAttempts(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $service = $ctx->get(RetryService::class);
        $service->attempts = 0;

        try {
            $service->alwaysFails();
        } catch (\RuntimeException $e) {}

        $this->assertSame(2, $service->attempts);
    }

    public function testNonAdvisedMethodOnAdvisedClass(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $service = $ctx->get(RetryService::class);

        // Methods without advice attributes should work normally
        $this->assertSame('raw', $service->nonAdvisedMethod());
    }

    public function testCacheInterceptorCachesResult(): void
    {
        $ctx   = AppContext::instance();
        $cache = new ArrayCache();
        $ctx->registerInterceptor(Cache::class, new CacheInterceptor($cache));

        $service = $ctx->get(CacheService::class);

        // First call: executes method
        $result1 = $service->expensiveOperation(5);
        $this->assertSame(10, $result1);
        $this->assertSame(1, $service->calls);

        // Second call: returns cached value, method NOT executed
        $result2 = $service->expensiveOperation(5);
        $this->assertSame(10, $result2);
        $this->assertSame(1, $service->calls); // still 1
    }

    public function testCacheInterceptorDifferentArgsDifferentKeys(): void
    {
        $ctx   = AppContext::instance();
        $cache = new ArrayCache();
        $ctx->registerInterceptor(Cache::class, new CacheInterceptor($cache));

        $service = $ctx->get(CacheService::class);

        $service->expensiveOperation(5);
        $service->expensiveOperation(10);

        $this->assertSame(2, $service->calls);
    }

    public function testCacheInterceptorCustomKey(): void
    {
        $ctx   = AppContext::instance();
        $cache = new ArrayCache();
        $ctx->registerInterceptor(Cache::class, new CacheInterceptor($cache));

        $service = $ctx->get(CacheService::class);

        $service->getProfile(42);
        $service->getProfile(42);

        $this->assertSame(1, $service->calls);
    }

    public function testLogInterceptorLogsEntryAndExit(): void
    {
        $logger = new TestLogger();
        $ctx    = AppContext::instance();
        $ctx->registerInterceptor(Log::class, new LogInterceptor($logger));

        $service = $ctx->get(LogService::class);

        $result = $service->doWork('test-task');

        $this->assertSame('done: test-task', $result);
        $this->assertGreaterThanOrEqual(2, count($logger->entries));

        // First entry should be "Entering"
        $this->assertStringContainsString('Entering', $logger->entries[0]['message']);
        // Second entry should be "Completed"
        $this->assertStringContainsString('Completed', $logger->entries[1]['message']);
    }

    public function testLogInterceptorLogsException(): void
    {
        $logger = new TestLogger();
        $ctx    = AppContext::instance();
        $ctx->registerInterceptor(Log::class, new LogInterceptor($logger));

        $service = $ctx->get(LogService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('work failed');

        try {
            $service->failingWork();
        } finally {
            // Should have an error log entry
            $errorEntries = array_filter($logger->entries, fn($e) => $e['level'] === 'error');
            $this->assertGreaterThanOrEqual(1, count($errorEntries));
        }
    }

    public function testMultipleInterceptorsOnSameClass(): void
    {
        $logger = new TestLogger();
        $cache  = new ArrayCache();

        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor($logger));
        $ctx->registerInterceptor(Cache::class, new CacheInterceptor($cache));
        $ctx->registerInterceptor(Log::class, new LogInterceptor($logger));

        // RetryService has #[Retry] but not #[Cache] or #[Log]
        $service = $ctx->get(RetryService::class);
        $this->assertInstanceOf(RetryService::class, $service);

        // CacheService has #[Cache] but not #[Retry] or #[Log]
        $cacheService = $ctx->get(CacheService::class);
        $this->assertInstanceOf(CacheService::class, $cacheService);
    }

    /**
     * A final advised method used to produce a PHP fatal ("Cannot override
     * final method") while the generated proxy file was required, with no
     * reference to the attribute that caused it.
     */
    public function testFinalAdvisedMethodThrowsAnActionableError(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/finalAdvised.*final|final.*finalAdvised/s');

        $ctx->get(FinalMethodService::class);
    }

    /**
     * A final class cannot be extended at all, so advice on its methods is
     * unresolvable; report which class and why instead of failing to declare
     * the subclass.
     */
    public function testFinalAdvisedClassThrowsAnActionableError(): void
    {
        $ctx = AppContext::instance();
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/FinalClassService.*final|final.*FinalClassService/s');

        $ctx->get(FinalClassService::class);
    }
}