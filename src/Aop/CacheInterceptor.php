<?php

namespace Azera\Aop;

use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;

/**
 * Intercepts methods marked with {@see Cache} and caches their return values.
 *
 * Cache key resolution:
 * 1. If a custom key template is provided, interpolate `{argName}` placeholders
 *    with the method arguments.
 * 2. Otherwise, build a key from the class name, method name, and a hash
 *    of the arguments.
 *
 * On a cache hit, the method is NOT executed — the cached value is returned.
 * On a miss, the method executes and the result is stored with the given TTL.
 */
class CacheInterceptor implements InterceptorInterface
{
    /**
     * Longest cache key {@see \Azera\Cache\ArrayCache} accepts. Keys that
     * would exceed it are folded into a digest instead of being truncated.
     */
    private const MAX_KEY_LENGTH = 64;

    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed
    {
        $advice = $this->getAdvice($method);
        $key    = $this->resolveKey($method, $args, $advice);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $next($args);

        $this->cache->set($key, $result, $advice->ttl);

        return $result;
    }

    private function getAdvice(ReflectionMethod $method): Cache
    {
        $attrs = $method->getAttributes(Cache::class);
        if ($attrs === []) {
            return new Cache();
        }
        return $attrs[0]->newInstance();
    }

    private function resolveKey(ReflectionMethod $method, array $args, Cache $advice): string
    {
        if ($advice->key !== null) {
            return $this->interpolateKey($advice->key, $method, $args);
        }

        return self::keyFor(
            $method->getDeclaringClass()->getShortName(),
            $method->getName(),
            $args,
        );
    }

    /**
     * Build the default cache key for a class/method/args triple.
     *
     * The declaring class name is sanitized because anonymous classes report
     * names like "Pipeline.php:150$5" (Windows) or, on Linux, the full
     * absolute declaring path — `getShortName()` has no backslash to split on
     * there, so the path leaks in. That path is both invalid as a cache key
     * and arbitrarily long, so an over-long key is folded into a fixed-length
     * digest. It is never truncated: that would drop the args hash and make
     * different arguments collide onto a single entry.
     *
     * @internal Exposed for testing; not part of the public AOP surface.
     *
     * @param string $className Declaring class name (may be path-derived).
     * @param string $methodName Method name.
     * @param array  $args       Call arguments.
     */
    public static function keyFor(string $className, string $methodName, array $args): string
    {
        $className = preg_replace('/[^A-Za-z0-9_]/', '_', $className);
        $key       = $className . '.' . $methodName . '.' . md5(serialize($args));

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            $key = 'aop.' . md5($className . "\0" . $methodName . "\0" . serialize($args));
        }

        return $key;
    }

    private function interpolateKey(string $template, ReflectionMethod $method, array $args): string
    {
        $paramNames = [];
        foreach ($method->getParameters() as $i => $param) {
            $paramNames['{' . $param->getName() . '}'] = $args[$i] ?? '';
        }

        return strtr($template, $paramNames);
    }
}