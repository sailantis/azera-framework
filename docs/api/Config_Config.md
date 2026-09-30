# Class: Config

**Full name:** [Azera\Config\Config](../../src/Config/Config.php)

Dot-notation access to a configuration array.

Provides `get('db.dsn')`, `set('db.dsn', ...)`, `has('db.dsn')` over a
nested array. Namespaces are separated by a dot.

Example:
```php
$config = new Config([
    'db' => ['dsn' => 'mysql:host=localhost', 'user' => 'root'],
    'app' => ['name' => 'Azera'],
]);
$config->get('db.dsn');      // 'mysql:host=localhost'
$config->get('app.name');    // 'Azera'
$config->get('missing', 'fallback'); // 'fallback'
$config->set('app.env', 'prod');
```

## Public methods

### __construct() · <small>[🗎](../../src/Config/Config.php#L29)</small>

`public function __construct(array $data = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array | `[]` | Initial configuration. |

**Return value**

- Type: `mixed`


---

### get() · <small>[🗎](../../src/Config/Config.php#L41)</small>

`public function get(string $key, mixed $default = null): mixed`

Get a configuration value by dot-notation key.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Dot-separated key (e.g. 'db.dsn'). |
| `$default` | mixed | `null` | Default value if the key is not found. |

**Return value**

- Type: `mixed`
- Description: The value, or $default if not found.


---

### set() · <small>[🗎](../../src/Config/Config.php#L63)</small>

`public function set(string $key, mixed $value): void`

Set a configuration value by dot-notation key.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Dot-separated key. |
| `$value` | mixed | - | The value to set. |

**Return value**

- Type: `void`


---

### has() · <small>[🗎](../../src/Config/Config.php#L88)</small>

`public function has(string $key): bool`

Check if a configuration key exists.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Dot-separated key. |

**Return value**

- Type: `bool`
- Description: True if the key exists.


---

### all() · <small>[🗎](../../src/Config/Config.php#L108)</small>

`public function all(): array`

Return the entire configuration as a nested array.

**Return value**

- Type: `array`


---

### setArray() · <small>[🗎](../../src/Config/Config.php#L119)</small>

`public function setArray(array $data): void`

Replace the configuration data with a new array.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array | - |  |

**Return value**

- Type: `void`


---

### merge() · <small>[🗎](../../src/Config/Config.php#L130)</small>

`public function merge(array $data): void`

Merge another configuration array into this one (recursive).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array | - |  |

**Return value**

- Type: `void`


---

### scope() · <small>[🗎](../../src/Config/Config.php#L145)</small>

`public function scope(string $namespace): self`

Get a namespaced sub-configuration view.

Returns a new Config rooted at the given namespace, so
`$config->scope('db')->get('dsn')` is equivalent to
`$config->get('db.dsn')`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - | Dot-separated prefix. |

**Return value**

- Type: `self`



---

[Back to the Index ⤴](README.md)
