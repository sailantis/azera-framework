# Class: CipherRegistry

**Full name:** [Azera\Security\Crypt\CipherRegistry](../../src/Security/Crypt/CipherRegistry.php)

Collection of [`CipherProviderInterface`](Security_Crypt_CipherProviderInterface.md) instances, keyed by id.

Deliberately *not* wired into the DI container: different call sites may
legitimately need different algorithms (a cookie, a short-lived signed
token and a long-lived archival blob do not share a threat model), so a
registry is constructed where it is used and kept next to the thing that
uses it rather than being resolved globally.

The registry is what makes decryption portable. A payload names the provider
that produced it; `decrypt()` follows that name. A node with only
OpenSSL can therefore read a cookie written by a node with libsodium, as
long as both providers are registered.

```php
$registry = CipherRegistry::withDefaults();
$sealed   = $registry->encrypt('value', $key);        // preferred provider
$plain    = $registry->decrypt($sealed, $key);        // routed by payload id
```

## Public methods

### __construct() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L41)</small>

`public function __construct(iterable $providers = []): mixed`

Create a registry from a set of providers.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$providers` | iterable | `[]` | Providers in preference order. |

**Return value**

- Type: `mixed`

**Throws**

- Exception  When two providers claim the same id.


---

### withDefaults() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L57)</small>

`public static function withDefaults(): self`

Registry with the built-in providers, preferred one first.

Registration order is preference order: ChaCha20-Poly1305 before
AES-256-GCM, and unavailable providers are skipped. A provider that
cannot encrypt here is still registered when its id is known, so
payloads from other machines remain readable whenever the extension is
present at read time.

**Return value**

- Type: `self`


---

### defaultProviders() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L67)</small>

`public static function defaultProviders(): array`

The providers shipped with the framework, in preference order.

**Return value**

- Type: `array`


---

### preferredId() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L80)</small>

`public function preferredId(): string`

The id of the provider that would be used to encrypt right now.

**Return value**

- Type: `string`
- Description: Provider id.

**Throws**

- Exception  When no registered provider is available.


---

### preferred() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L90)</small>

`public function preferred(): Azera\Security\Crypt\CipherProviderInterface`

The provider that would be used to encrypt right now.

**Return value**

- Type: [CipherProviderInterface](Security_Crypt_CipherProviderInterface.md)

**Throws**

- Exception  When no registered provider is available.


---

### encrypt() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L113)</small>

`public function encrypt(string $plaintext, string $key, string $associated = '', string|null $providerId = null): string`

Encrypt with the preferred provider.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$plaintext` | string | - | Value to protect. |
| `$key` | string | - | Secret key material. |
| `$associated` | string | `''` | Additional authenticated data. |
| `$providerId` | string\|null | `null` | Force a specific provider instead of the preferred one. |

**Return value**

- Type: `string`
- Description: Self-describing payload.

**Throws**

- Exception  When the requested provider is unknown or unavailable.


---

### decrypt() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L138)</small>

`public function decrypt(string $payload, string $key, string $associated = ''): string|null`

Decrypt a payload by following the provider id it carries.

This is the method that removes the portability hazard: the provider is
chosen from the payload, not from local capability.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$payload` | string | - | Payload produced by any registered provider. |
| `$key` | string | - | Secret key material. |
| `$associated` | string | `''` | Additional authenticated data. |

**Return value**

- Type: `string`|`null`
- Description: Plaintext, or null when the payload is malformed,<br>names an unregistered provider, or fails authentication.


---

### add() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L164)</small>

`public function add(Azera\Security\Crypt\CipherProviderInterface $provider): self`

Add (or replace) a provider.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$provider` | [CipherProviderInterface](Security_Crypt_CipherProviderInterface.md) | - | Provider to register. |

**Return value**

- Type: `self`

**Throws**

- Exception  When a *different* provider already claims this id.


---

### get() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L183)</small>

`public function get(string $id): Azera\Security\Crypt\CipherProviderInterface`

Fetch a provider by id.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | Provider id. |

**Return value**

- Type: [CipherProviderInterface](Security_Crypt_CipherProviderInterface.md)

**Throws**

- Exception  When the id is not registered.


---

### has() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L199)</small>

`public function has(string $id): bool`

Whether a provider with this id is registered.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | Provider id. |

**Return value**

- Type: `bool`


---

### ids() · <small>[🗎](../../src/Security/Crypt/CipherRegistry.php#L209)</small>

`public function ids(): array`

Registered provider ids, in preference order.

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
