![Clarity Engine](images/clarity-engine-logo.svg)

**Sandboxed and compiled** — Clarity compiles `.clarity.html` templates into
cached PHP classes. A [policy](#policies) controls what each template can
access. The default allows render variables and registered filters/functions;
trusted templates can grant selected capabilities or enable full [PHP mode](#php-mode).

---

## Setup

`ClarityEngine` is the default view engine. Configure it in your bootstrap:

```php
use Azera\AppContext;

$ctx = AppContext::instance();

$ctx->view()
    ->setViewPath(__DIR__ . '/../views')  // optional, defaults to "views" in the project root
    ->setLayout('layouts/main');          // optional default layout
```

To use plain-PHP templates instead, swap to `NativeEngine`:

```php
use Azera\Core\Engines\NativeEngine;

$ctx->setView(new NativeEngine());
$ctx->view()->setViewPath(__DIR__ . '/../views');
```

### Configuration

| Method                                             | Description                                                                    |
| -------------------------------------------------- | ------------------------------------------------------------------------------ |
| `setViewPath(string $path)`                        | Base directory where templates are found                                       |
| `setLayout(?string $layout)`                       | Default layout template (`null` disables the layout)                           |
| `setExtension(string $ext)`                        | Override the file extension (default: `.clarity.html`)                         |
| `setCachePath(string $path)`                       | Directory for compiled PHP files (default: `sys_get_temp_dir()/clarity_cache`) |
| `getCachePath()`                                   | Return the current cache path                                                  |
| `flushCache()`                                     | Delete all compiled files – useful during development                          |
| `addFilter(string $name, callable $fn)`            | Register a custom filter                                                       |
| `addFunction(string $name, callable $fn)`          | Register a custom function                                                     |
| `addNamespace(string $ns, string $path)`           | Register a named directory for template resolution                             |
| `renderPartial(string $view, array $vars)`         | Render without applying the default layout                                     |
| `setPolicy(Policy\|array $policy)`                 | What templates may reach (default: `Clarity\Engine\Policy::restricted()`) — see [Policies](#policies) |
| `getPolicy(): Policy`                              | The current policy object                                                      |
| `isSandboxed(): bool`                              | Whether the policy lets templates reach PHP at all                             |
| `setDebugMode(bool $debug)` / `isDebugMode()`      | Runtime safety checks plus context-aware `dump()`/`dd()`                       |
| `addInlineFilter(string $name, array $definition)` | Zero-overhead filter compiled straight into the generated PHP                  |
| `addDirective(string $keyword, callable $handler)` | Custom `{% keyword %}` directive (emits PHP at compile time)                   |
| `use(ModuleInterface $module)`                     | Bundle filters/functions/directives into a self-registering module             |

---

## Template Syntax at a Glance

```
{{ expression }}          Output a value (auto-escaped)
{{ expression |> raw }}   Output raw HTML (no escaping; `raw` is a special marker that disables auto-escaping)
{% directive %}           Control flow, assignment, includes, inheritance, macros
{# comment #}             Template comment (removed at compile time)
{% for x in xs -%} … {%- endfor %}   `-` on either side trims whitespace around the tag
```

---

## Output Tags

```html
<p>Hello, {{ user.name }}!</p>
```

**Auto-escaping** is always applied: every output tag calls `htmlspecialchars()` automatically. To print raw HTML, pipe through `raw`:

```html
{# trusted HTML stored in a variable #}
<div>{{ body |> raw }}</div>
```

`raw` is a special compile-time marker handled by the Clarity engine. It acts as an identity within the filter pipeline and, when present anywhere in the chain, disables the automatic `htmlspecialchars()` wrap for the whole expression.

---

## Expressions

### Variable Access

Access is **strict**: the operator states what the value IS, and the engine emits exactly that read. **Objects stay objects** — there is no automatic object → array conversion before rendering; PHP's own visibility rules apply, so private/protected state is never exposed.

| Syntax                        | Meaning                                  | Emits                      |
| ----------------------------- | ---------------------------------------- | -------------------------- |
| `a.b.c`                       | **object property** (static)             | `$vars['a']->b->c`         |
| `a{expr}`                     | object property (dynamic)                | `$vars['a']->{$exprPhp}`   |
| `items[expr]`                 | **array index**                          | `$vars['items'][$exprPhp]` |
| `a:b:c`                       | **array key** (static)                   | `$vars['a']['b']['c']`     |
| `$a.b` / `$a->b`              | PHP-style alias for `.`                  | `$vars['a']->b`            |
| `a?.b` `a?[i]` `a?{k}` `a?:k` | optional **receiver**                    | `isset(…) ? … : null`      |
| `${expr}` / `$$name`          | variable whose **name** an expression produces | render-scope lookup (the `variableVariables` capability, on by default) |

```
user.name                 → $vars['user']->name
user:name                 → $vars['user']['name']
items[0]                  → $vars['items'][0]
items[index]              → $vars['items'][$vars['index']]
a.b[c.d].e                → $vars['a']->b[$vars['c']->d]->e
${which} / $$which        → the value of the variable NAMED by `which`
```

Applying the wrong operator is an error, not a silent `null`:

```twig
{% set user = { name: "Alice" } %}   {# an ARRAY #}
{{ user.name }}     {# ERROR: Cannot read property "name" on array #}
{{ user:name }}     {# CORRECT #}
```

A missing key or property raises an exception naming the template and line, while a key that is *present but holds `null`* returns `null` — only genuinely absent names throw.
Whitespace around a chain operator is not significant, so a chain may wrap
(`user.\naddress.\ncity`). `.` is never string concatenation — use `~` — and an
operator with no member after it is a compile error.

Objects iterate their public properties, and container filters (`length`, `keys`, `values`, `first`, `last`, `reverse`) accept arrays, `Traversable`, `Countable`, and objects with public state. A value object without public properties that is `Stringable` keeps string semantics.

```twig
{{ firstName ~ ' ' ~ lastName }}
```

#### Optional access guards the receiver only

`?` tolerates an **absent or null receiver**; the member read after it stays strict, so a typo still fails:

| Expression          | `user` absent/null | `user` present, `name` missing |
| ------------------- | ------------------ | ------------------------------ |
| `user.name`         | ERROR              | ERROR                          |
| `user?.name`        | `null`             | **ERROR**                      |
| `user.name ?? 'x'`  | ERROR              | `'x'`                          |
| `user?.name ?? 'x'` | `'x'`              | `'x'`                          |

### Operators

| Clarity                          | PHP equivalent | Notes                            |
| -------------------------------- | -------------- | -------------------------------- |
| `and` (also `&&`)                | `&&`           |                                  |
| `or` (also `\|\|`)               | `\|\|`         |                                  |
| `not` (also `!`)                 | `!`            |                                  |
| `~`                              | `.`            | String concatenation             |
| `==`, `!=`, `<`, `>`, `<=`, `>=` | same           |                                  |
| `+`, `-`, `*`, `/`, `%`          | same           |                                  |
| `bor`, `band`, `bxor`, `bnot`    | `\|`, `&`, `^`, `~` | Bitwise keywords (single `\|` is the filter pipe) |
| `blsh`, `brsh`                   | `<<`, `>>`     | Bit shifts                       |
| `??`                             | same           | Null coalescing                  |
| `x instanceof Foo`               | same           | Class test – works under every policy (no capability needed) |
| `true`, `false`, `null`          | same           |                                  |

#### Twig-Style Tests

Tests read as words and can be used anywhere a boolean is expected. The absence-tolerant tests (`is defined`, `is null`, `is empty`) answer without reading their operand, so they are safe on a name that was never passed to the template.

```twig
{% if 2 in [1, 2, 3] %}…{% endif %}
{% if name starts with 'Jo' %}…{% endif %}
{% if name ends with '.pdf' %}…{% endif %}
{% if title matches '/^Foo/' %}…{% endif %}
{% if n is even %}…{% endif %}        {# also: is odd, divisible by 3, is iterable #}
{% if a is same as(b) %}…{% endif %}
{% if user is defined %}…{% endif %}  {# also: is not defined, is null, is empty #}
```

```twig
{% if user.active and user.role == 'admin' %}
<span>Admin</span>
{% endif %}

<p>{{ firstName ~ ' ' ~ lastName }}</p>
```

Registered template functions are allowed in expressions. Built-in `context()`, `include()`, `range()`, `cycle()`, `attribute()` and `json()` are always available, and user code may register additional functions via `addFunction()`. Arbitrary PHP function calls such as `strtoupper(name)` are rejected at compile time unless the policy grants them (via `allowFunctions()`, a preset, or [PHP mode](#php-mode)).

### Nested Conditions

```twig
{{ cond ? (foo ? bar : blubb) : blobb }}
{{ user:active ? 'Active' : 'Inactive' }}
```

A nested ternary in the else-branch must be parenthesised, because PHP rejects
`a ? b : c ? d : e` outright.

### Collection Literals

```twig
{{ [1, 2, user.id] |> json |> raw }}
{{ { name: user.name, active: user.active } |> json |> raw }}
```

Object keys must be fixed identifiers or quoted strings.

### Spread Operator

```twig
{{ [1, ...items, 99] |> json |> raw }}
{{ { foo: "bar", ...payload } |> json |> raw }}
```

Spread is only valid inside array and object literals.

### Built-in Functions

Several Clarity functions are built in:

```twig
{{ context() |> json |> raw }}
{{ include("partials/card", { ...context(), title: "Hello" }) }}
{{ range(1, 5) |> join(', ') }}                 {# 1, 2, 3, 4, 5 (inclusive) #}
{{ cycle(['odd', 'even'], loopPosition) }}      {# the value at position mod length #}
{{ attribute(user, fieldName, 'n/a') }}         {# dynamic read with fallback #}
```

- `context()` returns the current template variable array (`$vars`).
- `include(view, context = [])` renders another template dynamically at runtime and returns its rendered markup.
- `range(low, high, step = 1)` returns an inclusive list of integers.
- `cycle(values, position)` returns the value at `position` modulo the list length — useful for alternating row classes inside loops.
- `attribute(subject, name, default = null)` performs a dynamic read that follows the same access model as `a.b` / `a:b`.
- `include()` resolves paths from the view base path or registered namespaces, just like `{% include %}` and `{% extends %}`.
- Markup returned by `include()` bypasses auto-escaping when output directly or piped (the compiler flags the call as safe). Note that once stored in a variable via `{% set %}` it is an ordinary string — re-outputting it applies standard escaping.

### Named Arguments

Filters and functions accept named arguments with the `name:value` syntax, emitted as PHP 8 named arguments:

```twig
{{ text |> truncate(length: 50) }}
{{ name |> slug(separator: '_') }}
{{ n |> round(precision: 2) }}
{{ t |> truncate(100, ellipsis: '...') }}   {# positional + named mix #}
```

---

## Filter Pipeline (`|>`)

Filters transform a value before it is output. Chain multiple filters with `|>`:

```twig
{{ user.name |> upper }}
{{ price |> number(2) }}
{{ createdAt |> date('d.m.Y') }}
{{ description |> trim |> upper }}
```

The single `|` (Twig/Svelte style) is fully interchangeable with the fat pipe `|>`; `||` is always logical OR, and `bor` is the bitwise-OR keyword.

### One Call Model

Filters and functions share one namespace: every registered name can be used piped *or* called. The piped value becomes the first argument, so `{{ trim(name) }}` equals `{{ name |> trim }}`. Two names mirror their PHP builtins in call form instead:

| Name   | Filter form               | Function form           | Mirrors              |
| ------ | ------------------------- | ----------------------- | -------------------- |
| `date` | `ts \|> date('Y-m-d')`    | `date('Y-m-d', ts)`     | `date($format, $ts)` |
| `join` | `items \|> join(', ')`    | `join(', ', items)`     | `implode($glue, $arr)` |

A registered name always wins over a same-named PHP builtin in both forms.

Filters with arguments use parentheses after the filter name:

```twig
{{ amount |> number(0) }} {# 0 decimal places #}
{{ timestamp |> date('H:i') }} {# format as time #}
```

### Built-in Filters

#### Strings

| Filter                        | Signature                            | Description                                     |
| ----------------------------- | ------------------------------------ | ----------------------------------------------- |
| `trim`                        | `(value)`                            | Remove leading/trailing whitespace              |
| `upper` / `lower`             | `(value)`                            | `mb_strtoupper` / `mb_strtolower`               |
| `capitalize` / `title`        | `(value)`                            | First-letter case / title-case every word       |
| `length` (alias: `len`)       | `(value)`                            | `mb_strlen` for strings, `count` for arrays     |
| `replace(search, replace)`    | `(value, …)`                         | `str_replace`                                   |
| `split(delimiter, limit?)`    | `(value, …)`                         | `explode` into an array                         |
| `join(glue)`                  | `(value, …)`                         | `implode`; call form takes glue first           |
| `truncate(length, ellipsis?)` | `(value, …)`                         | Truncate to length, append `…`                  |
| `slug(separator?)`            | `(value, …)`                         | URL-friendly slug with Unicode transliteration  |
| `striptags(allowed?)`         | `(value, …)`                         | Strip HTML/PHP tags                             |
| `nl2br`                       | `(value)`                            | `<br>` before newlines (use with `\|> raw`)     |
| `sprintf(...args)` / `format` | `(value, …)`                         | Value is the format string                      |
| `escape` (aliases: `e`, `esc`) | `(value)`                           | HTML-escape (rarely needed; auto-escaping is on) |
| `url_encode`                  | `(value)`                            | `rawurlencode`                                  |

#### Numbers

| Filter                | Signature        | Description            |
| --------------------- | ---------------- | ---------------------- |
| `number(decimals=2)`  | `(value, …)`     | `number_format`        |
| `abs`                 | `(value)`        | Absolute value         |
| `round(precision=0)`  | `(value, …)`     | Round to precision     |
| `ceil` / `floor`      | `(value)`        | Round up / down        |

#### Dates

| Filter                  | Signature        | Description                                     |
| ----------------------- | ---------------- | ----------------------------------------------- |
| `date(format='Y-m-d')`  | `(value, …)`     | Formats a Unix timestamp, date string, or `DateTimeInterface` |
| `date_modify(modifier)` | `(value, …)`     | Apply a modifier (e.g. `'+1 day'`), returns timestamp    |
| `format_datetime(...)`  | `(value, …)`     | `IntlDateFormatter` styles (requires `intl`)    |

#### Arrays & Collections

| Filter                   | Signature              | Description                                        |
| ------------------------ | ---------------------- | -------------------------------------------------- |
| `first` / `last`         | `(value)`              | First/last element (arrays and strings)            |
| `keys` / `values`        | `(value)`              | Array keys / re-indexed values                     |
| `slice(start, length?)`  | `(value, …)`           | `array_slice` / `mb_substr`                        |
| `merge(other)`           | `(value, …)`           | `array_merge`                                      |
| `sort`                   | `(value)`              | Sorted **copy** (never in place)                   |
| `reverse`                | `(value)`              | Reverse array or string (Unicode-aware)            |
| `shuffle`                | `(value)`              | Shuffled copy                                      |
| `batch(size, fill?)`     | `(value, …)`           | Chunk into batches, optionally padded              |
| `map(fn)`                | `(value, callable)`    | Transform each element (see [Lambda Expressions](#lambda-expressions)) |
| `filter(fn?)`            | `(value, callable?)`   | Keep elements matching a predicate; keys preserved |
| `reduce(fn, initial?)`   | `(value, callable, …)` | Reduce to a single value                           |

#### Utility

| Filter                  | Signature        | Description                                  |
| ----------------------- | ---------------- | -------------------------------------------- |
| `json`                  | `(value)`        | `json_encode` (use with `\|> raw`)           |
| `default(fallback)`     | `(value, …)`     | Fallback on `null`/missing (`??` semantics)  |
| `empty(fallback)`       | `(value, …)`     | Fallback on any falsy value (`?:` semantics) |
| `data_uri(mime?)`       | `(value, …)`     | Base64 `data:` URI                           |
| `unicode`               | `(value)`        | Wrap in `UnicodeString` for Unicode ops      |

> `|> filter` keeps the original keys and does **not** reindex — follow with `|> values` when a zero-based list is needed. `|> map` also preserves keys.

### Escaping Contexts

Clarity auto-detects `<script>` and `<style>` regions and switches escaping accordingly, and a comment hint can override it:

```twig
{# @context js #}
var userData = {{ user |> json }};
{# @context html #}
<p>{{ message }}</p>
```

| Context | Escaping applied to output expressions                  |
| ------- | ------------------------------------------------------- |
| `html`  | `htmlspecialchars()` (default)                          |
| `js`    | `json_encode()` with hex escaping (safe for inline JS)  |
| `css`   | Cast to `(string)` — no HTML escaping                   |

### Custom Filters

```php
$ctx->view()->addFilter('currency', fn($v, string $sym = '€') =>
    number_format($v, 2) . ' ' . $sym
);

$ctx->view()->addFilter('excerpt', fn($v, int $len = 100) =>
    mb_strlen($v) > $len ? mb_substr($v, 0, $len) . '…' : $v
);
```

Use them in templates:

```twig
{{ product.price |> currency }}
{{ product.price |> currency('$') }}
{{ article.body |> excerpt(150) }}
```

---

## Lambda Expressions

The `map`, `filter`, and `reduce` filters accept a **lambda expression** or a **filter reference** as their callable argument. Arbitrary PHP callables cannot be injected through template variables.

### Lambda syntax

```
param => expression
```

The parameter is bound to the current element; the body is a full Clarity expression — it can access outer template variables and use the filter pipeline (`|>`).

```twig
{# Extract a field from every item #}
{{ users |> map(u => u.name) |> join(', ') }}
{# Keep only active items, then extract labels #}
{{ items |> filter(i => i.active) |> map(i => i.label) |> join(', ') }}
{# Apply a filter inside the lambda body #}
{{ tags |> map(t => t.name |> upper) |> join(', ') }}
{# Access an outer template variable inside the lambda #}
{{ words |> map(w => w ~ suffix) |> join(' ') }}
```

### Reduce: two parameters are required

`reduce` passes two arguments to its callable: the accumulator and the current element. The lambda must declare **both** names separated by a comma — a one-parameter lambda (or an inline filter reference, which is unary) is rejected at compile time.

```twig
{# Sum a list of numbers (0 is the optional initial value) #}
{{ numbers |> reduce(sum, value => sum + value, 0) }}
{# Build a string #}
{{ words |> reduce(acc, word => acc ~ ' ' ~ word, '') }}
{# Total price #}
{{ cart:items |> reduce(total, item => total + (item:price * item:quantity), 0) |> number(2) }}
```

### Filter references

A quoted string resolves to a registered Clarity filter, so existing filters can be reused as callbacks:

```twig
{# 'upper' is a built-in filter #}
{{ tags |> map("upper") |> join(', ') }}
{# Use a custom filter registered via addFilter() #}
{{ prices |> map("currency") |> join(', ') }}
```

`map` expects a **transformer** (return value replaces the element), `filter` expects a **predicate** (return value is only tested; the original element passes through), and `reduce` needs a two-parameter lambda — a unary filter reference is rejected.

> **Security note:** Only registered Clarity filters can be referenced this way. Arbitrary PHP function names are rejected at compile time.

---

## Control Flow

### If / Elseif / Else

```twig
{% if stock > 0 %}
<button>Add to cart</button>
{% elseif stock == 0 %}
<span>Out of stock</span>
{% else %}
<span>Unavailable</span>
{% endif %}
```

### For Loops

**Iterate over a list:**

```twig
<ul>
  {% for item in items %}
  <li>{{ item.name }}</li>
  {% endfor %}
</ul>
```

**Key and value together** — the **first** name is the key, the **second** the value (Twig order):

```twig
{% for k, v in settings %}
<p>{{ k }}: {{ v }}</p>
{% endfor %}
```

**Inclusive range** (`..`) – last value is included:

```twig
{% for i in 1..10 %} {{ i }} {% endfor %}
{# prints 1 2 3 4 5 6 7 8 9 10 #}
```

**Exclusive range** (`...`) – last value is not included:

```twig
{% for i in 1...10 %} {{ i }} {% endfor %}
{# prints 1 2 3 4 5 6 7 8 9 #}
```

**With a step:**

```twig
{% for i in 0..100 step 10 %} {{ i }} {% endfor %}
{# prints 0 10 20 30 40 50 60 70 80 90 100 #}
```

Ranges can use variables:

```twig
{% for i in start..end step stride %} {{ i }} {% endfor %}
```

### Loop `else` Branch

A loop body may be followed by `{% else %}`, rendered when the sequence is empty. It works for all loop forms — over arrays, over mappings with `for k, v`, and over ranges:

```twig
<ul>
  {% for user in users %}
  <li>{{ user.name }}</li>
  {% else %}
  <li class="empty">No users yet.</li>
  {% endfor %}
</ul>
```

Two rules: the loop variable does not exist in the else branch, and a loop takes exactly one `{% else %}` (a second one or an `{% elseif %}` is a compile error). `{% else %}` belongs to the innermost open construct at the same nesting level.

### Macros

Macros are reusable fragments defined once and expanded **inline at compile time** (zero runtime overhead):

```twig
{% macro @card(title, body) %}
<div class="card">
  <h3>{{ title }}</h3>
  <p>{{ body }}</p>
</div>
{% endmacro %}

{% @card("Welcome", "Hello from Clarity!") %}
{% @card(article.title, article.excerpt) %}
```

- Macro names are prefixed with `@` in both the definition and the call.
- Parameters can reference any expression available at the call site.
- Macros cannot call themselves recursively (cycle detection throws a compile error).
- Macros defined in a statically included template become available once it is included — a handy way to ship macro libraries.

### Variable Assignment

```twig
{% set total = items |> length %}
{% set label = user.firstName ~ ' ' ~ user.lastName %}

<p>{{ total }} items for {{ label }}</p>
```

Assigned variables are scoped to the current template and blocks.

---

## Template Inheritance

A child template extends a parent layout and overrides named blocks.

**Parent layout** (`layouts/main.clarity.html`):

```twig
<!DOCTYPE html>
<html>
  <head>
    <title>{% block title %}My App{% endblock %}</title>
    {% block head %}{% endblock %}
  </head>
  <body>
    {% block body %}{% endblock %} {% block footer %}
    <footer>&copy; My App</footer>
    {% endblock %}
  </body>
</html>
```

**Child template** (`pages/home.clarity.html`):

```twig
{% extends "layouts/main" %}
{% block title %}Home – My App{% endblock %}
{% block body %}
<h1>Welcome, {{ user.name }}!</h1>
{% endblock %}
```

- Blocks not overridden in the child retain the parent's default content.
- Inheritance is resolved at **compile time** – no runtime overhead.
- Nesting is supported: a child layout may itself extend another parent.
- Only leading `{% set %}` directives (plus comments/whitespace) written before `{% extends %}` are carried into the merged template, so they can feed the layout's blocks; rendered content outside blocks in a child template is ignored.
- `{% extends %}` must come before any rendered output.

### Parent Block Fallback

Inside an overriding child block, `{% @parent %}` inlines the parent block's content at that position:

```twig
{% extends "layouts/main" %}

{% block title %}
Admin | {% @parent %}
{% endblock %}
```

`{% @parent %}` is only valid inside an overriding child block, can appear multiple times in the same block, and refers to the **immediate** parent in multi-level chains.

---

## Includes

Embed another template inline with `{% include %}`. The included file shares the current variable scope and is inlined at compile time (no separate render call). Recursive include chains are rejected during compilation.

```twig
{% include "partials/nav" %}
{% include "partials/user_card" %}
```

### Dynamic Include Function

Use `include()` when the target template or the include context must be decided dynamically at render time:

```twig
{{ include("partials/user_card", { role: "admin", ...context() }) }}
{{ include(selectedTemplate, context()) }}
```

Unlike `{% include %}`, the `include()` function performs a separate render call at runtime and returns the rendered markup directly. A static `{% include %}` can also act as a macro library: macros defined there become callable in the including template.

### Named Namespaces

Register a named directory and reference templates with the `namespace::path` syntax:

```php
$ctx->view()->addNamespace('admin', __DIR__ . '/../views/admin');
```

```twig
{% include "admin::partials/sidebar" %} {% extends "admin::layouts/main" %}
```

Dots and slashes are interchangeable as path separators:

```twig
{% include "admin::partials.sidebar" %} {# same as above #}
```

Unprefixed names resolve against the base view path. Namespaces are also available to the dynamic `include()` function: `{{ include("admin::partials/sidebar") }}`.

---

## Layouts via Controller

Use the view engine helpers in your controller as usual – `ClarityEngine` honors the same `ViewEngine` API:

```php
class ArticleController extends Controller
{
    public function showAction(int $id): string
    {
        $article = Article::findOrFail($id);

        // No layout for this response
        $this->view()->setLayout(null);
        return $this->view()->render('articles/show', [
            'article' => $article,
        ]);
    }
}
```

The `content` variable is automatically injected into the layout template when a layout is active (identical to `NativeEngine` behaviour).

---

## Caching

Compiled classes are cached as PHP files and served through OPcache. Clarity
invalidates them when a template or its dependencies change, or when the
compiler version, debug flag, or policy digest changes. Policy changes include
preset, capability, and allowlist updates. You do not need to flush the cache
after upgrading Clarity Engine.

```php
// Custom cache location
$ctx->view()->setCachePath('/var/cache/clarity');

// Flush during development when template changes are not being picked up
$ctx->view()->flushCache();
```

> **Tip:** In production, point the cache to a persistent directory outside the system temp dir and ensure the web server user has write access.

---

## Debug Mode

```php
$ctx->view()->setDebugMode(true);                      // everything on
$ctx->view()->setDebugMode(new DumpOptions(maxDepth: 3)); // on, with options
$ctx->view()->setDebugMode(false);                     // everything off
$ctx->view()->isDebugMode();                           // check the current state
```

There is one debug switch. `setDebugMode()` turns on the compiler assertions, the
context-aware renderers, the event bus and the panel, and turns all of them back
off; `enableDebug()` is still accepted as a deprecated alias.

When active:

- `{{ dump(user, settings) }}` renders a rich, context-aware dump instead of being pruned to nothing in production; `{{ items |> dump |> length }}` probes mid-pipeline and passes its value through. With debug off every `dump` form is eliminated, and `{{ dd(x) }}` throws rather than dumping raw values — it is the one form that is never pruned.
- Range loops get runtime safety checks: a step of `0`, or one that moves away from the end, throws instead of looping forever.
- Cache files compiled with a different debug flag recompile automatically.

---

## Policies

A **policy** controls what a template can reach through capabilities and two
allowlists. Clarity checks it at compile time, so a violation prevents
compilation and adds no render-time cost. Templates are sandboxed by default.

### Presets

```php
use Clarity\Engine\Policy;

$ctx->view()->setPolicy(Policy::restricted());   // the default — nothing to reach PHP
$ctx->view()->setPolicy(Policy::trusted());      // raw PHP, methods, superglobals — but no `new`/`::`
$ctx->view()->setPolicy(Policy::unrestricted()); // every capability: the full power of PHP
$ctx->view()->setPolicy(Policy::default()        // the default, plus the grants you name
    ->allowCapability('methodCalls')
    ->allowFunctions('strtoupper', 'count'));
```

Each preset is named for what it grants. `default()` is not a blank slate: it
starts from `restricted()`, so every capability you do not name stays off.

| Capability          | Default | What it grants                                                     |
| ------------------- | ------- | ------------------------------------------------------------------ |
| `rawPhp`            | `false` | `{% php CODE %}` tags                                              |
| `methodCalls`       | `false` | `obj.method(args)` / `$obj->method(args)`, with arguments and dynamic names |
| `superglobals`      | `false` | `$_SERVER`, `$_GET`, `$_ENV`, … as chain roots                     |
| `phpVariables`      | `false` | the render scope seeded into PHP locals (`{% php echo $title; %}`) |
| `variableVariables` | `true`  | `$$name` / `${expr}` dynamic variable access                       |
| `newExpressions`    | `false` | `new Foo(args)`                                                    |
| `staticCalls`       | `false` | `Foo::method(args)`, `Foo::CONST`, `Foo::class`                    |

`Policy::trusted()` enables `rawPhp`, `methodCalls`, `superglobals`, and
`phpVariables`, but not `newExpressions` or `staticCalls`. Those class-reaching
capabilities can expose code the application did not pass to the template;
method calls are limited to objects the host provides.

### Allowlists: the one rule

The `functions` allowlist limits bare calls and filter steps that fall back to
PHP functions. The `filters` allowlist limits names after `|>`. For both:

> **An empty allowlist adds no restriction; a non-empty one is the complete set.**
> Other names fail at compile time. Registered filters and functions are not
> restricted by these allowlists.

An allowlist **narrows**; it never opens a door. It is consulted only where a
capability has already made a construct reachable, so
`Policy::restricted()->allowFunctions('count')` stays sandboxed — there is no
construct for the grant to apply to. Pair it with a capability:

```php
$ctx->view()->setPolicy(
    Policy::restricted()->allowCapability('methodCalls')->allowFunctions('count')
);
```

`Policy::unrestricted()` enables every capability and leaves both allowlists
empty. Allowlists can narrow it; `denyFunctions()` then blocks named functions.
Use it for rules such as “allow everything except `exec`”:

```php
$ctx->view()->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system', 'proc_open'));
```

### Array form (config files)

`setPolicy()` also accepts the array form, which `Policy::fromArray()` / `toArray()` round-trip — every key and capability name is validated, and a typo is refused rather than ignored:

```php
$ctx->view()->setPolicy([
    'capabilities'    => ['methodCalls' => true],
    'functions'       => ['strtoupper', 'count'],
    'deniedFunctions' => ['exec'],
]);
```

### Inspecting a policy

```php
$V = $ctx->view();
$V->getPolicy();                   // always a real object (fresh engine: Policy::restricted())
$V->isSandboxed();                 // true while no capability reaches PHP
$V->getPolicy()->isUnrestricted(); // the former PHP mode: everything on, nothing restricted
$V->getPolicy()->allowsPhp();      // a capability reaches PHP
```

Every rejection is a compile-time `ClarityException` naming the grant that would fix it — `Grant the 'rawPhp' capability to allow it.`, `Add it with allowFunctions().`, and so on.

`addFunction('upper', $fn)` registers a callable. `allowFunctions('strtoupper')`
allows a PHP function to be called by name. If a name is both registered and
allowed, the registered callable takes precedence.

### Allowlisted PHP functions without an unrestricted policy

An allowlist narrows an existing capability; it does not create one. To let a
bare call through, name the capability that makes the construct reachable
**and** the functions it may use:

```php
$ctx->view()->setPolicy(
    Policy::restricted()->allowCapability('methodCalls')->allowFunctions('strtoupper', 'number_format')
);
```

The allowlist alone changes nothing: `Policy::restricted()` reaches no PHP, so
`Policy::restricted()->allowFunctions('strtoupper')` is still sandboxed — the
same as the old `setSandboxMode(true)` with an allowlist added. `addFunction()`
remains the way to expose a callable to a plain sandbox.

Registered filters are not affected by either allowlist:

```twig
{{ user.name |> trim }}           {# fine: `trim` is a registered filter #}
```

---

## PHP Mode

`Policy::unrestricted()` or another policy with PHP-reaching capabilities is
**PHP mode** (also called _open mode_). The syntax stays the same, and
registered filters/functions take precedence. Access changes only for
unregistered names or explicitly granted constructs. Use PHP mode only for
trusted templates, never for a template selected by a request.

```twig
{# granted by capabilities #}
{{ strtoupper(name) }}            {# any PHP function #}
{{ 'ab' |> str_pad(3, '-') }}     {# any PHP function as a filter step #}
{{ $user->greet() }}              {# method calls (the sigil is optional) #}
{{ user.greet() }}                {# the same call, without the `$` #}
{% php echo "Hi"; %}              {# one-statement raw PHP #}
{% php
$total = 0;
foreach ($items as $item) { $total += $item['qty']; }
echo $total;
%}                                 {# a tag may span lines #}
{{ new DateTimeImmutable("now") }} {# with newExpressions #}
{{ DateTime::ATOM }}               {# with staticCalls #}
```

The old `{% php %}…{% endphp %}` block form is no longer supported. Use
`{% php CODE %}`; the tag can span multiple lines. A bare `{% php %}` fails
at compile time because it has no code.

**PHP mode is equivalent to executing arbitrary PHP.** Nothing is denied by default in `Policy::unrestricted()` — an unrestricted policy is itself the security decision. If the application wants guardrails on top, it adds them:

```php
$ctx->view()->setPolicy(Policy::unrestricted()->denyFunctions('exec', 'system'));
```

Each compiled template records a **digest** of its policy. If the policy
changes, Clarity recompiles the template on its next render, so it is not
served under a different policy. See [Caching](#caching).

---

## The Default Policy Refuses

With the default `Policy::restricted()`, everything below is rejected **at compile time** (the template won't compile):

```twig
{{ strtoupper(x) }}     {# ERROR: unregistered function — register it via addFunction() or allowlist it #}
{{ $obj->method() }}    {# ERROR: needs the 'methodCalls' capability #}
{% php echo 1; %}       {# ERROR: needs the 'rawPhp' capability #}
{{ $_SERVER['HOST'] }}  {# ERROR: a scope read of an absent name (needs 'superglobals') #}
{{ new DateTime() }}    {# ERROR: needs the 'newExpressions' capability #}
{{ DateTime::ATOM }}    {# ERROR: needs the 'staticCalls' capability #}
```

What holds in **every** policy, including an open one:

- Direct access to engine internals: identifiers prefixed `__c_` are engine-owned; a template cannot *bind* a `__c_`-prefixed name (it would swap an internal for the rest of the render).
- A callable cannot be smuggled through a variable: `$fn()` on the *root* value is rejected (the function-level equivalent of variable-variable expansion), and `map`/`filter`/`reduce` only accept lambda expressions or filter references.
- `superglobals` is independent of `phpVariables`: without it, a superglobal name is an ordinary scope read of a name the scope does not hold and therefore throws — even when `phpVariables` is granted.
- `${expr}` / `$$name` dynamic access resolves only against the render scope and loop locals; it cannot reach a superglobal or an engine internal in any policy.

### Security Sandbox (default policy)

Under the default policy, Clarity templates have **no access to PHP**:

- Direct PHP variables (`$name` as a scope read) are rejected; the `$` sigil is only syntax for property access on scope values (`$user->name`).
- Function calls (`strtoupper(x)`) are rejected at compile time — use the filter pipeline, register the function, or grant it via `allowFunctions()`.
- Method calls (`a.b()`) are rejected without the `methodCalls` capability. The capability alone decides — both `a.b()` and `$a.b()` are the same call, and granting it makes both work.
- **Objects stay objects.** No automatic object → array conversion happens: `a.b` performs a real public-property read and container operations (`{% for %}`, `length`, `keys`, `values`, `first`, `last`) read an object's public properties or its `Traversable` state. Private/protected state is never exposed, `toArray()`/`JsonSerializable` are not consulted on the access path, and a value object without public properties that is `Stringable` renders via `__toString()`.
- Statement delimiters (`;`), backticks, heredocs, and PHP open/close tags are disallowed in expressions.
- Superglobals are out of reach without the dedicated capability; the engine's internal `__c_`-prefixed namespace is unreachable in **every** policy; `instanceof` is the one class-name construct available everywhere (it takes a class name because that is what the operator means and reaches nothing the scope did not already hold — `{% if order instanceof DateTimeInterface %}` works).
- `map`, `filter`, and `reduce` only accept lambda expressions or filter references — passing a callable via a template variable is rejected at compile time.

View-path boundary: a template name may not address a file outside the view path — absolute names and any `..` segment are refused, whatever the spelling.

---

## Benchmark Results

A micro-benchmark in the `azera-competition` repository compares Clarity
(compiled templates) with the other popular PHP template engines rendering
one identical page — a layout, an included partial, a loop over the items, and
nested loops inside it. `NativeEngine` and Clarity are engines inside Azera; the
rest are the template languages Azera adapts via its view adapter layer.

The chart and the table below are generated from the run's own JSON, so the
figures cannot drift from the data they came from:

<!-- view-engine:begin -->

Clarity is measured against other PHP template engines rendering the same page, on the same machine and PHP build. The chart and the table below are generated from the run's own JSON.

![Template engine benchmark](images/benchmarks/view-engine/render-time.svg)

Rows are ordered by median, fastest first. A difference of a few percent is a tie — it is within a single engine's own run-to-run spread.

| Engine   | First render (ms) | Mean (ms) | Median (ms) | Min (ms) | p95 (ms) | Retained (MB) | Peak (MB) |
| -------- | ----------------: | --------: | ----------: | -------: | -------: | ------------: | --------: |
| Clarity  |            16.276 |     0.436 |       0.421 |    0.397 |    0.507 |          1.03 |      1.61 |
| Stempler |            23.179 |     0.443 |       0.427 |    0.398 |    0.518 |          1.29 |      1.71 |
| Native   |             0.686 |     0.464 |       0.449 |    0.423 |    0.537 |          0.79 |      1.61 |
| Plates   |             2.684 |     0.551 |       0.531 |    0.493 |    0.644 |          0.86 |      1.61 |
| Latte    |            46.966 |     0.720 |       0.692 |    0.661 |    0.840 |          1.44 |      7.12 |
| Blade    |            31.396 |     0.750 |       0.724 |    0.687 |    0.873 |          1.57 |      2.05 |
| Twig     |            35.577 |     1.288 |       1.249 |    1.195 |    1.506 |          1.48 |      1.79 |

**Environment** — PHP 8.3.33 · Linux 6.8.0-139-generic · SAPI cli · OPcache (`opcache.enable_cli`): yes

**Budget** — 10,000 renders × 30 runs, 200 items per render

**Method** — Steady-state timings: the render loop for each (engine, page) cell runs in its own fresh process against a warm cache: one untimed warm-up render, then runs x iterations-per-run timed renders. No order: each (engine, page) cell is measured in its own process, so measurement order cannot affect a cell. The first render was measured as one render in a fresh process with a cold template cache: engine class loading, template compile, cache write and one render.

**Engines** — Clarity dev-main (cbe1b05+dirty) · NativeEngine (Azera) dev-main (5a0c30e+dirty) · Plates 3.6.0 · Blade 12.69.2 · Twig 3.27.0 · Stempler 3.17.2 · Latte 3.1.6

_Measured 2026-09-30T23:30:00+00:00_

Full report — every chart, including the first render and per-run memory: <https://sailantis.github.io/azera-competition/benchmarks/view-engine.html>

<!-- view-engine:end -->

The harness lives in `azera-competition` (not in this repository): run
`php benchmarks/view-engine/run.php` there, then
`php scripts/view-engine-report.php` to regenerate this section.

## Choosing Between ClarityEngine and NativeEngine

|                       | ClarityEngine _(default)_                                        | NativeEngine                             |
| --------------------- | ---------------------------------------------------------------- | ---------------------------------------- |
| Template syntax       | Clarity DSL (`{{ }}`, `{% %}`, `{# #}`)                          | Plain PHP (`<?= ?>`, `<?php ?>`)         |
| Sandboxed             | Yes (opt-in grants via [policies](#policies))                    | No                                       |
| Auto-escaping         | Always on by default, context-aware (`html`/`js`/`css`)          | Manual                                   |
| Compilation & caching | Yes                                                              | No                                       |
| Template inheritance  | `{% extends %}` / `{% block %}` (+ `{% @parent %}`)              | require/include                          |
| Filter pipeline       | 40+ built-in filters, one call model, lambdas, named arguments   | Not built-in                             |
| Reusable fragments    | `{% macro %}` / `{% @name() %}`, compile-time inlining           | require/include                          |
| Suitable for          | User-facing views, team projects, untrusted template authors     | Full PHP control, existing PHP templates |

---

## Full Bootstrap Example

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Azera\AppContext;
use Azera\Db\Database;
use Azera\Http\Response;
use Azera\Http\SessionMiddleware;
use Azera\Core\Dispatcher;
use Azera\Core\Router;

$ctx = AppContext::instance();

// Database
$ctx->dbManager()->set('default', fn() => new Database(
    'mysql:host=localhost;dbname=myapp', 'user', 'pass'
));

// ClarityEngine is the default – just configure it
$ctx->view()
    ->setViewPath(__DIR__ . '/../views')
    ->setLayout('layouts/main')
    ->setCachePath('/var/cache/clarity');

// Custom filters
$ctx->view()->addFilter('currency', fn($v) => '€ ' . number_format($v, 2));

// Routing & dispatching
$router = $ctx->router();
$router->add('GET', '/', 'IndexController::indexAction');

$dispatcher = new Dispatcher();
$dispatcher->setBaseNamespace('\\App\\Controllers');
$dispatcher->addMiddleware(new SessionMiddleware());

$route = $router->match(
    $ctx->request()->getPath(),
    $ctx->request()->getMethod()
);

if ($route === null) {
    Response::status(404)->send();
} else {
    $dispatcher->dispatch($route)->send();
}
```
