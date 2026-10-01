# Validation

A fluent input validator: validate and coerce associative arrays (form POST data, decoded JSON) against declarative field rules.

## Quick Start

```php
use Azera\Validation\Validator;
use Azera\Validation\ValidationException;

$v = new Validator($request->post());

$v->field('name')->required()->string()->min(2)->max(100);
$v->field('email')->required()->email()->max(255);
$v->field('age')->required()->int()->min(18)->max(120);
$v->field('bio')->optional()->string()->max(500);

if ($v->fails()) {
    return Response::json(['errors' => $v->errors()], 422);
}

$data = $v->validated(); // only validated and coerced fields
User::create($data);
```

Or throw on failure instead of branching:

```php
try {
    $data = $v->validate(); // throws ValidationException on failure
} catch (ValidationException $e) {
    return Response::json(['errors' => $e->errors()], 422);
}
```

## Registering Fields

Call `field(string $name)` to register a field and receive a fluent `FieldValidator`. Fields are **required by default**.

```php
$v->field('title');                        // required
$v->field('bio')->optional();              // missing key is silently ignored
$v->field('role')->default('viewer');      // missing key uses 'viewer'; implied optional
$v->field('count')->int()->default(0);
```

`default()` only applies when the field is **absent** from the input; a present key is validated and coerced normally. The default is included in `validated()` as-is — no rules are applied to it, so make sure it already matches the expected type. `default()` implicitly makes a field optional; calling `->optional()` alongside is allowed but redundant.

## Type Coercion

Type rules coerce the raw string value to the target PHP type. If coercion is not possible, the field fails and the remaining rules for it are skipped.

| Rule         | Accepts                                         | PHP type produced |
| ------------ | ----------------------------------------------- | ----------------- |
| `->int()`    | Integer strings, negative, native `int`         | `int`             |
| `->float()`  | Any numeric string, native `int`/`float`        | `float`           |
| `->bool()`   | `true`/`false`, `yes`/`no`, `on`/`off`, `1`/`0` | `bool`            |
| `->string()` | Anything castable (explicit cast)               | `string`          |

Without a type rule, the value is passed through as-is (HTTP input normally arrives as `string`; JSON input retains its decoded type).

```php
$v->field('count')->int();          // '42' → 42
$v->field('price')->float();        // '9.99' → 9.99
$v->field('active')->bool();        // 'yes' → true
$v->field('slug')->string();        // 123 → '123'
$v->field('meta')->optional();      // passed through unchanged
```

## Constraints

`min()` and `max()` apply to the value _after_ type coercion. Their meaning depends on type:

| Value type      | `min` / `max` measures         |
| --------------- | ------------------------------ |
| `string`        | Character length (`mb_strlen`) |
| `int` / `float` | Numeric value                  |
| `array`         | Item count (`count`)           |

```php
$v->field('username')->string()->min(3)->max(30);
$v->field('rating')->int()->min(1)->max(5);
$v->field('tags')->list(fn($f) => $f->string())->min(1)->max(10);
```

## Format Rules

Format rules validate the _content_ of the value without changing its type.

| Rule                       | Validates                                        |
| -------------------------- | ------------------------------------------------ |
| `->email()`                | RFC 5321 e-mail address                          |
| `->url()`                  | URL (`FILTER_VALIDATE_URL`)                      |
| `->ip()`                   | IPv4 or IPv6 address                             |
| `->domain()`               | Bare hostname (`example.com`), no scheme or path |
| `->pattern(string $regex)` | Value matches the regular expression             |
| `->in(array $allowed)`     | Strict (`===`) membership in the array           |
| `->custom(callable $fn)`   | User-defined callback                            |

```php
$v->field('email')->email();
$v->field('website')->optional()->url();
$v->field('client_ip')->ip();
$v->field('host')->domain();               // 'example.com' ✓  'https://example.com' ✗
$v->field('zip')->pattern('/^\d{5}$/');
$v->field('role')->in(['admin', 'editor', 'viewer']);
```

### Custom format callback

`custom()` accepts a callable that receives the current value and returns:

- `null` — valid, no error
- `string` — fail with that string as the error message
- `array` — structured error, giving the translator the same information as built-in rules:
  - `code` _(required)_ — error code passed to the translator
  - `params` _(optional)_ — raw values for `{placeholder}` substitution, default `[]`
  - `template` _(optional)_ — English fallback with `{placeholder}` markers; if omitted, looked up from the built-in code table or falls back to the code string itself

Multiple `custom()` calls stack; the first failure short-circuits.

```php
// Simple string — no translation support needed
$v->field('username')
    ->string()
    ->custom(fn($v) => ctype_alnum($v) ? null : 'must contain only letters and digits');

// Structured — translator receives code + params like a built-in rule
$v->field('amount')
    ->int()
    ->custom(fn($v) => $v % 5 === 0 ? null : [
        'code'     => 'not_multiple',
        'params'   => ['factor' => 5],
        'template' => 'must be a multiple of {factor}',
    ]);
```

## Structure Rules

### list()

