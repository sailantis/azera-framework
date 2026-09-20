# 🧩 Class: FastFloatCast

**Full name:** [Azera\Orm\Casting\FastFloatCast](../../src/Orm/Casting/FastFloatCast.php)

Scalar float cast — the FAST, NON-STRICT sibling of [`FloatCast`](Orm_Casting_FloatCast.md).

Drop-in replacement for applications that prefer raw speed over the
strict-decode contract. Register it to override the built-in in a
composition root, BEFORE the first Metadata::for() of the affected
classes:

    Casts::register('float', new FastFloatCast());

FastHydrator compiles the decode plan per class ONCE, so a later
registration additionally needs Metadata::clear() +
FastHydrator::clear() for already-compiled classes (see
[`Casts::register()`](Orm_Casting_Casts.md#register)).

Differences from FloatCast:

- decode() coerces with a bare `(float)` — no form validation.
  `'abc'` decodes to 0.0, exactly like pre-strict FloatCast.
- Roughly 40-50% faster per value (measured 2026-09-19: 59ns vs
  93ns for a decimal string) at the cost of silent zeros on corrupt
  data — the corruption class FloatCast exists to reject.

Registered by NOTHING by default — the built-in 'float' is the
strict FloatCast. This class only becomes active through an
explicit register().

## 🚀 Public methods

### encode() · [source](../../src/Orm/Casting/FastFloatCast.php#L36)

`public function encode(mixed $value): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**➡️ Return value**

- Type: mixed


---

### decode() · [source](../../src/Orm/Casting/FastFloatCast.php#L41)

`public function decode(mixed $value): float|null`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**➡️ Return value**

- Type: float|null



---

[Back to the Index ⤴](README.md)
