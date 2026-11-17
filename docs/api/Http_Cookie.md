# Class: Cookie

**Full name:** [Azera\Http\Cookie](../../src/Http/Cookie.php)

Represents a single HTTP cookie with optional transparent encryption.

Use the static `create()` factory or construct directly. Read the value
with `value()`, which handles decryption automatically, and turn the
cookie into a header line with `toHeader()`, then add it to a
[`Response`](Http_Response.md).

## Public methods

### create() · <small>[🗎](../../src/Http/Cookie.php#L63)</small>

`public static function create(string $name, mixed $value = null, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true): static`

Create a new Cookie instance with the given parameters.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the cookie. |
| `$value` | mixed | `null` | The value of the cookie (optional). |
| `$expires` | int | `0` | Expiration timestamp (optional). |
| `$path` | string | `'/'` | Path for which the cookie is valid (optional). |
| `$domain` | string | `''` | Domain for which the cookie is valid (optional). |
| `$secure` | bool | `false` | Whether the cookie should only be sent over HTTPS (optional). |
| `$httpOnly` | bool | `true` | Whether the cookie should be inaccessible to JavaScript (optional). |

**Return value**

- Type: `static`
- Description: A new Cookie instance.


---

### __construct() · <small>[🗎](../../src/Http/Cookie.php#L88)</small>

`public function __construct(string $name, mixed $value = null, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true): mixed`

Create a new Cookie instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Cookie name. |
| `$value` | mixed | `null` | Initial value (null means "not yet loaded"). |
| `$expires` | int | `0` | Expiration timestamp (0 = session cookie). |
| `$path` | string | `'/'` | URL path scope. |
| `$domain` | string | `''` | Domain scope. |
| `$secure` | bool | `false` | Send over HTTPS only. |
| `$httpOnly` | bool | `true` | Inaccessible to JavaScript. |

**Return value**

- Type: `mixed`


---

### value() · <small>[🗎](../../src/Http/Cookie.php#L123)</small>

`public function value(mixed $default = null): mixed`

Read the cookie value, lazily loading it from the raw incoming value and
decrypting if needed.

