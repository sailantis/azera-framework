# 🧩 Class: Validator

**Full name:** [Azera\Validation\Validator](../../src/Validation/Validator.php)

Validates and coerces an associative input array against a set of field rules.

Usage:

```php
  $v = new Validator($request->post());

  $v->field('email')->required()->email()->max(255);
  $v->field('age')->required()->int()->min(18)->max(120);
  $v->field('name')->optional()->string()->min(2)->max(100);
  $v->field('tags')->optional()->list(fn($f) => $f->string()->max(50));
  $v->field('address')->optional()->model([
      'street' => fn($f) => $f->required()->string(),
      'zip'    => fn($f) => $f->required()->pattern('/^\d{5}$/'),
  ]);

  if ($v->fails()) {
      return Response::json(['errors' => $v->errors()], 422);
  }
  $data = $v->validated();
```

Or in a single call (throws ValidationException on failure):

```php
  $data = $v->validate();
```

## 🚀 Public methods

### __construct() · [source](../../src/Validation/Validator.php#L53)

`public function __construct(array $data): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array | - | Raw input array (e.g. from Request::post()). |

**➡️ Return value**

- Type: mixed


---

### setTranslator() · [source](../../src/Validation/Validator.php#L80)

`public function setTranslator(callable $fn): static`

Set a translator callback invoked for each error when rendering messages.

The callback receives:
  - $field:          the dot-path field name (e.g. "address.zip", "tags[0]")
  - $code:           the error code (see table below)
  - $params:         raw parameters with native PHP types
  - $template:       the English template string with {placeholder} markers intact

The callback may return either a translated template (placeholders will be
replaced by the framework) or a fully pre-rendered string (str_replace is
a no-op when no markers remain). Return $template as-is to fall back to
the English default.

Error codes and their $params keys / types:
  required, type.int, type.float, type.bool,
  email, url, ip, domain, pattern,
  not_array, not_object              => params is []
  min.string, min.number, min.array  => ['min' => int|float]
  max.string, max.number, max.array  => ['max' => int|float]
  in                                 => ['allowed' => array<mixed>]
  custom                             => [] (template is the callback's own message)

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fn` | callable | - |  |

**➡️ Return value**

- Type: static


---

### field() · [source](../../src/Validation/Validator.php#L92)

`public function field(string $name): Azera\Validation\FieldValidator`

Register rules for a field and return the fluent FieldValidator.

Fields default to required. Call ->optional() on the returned validator
to make the field optional.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: [FieldValidator](Validation_FieldValidator.md)


---

### fails() · [source](../../src/Validation/Validator.php#L103)

`public function fails(): bool`

Run all rules. Returns true when at least one rule failed.

**➡️ Return value**

- Type: bool


---

### errors() · [source](../../src/Validation/Validator.php#L115)

`public function errors(): array`

Dot-path keyed error messages from the last run.

Empty when validation has not been run yet or all rules passed.

**➡️ Return value**

- Type: array


---

### validated() · [source](../../src/Validation/Validator.php#L146)

`public function validated(): array`

Returns only the fields that passed validation, with values coerced to
their declared types. Fields that failed are excluded.

**➡️ Return value**

- Type: array


---

### validate() · [source](../../src/Validation/Validator.php#L158)

`public function validate(): array`

Run validation and return the validated data, or throw on failure.

**➡️ Return value**

- Type: array

**⚠️ Throws**

- [ValidationException](Validation_ValidationException.md)



---

[Back to the Index ⤴](README.md)
