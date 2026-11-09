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
        $factory->buildProxyClass(new \ReflectionClass(AopCacheTarget::class));

        $this->assertDirectoryExists($this->cacheDir);

        if (\DIRECTORY_SEPARATOR !== '\\') {
            $perms = fileperms($this->cacheDir) & 0777;
            $this->assertSame(0, $perms & 0022, 'cache dir must not be group/world writable');
        }
    }

    /**
     * A planted file at the predictable cache path must be overwritten by the
     * proxy we generate; the planted contents must never be required.
     */
    public function testPlantedProxyFileIsOverwrittenNotLoaded(): void
    {
        @mkdir($this->cacheDir, 0700, true);

        $predictable = $this->cacheDir . '/Proxy_' . md5(AopCacheTarget::class) . '.php';
        file_put_contents($predictable, "<?php return 'PLANTED';");

        $factory = $this->factory();
        $class   = $factory->buildProxyClass(new \ReflectionClass(AopCacheTarget::class));

        $this->assertNotNull($class);
        $this->assertNotSame('PLANTED', $class);
        $this->assertTrue(class_exists($class));
        $this->assertTrue(is_subclass_of($class, AopCacheTarget::class));

        // The planted payload is gone, replaced by the generated proxy.
        $this->assertStringNotContainsString('PLANTED', (string) file_get_contents($predictable));
        $this->assertStringContainsString('extends', (string) file_get_contents($predictable));
    }

    /**
     * Temp files must not survive a successful write.
     */
    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $this->factory()->buildProxyClass(new \ReflectionClass(AopCacheTarget::class));

        $leftovers = glob($this->cacheDir . '/azera_aop_*');

        $this->assertSame([], $leftovers ?: []);
    }
}
