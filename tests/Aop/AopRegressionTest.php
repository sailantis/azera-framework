<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Aop\Advised;
use Azera\Aop\AopRuntime;
use Azera\Aop\Cache;
use Azera\Aop\CacheInterceptor;
use Azera\Aop\Invocation;
use Azera\Aop\InterceptorInterface;
use Azera\Aop\Pipeline;
use Azera\Aop\Retry;
use Azera\Aop\Transactional;
use Azera\Aop\TransactionalInterceptor;
use Azera\AppContext;
use Azera\Cache\ArrayCache;
use Azera\Db\Database;
use Azera\Db\DatabaseManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

// --- Fixtures: proxies that must survive clone and serialization ---

#[Advised]
class CloneableService
{
    public int $state = 0;

    #[Retry(times: 1, backoff: 0)]
    public function bump(int $by): int
    {
        return $this->state += $by;
    }
}

#[Advised]
class SerializableService
{
    public int $state = 0;

    #[Retry(times: 1, backoff: 0)]
    public function bump(int $by): int
    {
        return $this->state += $by;
    }
}

/**
 * Declares a property named exactly like the closure cache the generator used to
 * emit (`__aopTerminal_<method>`). A generated private redeclaration of it was a
 * fatal "access level must not be reduced" error.
 */
#[Advised]
class ConflictingPropertyService
{
    public ?string $__aopTerminal_bump = 'user-owned';

    #[Retry(times: 1, backoff: 0)]
    public function bump(int $by): int
    {
        return $by + 1;
    }
}

/**
 * A `&...$refs` method. PHP allows by-reference variadics, and the generated args
 * array must keep those references alive.
 */
#[Advised]
class VariadicReferenceService
{
    #[Retry(times: 1, backoff: 0)]
    public function fill(int $n, &...$refs): void
    {
        foreach ($refs as &$ref) {
            $ref[] = "n={$n}";
        }
    }
}

/** A service whose advised method leaves another role inside a transaction. */
#[Advised]
class LeakingRoleService
{
    public function __construct(public Database $write, public Database $leak) {}

    #[Transactional]
    public function writesThenLeaks(): string
    {
        $this->write->query("INSERT INTO t (v) VALUES ('committed?')");
        $this->leak->query("INSERT INTO t (v) VALUES ('orphan')");
        $this->leak->begin();

        return 'done';
    }
}

class RecordingInterceptor implements InterceptorInterface
{
    public int $calls = 0;

    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $this->calls++;

        return $next($invocation);
    }
}

#[Advised]
class RegistrableService
{
    #[Retry(times: 1, backoff: 0)]
    public function work(): string
    {
        return 'ok';
    }
}

/**
 * Regression coverage for the AOP review findings: cache key collisions, proxy
 * clone/serialization defects, generated property collisions, by-reference
 * variadics, transaction leak ordering, registry namespacing, and the
 * `Pipeline::through()` compatibility shim.
 */
class AopRegressionTest extends TestCase
{
    private ?string $cacheDir = null;

    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
        $this->cacheDir = sys_get_temp_dir() . '/azera_aop_regress_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        AppContext::reset();
        $this->resetLoadedProxies();

