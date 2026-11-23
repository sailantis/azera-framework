# Class: Session

**Full name:** [Azera\Http\Session](../../src/Http/Session.php)

Wrapper around a PHP session array that provides typed accessors and
a clean API for reading, writing, and clearing session data.

## Public methods

### __construct() · <small>[🗎](../../src/Http/Session.php#L15)</small>

`public function __construct(array &$store): mixed`

Create a new Session backed by the given store reference.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$store` | array | - | Reference to the underlying session array (typically $_SESSION). |

**Return value**

- Type: `mixed`


---

### get() · <small>[🗎](../../src/Http/Session.php#L26)</small>

`public function get(string $key, mixed $default = null): mixed`

Retrieve a value from the session.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Session key. |
| `$default` | mixed | `null` | Value to return when the key is not set. |

**Return value**

- Type: `mixed`


---

### set() · <small>[🗎](../../src/Http/Session.php#L37)</small>

`public function set(string $key, mixed $value): void`

Store a value in the session.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Session key. |
| `$value` | mixed | - | Value to store. |

**Return value**

- Type: `void`


---

### remove() · <small>[🗎](../../src/Http/Session.php#L47)</small>

`public function remove(string $key): void`

Remove a key from the session.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Session key to unset. |

**Return value**

- Type: `void`


---

### has() · <small>[🗎](../../src/Http/Session.php#L58)</small>

`public function has(string $key): bool`

Check whether a key exists in the session.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Session key. |

**Return value**

- Type: `bool`
- Description: True if the key is set and not null.


---

### clear() · <small>[🗎](../../src/Http/Session.php#L66)</small>

`public function clear(): void`

Remove all data from the session.

**Return value**

- Type: `void`


---

### isActive() · <small>[🗎](../../src/Http/Session.php#L77)</small>

`public function isActive(): bool`

Whether a PHP session is active.

The lifecycle helpers use this status to determine their behavior in
controllers, CLI tasks, and tests, with or without [`SessionMiddleware`](Http_SessionMiddleware.md).

**Return value**

- Type: `bool`


---

### id() · <small>[🗎](../../src/Http/Session.php#L89)</small>

`public function id(): string`

Return the current session ID, or `''` when no session is active.

This method reads the ID; use `regenerateId()` to change it. The ID
can be used in audit logs or to correlate a request with a stored session
without accessing the session superglobal.

**Return value**

- Type: `string`


---

### regenerateId() · <small>[🗎](../../src/Http/Session.php#L110)</small>

`public function regenerateId(bool $deleteOldSession = true): bool`

Issue a new session ID while keeping the session data.

Regenerate the ID after login or a privilege change, such as a role grant
or the start of impersonation, so the authenticated session uses a new ID.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$deleteOldSession` | bool | `true` | Whether to delete the previous session's storage. |

**Return value**

- Type: `bool`
- Description: True when a new ID was issued; false when no session is active<br>or the SAPI cannot regenerate it (for example, after output has started).


---

### destroy() · <small>[🗎](../../src/Http/Session.php#L134)</small>

`public function destroy(): void`

Destroy the active session and clear this store.

PHP's `session_destroy()` removes server-side data while leaving
`$_SESSION` populated for the rest of the request. Clearing the store
keeps subsequent reads in the request consistent with the destroyed
session.

When a session is active, it is destroyed before the store is cleared.
The subsequent `session_write_close()` in [`SessionMiddleware`](Http_SessionMiddleware.md)
returns `false` when the session is inactive.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
