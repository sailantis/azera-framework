<?php

namespace Azera\Aop;

/**
 * The single invocation object passed through an interceptor chain.
 *
 * The transparent (generated proxy) path builds one of these per call from baked
 * constants. `args` is `array` but its elements may be references, which is how
 * by-reference parameters of the wrapped callable stay live through the chain.
 *
 * Chain semantics: `args` is updated in place as the chain descends; interceptors
 * that want to rewrite parameters assign to `$invocation->args` or mutate it in place.
 * Element references are preserved only as long as they are carried over —
 * assigning a fresh array to `$invocation->args` drops them, exactly as it would
 * for a plain PHP array. `attributes` is scratch space shared by every interceptor
 * for this invocation. The advice map is baked in by the proxy and read-only in effect.
 */
final class Invocation
{
    /**
     * Free-form inter-interceptor scratch space (start times, attempt counters, ids).
     *
     * @var array<string, mixed>
     */
    public array $attributes = [];

    /**
     * @param object|string|null $target   The advised object in transparent mode; a label or class
     *                                     name in explicit mode; null when unknown.
     * @param string|null         $method  The advised method name, or null in explicit mode.
     * @param string              $identity Stable name for the unit: `Class::method` for proxies,
     *                                     `file:line` for closures, or a caller-supplied label.
     * @param array               $args     Arguments, references preserved. Positional.
     * @param array<class-string<Advice>, Advice> $advice Advice baked in at build time. Empty in explicit mode.
     * @param list<string>        $parameterNames Parameter names in order, when known (generated proxies bake these in). Empty for explicit calls.
     */
    public function __construct(
        public readonly object|string|null $target,
        public readonly ?string $method,
        public readonly string $identity,
        public array $args,
        private readonly array $advice = [],
        public readonly array $parameterNames = [],
    ) {
    }

    /**
     * Advice of a given class baked into this invocation, or null.
     *
     * Explicit calls carry no advice at all, so interceptors that support both
     * modes read this and fall back to their constructor configuration.
     *
     * @template T of Advice
     * @param class-string<T> $class
     * @return T|null
     */
    public function advice(string $class): ?Advice
    {
        $found = $this->advice[$class] ?? null;
        return $found instanceof $class ? $found : null;
    }

    /** @return array<class-string<Advice>, Advice> */
    public function advices(): array
    {
        return $this->advice;
    }
}
