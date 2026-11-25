<?php

namespace Azera\Aop;

/**
 * An immutable, process-local bundle of everything one advised method needs per call.
 *
 * Built once per (class, method) by {@see AopRuntime::plan()} and shared by reference,
 * so generated proxies never re-read attributes or rebuild a chain per call.
 *
 * It lives in its own file because PSR-4 resolves `Azera\Aop\AopPlan` to
 * `src/Aop/AopPlan.php`: a second class inside `AopRuntime.php` is only reachable
 * once that file happens to be loaded, which fails for any direct reference
 * (tests, diagnostics, a tool that only touches the plan).
 *
 * @internal Not part of the public API.
 */
final class AopPlan
{
    /**
     * @param array<class-string<Advice>, Advice> $advice
     * @param list<string>                        $parameterNames
     */
    public function __construct(
        public readonly Pipeline $pipeline,
        public readonly array $advice,
        public readonly array $parameterNames = [],
    ) {
    }
}
