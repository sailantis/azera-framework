# Class: ValidationException

**Full name:** [Azera\Validation\ValidationException](../../src/Validation/ValidationException.php)

Thrown by Validator::validate() when one or more field rules fail.

The errors array is keyed by dot-path field name (e.g. "address.zip", "tags[0]")
and each value is a human-readable error message string.

## Public methods

### __construct() · <small>[🗎](../../src/Validation/ValidationException.php#L16)</small>

`public function __construct(array $errors): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$errors` | array | - | Dot-path field errors. |

**Return value**

- Type: `mixed`


---

### errors() · <small>[🗎](../../src/Validation/ValidationException.php#L24)</small>

`public function errors(): array`

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
