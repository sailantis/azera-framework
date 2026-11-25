<?php

namespace Azera\Aop;

/**
 * The single interceptor contract.
 *
 * One implementation serves both the explicit pipeline and the generated proxies:
 * there is no separate reflection-based flavour to drift from.
 *
 * Implementations may:
 *
 * - run code before calling `$next`,
 * - rewrite the arguments by assigning `$invocation->args = [...]` before calling `$next`,
 * - short-circuit by returning a value without calling `$next`,
 * - run code after `$next` returns,
 * - catch or replace exceptions thrown by `$next`.
 *
 * Read the advice for this invocation with `$invocation->advice(MyAdvice::class)`.
 * In explicit mode that returns null and the constructor configuration applies.
 *
 * Example:
 * ```php
 * final class RetryInterceptor implements InterceptorInterface
 * {
 *     public function intercept(Invocation $invocation, callable $next): mixed
 *     {
 *         $advice = $invocation->advice(Retry::class);
 *         $times  = $advice?->times ?? $this->defaultTimes;
 *
 *         return $next($invocation);
 *     }
 * }
 * ```
 */
interface InterceptorInterface
{
    /**
     * @param Invocation                  $invocation The invocation being intercepted.
     * @param callable(Invocation): mixed $next       Invokes the next interceptor, or the underlying call.
     */
    public function intercept(Invocation $invocation, callable $next): mixed;
}
