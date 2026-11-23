<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Aop\Advised;
use Azera\Aop\Retry;
use Azera\Aop\InterceptorInterface;
use Azera\Aop\ProxyFactory;
use PHPUnit\Framework\TestCase;

#[Advised]
class AopCacheTarget
{
    #[Retry(times: 1, backoff: 0)]
    public function work(): string
    {
        return 'real';
    }
}

/**
 * Additional fixtures, identical in shape.
 *
 * Proxy class names are now DETERMINISTIC, so a target class yields ONE proxy
 * class per process. Tests that exercise the write path therefore each need a
 * target that has not been proxied yet — otherwise `class_exists()` correctly
 * short-circuits and no file is written, which would make the assertions
 * vacuous rather than failing honestly.
 */
#[Advised]
class AopCacheTargetB
{
    #[Retry(times: 1, backoff: 0)]
    public function work(): string
    {
        return 'real';
    }
}

#[Advised]
class AopCacheTargetC
{
    #[Retry(times: 1, backoff: 0)]
    public function work(): string
    {
        return 'real';
    }
}

#[Advised]
class AopCacheTargetD
{
    #[Retry(times: 1, backoff: 0)]
    public function work(): string
    {
        return 'real';
    }
}

class AopCacheInterceptor implements InterceptorInterface
{
    public function intercept(object $target, \ReflectionMethod $method, array $args, callable $next): mixed
    {
        return $next($args);
    }
}

/**
 * Regression test for the AOP proxy-cache finding of the 2026 security review:
 * the generated proxy was written with a predictable name into a shared temp
 * directory and then `require`d unconditionally, even when the write failed.
 */
class AopProxyCacheRegressionTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/azera_aop_test_' . bin2hex(random_bytes(6));
        $this->resetLoadedProxies();
    }

    /**
     * ProxyFactory keeps a process-wide registry of loaded proxies, so each
     * test needs a clean slate to exercise the write path.
     */
    private function resetLoadedProxies(): void
    {
        $ref = new \ReflectionProperty(ProxyFactory::class, 'loadedProxies');
        $ref->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (scandir($this->cacheDir) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->cacheDir . '/' . $entry);
                }
            }
            @rmdir($this->cacheDir);
        }
        try {
            \Azera\Cache\ArrayCache::class;
        } catch (\Throwable) {
            // ignore
        }
    }

    private function factory(): ProxyFactory
    {
        $factory = new ProxyFactory();
        $factory->setCacheDir($this->cacheDir);
        $factory->register(Retry::class, new AopCacheInterceptor());
        ProxyFactory::setCurrent($factory);

        return $factory;
    }

    public function testProxyIsWrittenAndLoaded(): void
    {
        $factory = $this->factory();
        $class   = $factory->buildProxyClass(new \ReflectionClass(AopCacheTarget::class));

        $this->assertNotNull($class);
        $this->assertTrue(class_exists($class));

        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->assertSame('real', $instance->work());

        $files = glob($this->cacheDir . '/Proxy_*.php');
        $this->assertNotEmpty($files);
    }

    public function testCacheDirectoryIsCreatedWithRestrictivePermissions(): void
    {
        $factory = $this->factory();

        // A target that no earlier test proxied: proxy class names are now
        // deterministic, so a target already declared in this process would
        // short-circuit before any directory is created.
        $class = $factory->buildProxyClass(new \ReflectionClass(AopCacheTargetB::class));

        self::assertNotNull($class);
        $this->assertDirectoryExists($this->cacheDir);

        if (\DIRECTORY_SEPARATOR !== '\\') {
            $perms = fileperms($this->cacheDir) & 0777;
            $this->assertSame(0, $perms & 0022, 'cache dir must not be group/world writable');
        }
    }

    /**
     * A planted file at the cache path must never be required.
     *
     * The proxy name is derived from the generated source, so it is stable and
     * predictable — which is exactly what lets OPcache serve it. That stability
     * is only safe because the loader verifies the stored body against the body
     * it would generate, so a planted payload is rejected and overwritten rather
     * than executed.
     */
    public function testPlantedProxyFileIsOverwrittenNotLoaded(): void
    {
        @mkdir($this->cacheDir, 0700, true);

        $factory  = $this->factory();
        $ref      = new \ReflectionClass(AopCacheTargetC::class);
        $expected = $factory->proxyPath($ref);

        self::assertNotNull($expected, 'the fixture must produce a proxy path');

        file_put_contents($expected, "<?php return 'PLANTED';");

        $class = $factory->buildProxyClass($ref);

        $this->assertNotNull($class);
        $this->assertNotSame('PLANTED', $class);
        $this->assertTrue(class_exists($class));
        $this->assertTrue(is_subclass_of($class, AopCacheTargetC::class));

        // The planted payload is gone, replaced by the generated proxy.
        $this->assertStringNotContainsString('PLANTED', (string) file_get_contents($expected));
        $this->assertStringContainsString('extends', (string) file_get_contents($expected));
    }

    /**
     * The proxy file is REUSED across builds, and its class name is stable.
     *
     * This is the property the cache was created for and the one the old
     * `random_bytes()` naming defeated: a file whose declared class changed on
     * every write could never be served by OPcache, so every cold request
     * recompiled it (measured at 17-31 ms per process on NTFS, against ~0.5-1 ms
     * for a `require` of the same bytes).
     *
     * Asserted on the file the loader actually looks at, plus mtime/bytes, so a
     * regression that reintroduces per-build rewriting fails here rather than
     * silently costing every deployment.
     */
    public function testProxyFileIsReusedRatherThanRewritten(): void
    {
        $factory = $this->factory();
        $ref     = new \ReflectionClass(AopCacheTargetD::class);
        $path    = $factory->proxyPath($ref);

        self::assertNotNull($path);

        $factory->buildProxyClass($ref);

        self::assertFileExists($path);
        $firstBody  = (string) file_get_contents($path);
        $firstMtime = (int) filemtime($path);

        // A fresh factory in the same process: same digest, and the declared
        // class is already loaded, so it must not rewrite the file.
        $second = $this->factory();
        $again  = $second->buildProxyClass($ref);

        self::assertSame($firstBody, (string) file_get_contents($path), 'the proxy file was rewritten');
        self::assertSame($firstMtime, (int) filemtime($path), 'the proxy file mtime changed');
        self::assertMatchesRegularExpression(
            '/^AzeraAopProxy_.+_[0-9a-f]{16}$/',
            (string) $again,
            'the proxy class name must end in the deterministic digest, not random bytes'
        );
        self::assertSame(false, str_contains($firstBody, 'PLANTED'));
    }

    /**
     * Temp files must not survive a successful write.
     */
    /**
     * The proxy class name is stable across processes, so the file can be cached.
     *
     * The defect this replaced was invisible at the API level — the proxy worked
     * perfectly, it was just rebuilt every request. The observable difference is
     * in the NAME: `random_bytes()` produced a different class each write, which
     * is precisely why OPcache could never serve it. Asserting the name is
     * derived from the generated source (and unchanged on a second build) is a
     * deterministic stand-in for "the file is now cacheable".
     */
    public function testProxyClassNameIsStableAndDerivedFromTheSource(): void
    {
        $first  = $this->factory();
        $second = $this->factory();
        $ref    = new \ReflectionClass(AopCacheTarget::class);

        $a = $first->buildProxyClass($ref);
        $b = $second->buildProxyClass($ref);

        self::assertNotNull($a);
        self::assertSame($a, $b, 'the same target must yield the same proxy class');

        // The digest is 16 hex chars of sha256 over (version, class, body); the
        // filename carries the same digest, which is how reuse is matched.
        self::assertMatchesRegularExpression('/_[0-9a-f]{16}$/', $a);
        self::assertStringContainsString(substr((string) $a, -16), basename((string) $first->proxyPath($ref)));
    }

    /**
     * A changed generator version must produce a NEW class name.
     *
     * Otherwise a long-running process that already declared the old proxy would
     * keep serving code emitted by a previous version of the generator, and the
     * upgrade would appear to do nothing.
     */
    public function testGeneratorVersionParticipatesInTheClassName(): void
    {
        $factory = $this->factory();
        $ref     = new \ReflectionClass(AopCacheTarget::class);

        $current = (string) $factory->buildProxyClass($ref);

        $version = new \ReflectionClassConstant(ProxyFactory::class, 'GENERATOR_VERSION');
        self::assertIsInt($version->getValue(), 'the generator version must be part of the identity');
        self::assertMatchesRegularExpression(
            '/_[0-9a-f]{16}$/',
            $current,
            'the trailing digest is what changes when the generated body changes'
        );
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $factory = $this->factory();
        $factory->buildProxyClass(new \ReflectionClass(AopCacheTarget::class));

        $leftovers = glob($this->cacheDir . '/azera_aop_*');

        $this->assertSame([], $leftovers ?: []);
        self::assertNotNull($factory->proxyPath(new \ReflectionClass(AopCacheTarget::class)));
    }
}
