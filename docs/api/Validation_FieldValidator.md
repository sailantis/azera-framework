# Class: FieldValidator

**Full name:** [Azera\Validation\FieldValidator](../../src/Validation/FieldValidator.php)

Fluent validator for a single input field.

Chain rules to describe what the field must look like.
The validator is executed by [`Validator`](Validation_Validator.md)
(or the nested model/list machinery)
via the internal `validate()` method.

Example:
```php
  $v->field('email')->required()->email()->max(255);
  $v->field('age')->optional()->int()->min(18)->max(120);
  $v->field('tags')->optional()->list(fn($f) => $f->string()->max(50));
```

## Public methods

### required() · <small>[🗎](../../src/Validation/FieldValidator.php#L80)</small>

`public function required(): static`

**Return value**

- Type: `static`


---

### optional() · <small>[🗎](../../src/Validation/FieldValidator.php#L86)</small>

`public function optional(): static`

**Return value**

- Type: `static`


---

### isRequired() · <small>[🗎](../../src/Validation/FieldValidator.php#L92)</small>

`public function isRequired(): bool`

**Return value**

- Type: `bool`


---

### default() · <small>[🗎](../../src/Validation/FieldValidator.php#L102)</small>

`public function default(mixed $value): static`

Supply a default value used when the field is absent.

Calling default() implicitly makes the field optional.
The default is included in validated() as-is (no rules are applied to it).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `static`


---

### hasDefault() · <small>[🗎](../../src/Validation/FieldValidator.php#L110)</small>

`public function hasDefault(): bool`

**Return value**

- Type: `bool`


---

### getDefault() · <small>[🗎](../../src/Validation/FieldValidator.php#L115)</small>

`public function getDefault(): mixed`

**Return value**

- Type: `mixed`


---

### int() · <small>[🗎](../../src/Validation/FieldValidator.php#L125)</small>

`public function int(): static`

Coerce to integer. Accepts int values and numeric strings (including negatives).

**Return value**

- Type: `static`


---

### float() · <small>[🗎](../../src/Validation/FieldValidator.php#L134)</small>

`public function float(): static`

Coerce to float. Accepts any numeric value.

**Return value**

- Type: `static`


---

### bool() · <small>[🗎](../../src/Validation/FieldValidator.php#L143)</small>

`public function bool(): static`

Coerce to bool. Accepts true/false, 1/0, "true"/"false", "yes"/"no", "on"/"off".

**Return value**

- Type: `static`


---

### string() · <small>[🗎](../../src/Validation/FieldValidator.php#L152)</small>

`public function string(): static`

Explicitly cast to string. Useful for ensuring min/max applies to character length.

**Return value**

- Type: `static`


---

### min() · <small>[🗎](../../src/Validation/FieldValidator.php#L166)</small>

`public function min(int|float $n): static`

Minimum value / length / count depending on type:
  - string: minimum character length (mb_strlen)
  - int/float: minimum numeric value
  - array: minimum number of items

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$n` | int\|float | - |  |

**Return value**

- Type: `static`


---

### max() · <small>[🗎](../../src/Validation/FieldValidator.php#L175)</small>

`public function max(int|float $n): static`

Maximum value / length / count (same semantics as min).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$n` | int\|float | - |  |

**Return value**

- Type: `static`


---

### email() · <small>[🗎](../../src/Validation/FieldValidator.php#L184)</small>

`public function email(): static`

Value must be a valid e-mail address (RFC 5321).

**Return value**

- Type: `static`


---

### url() · <small>[🗎](../../src/Validation/FieldValidator.php#L191)</small>

`public function url(): static`

Value must be a valid URL (FILTER_VALIDATE_URL).

**Return value**

- Type: `static`


---

### ip() · <small>[🗎](../../src/Validation/FieldValidator.php#L198)</small>

`public function ip(): static`

Value must be a valid IPv4 or IPv6 address.

**Return value**

- Type: `static`


---

### pattern() · <small>[🗎](../../src/Validation/FieldValidator.php#L205)</small>

`public function pattern(string $regex): static`

Value must match the given regular expression.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$regex` | string | - |  |

**Return value**

- Type: `static`


---

### in() · <small>[🗎](../../src/Validation/FieldValidator.php#L216)</small>

`public function in(array $allowed): static`

Value must be strictly equal (===) to one of the allowed values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$allowed` | array | - |  |

**Return value**

- Type: `static`


---

### domain() · <small>[🗎](../../src/Validation/FieldValidator.php#L223)</small>

`public function domain(): static`

Value must be a valid domain name (e.g. example.com), without scheme or path.

**Return value**

- Type: `static`


---

### custom() · <small>[🗎](../../src/Validation/FieldValidator.php#L244)</small>

`public function custom(callable $fn): static`

Custom validation callback. Return:
  - null                  → valid, no error
  - string                → error with code 'custom' and the string as the message
  - array                 → structured error; supports the same keys as built-in errors:
      'code'     (required) – error code passed to the translator
      'params'   (optional) – raw parameter values for placeholder replacement, default []
      'template' (optional) – English fallback template with {placeholder} markers;
                              if omitted, looked up from the built-in TEMPLATES table
                              or falls back to the code string itself

Multiple custom() calls are supported and stack; the first failure short-circuits.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fn` | callable | - |  |

**Return value**

- Type: `static`


---

### list() · <small>[🗎](../../src/Validation/FieldValidator.php#L257)</small>

`public function list(callable $configure): static`

Value must be an array; each element is validated by the configured sub-validator.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$configure` | callable | - |  |

**Return value**

- Type: `static`


---

### model() · <small>[🗎](../../src/Validation/FieldValidator.php#L271)</small>

`public function model(array $fields): static`

Value must be an associative array matching the given field definitions.

Each entry maps a key name to a callable that configures a FieldValidator.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fields` | array | - |  |

**Return value**

- Type: `static`


---

### validate() · <small>[🗎](../../src/Validation/FieldValidator.php#L287)</small>

`public function validate(mixed $value, string $path, array &$errors): mixed`

Apply all configured rules to $value, appending any errors to $errors.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | The raw input value. |
| `$path` | string | - | Dot-path used as the error key. |
| `$errors` | array | - | Accumulated errors (mutated in place). |

**Return value**

- Type: `mixed`
- Description: The coerced / validated value.



---

[Back to the Index ⤴](README.md)
