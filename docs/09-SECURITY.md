# Security

Building blocks for secure applications: parameterised queries, authenticated cookie encryption, and safe cookie handling. The remaining measures — output escaping, CSRF, password hashing — are documented per feature; Clarity templates auto-escape (see [Clarity Engine](03b-CLARITY-ENGINE.md#security-sandbox)), plain PHP templates do not.

## SQL Injection Protection

### Bound parameters (preferred)

Values passed through `->bind()` become real PDO named parameters and are never interpolated into SQL.

```php
User::query()
    ->where('email = :email')
    ->bind(['email' => $email])
    ->first();
```

### Inline values

Values passed directly to `where()` as an array are escaped with PDO's quoting mechanism and inlined as string literals. They are safe against injection but are **not** real bound parameters.

```php
User::query()
    ->where('email = :email', ['email' => $email])
    ->first();
```

Use `->bind()` for all user-supplied input. Reserve inline values for internally constructed fragments where binding is not practical.

### Raw SQL escape hatch

`Sql::raw()` and plain string arguments to query methods inject SQL verbatim. **Never** interpolate user input through these APIs.

```php
// UNSAFE – never do this
$col = $_GET['sort'];
User::query()->orderBy(Sql::raw($col));

// Safe alternative – validate against an allowlist first
$allowed = ['name', 'email', 'created_at'];
$col = in_array($_GET['sort'], $allowed, true) ? $_GET['sort'] : 'name';
User::query()->orderBy($col);
```

## XSS – Output Escaping

The default `ClarityEngine` auto-escapes every `{{ }}` output. `NativeEngine` renders plain PHP templates and does **not** escape — you are responsible for escaping every dynamic value before printing it:

```php
<!-- views/profile/show.php -->
<h1><?= htmlspecialchars($user->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
<p><?= htmlspecialchars($user->bio,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
```

A small global helper keeps templates readable:

```php
// bootstrap or a helpers file
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

```php
<h1><?= e($user->name) ?></h1>
```

Never print raw request input, database values, or any externally sourced string without escaping.

## CSRF Protection

Azera has no built-in CSRF middleware, so you must implement token-based protection for all state-changing forms. A straightforward pattern using the session:

```php
function csrf_token(): string
{
    $session = AppContext::instance()->session();
    if (!$session->has('csrf_token')) {
        $session->set('csrf_token', bin2hex(random_bytes(32)));
    }
    return $session->get('csrf_token');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $session  = AppContext::instance()->session();
    $expected = $session->get('csrf_token');
    $provided = AppContext::instance()->request()->post('_csrf', '');

    if (!$expected || !hash_equals($expected, $provided)) {
        Response::status(403)->send();
        exit;
    }
}
```

Wire validation into a global or controller-level middleware:

```php
class CsrfMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        if ($context->request()->getMethod() === 'POST') {
            csrf_verify();
        }
        return $next($context);
    }
}
```

Always use `hash_equals()` for token comparison to prevent timing attacks.

Azera also ships a ready-made `Azera\Security\CsrfMiddleware` — see [Security (Enterprise)](18-SECURITY-ENTERPRISE.md) before hand-rolling this.

## Password Storage

Use PHP's built-in password API. It handles algorithm selection, salting, and upgrade paths automatically.

```php
// On registration / password change
$hash = password_hash($plainPassword, PASSWORD_DEFAULT);

// On login
if (!password_verify($plainPassword, $storedHash)) {
    // reject
}

// Upgrade the hash when the algorithm/cost changes (after a successful verify)
if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
    $storedHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    // persist the new hash
}
```

A `Azera\Security\Hasher` wrapper is available — see [Security (Enterprise)](18-SECURITY-ENTERPRISE.md).

Never store or log plain-text passwords.

## Cookies and Encryption

Cookies default to `httpOnly = true` (inaccessible to JavaScript). Enable `secure` in production so cookies are only sent over HTTPS.

```php
use Azera\Http\Cookie;

$cookie = Cookie::make('auth')
    ->set($token)
    ->encrypted()               // authenticated encryption via libsodium/OpenSSL
    ->key($secretKey)           // recommended: explicit key (default derives from php_uname())
    ->expires(time() + 3_600)
    ->secure(true)              // HTTPS only – enable in production
    ->httpOnly(true)            // not accessible from JavaScript (default)
    ->send();

