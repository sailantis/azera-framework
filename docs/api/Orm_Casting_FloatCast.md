# Class: FloatCast

**Full name:** [Azera\Orm\Casting\FloatCast](../../src/Orm/Casting/FloatCast.php)

Scalar float cast — see IntCast for the shared rationale, including the
strict-decode policy: `(float) 'abc'` is 0.0, which is indistinguishable
from a real 0.0, so a non-numeric value throws instead.

## How each driver returns ZERO (measured 2026-09-19)

| driver                      | int col      | float col    |
| --------------------------- | ------------ | ------------ |
| sqlite                      | `int 0`      | `float 0.0`  |
| pgsql (always text)         | `'0'`        | `'0'`        |
| mysql, emulated prepares    | `'0'`        | `'0'`        |
| mysql, native prepares      | `int 0`      | `float 0.0`  |
| mysql, DECIMAL/FLOAT col    | `'0.00'`     | `'0.00'`     |

sqlite is never stringified — it hands over real PHP int/float, so the
`(float)` coercion is essentially free on the common local path.
mysql only returns typed values with native prepares ON mysqlnd AND
`PDO::ATTR_EMULATE_PREPARES => false`; the pdo_mysql DEFAULT is
emulated, and the framework does not override it, so in practice mysql
arrives as strings just like pgsql.

The `$result !== 0.0` shortcut sends every NON-zero value straight back
(one comparison, the common case) and lets ZERO fall through to the
form-validating chain below. That is what makes a zero-literal
allowlist unnecessary: `'0'`, `'0.00'`, `'0e0'`, `'.0'`, `'0.'` and
`' 0 '` are all reachable depending on driver and column type, and
is_numeric() covers every spelling at once. The `'0.0'` / `0.0`
comparisons are not the validation — the chain below is.

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/FloatCast.php#L41)</small>

`public function encode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/FloatCast.php#L46)</small>

`public function decode(mixed $value): float|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `float`|`null`



---

[Back to the Index ⤴](README.md)
