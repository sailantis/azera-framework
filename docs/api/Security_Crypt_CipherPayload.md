# Class: CipherPayload

**Full name:** [Azera\Security\Crypt\CipherPayload](../../src/Security/Crypt/CipherPayload.php)

The wire format shared by all [`CipherProviderInterface`](Security_Crypt_CipherProviderInterface.md) implementations.

base64url( provider-id "|" raw-ciphertext )

The payload records the provider that produced it, so decoding uses the provider named in the
payload rather than the providers installed on the current machine. Cookies can therefore be
read on machines where different extensions are installed.

## Public methods

### encode() · <small>[🗎](../../src/Security/Crypt/CipherPayload.php#L32)</small>

`public static function encode(string $providerId, string $ciphertext): string`

Encode a provider id and its raw ciphertext into a transport-safe string.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$providerId` | string | - | Provider that produced the ciphertext. |
| `$ciphertext` | string | - | Raw binary ciphertext. |

**Return value**

- Type: `string`
- Description: Base64url-encoded payload, safe inside a cookie value.

**Throws**

- Exception  When the provider id is not well formed.


---

### decode() · <small>[🗎](../../src/Security/Crypt/CipherPayload.php#L48)</small>

`public static function decode(string $payload): array|null`

Split a payload into its provider id and raw ciphertext.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$payload` | string | - | Payload produced by `encode()`. |

**Return value**

- Type: `array`|`null`
- Description: [providerId, ciphertext], or null<br>when the payload is malformed.


---

### providerIdOf() · <small>[🗎](../../src/Security/Crypt/CipherPayload.php#L77)</small>

`public static function providerIdOf(string $payload): string|null`

Read the provider id from a payload without decrypting it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$payload` | string | - | Payload produced by `encode()`. |

**Return value**

- Type: `string`|`null`
- Description: Provider id, or null when absent/malformed.



---

[Back to the Index ⤴](README.md)
