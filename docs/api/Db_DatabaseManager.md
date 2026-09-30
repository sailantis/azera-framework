# Class: DatabaseManager

**Full name:** [Azera\Db\DatabaseManager](../../src/Db/DatabaseManager.php)

Manages multiple SQL connections (roles) and their factories.

This class allows the definition of multiple SQL connections (e.g. "default", "analytics", "logging") and retrieval of them by role. The first role defined will be used as the default when requesting the default connection, but it can be changed by calling setDefault(). Each role can be defined with either a Database instance or a factory callable that returns a Database instance. The factory will only be called once per role, and the resulting Database instance will be cached for future use.

## Public methods

### set() · <small>[🗎](../../src/Db/DatabaseManager.php#L26)</small>

`public function set(string $role, Azera\Db\Database|callable $factory): static`

Define a SQL connection for a specific role.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | The name of the role (e.g. "default", "analytics") |
| `$factory` | [Database](Db_Database.md)\|callable | - | A factory callable that returns a Database instance, or a Database instance directly |

**Return value**

- Type: `static`


---

### setDefault() · <small>[🗎](../../src/Db/DatabaseManager.php#L45)</small>

`public function setDefault(string $role): static`

Set the default SQL role to use when requesting the default connection. By default, the first defined role will be used as the default.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | The name of the role to set as default |

**Return value**

- Type: `static`

**Throws**

- RuntimeException  If the specified role is not defined


---

### has() · <small>[🗎](../../src/Db/DatabaseManager.php#L61)</small>

`public function has(string $role): bool`

Check if a SQL role is defined.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | The name of the role to check |

**Return value**

- Type: `bool`
- Description: True if the role is defined, false otherwise


---

### get() · <small>[🗎](../../src/Db/DatabaseManager.php#L73)</small>

`public function get(string $role): Azera\Db\Database`

Get the Database instance for a specific role.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | The name of the role to retrieve |

**Return value**

- Type: [Database](Db_Database.md)
- Description: The Database instance for the specified role

**Throws**

- RuntimeException  If the role is not defined or if the factory does not return a Database instance


---

### getOrDefault() · <small>[🗎](../../src/Db/DatabaseManager.php#L105)</small>

`public function getOrDefault(string $role): Azera\Db\Database`

Get the Database instance for a specific role, or the default if the role is not defined.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | The name of the role to retrieve |

**Return value**

- Type: [Database](Db_Database.md)
- Description: The Database instance for the specified role, or the default if not defined

**Throws**

- RuntimeException  If no default Database is configured


---

### getDefault() · <small>[🗎](../../src/Db/DatabaseManager.php#L120)</small>

`public function getDefault(): Azera\Db\Database`

Get the default Database instance.

**Return value**

- Type: [Database](Db_Database.md)
- Description: The default Database instance

**Throws**

- RuntimeException  If no default Database is configured


---

### roles() · <small>[🗎](../../src/Db/DatabaseManager.php#L133)</small>

`public function roles(): array`

Return the names of all registered SQL roles.

**Return value**

- Type: `array`
- Description: List of role names (e.g. ["default", "read", "write"]).


---

### defaultRole() · <small>[🗎](../../src/Db/DatabaseManager.php#L143)</small>

`public function defaultRole(): string|null`

Return the name of the default SQL role, or null if none is configured.

**Return value**

- Type: `string`|`null`
- Description: The default role name.



---

[Back to the Index ⤴](README.md)
