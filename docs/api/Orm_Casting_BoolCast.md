# Class: BoolCast

**Full name:** [Azera\Orm\Casting\BoolCast](../../src/Orm/Casting/BoolCast.php)

Scalar bool cast — see IntCast for the shared decode rationale.

Encode is NOT pass-through here, unlike the numeric casts. pdo_pgsql sends a
bound PHP `false` as the empty string, and PostgreSQL rejects '' for a
`boolean` column ("ungültige Eingabesyntax für Typ boolean"). PHP `true`
happens to work because it stringifies to '1', so the failure only ever
appears on rows that write `false`: a boolean column left at its default
breaks the INSERT while the identical row with the box ticked succeeds.

Normalising to '1'/'0' makes the wire format driver-independent — both
literals are accepted by PostgreSQL and by MySQL.

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/BoolCast.php#L28)</small>

`public function encode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/BoolCast.php#L60)</small>

`public function decode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
