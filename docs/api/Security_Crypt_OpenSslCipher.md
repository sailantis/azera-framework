# Class: OpenSslCipher

**Full name:** [Azera\Security\Crypt\OpenSslCipher](../../src/Security/Crypt/OpenSslCipher.php)

AES-256-GCM authenticated encryption via OpenSSL.

Fallback for environments without libsodium. GCM is a sound AEAD, but the
Sodium provider is preferred because OpenSSL's GCM path has historically
been more prone to implementation-side pitfalls in userland PHP.

Payload layout, inside the [`CipherPayload`](Security_Crypt_CipherPayload.md) envelope:

    iv (12 bytes) || tag (16 bytes) || ciphertext

## Public Constants

- **ID** = `'openssl-aes256gcm.v1'`

## Public methods

### id() · <small>[🗎](../../src/Security/Crypt/OpenSslCipher.php#L25)</small>

`public function id(): string`

**Return value**

- Type: `string`


---

### isAvailable() · <small>[🗎](../../src/Security/Crypt/OpenSslCipher.php#L30)</small>

`public function isAvailable(): bool`

**Return value**

- Type: `bool`


---

### encrypt() · <small>[🗎](../../src/Security/Crypt/OpenSslCipher.php#L36)</small>

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

### decrypt() · <small>[🗎](../../src/Security/Crypt/OpenSslCipher.php#L62)</small>

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
