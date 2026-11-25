# Class: AopPlan

**Full name:** [Azera\Aop\AopPlan](../../src/Aop/AopPlan.php)

An immutable, process-local bundle of everything one advised method needs per call.

Built once per (class, method) by [`AopRuntime::plan()`](Aop_AopRuntime.md#plan) and shared by reference,
so generated proxies never re-read attributes or rebuild a chain per call.

It lives in its own file because PSR-4 resolves `Azera\Aop\AopPlan` to
`src/Aop/AopPlan.php`: a second class inside `AopRuntime.php` is only reachable
once that file happens to be loaded, which fails for any direct reference
(tests, diagnostics, a tool that only touches the plan).

## Public Properties

- `public readonly` [Pipeline](Aop_Pipeline.md) `$pipeline` · <small>[🗎](../../src/Aop/AopPlan.php)</small>
- `public readonly` array `$advice` · <small>[🗎](../../src/Aop/AopPlan.php)</small>
- `public readonly` array `$parameterNames` · <small>[🗎](../../src/Aop/AopPlan.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Aop/AopPlan.php#L24)</small>

`public function __construct(Azera\Aop\Pipeline $pipeline, array $advice, array $parameterNames = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pipeline` | [Pipeline](Aop_Pipeline.md) | - |  |
| `$advice` | array | - |  |
| `$parameterNames` | array | `[]` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
