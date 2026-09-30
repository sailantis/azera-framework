# Class: Cache

**Full name:** [Azera\Aop\Cache](../../src/Aop/Cache.php)

Marks a method's return value as cacheable.

The [`CacheInterceptor`](Aop_CacheInterceptor.md) stores the method's return value in the
cache under a computed key. On subsequent calls, the cached value is
returned without executing the method.

The key is derived from the method name and arguments by default.
A custom key template can be provided with `{argName}` placeholders.

Example:
```php
#[Advised]
class UserService
{
    #[Cache(ttl: 300)]
    public function getProfile(int $userId): Profile { ... }

    #[Cache(ttl: 600, key: 'user_{userId}_profile')]
    public function loadProfile(int $userId): Profile { ... }
}
```

## Public Properties

- `public readonly` int|null `$ttl` · <small>[🗎](../../src/Aop/Cache.php)</small>
- `public readonly` string|null `$key` · <small>[🗎](../../src/Aop/Cache.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Cache.php#L31)</small>

`public function __construct(int|null $ttl = null, string|null $key = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ttl` | int\|null | `null` |  |
| `$key` | string\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