// Reading (decryption is transparent)
$value = $ctx->cookies()->get('auth');

// Deleting
$ctx->cookies()->delete('auth');
```

| Method                                  | Description                                 |
| --------------------------------------- | ------------------------------------------- |
| `set(mixed $value): static`             | Set the cookie value                        |
| `value(mixed $default = null): mixed`   | Read the current value (decrypts if needed) |
| `encrypted(bool $state = true): static` | Enable/disable authenticated encryption     |
| `key(?string $key): static`             | Encryption key (default derives from uname) |
| `expires(int $timestamp): static`       | Expiry Unix timestamp (0 = session)         |
| `path(string $path): static`            | Cookie path (default `/`)                   |
| `domain(string $domain): static`        | Cookie domain                               |
| `secure(bool $state): static`           | HTTPS-only flag                             |
| `httpOnly(bool $state): static`         | `HttpOnly` flag (default `true`)            |
| `send(): static`                        | Queue the `Set-Cookie` header               |
| `delete(): void`                        | Delete by sending a past-expiry header      |

## Encryption Internals

Encryption is `Azera\Http\Cookie`'s own machinery: authenticated encryption via libsodium ChaCha20-Poly1305 (preferred) or AES-256-GCM via OpenSSL as fallback. When `->encrypted()` is set, `send()` encrypts and `value()` decrypts transparently; a tampered ciphertext decrypts to `null`.

```php
use Azera\Http\Cookie;

// Cipher is selected automatically; check with Cookie::getAvailableCipher()
Cookie::getAvailableCipher(); // Cookie::CIPHER_CHACHA20_POLY1305 or CIPHER_AES_256_GCM
```

Store non-cookie secrets (database columns, tokens) with libsodium or `password_hash` — Azera's encryption helpers are scoped to cookie values.

### Key generation

Generate a cryptographically random key once, store it outside source control, and load it at runtime via an environment variable. Pass it explicitly with `->key($key)`, since the default (a hash of `php_uname()`) ties cookies to a single machine.

```php
// Generate once (run in a CLI script or tinker session)
echo bin2hex(random_bytes(32)); // 64 hex chars = 256-bit key

// Load in bootstrap
$key = $_ENV['APP_KEY'] ?? throw new RuntimeException('APP_KEY not set');
```

## Session Safety

Add `SessionMiddleware` to activate Azera's session wrapper:

```php
$dispatcher->addMiddleware(new SessionMiddleware());
```

Then apply these practices:

```php
// Regenerate the session ID on login or privilege change (prevents session fixation)
session_regenerate_id(true);

// Store only what you need
$session->set('user_id', $user->id);  // not the whole model object

// Clear everything on logout
$session->clear();
session_destroy();
```

Configure session cookie attributes in your bootstrap **before** `SessionMiddleware` runs:

```php
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
```

## Input Validation

Use the built-in `Validator` to sanitize and coerce all external input. It returns only the fields that passed, with values cast to declared types.

```php
use Azera\Validation\Validator;
use Azera\Validation\ValidationException;

$v = new Validator($this->request()->post());
$v->field('email')->email()->max(255);
$v->field('age')->int()->min(18)->max(120);
$v->field('role')->in(['admin', 'editor', 'viewer']);

$data = $v->validate(); // throws ValidationException on failure
User::create($data);    // only validated, coerced fields
```

- **Route parameters** — use typed route segments (`{id:int}`) to reject non-matching values before they reach the controller.
- **Uploaded files** — inspect `UploadedFile` for MIME type and size before moving the file; never trust the client-supplied filename.

See [Validation](07-VALIDATION.md) for the full rule reference.

## Logging and Secrets

- Never log raw passwords, tokens, or encryption keys.
- Redact PII in production logs.
- Keep `APP_KEY` and database credentials outside source control — use environment variables or a secrets manager.
- Rotate credentials on suspected compromise.

## Related

- [Validation](07-VALIDATION.md)
- [Logging](10-LOGGING.md)
- [HTTP Request](06-HTTP-REQUEST.md)
