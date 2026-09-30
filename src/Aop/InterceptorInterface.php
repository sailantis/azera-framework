<?php

namespace Azera\Aop;

use ReflectionMethod;

/**
 * Contract for interceptors that wrap advised methods.
 *
 * An interceptor receives the target object, the reflection method being
 * intercepted, the method arguments, and a `$next` callable that invokes
 * the next interceptor in the chain (or the actual method if this is the
 * last interceptor). Interceptors can:
 *
 * - Run code before calling `$next()`
 * - Modify the arguments before calling `$next()`
 * - Short-circuit by returning a value without calling `$next()`
 * - Run code after `$next()` returns (post-processing)
 * - Catch/transform exceptions thrown by `$next()`
 *
 * Example:
 * ```php
 * class TransactionalInterceptor implements InterceptorInterface
 * {
 *     public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed
 *     {
 *         $db->begin();
 *         try {
 *             $result = $next($args);
 *             $db->commit();
 *             return $result;
 *         } catch (\Throwable $e) {
 *             $db->rollback();
 *             throw $e;
 *         }
 *     }
 * }
 * ```
 */
interface InterceptorInterface
{
    /**
     * Intercept a method invocation.
     *
     * @param object           $target  The proxy-wrapped target object.
     * @param ReflectionMethod $method  The reflection method being intercepted.
     * @param array            $args    The method arguments (may be modified).
     * @param callable         $next    Callable that invokes the next handler.
     *   Receives the (possibly modified) args array and returns the method result.
     * @return mixed The method result (or a replacement value).
     */
    public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed;
}