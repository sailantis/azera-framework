# Class: Tx

**Full name:** [Azera\Facade\Tx](../../src/Facade/Tx.php)

Thin static proxy for transactions over the SQL connection registry.

Plain static methods; no magic. Complements Db::transaction() with
explicit begin/commit/rollback handles when the callback shape doesn't
fit.

## Public methods

### begin() · <small>[🗎](../../src/Facade/Tx.php#L16)</small>

`public static function begin(string|null $role = null): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string\|null | `null` |  |

**Return value**

- Type: `void`


---

### commit() · <small>[🗎](../../src/Facade/Tx.php#L21)</small>

`public static function commit(string|null $role = null): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string\|null | `null` |  |

**Return value**

- Type: `void`


---

### rollback() · <small>[🗎](../../src/Facade/Tx.php#L26)</small>

`public static function rollback(string|null $role = null): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string\|null | `null` |  |

**Return value**

- Type: `void`


---

### level() · <small>[🗎](../../src/Facade/Tx.php#L31)</small>

`public static function level(string|null $role = null): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string\|null | `null` |  |

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
