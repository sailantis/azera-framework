<?php

namespace Azera\Aop;

/**
 * The explicit AOP entry point: wrap a callable in a chain of interceptors.
 *
 * A Pipeline is immutable and its chain is composed **once**, in the constructor,
 * as a right fold over the interceptors. `handle()` performs no closure allocation:
 * every frame of the chain is built ahead of time and captures only the interceptor
 * instance and the next frame. `call()` short-circuits to a plain variadic call when
 * no interceptors are registered.
 *
 * Generated proxies use the same mechanism: advice is resolved once per process by
 * {@see AopRuntime}, which memoizes one precomposed Pipeline per advised (class, method).
 *
 * Example:
 * ```php
 * $pipeline = new Pipeline([new RetryInterceptor()]);
 * $result   = $pipeline->call(fn() => $service->chargeCard(100));
 *
 * // Short form
 * $result = Pipeline::wrap([new RetryInterceptor()], fn() => $service->chargeCard(100));
 * ```
 *
 * Explicit calls carry no advice attributes. Interceptors that support attribute
 * configuration fall back to their constructor defaults here; use the transparent
 * (attribute + proxy) layer when the advice must be declared on the method.
 */
class Pipeline
{
    /**
     * Attribute key carrying the wrapped callable inside an Invocation.
     *
     * The stored callable is invoked variadically — `$terminal(...$invocation->args)` —
     * and element references in `$invocation->args` survive the spread because PHP passes
     * reference-elements by reference into the callee's parameters. `call()` stores the
     * user callable as-is; generated proxies install a by-reference variadic terminal
     * closure per call so by-ref parameters on the advised method stay live end to end.
     *
     * @internal Directly relevant only to generated code; public so generated classes
     *           belonging to no namespace can reference it.
     */
    public const ATTR_CALLABLE = 'azera.aop.callable';

    /** @var \Closure(Invocation): mixed Precomposed chain; built once. */
    private \Closure $chain;

    /** @var list<InterceptorInterface> */
    private readonly array $interceptors;

    /**
     * @param list<InterceptorInterface> $interceptors Outermost first.
     */
    public function __construct(array $interceptors = [])
    {
        foreach ($interceptors as $interceptor) {
            if (!$interceptor instanceof InterceptorInterface) {
                throw new \InvalidArgumentException(
                    'Pipeline interceptors must implement ' . InterceptorInterface::class . '.'
                );
            }
        }

        $this->interceptors = array_values($interceptors);
        $this->chain        = self::compose($this->interceptors);
    }

    /**
     * Compose the chain once. Each frame is a static closure capturing only the
     * interceptor and the frame beneath it, so invoking the chain allocates nothing.
     *
     * The innermost frame invokes the wrapped callable carried on the Invocation under
     * {@see Pipeline::ATTR_CALLABLE}.
     *
     * @param list<InterceptorInterface> $interceptors
     * @return \Closure(Invocation): mixed
     */
    private static function compose(array $interceptors): \Closure
    {
        $next = static function (Invocation $invocation): mixed {
            /** @var callable $terminal variadic: invoked with the invocation's args */
            $terminal = $invocation->attributes[self::ATTR_CALLABLE];
            return $terminal(...$invocation->args);
        };

        for ($i = count($interceptors) - 1; $i >= 0; $i--) {
            $interceptor = $interceptors[$i];
            $next        = static fn(Invocation $invocation): mixed => $interceptor->intercept($invocation, $next);
        }

        return $next;
    }

    /** @return list<InterceptorInterface> */
    public function interceptors(): array
    {
        return $this->interceptors;
    }

    /**
     * Add interceptors, returning a new pipeline.
     *
     * `Pipeline` is immutable and composes its chain in the constructor, so this
     * cannot mutate the receiver: it returns a new instance whose chain is the
     * receiver's interceptors followed by `$interceptors` — the same ordering the
     * old mutable fluent method produced.
     *
     * Retained for 0.6 callers that wrote
     * `(new Pipeline([$a]))->through([$b])->call(...)`. Prefer passing the whole
     * list to the constructor.
     *
     * @deprecated Pass every interceptor to the constructor instead.
     * @param list<InterceptorInterface> $interceptors Appended after the existing ones.
     */
    public function through(array $interceptors): static
    {
        return new static([...$this->interceptors, ...$interceptors]);
    }

    /**
     * Run an already-built invocation through the chain.
     *
     * Used by generated proxies, whose generated code builds the Invocation inline.
     */
    public function handle(Invocation $invocation): mixed
    {
        return ($this->chain)($invocation);
    }

    /**
     * Invoke `$callable` through the chain.
     *
     * With no interceptors registered nothing can observe an Invocation, an identity
     * or an advice map, so this short-circuits to a plain variadic call: no Invocation
     * allocation, no attribute-bag write, no chain frame, no reflection. Call sites
     * that take an interceptor list from configuration pay only for the call itself.
     *
     * @param callable(mixed ...): mixed $callable
     * @param array<int, mixed>          $args       Positional arguments.
     * @param string|null                $identity   Stable name for the unit. Supply it to skip
     *                                              derivation for closures.
     * @param array<class-string<Advice>, Advice> $advice Advice to expose on the invocation.
     */
    public function call(callable $callable, array $args = [], ?string $identity = null, array $advice = []): mixed
    {
        if ($this->interceptors === []) {
            return $callable(...$args);
        }

        $invocation = new Invocation(
            target: is_object($callable) ? $callable : null,
            method: null,
            identity: $identity ?? self::identify($callable),
            args: $args,
            advice: $advice,
        );
        $invocation->attributes[self::ATTR_CALLABLE] = $callable;

        return ($this->chain)($invocation);
    }

    /**
     * Static shortcut: wrap a callable with interceptors and call it.
     *
     * @param list<InterceptorInterface> $interceptors
     * @param callable(mixed ...): mixed $callable
     */
    public static function wrap(array $interceptors, callable $callable, array $args = [], ?string $identity = null): mixed
    {
        return (new self($interceptors))->call($callable, $args, $identity);
    }

    /**
     * Best-effort stable name for a wrapped callable.
     *
     * Closures are identified by declaration site (`file:line`) — stable across calls
     * within a deployment and distinct between closures. String and array callables use
     * `Class::method`; other invokables use their class name.
     */
    private static function identify(callable $callable): string
    {
        if ($callable instanceof \Closure) {
            $ref = new \ReflectionFunction($callable);

            return $ref->getFileName() . ':' . $ref->getStartLine();
        }

        if (is_string($callable)) {
            return $callable;
        }

        if (is_array($callable) && count($callable) === 2) {
            [$owner, $method] = $callable;

            return (is_object($owner) ? $owner::class : (string) $owner) . '::' . $method;
        }

        if (is_object($callable)) {
            return $callable::class . '::__invoke';
        }

        return 'callable';
    }
}