Validates every element of an array against a sub-validator.

```php
// Each tag must be a non-empty string up to 50 characters
$v->field('tags')->optional()->list(fn($f) => $f->string()->min(1)->max(50));

// Each ID must be a positive integer
$v->field('ids')->list(fn($f) => $f->int()->min(1));
```

### model()

Validates a nested associative array. Each key maps to a callable that receives and configures a `FieldValidator`.

```php
$v->field('address')->required()->model([
    'street' => fn($f) => $f->required()->string()->max(200),
    'city'   => fn($f) => $f->required()->string()->max(100),
    'zip'    => fn($f) => $f->required()->pattern('/^\d{5}$/'),
    'state'  => fn($f) => $f->optional()->string()->max(50),
]);
```

`list` and `model` can be nested to any depth.

## Running Validation

| Method        | Return type             | Behavior                                                        |
| ------------- | ----------------------- | --------------------------------------------------------------- |
| `fails()`     | `bool`                  | Runs rules, returns `true` if any field failed                  |
| `errors()`    | `array<string, string>` | Returns all error messages, keyed by dot-path field name        |
| `validated()` | `array<string, mixed>`  | Fields that passed, with coerced values; failed fields excluded |
| `validate()`  | `array<string, mixed>`  | Like `validated()` but throws `ValidationException` on failure  |

## Error Format

Errors are keyed by a dot-path derived from field nesting:

```
name          → top-level field
address.zip   → sub-field inside a model()
ids[2]        → third element inside a list()
items[0].qty  → sub-field inside a list of models
```

A typical JSON error response:

```json
{
  "errors": {
    "email": "must be a valid email address",
    "address.zip": "has an invalid format",
    "ids[1]": "must be an integer"
  }
}
```

## Translation

By default `errors()` returns English messages. Use `setTranslator()` to provide translated messages or custom formatting.

### Translator callback

```php
$v->setTranslator(function (string $field, string $code, array $params, string $template): string {
    // $field    – dot-path of the field, e.g. 'email' or 'address.zip'
    // $code     – error code, e.g. 'required', 'min.string', 'email'
    // $params   – raw parameter values (native PHP types)
    // $template – English template with {placeholder} markers, e.g. 'must be at least {min}'

    // Return a translated template and the framework fills {placeholders},
    // or return a fully pre-rendered string (placeholders are a no-op then).
    return $template; // fallback: keep English
});
```

### Error codes and their `$params`

| Code                                      | `$params` keys (types)                                           |
| ----------------------------------------- | ---------------------------------------------------------------- |
| `required`                                | _(empty)_                                                        |
| `type.int`, `type.float`, `type.bool`     | _(empty)_                                                        |
| `min.string`, `min.number`, `min.array`   | `min: int\|float`                                                |
| `max.string`, `max.number`, `max.array`   | `max: int\|float`                                                |
| `email`, `url`, `ip`, `domain`, `pattern` | _(empty)_                                                        |
| `in`                                      | `allowed: array<mixed>`                                          |
| `not_array`, `not_object`                 | _(empty)_                                                        |
| `custom`                                  | _(empty when string returned; user-defined when array returned)_ |

The sub-code suffix (`.string`, `.number`, `.array`) tells the translator which context `min`/`max` relates to, so you can use different phrasing per context without inspecting `$params`.

### Examples

```php
// Translated template — framework fills {min}
$v->setTranslator(fn($field, $code, $params, $template) => match($code) {
    'min.string' => 'mindestens {min} Zeichen erforderlich',
    'required'   => 'Pflichtfeld',
    default      => $template,
});

// Field-aware message
$v->setTranslator(function ($field, $code, $params, $template) {
    if ($field === 'email' && $code === 'required') {
        return 'E-Mail is required — please enter a valid address';
    }
    return $template;
});

// Locale-aware number formatting (e.g. Persian numerals)
$v->setTranslator(function ($field, $code, $params, $template) use ($locale) {
    $translated = $myTranslations[$locale][$code] ?? $template;
    // replace {min}/{max} ourselves with locale-formatted numbers
    if (isset($params['min'])) {
        $translated = str_replace('{min}', formatNumber($params['min'], $locale), $translated);
    }
    return $translated;
});
```

`setTranslator()` returns `static` for chaining:

```php
$v = (new Validator($data))->setTranslator($myTranslator);
```

## Controller Integration

```php
class UserController extends Controller
{
    public function createAction(): Response|array
    {
        $v = new Validator($this->request()->post());
        $v->field('name')->string()->min(2)->max(100);
        $v->field('email')->email()->max(255);
        $v->field('role')->in(['admin', 'editor', 'viewer']);

        if ($v->fails()) {
            return Response::json(['errors' => $v->errors()], 422);
        }

        $user = User::create($v->validated());
        return Response::json(['id' => $user->id], 201);
    }
}
```

## Related

- [Controllers & Views](03-CONTROLLERS-VIEWS.md)
- [HTTP Request](06-HTTP-REQUEST.md)
- [Security](09-SECURITY.md)
