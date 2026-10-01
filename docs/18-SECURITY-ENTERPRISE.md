# Security (Enterprise)

CSRF protection, rate limiting, password hashing, and authentication contracts. All components are opt-in — zero cost when not wired.

## CSRF Protection

`Azera\Security\CsrfMiddleware` implements `MiddlewareInterface` using the synchronizer (double-submit) token pattern.

### How it works

1. A random token is generated and stored in the session
2. For state-changing requests (POST, PUT, PATCH, DELETE), the middleware validates the submitted token against the session token
3. The token can be submitted via the POST body, `X-CSRF-Token` header, or a cookie (double-submit fallback)
4. Validation uses `hash_equals()` for timing-safe comparison
5. On mismatch, a 419 response is returned

### Registering

```php
use Azera\Security\CsrfMiddleware;

// Add to the global middleware pipeline
$dispatcher->addMiddleware(new CsrfMiddleware());
```

### Getting the token in views

```php
$session = $ctx->session();
$token = (new CsrfMiddleware())->ensureToken($session);
// Pass $token to your view as 'csrf_token'
```

### Submitting the token

```html
<form method="POST" action="/delete">
    <input type="hidden" name="_csrf_token" value="{{ csrf_token }}">
    <button type="submit">Delete</button>
</form>
```

Or via header (for AJAX):

```javascript
fetch('/api/update', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(data),
});
```

### Configuration

```php
// Custom token name (field/header/cookie name)
new CsrfMiddleware(tokenName: 'csrf_token')

// Custom guarded methods
new CsrfMiddleware(guardedMethods: ['POST', 'PUT', 'DELETE'])
```

### Without a session

When no session is available, the middleware fails closed — state-changing requests are denied with 419, safe requests (GET, HEAD, OPTIONS) pass through.

## Rate Limiter

`Azera\Security\RateLimiter` stores counters in any PSR-16 `CacheInterface` — `ArrayCache` in development, Redis/Memcached in production.

### Usage

```php
use Azera\Security\RateLimiter;

$limiter = new RateLimiter($ctx->cache());

// Allow max 5 requests per 60 seconds per IP
if (!$limiter->limit('login:' . $ip, 5, 60)) {
    return Response::json(['error' => 'Too many attempts'], 429);
}
```

### Methods

| Method | Description |
|---|---|
| `limit($key, $max, $perSeconds)` | Record a hit and return `true` if within limit, `false` if exceeded |
| `hits($key)` | Get the current hit count for the key |
| `isLimited($key, $max)` | Check if the key has reached its limit (without recording a hit) |
| `reset($key)` | Reset the counter for the key |

### Important: persistent cache required

`ArrayCache` is per-process, so rate limiting only works within a single PHP process. For multi-process (PHP-FPM) or multi-server rate limiting, use a persistent PSR-16 cache (Redis, Memcached):

```php
use Psr\SimpleCache\CacheInterface;

$ctx->set(CacheInterface::class, fn() => new RedisCache($redis));
```

## Password Hashing

`Azera\Security\Hasher` wraps PHP's native `password_hash()` / `password_verify()` with configurable algorithm and cost.

### Usage

```php
use Azera\Security\Hasher;

$hasher = new Hasher();

// Hash a password
$hash = $hasher->make('p@ssw0rd');

// Verify
if ($hasher->verify($password, $user->password_hash)) {
    // Login successful
}

// Check if hash needs upgrading (e.g. cost was increased)
if ($hasher->needsRehash($user->password_hash)) {
    $user->password_hash = $hasher->make($password);
    $user->save();
}

// Generate a random token (e.g. for API keys, reset tokens)
$token = $hasher->token(32); // 64-character hex string
```

### Configuration

```php
// Custom algorithm and cost
$hasher = new Hasher(
    algo: PASSWORD_BCRYPT,
    options: ['cost' => 12],
);
```

## Authentication Contracts

Azera provides interface contracts for authentication. Concrete guards (session, token, JWT) live in the companion `azera/auth` package.

### GuardInterface

A guard handles a single authentication strategy:

```php
interface GuardInterface
{
    public function attempt(array $credentials): bool;
    public function check(): bool;
    public function user(): mixed;
    public function id(): mixed;
    public function logout(): void;
}
```

### AuthManagerInterface

The manager is a registry of named guards:

```php
interface AuthManagerInterface
{
    public function addGuard(string $name, GuardInterface $guard): void;
    public function guard(?string $name = null): GuardInterface;
    public function currentGuard(): GuardInterface;
    public function attempt(array $credentials): bool;
    public function logout(): void;
    public function check(): bool;
    public function id(): mixed;
}
```

### Implementing a guard

```php
class SessionGuard implements GuardInterface
{
    public function __construct(
        private Session $session,
        private Hasher $hasher,
    ) {}

    public function attempt(array $credentials): bool
    {
        $user = User::findByEmail($credentials['email']);
        if ($user && $this->hasher->verify($credentials['password'], $user->password_hash)) {
            $this->session->set('user_id', $user->id);
            return true;
        }
        return false;
    }

    public function check(): bool
    {
        return $this->session->has('user_id');
    }

    public function user(): mixed
    {
        return $this->session->has('user_id')
            ? User::find($this->session->get('user_id'))
            : null;
    }

    public function id(): mixed
    {
        return $this->session->get('user_id');
    }

    public function logout(): void
    {
        $this->session->remove('user_id');
    }
}
```

### Existing middleware

Applications with existing auth middleware (e.g. `PhpThunderAuthMiddleware`, `SupportAgentAuthMiddleware`) can adopt `GuardInterface` later without breaking changes — the contract is additive.