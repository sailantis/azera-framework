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

    public function testProxiesAreEnabledByDefault(): void
    {
        $this->assertTrue(AppContext::instance()->aopProxiesEnabled());
    }

    /**
     * With proxying off an #[Advised] class is still a valid service — it just
     * runs its own body. This is the escape hatch a deployment uses when it
     * cannot write a proxy file and will not eval().
     */
    public function testDisabledProxiesReturnTheRawClass(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());
        $ctx->setAopProxiesEnabled(false);

        $service = $ctx->get(RetryService::class);

        $this->assertSame(RetryService::class, $service::class, 'must not be a proxy subclass');

        // The advice must NOT run: the raw method throws on its first attempt.
        $service->attempts = 0;
        try {
            $service->flakyOperation();
            $this->fail('the retry advice must be inactive while proxies are disabled');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Attempt 1 failed', $e->getMessage());
        }
        $this->assertSame(1, $service->attempts, 'the body must run exactly once, unadvised');
    }

    /** Re-enabling must reach services that were resolved while it was off. */
    public function testReEnablingProxiesEvictsRawInstances(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $ctx->setAopProxiesEnabled(false);
        $raw = $ctx->get(RetryService::class);
        $this->assertSame(RetryService::class, $raw::class);

        $ctx->setAopProxiesEnabled(true);
        $proxied = $ctx->get(RetryService::class);

        $this->assertNotSame($raw, $proxied);
        $this->assertNotSame(RetryService::class, $proxied::class, 'the re-resolved instance must be proxied');

        $proxied->attempts = 0;
        $this->assertSame('success', $proxied->flakyOperation(), 'advice must apply again');
    }

    /** Disabling must not leave a proxy in the container. */
    public function testDisablingProxiesEvictsProxiedInstances(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);
        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $proxied = $ctx->get(RetryService::class);
        $this->assertNotSame(RetryService::class, $proxied::class);

        $ctx->setAopProxiesEnabled(false);
        $raw = $ctx->get(RetryService::class);

        $this->assertSame(RetryService::class, $raw::class);
    }

    /**
     * An interceptor registered while proxies are disabled must not force an
     * eviction loop: nothing was proxied, so resolved instances stay put.
     */
    public function testRegisteringWhileDisabledLeavesResolvedServicesAlone(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);
        $ctx->setAopProxiesEnabled(false);

        $first = $ctx->get(RetryService::class);

        $ctx->registerInterceptor(Retry::class, new RetryInterceptor());

        $this->assertSame($first, $ctx->get(RetryService::class), 'same instance must be reused');
    }

    /** The explicit Pipeline never generated a proxy, so the switch must not affect it. */
    public function testDisabledProxiesLeaveTheExplicitPipelineWorking(): void
    {
        $ctx = AppContext::instance();
        $ctx->setAopCacheDir(null);
        $ctx->setAopProxiesEnabled(false);

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