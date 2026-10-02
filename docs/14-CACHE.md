# Cache

PSR-16 compatible caching through `AppContext::cache()` for arbitrary serializable values with an optional TTL.

## PSR-16 Interface

Azera uses the PSR-16 interface directly from the `psr/simple-cache` package:

- `\Psr\SimpleCache\CacheInterface` — `get`, `set`, `delete`, `clear`, `getMultiple`, `setMultiple`, `deleteMultiple`, `has`
- `\Psr\SimpleCache\InvalidArgumentException` — thrown on invalid keys

No adapter is needed. Any PSR-16 implementation — Symfony Cache, Laminas Cache, Redis adapters — works as a drop-in.

## The `cache()` accessor

`AppContext::cache()` returns a `CacheInterface`. When none is registered, it returns a `NullCache` that always reports a miss — so `$ctx->cache()->get('key', $default)` always returns `$default`:

```php
$value = $ctx->cache()->get('user.profile.' . $id, null);
```

Register a real cache:

```php
use Azera\Cache\ArrayCache;
use Psr\SimpleCache\CacheInterface;

// Development: in-process array cache
$ctx->set(CacheInterface::class, fn() => new ArrayCache());

// Production: Redis, Memcached, or any PSR-16 impl
// $ctx->set(CacheInterface::class, fn() => new RedisCache($redis));
```

## Implementations

### ArrayCache

In-process cache backed by a PHP array. Useful for development, testing, and short-lived processes.

- Values stored with an expiry timestamp (or `null` for no expiry)
- No serialization overhead — values kept as live references
- TTL: `int` (seconds), `DateInterval`, or `null` (no expiry)
- Keys validated: non-empty, alphanumeric + `._-`, max 64 chars

```php
use Azera\Cache\ArrayCache;

$cache = new ArrayCache();
$cache->set('key', 'value', 300); // TTL: 300 seconds
$value = $cache->get('key', 'default');
$cache->delete('key');
$cache->clear();
```

### NullCache

No-op cache that stores nothing and always reports a miss. `set()` returns `true`, `get()` returns the default, `has()` returns `false`.

This is the default returned by `AppContext::cache()` when no cache is registered.

### InvalidArgumentException

`Azera\Cache\InvalidArgumentException` extends `\InvalidArgumentException` and implements `\Psr\SimpleCache\InvalidArgumentException`. Thrown when a cache key is empty or contains invalid characters:

```php
use Azera\Cache\ArrayCache;
use Azera\Cache\InvalidArgumentException;

$cache = new ArrayCache();
try {
    $cache->get('invalid key with spaces');
} catch (InvalidArgumentException $e) {
    // Keys must be alphanumeric + ._-
}
```

## Used By

- `#[Cache]` AOP advice caches method return values through `AppContext::cache()` — see [AOP](16-AOP.md).
- `RateLimiter` stores its counters in any `CacheInterface`; use a persistent backend (Redis/Memcached) for multi-process rate limiting — see [Security (Enterprise)](18-SECURITY-ENTERPRISE.md).
