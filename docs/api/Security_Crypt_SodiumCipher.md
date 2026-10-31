# Class: SodiumCipher

**Full name:** [Azera\Security\Crypt\SodiumCipher](../../src/Security/Crypt/SodiumCipher.php)

ChaCha20-Poly1305 (IETF) authenticated encryption via libsodium.

Preferred default: it is the AEAD built into modern PHP, has no padding
oracle, and is constant-time on all supported platforms.

Payload layout, inside the [`CipherPayload`](Security_Crypt_CipherPayload.md) envelope:

    nonce (12 bytes) || ciphertext || tag (16 bytes)

## Public Constants

- **ID** = `'sodium-chacha20poly1305.v1'`

## Public methods

### id() · <small>[🗎](../../src/Security/Crypt/SodiumCipher.php#L24)</small>

`public function id(): string`

**Return value**

- Type: `string`


---

### isAvailable() · <small>[🗎](../../src/Security/Crypt/SodiumCipher.php#L29)</small>

`public function isAvailable(): bool`

**Return value**

- Type: `bool`


---

### encrypt() · <small>[🗎](../../src/Security/Crypt/SodiumCipher.php#L34)</small>

`public function encrypt(string $plaintext, string $key, string $associated = ''): string`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$plaintext` | string | - |  |
| `$key` | string | - |  |
| `$associated` | string | `''` |  |

**Return value**

- Type: `string`


---

### decrypt() · <small>[🗎](../../src/Security/Crypt/SodiumCipher.php#L49)</small>

`public function decrypt(string $payload, string $key, string $associated = ''): string|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$payload` | string | - |  |
| `$key` | string | - |  |
| `$associated` | string | `''` |  |

**Return value**

- Type: `string`|`null`



---

[Back to the Index ⤴](README.md)
