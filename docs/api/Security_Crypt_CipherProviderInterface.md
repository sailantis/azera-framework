# Interface: CipherProviderInterface

**Full name:** [Azera\Security\Crypt\CipherProviderInterface](../../src/Security/Crypt/CipherProviderInterface.php)

Contract for an authenticated-encryption provider.

A provider owns both the algorithm *and* the payload envelope. Every
ciphertext it produces begins with its `id()`, so a value can be
routed back to the provider that wrote it regardless of what the current
machine happens to have compiled in.

That self-description is the reason for the interface rather than a bare
`encrypt()`/`decrypt()` pair: ChaCha20-Poly1305 and AES-256-GCM both use a
12-byte nonce, so their payloads are structurally indistinguishable. Without
an embedded identifier, a cookie written on a node with libsodium cannot be
read on a node without it, and the failure is silent.

Implementations must be authenticated (AEAD). Decryption returns `null` —
never a partial or best-effort value — when authentication fails.

## Public methods

### id() · <small>[🗎](../../src/Security/Crypt/CipherProviderInterface.php#L37)</small>

`public function id(): string`

Stable identifier for this algorithm and payload format.

Written verbatim into every payload, so it must never change for an
existing format. Include a version suffix when the envelope changes
(e.g. `sodium-chacha20poly1305.v2`).

**Return value**

- Type: `string`
- Description: Provider identifier.


---

### isAvailable() · <small>[🗎](../../src/Security/Crypt/CipherProviderInterface.php#L47)</small>

`public function isAvailable(): bool`

Whether the backing extension is available on this machine.

Used to pick a default and to fail with a clear message rather than a
"call to undefined function".

**Return value**

- Type: `bool`
- Description: True when the provider can be used.


---

### encrypt() · <small>[🗎](../../src/Security/Crypt/CipherProviderInterface.php#L58)</small>

`public function encrypt(string $plaintext, string $key, string $associated = ''): string`

Encrypt a value with authenticated encryption.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$plaintext` | string | - | Value to protect. |
| `$key` | string | - | Secret key material (any length; providers derive). |
| `$associated` | string | `''` | Additional authenticated data, bound but not encrypted. |

**Return value**

- Type: `string`
- Description: Ciphertext that includes this provider's `id()`.

**Throws**

- [Exception](Exception.md)  When encryption fails or the provider is unavailable.


---

### decrypt() · <small>[🗎](../../src/Security/Crypt/CipherProviderInterface.php#L68)</small>

`public function decrypt(string $payload, string $key, string $associated = ''): string|null`

Decrypt a value and verify its authentication tag.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$payload` | string | - | Payload previously produced by this provider. |
| `$key` | string | - | Secret key material. |
| `$associated` | string | `''` | Additional authenticated data used at encryption time. |

**Return value**

- Type: `string`|`null`
- Description: Plaintext, or null when authentication fails.



---

[Back to the Index ⤴](README.md)
