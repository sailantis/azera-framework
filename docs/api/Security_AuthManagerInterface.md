# Interface: AuthManagerInterface

**Full name:** [Azera\Security\AuthManagerInterface](../../src/Security/AuthManagerInterface.php)

Contract for an authentication manager.

The manager is a registry of named guards (e.g. "web" for session-based
auth, "api" for token-based auth). It owns the currently active guard
and exposes a uniform API for the most common operations.

Concrete guards live in the companion package `azera/auth`. The
framework ships contracts only so applications can be built against
the interface without pulling in an implementation.

## Public methods

### addGuard() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L26)</small>

`public function addGuard(string $name, Azera\Security\GuardInterface $guard): void`

Register a guard under a name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Guard identifier (e.g. "web", "api"). |
| `$guard` | [GuardInterface](Security_GuardInterface.md) | - | The guard instance. |

**Return value**

- Type: `void`


---

### guard() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L34)</small>

`public function guard(string|null $name = null): Azera\Security\GuardInterface`

Set the guard that should be used for subsequent calls.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` | Guard identifier previously registered. |

**Return value**

- Type: [GuardInterface](Security_GuardInterface.md)

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  If no guard is registered under $name.


---

### currentGuard() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L39)</small>

`public function currentGuard(): Azera\Security\GuardInterface`

Get the currently active guard.

**Return value**

- Type: [GuardInterface](Security_GuardInterface.md)


---

### attempt() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L49)</small>

`public function attempt(array $credentials): bool`

Attempt to authenticate a set of credentials against the
active guard.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$credentials` | array | - | Credential bag (e.g.<br>`['email' => …, 'password' => …]`). |

**Return value**

- Type: `bool`
- Description: True if authentication succeeded.


---

### logout() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L54)</small>

`public function logout(): void`

Log the current user out via the active guard.

**Return value**

- Type: `void`


---

### check() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L60)</small>

`public function check(): bool`

Check whether a user is currently authenticated via the
active guard.

**Return value**

- Type: `bool`


---

### id() · <small>[🗎](../../src/Security/AuthManagerInterface.php#L66)</small>

`public function id(): mixed`

Get the authenticated user identifier, or null if not
authenticated.

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
