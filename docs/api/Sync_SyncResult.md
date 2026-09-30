# Class: SyncResult

**Full name:** [Azera\Sync\SyncResult](../../src/Sync/SyncResult.php)

Holds the result of synchronising a single model file against the database schema.

## Public Properties

- `public` string `$filePath` · <small>[🗎](../../src/Sync/SyncResult.php)</small>
- `public` string `$className` · <small>[🗎](../../src/Sync/SyncResult.php)</small>
- `public` string `$tableName` · <small>[🗎](../../src/Sync/SyncResult.php)</small>
- `public` array `$operations` · <small>[🗎](../../src/Sync/SyncResult.php)</small>
- `public` bool `$applied` · <small>[🗎](../../src/Sync/SyncResult.php)</small>
- `public` string|null `$error` · <small>[🗎](../../src/Sync/SyncResult.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Sync/SyncResult.php#L17)</small>

`public function __construct(string $filePath, string $className, string $tableName, array $operations, bool $applied, string|null $error = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$filePath` | string | - | Absolute path to the model file |
| `$className` | string | - | Fully-qualified class name |
| `$tableName` | string | - | Database table that was introspected |
| `$operations` | array | - | All diff operations calculated |
| `$applied` | bool | - | Whether the operations were written to disk |
| `$error` | string\|null | `null` | Error message, or null on success |

**Return value**

- Type: `mixed`


---

### hasChanges() · <small>[🗎](../../src/Sync/SyncResult.php#L27)</small>

`public function hasChanges(): bool`

**Return value**

- Type: `bool`


---

### isSuccess() · <small>[🗎](../../src/Sync/SyncResult.php#L32)</small>

`public function isSuccess(): bool`

**Return value**

- Type: `bool`


---

### addedProperties() · <small>[🗎](../../src/Sync/SyncResult.php#L38)</small>

`public function addedProperties(): array`

**Return value**

- Type: `array`


---

### removedProperties() · <small>[🗎](../../src/Sync/SyncResult.php#L44)</small>

`public function removedProperties(): array`

**Return value**

- Type: `array`


---

### typeChanges() · <small>[🗎](../../src/Sync/SyncResult.php#L50)</small>

`public function typeChanges(): array`

**Return value**

- Type: `array`


---

### addedAccessors() · <small>[🗎](../../src/Sync/SyncResult.php#L56)</small>

`public function addedAccessors(): array`

**Return value**

- Type: `array`


---

### summary() · <small>[🗎](../../src/Sync/SyncResult.php#L64)</small>

`public function summary(): string`

Human-readable summary line.

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
