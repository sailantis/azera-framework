# Class: Cookie

**Full name:** [Azera\Http\Cookie](../../src/Http/Cookie.php)

Represents a single HTTP cookie with optional transparent encryption.

Use the static `make()` factory or construct directly, then call
`send()` to emit the Set-Cookie header. Read the cookie value with
`value()`, which handles decryption automatically.

## Public methods

### make() · <small>[🗎](../../src/Http/Cookie.php#L49)</small>

`public static function make(string $name, mixed $value = null, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true): static`

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

### __construct() · <small>[🗎](../../src/Http/Cookie.php#L74)</small>

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

### value() · <small>[🗎](../../src/Http/Cookie.php#L105)</small>

`public function value(mixed $default = null): mixed`

Read the cookie value, lazily loading it from $_COOKIE and decrypting if needed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$default` | mixed | `null` | Value to return when the cookie is not present. |

**Return value**

- Type: `mixed`


---

### set() · <small>[🗎](../../src/Http/Cookie.php#L141)</small>

`public function set(mixed $value): static`

Set the cookie value (in memory; call `send()` to persist).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | New value. |

**Return value**

- Type: `static`


---

### send() · <small>[🗎](../../src/Http/Cookie.php#L157)</small>

`public function send(): static`

Emit a Set-Cookie header with the current cookie configuration.

Encrypts the value first if encryption is enabled.

**Return value**

- Type: `static`


---

### delete() · <small>[🗎](../../src/Http/Cookie.php#L181)</small>

`public function delete(): void`

Delete the cookie by setting its expiration to the past.

**Return value**

- Type: `void`


---

### encrypted() · <small>[🗎](../../src/Http/Cookie.php#L202)</small>

`public function encrypted(bool $state = true): static`

Enable or disable transparent encryption for this cookie.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | bool | `true` | True to enable encryption (default), false to disable. |

**Return value**

- Type: `static`


---

### key() · <small>[🗎](../../src/Http/Cookie.php#L219)</small>

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

### name() · <small>[🗎](../../src/Http/Cookie.php#L232)</small>

`public function name(): string`

Get the cookie name.

**Return value**

- Type: `string`
- Description: Cookie name.


---

### expires() · <small>[🗎](../../src/Http/Cookie.php#L243)</small>

`public function expires(int $timestamp): static`

Set the expiration timestamp.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$timestamp` | int | - | Unix timestamp (0 = session cookie). |

**Return value**

- Type: `static`


---

### path() · <small>[🗎](../../src/Http/Cookie.php#L255)</small>

`public function path(string $path): static`

Set the URL path scope for the cookie.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | URL path (e.g. "/"). |

**Return value**

- Type: `static`


---

### domain() · <small>[🗎](../../src/Http/Cookie.php#L267)</small>

`public function domain(string $domain): static`

Set the domain scope for the cookie.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - | Domain (e.g. ".example.com"). |

**Return value**

- Type: `static`


---

### secure() · <small>[🗎](../../src/Http/Cookie.php#L279)</small>

`public function secure(bool $state): static`

Restrict the cookie to HTTPS connections only.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | bool | - | True to require HTTPS. |

**Return value**

- Type: `static`


---

### httpOnly() · <small>[🗎](../../src/Http/Cookie.php#L291)</small>

`public function httpOnly(bool $state): static`

Make the cookie inaccessible to JavaScript (HttpOnly flag).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | bool | - | True to set the HttpOnly flag. |

**Return value**

- Type: `static`


---

### __toString() · <small>[🗎](../../src/Http/Cookie.php#L302)</small>

`public function __toString(): string`

Return the cookie value as a string (useful for string-casting).

**Return value**

- Type: `string`
- Description: Cookie value, or empty string when not set.


---

### cipher() · <small>[🗎](../../src/Http/Cookie.php#L325)</small>

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

### cipherProvider() · <small>[🗎](../../src/Http/Cookie.php#L341)</small>

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

### ciphers() · <small>[🗎](../../src/Http/Cookie.php#L358)</small>

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

### registry() · <small>[🗎](../../src/Http/Cookie.php#L369)</small>

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
