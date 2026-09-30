# Class: SyncOptions

**Full name:** [Azera\Sync\SyncOptions](../../src/Sync/SyncOptions.php)

Configuration options that control the behavior of the model-sync process.

## Public Properties

- `public` bool `$generateAccessors` · <small>[🗎](../../src/Sync/SyncOptions.php)</small>
- `public` string `$fieldVisibility` · <small>[🗎](../../src/Sync/SyncOptions.php)</small>
- `public` bool `$deprecate` · <small>[🗎](../../src/Sync/SyncOptions.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Sync/SyncOptions.php#L17)</small>

`public function __construct(bool $generateAccessors = false, string $fieldVisibility = 'public', bool $deprecate = true): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$generateAccessors` | bool | `false` | When true a camelized getter/setter method pair is<br>generated for every newly-added property. |
| `$fieldVisibility` | string | `'public'` | Visibility modifier applied to generated properties:<br>'public', 'protected', or 'private'. |
| `$deprecate` | bool | `true` | When false, properties whose columns have been removed<br>are left untouched instead of being tagged @deprecated. |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
