# Class: NullCache

**Full name:** [Azera\Cache\NullCache](../../src/Cache/NullCache.php)

No-op cache that stores nothing and always reports a miss.

This is the default cache returned by [`AppContext::cache()`](AppContext.md#cache)
when no concrete cache has been registered. It allows calling code to
safely use `$ctx->cache()->get('key', $default)` and always receive
the default, without null-checks.

## Public methods

### get() · <small>[🗎](../../src/Cache/NullCache.php#L17)</small>

`public function get(string $key, mixed $default = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `mixed`


---

### set() · <small>[🗎](../../src/Cache/NullCache.php#L22)</small>

`public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |
| `$value` | mixed | - |  |
| `$ttl` | DateInterval\|int\|null | `null` |  |

**Return value**

- Type: `bool`


---

### delete() · <small>[🗎](../../src/Cache/NullCache.php#L27)</small>

`public function delete(string $key): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |

**Return value**

- Type: `bool`


---

### clear() · <small>[🗎](../../src/Cache/NullCache.php#L32)</small>

`public function clear(): bool`

**Return value**

- Type: `bool`


---

### getMultiple() · <small>[🗎](../../src/Cache/NullCache.php#L37)</small>

`public function getMultiple(iterable $keys, mixed $default = null): iterable`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keys` | iterable | - |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `iterable`


---

### setMultiple() · <small>[🗎](../../src/Cache/NullCache.php#L46)</small>

`public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | iterable | - |  |
| `$ttl` | DateInterval\|int\|null | `null` |  |

**Return value**

- Type: `bool`


---

### deleteMultiple() · <small>[🗎](../../src/Cache/NullCache.php#L51)</small>

`public function deleteMultiple(iterable $keys): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keys` | iterable | - |  |

**Return value**

- Type: `bool`


---

### has() · <small>[🗎](../../src/Cache/NullCache.php#L56)</small>

`public function has(string $key): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
