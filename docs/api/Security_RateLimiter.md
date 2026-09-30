# Class: RateLimiter

**Full name:** [Azera\Security\RateLimiter](../../src/Security/RateLimiter.php)

Simple, cache-backed rate limiter.

Each call to `limit()` records a hit for the given key and
returns whether the caller is still within the configured budget.
Storage is delegated to a `CacheInterface` instance so the
limiter works with [`ArrayCache`](Cache_ArrayCache.md) in development
and a distributed cache (Redis, Memcached) in production.

The limiter uses a fixed time-window strategy: a counter is stored
with a TTL equal to the window size and incremented on every hit.
When the entry expires the window resets automatically.

## Public methods

### __construct() · <small>[🗎](../../src/Security/RateLimiter.php#L27)</small>

`public function __construct(Psr\SimpleCache\CacheInterface $cache): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$cache` | Psr\SimpleCache\CacheInterface | - | The cache used to persist counters. |

**Return value**

- Type: `mixed`


---

### limit() · <small>[🗎](../../src/Security/RateLimiter.php#L42)</small>

`public function limit(string $key, int $max, int $perSeconds): bool`

Record a hit for `$key` and report whether the limit is exceeded.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Identifier for the rate-limited resource<br>(e.g. `ip:127.0.0.1`, `login:user@example.com`). |
| `$max` | int | - | Maximum number of hits allowed within the<br>window. |
| `$perSeconds` | int | - | Size of the sliding window in seconds. |

**Return value**

- Type: `bool`
- Description: True if the request is within the limit (allowed),<br>false if the limit has been exceeded (deny).


---

### hits() · <small>[🗎](../../src/Security/RateLimiter.php#L65)</small>

`public function hits(string $key): int`

Get the number of hits recorded for `$key` within the current
window, or 0 when no entry exists.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |

**Return value**

- Type: `int`


---

### isLimited() · <small>[🗎](../../src/Security/RateLimiter.php#L78)</small>

`public function isLimited(string $key, int $max): bool`

Check whether `$key` has reached its limit without recording a
new hit.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Identifier for the rate-limited resource. |
| `$max` | int | - | Maximum number of hits allowed. |

**Return value**

- Type: `bool`


---

### reset() · <small>[🗎](../../src/Security/RateLimiter.php#L86)</small>

`public function reset(string $key): void`

Reset the counter for `$key`, clearing any recorded hits.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
