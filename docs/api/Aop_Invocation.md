# Class: Invocation

**Full name:** [Azera\Aop\Invocation](../../src/Aop/Invocation.php)

The single invocation object passed through an interceptor chain.

The transparent (generated proxy) path builds one of these per call from baked
constants. `args` is `array` but its elements may be references, which is how
by-reference parameters of the wrapped callable stay live through the chain.

Chain semantics: `args` is updated in place as the chain descends; interceptors
that want to rewrite parameters assign to `$invocation->args` or mutate it in place.
Element references are preserved only as long as they are carried over —
assigning a fresh array to `$invocation->args` drops them, exactly as it would
for a plain PHP array. `attributes` is scratch space shared by every interceptor
for this invocation. The advice map is baked in by the proxy and read-only in effect.

## Public Properties

- `public` array `$attributes` · <small>[🗎](../../src/Aop/Invocation.php)</small>
- `public readonly` object|string|null `$target` · <small>[🗎](../../src/Aop/Invocation.php)</small>
- `public readonly` string|null `$method` · <small>[🗎](../../src/Aop/Invocation.php)</small>
- `public readonly` string `$identity` · <small>[🗎](../../src/Aop/Invocation.php)</small>
- `public` array `$args` · <small>[🗎](../../src/Aop/Invocation.php)</small>
- `public readonly` array `$parameterNames` · <small>[🗎](../../src/Aop/Invocation.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Invocation.php#L38)</small>

`public function __construct(object|string|null $target, string|null $method, string $identity, array $args, array $advice = [], array $parameterNames = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$target` | object\|string\|null | - | The advised object in transparent mode; a label or class<br>name in explicit mode; null when unknown. |
| `$method` | string\|null | - | The advised method name, or null in explicit mode. |
| `$identity` | string | - | Stable name for the unit: `Class::method` for proxies,<br>`file:line` for closures, or a caller-supplied label. |
| `$args` | array | - | Arguments, references preserved. Positional. |
| `$advice` | array | `[]` | Advice baked in at build time. Empty in explicit mode. |
| `$parameterNames` | array | `[]` | Parameter names in order, when known (generated proxies bake these in). Empty for explicit calls. |

**Return value**

- Type: `mixed`


---

### advice() · <small>[🗎](../../src/Aop/Invocation.php#L58)</small>

`public function advice(string $class): Azera\Aop\Advice|null`

Advice of a given class baked into this invocation, or null.

Explicit calls carry no advice at all, so interceptors that support both
modes read this and fall back to their constructor configuration.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |

**Return value**

- Type: [Advice](Aop_Advice.md)|`null`


---

### advices() · <small>[🗎](../../src/Aop/Invocation.php#L65)</small>

`public function advices(): array`

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
