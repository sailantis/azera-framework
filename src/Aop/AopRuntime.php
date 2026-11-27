<?php

namespace Azera\Aop;

/**
 * Process-wide registry for generated proxies and {@see ProxyFactory}.
 *
 * Disk-cached proxies may load in another process, so their source includes advice
 * arguments and a namespace, but not interceptor instances. On first invocation, this
 * registry builds and memoizes a plan by namespace, class, and method using the
 * published interceptor map.
 *
 * `ProxyFactory` bakes advice arguments into the generated body, so attribute changes
 * alter the proxy digest. This runtime does not reflect on advice attributes. Namespaces
 * derive from the cache directory to isolate factories and remain stable across processes.
 *
 * Standalone callers must publish interceptors via {@see self::setInterceptors()} or
 * `ProxyFactory::publish()` before invoking a generated proxy.
 *
 * @internal
 */
final class AopRuntime
{
    /** @var array<string, array<class-string<Advice>, InterceptorInterface>> namespace => advice class => interceptor */
    private static array $interceptors = [];

    /** @var array<string, array<string, AopPlan>> namespace => (class::method) => plan */
    private static array $plans = [];

    /**
     * Replace every interceptor for one namespace.
     *
     * Plans for that namespace are discarded: they captured the interceptor
     * instances that were registered when they were built.
     *
     * @param array<class-string<Advice>, InterceptorInterface> $interceptors
     */
    public static function setInterceptors(string $namespace, array $interceptors): void
    {
        self::$interceptors[$namespace] = $interceptors;
        self::clearPlans($namespace);
    }

    /**
     * Register (or replace) one interceptor for a namespace without touching the rest.
     *
     * @param class-string<Advice> $adviceClass
     */
    public static function setInterceptor(
        string $namespace,
        string $adviceClass,
        InterceptorInterface $interceptor,
    ): void {
        self::$interceptors[$namespace][$adviceClass] = $interceptor;
        self::clearPlans($namespace);
    }

    /**
     * Forget every memoized plan for one namespace.
     *
     * Called when the interceptor map changes: a plan captures the interceptor
     * instances that were registered when it was built, so a plan that survived a
     * re-registration would keep invoking the old interceptor for an existing
     * advised method.
     */
    public static function clearPlans(string $namespace): void
    {
        unset(self::$plans[$namespace]);
    }

    /**
     * Return the memoized plan for a method in a namespace.
     *
     * The generated proxy supplies advice arguments and parameter names read at
     * build time, so no reflection is needed. Both are part of the generated source
     * and digest; changes produce a new proxy.
     *
     * @param array<class-string<Advice>, array<string, mixed>> $advice Advice arguments, keyed by class.
     * @param list<string>                                      $parameterNames Declared parameter names, in order.
     */
    public static function plan(
        string $namespace,
        string $class,
        string $method,
        array $advice = [],
        array $parameterNames = [],
    ): AopPlan {
        $key = $class . '::' . $method;

        if (isset(self::$plans[$namespace][$key])) {
            return self::$plans[$namespace][$key];
        }

        return self::$plans[$namespace][$key] = self::build($namespace, $key, $advice, $parameterNames);
    }

    /**
     * @param array<class-string<Advice>, array<string, mixed>> $advice
     * @param list<string>                                      $parameterNames
     */
    private static function build(
        string $namespace,
        string $key,
        array $advice,
        array $parameterNames,
    ): AopPlan {
        $interceptors = self::$interceptors[$namespace] ?? [];
        $chain        = [];
        $instances    = [];

        foreach ($advice as $adviceClass => $arguments) {
            if (!isset($interceptors[$adviceClass])) {
                throw new \LogicException(
                    "No interceptor registered for advice [{$adviceClass}] on {$key} (namespace '{$namespace}'). "
                    . 'Register one via AppContext::registerInterceptor() / ProxyFactory::register() before the advised method is called.'
                );
            }

            $chain[] = $interceptors[$adviceClass];

            /** @var Advice $instance Constructed from the arguments baked into the proxy. */
            $instance = new $adviceClass(...$arguments);
            $instances[$adviceClass] = $instance;
        }

        return new AopPlan(new Pipeline($chain), $instances, $parameterNames);
    }
}