The raw value comes from `setRaw()`, which the
[`Cookies`](Http_Cookies.md) jar fills from [`Request::cookies()`](Http_Request.md#cookies).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$default` | mixed | `null` | Value to return when the cookie is not present. |

**Return value**

- Type: `mixed`


---

### set() · <small>[🗎](../../src/Http/Cookie.php#L159)</small>

`public function set(mixed $value): static`

Set the cookie value and queue it for the response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | New value. |

**Return value**

- Type: `static`


---

### setRaw() · <small>[🗎](../../src/Http/Cookie.php#L179)</small>

`public function setRaw(string|null $raw): static`

Load the value the client sent, as read from [`Request::cookies()`](Http_Request.md#cookies).

Unlike `set()`, this does not queue a `Set-Cookie`. It replaces cached
state and keeps the cookie clean, so it is only re-sent after an attribute
change.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$raw` | string\|null | - | The raw (still encrypted, if applicable) cookie value. |

**Return value**

- Type: `static`


---

### isDirty() · <small>[🗎](../../src/Http/Cookie.php#L194)</small>

`public function isDirty(): bool`

Whether this cookie was written or modified in this request.

Used by [`Cookies::applyTo()`](Http_Cookies.md#applyto) so that reading a cookie never sends it.

**Return value**

- Type: `bool`


---

### markClean() · <small>[🗎](../../src/Http/Cookie.php#L202)</small>

`public function markClean(): void`

Mark the cookie as already emitted, so a repeated drain cannot duplicate it.

**Return value**

- Type: `void`


---

### toHeader() · <small>[🗎](../../src/Http/Cookie.php#L222)</small>

`public function toHeader(): string`

Build the `Set-Cookie` header value for this cookie.

This is the one place that knows the header format. It works without a jar
and without a request, so an application can set the header itself:

    $response->addHeader('Set-Cookie', $cookie->toHeader());

Encrypts the value when enabled and URL-encodes it for transport. Rejects
invalid cookie names or unsafe path/domain attributes.

**Return value**

- Type: `string`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When a name or attribute is invalid.


---

### delete() · <small>[🗎](../../src/Http/Cookie.php#L288)</small>

`public function delete(): static`

Delete the cookie by queueing a past-expiry `Set-Cookie` for the response.

This is the only way a cookie is deleted. There is no `setcookie()` call
anywhere: a worker is not a SAPI, and under `php-fpm` the response carries the
header anyway — doing both would send two `Set-Cookie` lines for one delete.

**Return value**

- Type: `static`


---

### encrypted() · <small>[🗎](../../src/Http/Cookie.php#L304)</small>

`public function encrypted(bool $state = true): static`

Enable or disable transparent encryption for this cookie.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | bool | `true` | True to enable encryption (default), false to disable. |

**Return value**

- Type: `static`


---

### key() · <small>[🗎](../../src/Http/Cookie.php#L321)</small>

`public function key(string|null $key): static`

Set the encryption key.

Required before reading or writing an encrypted cookie. Use a random,
secret value (e.g. base64_encode(random_bytes(32))) persisted outside
the web root. There is deliberately no default: a derivable key would
make the AEAD protection meaningless.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string\|null | - | Encryption key, or null to clear it. |

**Return value**

- Type: `static`


---

### name() · <small>[🗎](../../src/Http/Cookie.php#L334)</small>

`public function name(): string`

Get the cookie name.

**Return value**

- Type: `string`
- Description: Cookie name.


---

### expires() · <small>[🗎](../../src/Http/Cookie.php#L345)</small>

`public function expires(int $timestamp): static`

Set the expiration timestamp.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$timestamp` | int | - | Unix timestamp (0 = session cookie). |

**Return value**

- Type: `static`


---

### path() · <small>[🗎](../../src/Http/Cookie.php#L358)</small>

`public function path(string $path): static`

Set the URL path scope for the cookie.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | URL path (e.g. "/"). |

**Return value**

- Type: `static`


---

### domain() · <small>[🗎](../../src/Http/Cookie.php#L371)</small>

`public function domain(string $domain): static`

Set the domain scope for the cookie.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - | Domain (e.g. ".example.com"). |

**Return value**

- Type: `static`


---

### secure() · <small>[🗎](../../src/Http/Cookie.php#L384)</small>

`public function secure(bool $state): static`

Restrict the cookie to HTTPS connections only.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | bool | - | True to require HTTPS. |

**Return value**

- Type: `static`


---

### httpOnly() · <small>[🗎](../../src/Http/Cookie.php#L397)</small>

`public function httpOnly(bool $state): static`

Make the cookie inaccessible to JavaScript (HttpOnly flag).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | bool | - | True to set the HttpOnly flag. |

**Return value**

- Type: `static`


---

### __toString() · <small>[🗎](../../src/Http/Cookie.php#L409)</small>

`public function __toString(): string`

Return the cookie value as a string (useful for string-casting).

**Return value**

- Type: `string`
- Description: Cookie value, or empty string when not set.


---

### cipher() · <small>[🗎](../../src/Http/Cookie.php#L431)</small>

`public function cipher(string|null $cipher): static`

Set the cipher provider used for this cookie.

Accepts any registered provider id (see
[`SodiumCipher::ID()`](Security_Crypt_SodiumCipher.md#id) and
[`OpenSslCipher::ID()`](Security_Crypt_OpenSslCipher.md#id)), or null to let the
registry pick the best available algorithm.

Decryption never depends on this setting: a payload names the provider
that produced it, so a cookie written with ChaCha20 stays readable after
switching to AES-GCM.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$cipher` | string\|null | - | Provider id, or null for automatic selection. |

**Return value**

- Type: `static`


---

### cipherProvider() · <small>[🗎](../../src/Http/Cookie.php#L447)</small>

`public function cipherProvider(Azera\Security\Crypt\CipherProviderInterface $provider): static`

Use a specific cipher provider instance for this cookie.

For consumers that bring their own provider (KMS-backed, HSM-backed, or
an algorithm the framework does not ship). Registering it here also makes
it available for reading payloads that name it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$provider` | [CipherProviderInterface](Security_Crypt_CipherProviderInterface.md) | - | Provider to use. |

**Return value**

- Type: `static`


---

### ciphers() · <small>[🗎](../../src/Http/Cookie.php#L464)</small>

`public function ciphers(Azera\Security\Crypt\CipherRegistry $registry): static`

Replace the registry used by this cookie.

Different cookies may need different algorithms, so the registry is owned
per cookie (and can be shared explicitly) rather than resolved globally.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$registry` | [CipherRegistry](Security_Crypt_CipherRegistry.md) | - | Registry to use. |

**Return value**

- Type: `static`


---

### registry() · <small>[🗎](../../src/Http/Cookie.php#L475)</small>

`public function registry(bool $fresh = false): Azera\Security\Crypt\CipherRegistry`

The registry used by this cookie, created on first use.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fresh` | bool | `false` | Discard any existing registry and build a new one. |

**Return value**

- Type: [CipherRegistry](Security_Crypt_CipherRegistry.md)



---

[Back to the Index ⤴](README.md)
