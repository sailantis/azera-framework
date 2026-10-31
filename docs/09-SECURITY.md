# Security

Building blocks for secure applications: parameterised queries, authenticated cookie encryption, and safe cookie handling. The remaining measures — output escaping, CSRF, password hashing — are documented per feature; Clarity templates auto-escape (see [Clarity Engine](03b-CLARITY-ENGINE.md#the-default-policy-refuses)), plain PHP templates do not.

## SQL Injection Protection

### Bound parameters (preferred)

Values passed through `->bind()` become real PDO named parameters and are never interpolated into SQL.

```php
User::query()
    ->where('email = :email')
    ->bind(['email' => $email])
    ->first();
```

### Identifier clauses (ORDER BY / GROUP BY / columns)

In model mode, `orderBy()`, `groupBy()` and `columns()` validate every term against the model metadata. Only `<field>`, `alias.<field>`, `<field> AS <alias>` and an optional `ASC`/`DESC` are accepted. Use `Sql::raw()` for arbitrary SQL fragments.

```php
// Safe: allowlist a request-supplied sort key before it reaches the builder
$allowed = ['name', 'email', 'created_at'];
$sort = $this->request()->get('sort');
$sort = in_array($sort, $allowed, true) ? $sort : 'name';

User::query()->orderBy($sort);
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

`Sql::raw()` and query methods that accept raw SQL fragments inject SQL
verbatim. **Never** interpolate user input through them.

```php
// UNSAFE – never do this
$col = $this->request()->get('sort');
User::query()->orderBy(Sql::raw($col));

// Safe alternative – validate against an allowlist first
$allowed = ['name', 'email', 'created_at'];
$col = in_array($col, $allowed, true) ? $col : 'name';
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

Use Azera's built-in `CsrfMiddleware` to protect state-changing requests. If
you need a custom flow, the session-token pattern below shows the basics:

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

See [Security (Enterprise)](18-SECURITY-ENTERPRISE.md) for middleware options.

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
    ->key($secretKey)           // REQUIRED: explicit, random key (no default exists)
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
| `key(?string $key): static`             | Encryption key (**required** — no default)  |
| `cipher(?string $cipher): static`       | Pin a provider id (`null` = automatic)      |
| `cipherProvider(CipherProviderInterface $p): static` | Use a custom provider          |
| `ciphers(CipherRegistry $registry): static` | Share a registry across cookies       |
| `registry(bool $fresh = false): CipherRegistry` | The cookie's registry           |
| `expires(int $timestamp): static`       | Expiry Unix timestamp (0 = session)         |
| `path(string $path): static`            | Cookie path (default `/`)                   |
| `domain(string $domain): static`        | Cookie domain                               |
| `secure(bool $state): static`           | HTTPS-only flag                             |
| `httpOnly(bool $state): static`         | `HttpOnly` flag (default `true`)            |
| `send(): static`                        | Queue the `Set-Cookie` header               |
| `delete(): void`                        | Delete by sending a past-expiry header      |

## Encryption Internals

Encryption is delegated to a {@see \Azera\Security\Crypt\CipherRegistry} owned by
the cookie. Two providers ship with the framework:

| Provider | Id | Algorithm | Extension |
| -------- | -- | --------- | --------- |
| `SodiumCipher` | `sodium-chacha20poly1305.v1` | ChaCha20-Poly1305 (IETF) | libsodium |
| `OpenSslCipher` | `openssl-aes256gcm.v1` | AES-256-GCM | OpenSSL |

Both are AEAD, and by default the registry prefers ChaCha20-Poly1305 and falls
back to AES-256-GCM. A value that fails to decrypt or authenticate — tampered,
forged, or a plaintext cookie sent in place of a ciphertext — yields the
caller's `$default`. The raw cookie is never returned as a fallback, so an
attacker cannot bypass the integrity check by simply omitting the encryption.

### Payloads carry their own algorithm

Every payload names the provider that produced it:

```
base64url( provider-id "|" ciphertext )
```

Decryption therefore follows the payload, **not** what the local machine
happens to support. This matters because ChaCha20-Poly1305 and AES-256-GCM both
use a 12-byte nonce, which makes their ciphertexts structurally
indistinguishable. Without an embedded identifier, a cookie written on a node
with libsodium would fail to decrypt on a node without it — and would fail
*silently*, logging users out with no diagnosable cause.

With the identifier, a mixed fleet works: any node that can run either
algorithm reads cookies written by both.

```php
use Azera\Security\Crypt\CipherRegistry;

// Same registry, both directions
$registry = CipherRegistry::withDefaults();

$sealed = $registry->encrypt('value', $key);      // preferred provider
$plain  = $registry->decrypt($sealed, $key);      // routed by payload id
```

### Choosing a provider per cookie

The registry is deliberately *not* in the DI container. Different call sites
have different threat models and compliance requirements — a session cookie, a
short-lived token and a long-lived archival blob do not want the same
algorithm, custodian or rotation policy.

```php
use Azera\Http\Cookie;
use Azera\Security\Crypt\CipherRegistry;

// 1. Let the framework choose (default)
Cookie::make('session')->encrypted()->key($key);

// 2. Pin an algorithm
Cookie::make('session')->encrypted()->key($key)->cipher(SodiumCipher::ID);

// 3. Bring your own provider (KMS/HSM-backed, or a new algorithm)
Cookie::make('session')->encrypted()->key($key)->cipherProvider($myProvider);

// 4. Share one registry across several cookies
Cookie::make('a')->encrypted()->key($key)->ciphers($registry);
```

A custom provider implements
{@see \Azera\Security\Crypt\CipherProviderInterface} — `id()`, `isAvailable()`,
`encrypt()` and `decrypt()`. The `id()` is written into every payload, so it
must stay stable for as long as any ciphertext using it may exist.

### Switching algorithms is safe

Existing cookies keep decrypting after a switch, because they carry their own
provider id:

```php
// Issued under ChaCha20-Poly1305, read back under AES-256-GCM — still works.
$cookie->cipher(OpenSslCipher::ID);
```

To retire an algorithm, stop *encrypting* with it while keeping its provider
registered until every outstanding cookie has expired.

Store non-cookie secrets (database columns, tokens) with libsodium or `password_hash` — Azera's encryption helpers are scoped to cookie values.

### Key generation

Generate a cryptographically random key once, store it outside source control, and load it at runtime via an environment variable. Pass it explicitly with `->key($key)`. There is deliberately **no default key**: deriving one from `php_uname()` (as earlier versions did) is unsafe, because the host name, OS and build string are routinely disclosed. Using `->encrypted()` without a key throws.

```php
// generate once, store e.g. in .env outside the web root
$key = base64_encode(random_bytes(32));

Cookie::make('session')->encrypted()->key($key)->send();
```

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

Use the built-in `Validator` to validate and coerce external input. It returns
only fields that pass, with values cast to their declared types. Validation
does not replace output escaping.

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
