# Class: Hasher

**Full name:** [Azera\Security\Hasher](../../src/Security/Hasher.php)

Hashes and verifies passwords using PHP's native password hashing API.

A thin, testable wrapper around `password_hash()` and
`password_verify()`. The algorithm and cost are configurable
so applications can tune them from a single place. Implementations
MUST NOT store or log plain passwords.

## Public methods

### __construct() · <small>[🗎](../../src/Security/Hasher.php#L24)</small>

`public function __construct(string|int|null $algo = null, array $options = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$algo` | string\|int\|null | `null` | Hashing algorithm. Defaults to<br>`PASSWORD_DEFAULT` when null. Pass a named algorithm constant<br>(e.g. `PASSWORD_BCRYPT`) or null. |
| `$options` | array | `[]` | Options forwarded to<br>`password_hash()` (e.g. `['cost' => 12]` for bcrypt). |

**Return value**

- Type: `mixed`


---

### make() · <small>[🗎](../../src/Security/Hasher.php#L35)</small>

`public function make(string $password): string`

Hash a plain-text password.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$password` | string | - | The plain password to hash. |

**Return value**

- Type: `string`
- Description: The hash, including the algorithm and cost.


---

### verify() · <small>[🗎](../../src/Security/Hasher.php#L47)</small>

`public function verify(string $password, string $hash): bool`

Verify a plain password against a stored hash.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$password` | string | - | The plain password to check. |
| `$hash` | string | - | The stored hash. |

**Return value**

- Type: `bool`
- Description: True if the password matches the hash.


---

### needsRehash() · <small>[🗎](../../src/Security/Hasher.php#L59)</small>

`public function needsRehash(string $hash): bool`

Check whether a stored hash should be rehashed because the
algorithm or cost no longer match the current configuration.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$hash` | string | - | The stored hash. |

**Return value**

- Type: `bool`
- Description: True if the hash needs rehashing.


---

### token() · <small>[🗎](../../src/Security/Hasher.php#L72)</small>

`public function token(int $length = 32): string`

Generate a random token of the given length in raw bytes,
returned as a hex-encoded string.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$length` | int | `32` | Number of random bytes (default 32). |

**Return value**

- Type: `string`
- Description: Hex-encoded token (2 × $length characters).

**Throws**

- Exception  If sufficient random bytes cannot be generated.



---

[Back to the Index ⤴](README.md)