        if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
            foreach (scandir($this->cacheDir) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->cacheDir . '/' . $entry);
                }
            }
            @rmdir($this->cacheDir);
        }
    }

    private function resetLoadedProxies(): void
    {
        $ref = new \ReflectionProperty(\Azera\Aop\ProxyFactory::class, 'loadedProxies');
        $ref->setValue(null, []);
    }

    private function context(): AppContext
    {
        return AppContext::instance();
    }

    // ---------------------------------------------------------------- cache keys

    /**
     * Non-scalar placeholder values used to be replaced with `''`, so every array
     * or object argument collapsed onto one cache entry. Two different filter
     * arrays must not share a result.
     */
    public function testNonScalarPlaceholderValuesDoNotCollide(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);
        $advice   = [Cache::class => new Cache(ttl: 60, key: 'report_{filter}')];

        // Named args: the explicit path resolves placeholders only from string keys.
        $first = $pipeline->call(
            static fn(array $filter) => 'first:' . json_encode($filter),
            ['filter' => ['status' => 'open']],
            'reports',
            $advice,
        );
        $second = $pipeline->call(
            static fn(array $filter) => 'second:' . json_encode($filter),
            ['filter' => ['status' => 'closed']],
            'reports',
            $advice,
        );

        $this->assertSame('first:{"status":"open"}', $first);
        $this->assertSame(
            'second:{"status":"closed"}',
            $second,
            'a different array argument must not read the first call\'s cached value',
        );
    }

    /** `null`, `false` and `''` are three different arguments and three different keys. */
    public function testNullFalseAndEmptyStringStayDistinct(): void
    {
        $advice = [Cache::class => new Cache(ttl: 60, key: 'v_{value}')];

        $keys = [];
        foreach ([null, false, '', 0] as $value) {
            $cache    = new ArrayCache();
            $pipeline = new Pipeline([new CacheInterceptor($cache)]);
            $pipeline->call(static fn($value) => 'x', ['value' => $value], 'svc', $advice);
            $keys[] = $this->cacheKeys($cache)[0];
        }

        $this->assertCount(4, array_unique($keys), 'each scalar form must produce its own key, got: ' . implode(', ', $keys));
    }

    /**
     * A *value* that looks like a placeholder must not be re-scanned as one. The
     * old code validated the substituted result, so passing `'ops_{admin}'` raised
     * a LogicException even though the template resolved cleanly.
     */
    public function testPlaceholderLikeArgumentValueDoesNotTriggerFalseError(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);
        $advice   = [Cache::class => new Cache(ttl: 60, key: 'user_{name}')];

        $result = $pipeline->call(
            static fn(string $name) => "hello {$name}",
            ['name' => 'ops_{admin}'],
            'svc',
            $advice,
        );

        $this->assertSame('hello ops_{admin}', $result);
    }

    /** A genuinely unresolved placeholder still fails loudly. */
    public function testUnresolvedPlaceholderStillThrows(): void
    {
        $pipeline = new Pipeline([new CacheInterceptor(new ArrayCache())]);
        $advice   = [Cache::class => new Cache(ttl: 60, key: 'user_{missing}')];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\{missing\}/');

        $pipeline->call(static fn(int $id) => $id, ['id' => 1], 'svc', $advice);
    }

    /**
     * Values of different types must not render alike. A naive `(string)` cast made
     * the integer `42`, the string `'42'` and the float `42.0` share a token.
     */
    public function testDifferentTypesDoNotRenderAlike(): void
    {
        $advice = [Cache::class => new Cache(ttl: 60, key: 'v_{value}')];

        $keys = [];
        foreach ([42, '42', 42.0, 'null', null, true, 'true'] as $value) {
            $cache    = new ArrayCache();
            $pipeline = new Pipeline([new CacheInterceptor($cache)]);
            $pipeline->call(static fn($value) => 'x', ['value' => $value], 'svc', $advice);
            $keys[] = $this->cacheKeys($cache)[0];
        }

        $this->assertCount(
            count($keys),
            array_unique($keys),
            'each type/value pair must produce its own key, got: ' . implode(', ', $keys),
        );
    }

    /**
     * An argument the template does not name must not be tokenized at all: it may be
     * an object that cannot be serialized, and rejecting the call would be wrong.
     */
    public function testArgumentsNotNamedByTheTemplateAreIgnored(): void
    {
        $cache    = new ArrayCache();
        $pipeline = new Pipeline([new CacheInterceptor($cache)]);
        $advice   = [Cache::class => new Cache(ttl: 60, key: 'user_{id}')];

        $closure = static fn() => 'not serializable';

        $result = $pipeline->call(
            static fn(\Closure $callback, int $id) => $callback() . " {$id}",
            ['callback' => $closure, 'id' => 7],
            'svc',
            $advice,
        );

        $this->assertSame('not serializable 7', $result);
    }

    // ------------------------------------------------------------------- proxies

    /**
     * A clone must run the advised method against ITSELF. The cached terminal
     * closure was bound to the original instance, so the clone ran
     * `parent::method` on the original object.
     */
    public function testClonedProxyExecutesAgainstTheClone(): void
    {
        $this->context()->setAopCacheDir($this->cacheDir);
        $this->context()->registerInterceptor(Retry::class, new \Azera\Aop\RetryInterceptor());

        $original = $this->context()->get(CloneableService::class);
        $original->bump(1);

        $clone = clone $original;
        $clone->state = 100;

        $this->assertSame(105, $clone->bump(5), 'the clone must update its own state');
        $this->assertSame(1, $original->state, 'the original must be untouched');
    }

    /**
     * An advised proxy must remain serializable after a call. The cached Closure
     * made `serialize()` throw "Serialization of 'Closure' is not allowed".
     */
    public function testProxyRemainsSerializableAfterAnAdvisedCall(): void
    {
        $this->context()->setAopCacheDir($this->cacheDir);
        $this->context()->registerInterceptor(Retry::class, new \Azera\Aop\RetryInterceptor());

        $service = $this->context()->get(SerializableService::class);
        $service->bump(2);
        $service->state = 10;

        $restored = unserialize(serialize($service));

        $this->assertInstanceOf(SerializableService::class, $restored);
        $this->assertSame(10, $restored->state);
        $this->assertSame(13, $restored->bump(3), 'the restored proxy must still be advised');
    }

    /**
     * The generator must not declare a property that can collide with one the
     * target class already defines — doing so was a fatal visibility error.
     */
    public function testProxyDoesNotDeclarePropertiesThatCollideWithTheTarget(): void
    {
        $this->context()->setAopCacheDir($this->cacheDir);
        $this->context()->registerInterceptor(Retry::class, new \Azera\Aop\RetryInterceptor());

        $service = $this->context()->get(ConflictingPropertyService::class);

        $this->assertSame('user-owned', $service->__aopTerminal_bump, 'the user property must survive');
        $this->assertSame(6, $service->bump(5));
        $this->assertSame('user-owned', $service->__aopTerminal_bump);
    }

    /**
     * PHP supports by-reference variadics; mutations through `&...$refs` must reach
     * the caller through the generated proxy.
     */
    public function testVariadicByReferenceParametersKeepTheirReferences(): void
    {
        $this->context()->setAopCacheDir($this->cacheDir);
        $this->context()->registerInterceptor(Retry::class, new \Azera\Aop\RetryInterceptor());

        $service = $this->context()->get(VariadicReferenceService::class);

        $first  = ['a'];
        $second = ['b'];
        $service->fill(7, $first, $second);

        $this->assertSame(['a', 'n=7'], $first);
        $this->assertSame(['b', 'n=7'], $second);
    }

    // ------------------------------------------------------------ transactions

    /**
     * The leak check must run BEFORE commit and must roll the unit of work back.
     * Running it after `commit()` left the advised method's writes committed while
     * throwing, so an outer RetryInterceptor could retry and duplicate them.
     */
    public function testLeakedConnectionCheckRunsBeforeCommitAndRollsBack(): void
    {
        $write = new Database('sqlite::memory:');
        $write->query('CREATE TABLE t (v TEXT)');

        $leak = new Database('sqlite::memory:');
        $leak->query('CREATE TABLE t (v TEXT)');

        $manager = new DatabaseManager();
        $manager->set('write', $write);
        $manager->set('leak', $leak);

        $interceptor = new TransactionalInterceptor($manager, detectLeakedConnections: true);

        $caught = null;
        try {
            $interceptor->intercept(
                $this->invocation([Transactional::class => new Transactional()]),
                static function () use ($write, $leak) {
                    $write->query("INSERT INTO t (v) VALUES ('must roll back')");
                    $leak->begin();

                    return 'done';
                },
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringContainsString("'leak'", $caught->getMessage());
        $this->assertFalse($write->inTransaction(), 'the opened transaction must be rolled back');

        $rows = $write->query('SELECT v FROM t')->fetchAll();
        $this->assertSame([], $rows, 'work from the failed unit must not be committed');

        $leak->rollback();
    }

    /**
     * A connection that was ALREADY inside a transaction (an outer unit of work on
     * another role) is not this interceptor's leak.
     */
    public function testPreexistingOuterTransactionIsNotReportedAsALeak(): void
    {
        $write = new Database('sqlite::memory:');
        $write->query('CREATE TABLE t (v TEXT)');

        $outer = new Database('sqlite::memory:');
        $outer->query('CREATE TABLE t (v TEXT)');

        $manager = new DatabaseManager();
        $manager->set('write', $write);
        $manager->set('outer', $outer);

        // Outer unit of work already open before the advised call.
        $outer->begin();

        $interceptor = new TransactionalInterceptor($manager, detectLeakedConnections: true);

        $result = $interceptor->intercept(
            $this->invocation([Transactional::class => new Transactional()]),
            static function () use ($write) {
                $write->query("INSERT INTO t (v) VALUES ('ok')");

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        $this->assertTrue($outer->inTransaction(), 'the outer transaction must be left alone');
        $this->assertSame(1, count($write->query('SELECT v FROM t')->fetchAll()));

        $outer->rollback();
    }

    /**
     * An attribute that names a role must still fail loudly, while the implicit
     * default must keep falling back to the manager's default connection (so an app
     * that configures only one connection keeps working).
     */
    public function testImplicitDefaultRoleFallsBackToTheManagersDefault(): void
    {
        $db = new Database('sqlite::memory:');
        $db->query('CREATE TABLE t (v TEXT)');

        $manager = new DatabaseManager();
        $manager->set('primary', $db);

        $interceptor = new TransactionalInterceptor($manager);

        $result = $interceptor->intercept(
            $this->invocation([Transactional::class => new Transactional()]),
            static function () use ($db) {
                $db->query("INSERT INTO t (v) VALUES ('via default')");

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        $this->assertSame(1, count($db->query('SELECT v FROM t')->fetchAll()));
    }

    /** An explicitly named, unconfigured role still throws. */
    public function testExplicitUnknownRoleStillThrows(): void
    {
        $manager = new DatabaseManager();
        $manager->set('write', new Database('sqlite::memory:'));

        $interceptor = new TransactionalInterceptor($manager);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/'analytcs'/");

        $interceptor->intercept(
            $this->invocation([Transactional::class => new Transactional(connection: 'analytcs')]),
            static fn() => 'never',
        );
    }

    // ---------------------------------------------------------------- registry

    /**
     * A second factory must not silently replace the first one's interceptors, and
     * a re-registration must invalidate the memoized plan. The proxy must switch to
     * the interceptor registered last.
     */
    public function testReregisteringAnInterceptorReplacesTheMemoizedPlan(): void
    {
        $ctx = $this->context();
        $ctx->setAopCacheDir($this->cacheDir);

        $first = new RecordingInterceptor();
        $ctx->registerInterceptor(Retry::class, $first);

        $service = $ctx->get(RegistrableService::class);
        $this->assertSame('ok', $service->work());
        $this->assertSame(1, $first->calls);

        $second = new RecordingInterceptor();
        $ctx->registerInterceptor(Retry::class, $second);

        $rebuilt = $ctx->get(RegistrableService::class);
        $this->assertSame('ok', $rebuilt->work());

        $this->assertSame(1, $second->calls, 'the newly registered interceptor must run');
        $this->assertSame(1, $first->calls, 'the replaced interceptor must not run again');
    }

    /**
     * Two factories with different cache directories must each resolve their OWN
     * interceptors. Before namespacing, the second publication overwrote the first
     * and a proxy generated by factory A ran factory B's interceptor.
     */
    public function testTwoFactoriesInOneProcessDoNotShareInterceptors(): void
    {
        $dirA = $this->cacheDir . '_a';
        $dirB = $this->cacheDir . '_b';

        $factoryA = new \Azera\Aop\ProxyFactory();
        $factoryA->setCacheDir($dirA);
        $interceptorA = new RecordingInterceptor();
        $factoryA->register(Retry::class, $interceptorA);

        $factoryB = new \Azera\Aop\ProxyFactory();
        $factoryB->setCacheDir($dirB);
        $interceptorB = new RecordingInterceptor();
        $factoryB->register(Retry::class, $interceptorB);

        // Build from factory B LAST, so a single global namespace would have left
        // factory A's proxy resolving against B's interceptor.
        $classA = $factoryA->buildProxyClass(new \ReflectionClass(RegistrableService::class));
        $classB = $factoryB->buildProxyClass(new \ReflectionClass(RegistrableService::class));

        $this->assertNotNull($classA);
        $this->assertNotNull($classB);
        $this->assertNotSame($classA, $classB, 'each factory must emit its own proxy');

        (new \ReflectionClass($classA))->newInstance()->work();
        $this->assertSame(1, $interceptorA->calls, 'factory A proxy must use factory A interceptor');
        $this->assertSame(0, $interceptorB->calls);

        (new \ReflectionClass($classB))->newInstance()->work();
        $this->assertSame(1, $interceptorA->calls);
        $this->assertSame(1, $interceptorB->calls, 'factory B proxy must use factory B interceptor');

        foreach ([$dirA, $dirB] as $dir) {
            if (is_dir($dir)) {
                foreach (scandir($dir) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($dir . '/' . $entry);
                    }
                }
                @rmdir($dir);
            }
        }
    }

    // ------------------------------------------------------------- BC shim

    /**
     * `Pipeline::through()` is retained as an immutable alias so 0.6 call sites keep
     * working. It must preserve order (existing interceptors first) and must not
     * mutate the receiver.
     */
    public function testThroughReturnsANewPipelineAndPreservesOrder(): void
    {
        $trace = [];

        $outer = new class ($trace) implements InterceptorInterface
        {
            public function __construct(public array &$trace)
            {
            }

            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $this->trace[] = 'outer';
                $result = $next($invocation);
                $this->trace[] = 'outer-after';

                return $result;
            }
        };

        $inner = new class ($trace) implements InterceptorInterface
        {
            public function __construct(public array &$trace)
            {
            }

            public function intercept(Invocation $invocation, callable $next): mixed
            {
                $this->trace[] = 'inner';

                return $next($invocation);
            }
        };

        $base = new Pipeline([$outer]);

        /** @phpstan-ignore-next-line deliberately exercises the deprecated BC shim */
        $combined = $base->through([$inner]);

        $this->assertNotSame($base, $combined, 'through() must not mutate the receiver');
        $this->assertCount(1, $base->interceptors(), 'the receiver keeps its own chain');

        $this->assertSame('result', $combined->call(static fn() => 'result'));
        $this->assertSame(['outer', 'inner', 'outer-after'], $trace);
    }

    /** @return Invocation */
    private function invocation(array $advice): Invocation
    {
        return new Invocation(
            target: null,
            method: 'advised',
            identity: 'TestService::advised',
            args: [],
            advice: $advice,
        );
    }

    /**
     * The keys the cache actually holds.
     *
     * @return list<string>
     */
    private function cacheKeys(ArrayCache $cache): array
    {
        $reader = \Closure::bind(
            static fn(ArrayCache $c): array => array_keys($c->data),
            null,
            ArrayCache::class,
        );

        \assert($reader instanceof \Closure);

        return $reader($cache);
    }
}
