# Class: SchemaProviderFactory

**Full name:** [Azera\Sync\Schema\SchemaProviderFactory](../../src/Sync/Schema/SchemaProviderFactory.php)

Factory for creating the correct [`SchemaProvider`](Sync_Schema_SchemaProvider.md) for a given
SQL connection based on its driver name.

Shared by the model-sync machinery ([`SyncRunner`](Sync_SyncRunner.md)) and
the CLI Database inspection task ([`DbTask`](Cli_Tasks_DbTask.md)) so
that driverâ†’provider mapping lives in exactly one place.

## Public methods

### create() · <small>[🗎](../../src/Sync/Schema/SchemaProviderFactory.php#L25)</small>

`public static function create(Azera\Db\Database $db): Azera\Sync\Schema\SchemaProvider`

Create a SchemaProvider for the given SQL connection.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$db` | [Database](Db_Database.md) | - | A connected Database instance. |

**Return value**

- Type: [SchemaProvider](Sync_Schema_SchemaProvider.md)
- Description: The provider matching the connection's driver.

**Throws**

- RuntimeException  If the driver has no registered provider.



---

[Back to the Index ⤴](README.md)
