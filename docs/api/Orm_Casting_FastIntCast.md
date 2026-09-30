# Class: FastIntCast

**Full name:** [Azera\Orm\Casting\FastIntCast](../../src/Orm/Casting/FastIntCast.php)

Scalar int cast — the FAST, NON-STRICT sibling of [`IntCast`](Orm_Casting_IntCast.md).

Drop-in replacement for applications that prefer raw speed over the
strict-decode contract. Register it to override the built-in in a
composition root, BEFORE the first Metadata::for() of the affected
classes:

    Casts::register('int', new FastIntCast());

FastHydrator compiles the decode plan per class ONCE, so a later
registration additionally needs Metadata::clear() +
FastHydrator::clear() for already-compiled classes (see
[`Casts::register()`](Orm_Casting_Casts.md#register)).

Differences from IntCast:

- decode() coerces with a bare `(int)` — no form validation. `'abc'`
  decodes to 0, `'12.9'` to 12, exactly like pre-strict IntCast.
- Roughly 30-55% faster per value (measured 2026-09-19: 55ns vs
  86ns for a digit string) at the cost of silent zeros on corrupt
  data — the corruption class IntCast exists to reject.

Registered by NOTHING by default — the built-in 'int' is the strict
IntCast. This class only becomes active through an explicit register().

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/FastIntCast.php#L35)</small>

`public function encode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/FastIntCast.php#L40)</small>

`public function decode(mixed $value): int|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `int`|`null`



---

[Back to the Index ⤴](README.md)
