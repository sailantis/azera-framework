# Clarity DSL Template Engine

![Clarity Logo](images/clarity-dsl-logo-opt.svg)

**A sandboxed, compiled template engine for Azera** – Clarity compiles `.clarity.html` files into PHP classes that are cached on disk. Templates can only access variables passed to `render()` and registered filters; arbitrary PHP code is intentionally disallowed.

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

| Method                                    | Description                                                              |
| ----------------------------------------- | ------------------------------------------------------------------------ |
| `setViewPath(string $path)`               | Base directory where templates are found                                 |
| `setLayout(?string $layout)`              | Default layout template (`null` disables the layout)                     |
| `setExtension(string $ext)`               | Override the file extension (default: `.clarity.html`)                   |
| `setCachePath(string $path)`              | Directory for compiled PHP files (default: `sys_get_temp_dir()/clarity`) |
| `getCachePath()`                          | Return the current cache path                                            |
| `flushCache()`                            | Delete all compiled files – useful during development                    |
| `addFilter(string $name, callable $fn)`   | Register a custom filter                                                 |
| `addFunction(string $name, callable $fn)` | Register a custom function                                               |
| `addNamespace(string $ns, string $path)`  | Register a named directory for template resolution                       |

---

## Template Syntax at a Glance

```
{{ expression }}          Output a value (auto-escaped)
{{ expression |> raw }}   Output raw HTML (no escaping; `raw` is a special marker that disables auto-escaping)
{% directive %}           Control flow, assignment, includes, inheritance
```

---

## Output Tags

Enclose any Clarity expression in double curly braces to print it:

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

Access is **strict**: the operator states what the value IS, and the engine emits
exactly that read. There is no conversion of objects to arrays before rendering.

| Syntax                        | Meaning                                  | Emits                      |
| ----------------------------- | ---------------------------------------- | -------------------------- |
| `a.b.c`                       | **object property** (static)             | `$vars['a']->b->c`         |
| `a{expr}`                     | object property (dynamic)                | `$vars['a']->{$exprPhp}`   |
| `items[expr]`                 | **array index**                          | `$vars['items'][$exprPhp]` |
| `a:b:c`                       | **array key** (static)                   | `$vars['a']['b']['c']`     |
| `$a.b` / `$a->b`              | PHP-style alias for `.` (sigil required) | `$vars['a']->b`            |
| `a?.b` `a?[i]` `a?{k}` `a?:k` | optional **receiver**                    | `isset(…) ? … : null`      |

```
user.name                 → $vars['user']->name
user:name                 → $vars['user']['name']
items[0]                  → $vars['items'][0]
items[index]              → $vars['items'][$vars['index']]
a.b[c.d].e                → $vars['a']->b[$vars['c']->d]->e
```

Applying the wrong operator is an error, not a silent `null`:

```twig
{% set user = { name: "Alice" } %}   {# an ARRAY #}
{{ user.name }}     {# ERROR: Cannot read property "name" on array #}
{{ user:name }}     {# CORRECT #}
```

A missing key or property raises an exception naming the template and line.
Whitespace around a chain operator is not significant, so a chain may wrap
(`user.\naddress.\ncity`). `.` is never string concatenation — use `~` — and an
operator with no member after it is a compile error.

```twig
{{ firstName ~ ' ' ~ lastName }}
```

### Operators

| Clarity                          | PHP equivalent | Notes                |
| -------------------------------- | -------------- | -------------------- |
| `and`                            | `&&`           |                      |
| `or`                             | `\|\|`         |                      |
| `not`                            | `!`            |                      |
| `~`                              | `.`            | String concatenation |
| `==`, `!=`, `<`, `>`, `<=`, `>=` | same           |                      |
| `+`, `-`, `*`, `/`, `%`          | same           |                      |
| `true`, `false`, `null`          | same           |                      |

```twig
{% if user.active and user.role == 'admin' %}
<span>Admin</span>
{% endif %}

<p>{{ firstName ~ ' ' ~ lastName }}</p>
```

Registered template functions are allowed in expressions. Built-in `context()` and `include()` are always available, and user code may register additional functions via `addFunction()`. Arbitrary PHP function calls such as `strtoupper(name)` are still rejected at compile time.

### Nested Conditions

```twig
{{ cond ? (foo ? bar : blubb) : blobb }}
{{ user:active ? 'Active' : 'Inactive' }}
```

A nested ternary in the else-branch must be parenthesised, because PHP rejects
`a ? b : c ? d : e` outright.

### Collection Literals

Clarity supports array and object literals directly inside expressions:

```twig
{{ [1, 2, user.id] |> json |> raw }}
{{ { name: user.name, active: user.active } |> json |> raw }}
```

Object keys must be fixed identifiers or quoted strings.

### Spread Operator

Array and object literals support the spread operator:

```twig
{{ [1, ...items, 99] |> json |> raw }}
{{ { foo: "bar", ...payload } |> json |> raw }}
```

Spread is only valid inside array and object literals.

### Built-in Functions

Two Clarity functions are built in:

```twig
{{ context() |> json |> raw }}
{{ include("partials/card", { ...context(), title: "Hello" }) }}
```

- `context()` returns the current template variable array (`$vars`).
- `include(view, context = [])` renders another template dynamically and returns its rendered markup.
- `include()` resolves paths from the view base path or registered namespaces, just like `{% include %}` and `{% extends %}`.
- Markup returned by `include()` is treated as safe by Clarity auto-escaping, even when stored via `{% set %}` and rendered later.

---

## Filter Pipeline (`|>`)

Filters transform a value before it is output. Chain multiple filters with `|>`:

```twig
{{ user.name |> upper }}
{{ price |> number(2) }}
{{ createdAt |> date('d.m.Y') }}
{{ description |> trim |> upper }}
```

Filters with arguments use parentheses after the filter name:

```twig
{{ amount |> number(0) }} {# 0 decimal places #}
{{ timestamp |> date('H:i') }} {# format as time #}
```

### Built-in Filters

| Filter   | Signature                   | Description                                     |
| -------- | --------------------------- | ----------------------------------------------- |
| `trim`   | `(value)`                   | Remove leading/trailing whitespace              |
| `upper`  | `(value)`                   | `mb_strtoupper`                                 |
| `lower`  | `(value)`                   | `mb_strtolower`                                 |
| `length` | `(value)`                   | `strlen` for strings, `count` for arrays        |
| `number` | `(value, decimals = 2)`     | `number_format`                                 |
| `date`   | `(value, format = 'Y-m-d')` | Formats a Unix timestamp or `DateTimeInterface` |
| `json`   | `(value)`                   | `json_encode`                                   |

### Custom Filters

Register additional filters in your bootstrap:

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

The `map`, `filter`, and `reduce` filters accept a **lambda expression** or a **filter reference** as their callable argument. This keeps templates secure: arbitrary PHP callables cannot be injected through template variables.

### Lambda syntax

```
param => expression
```

The parameter name becomes a local variable bound to the current element. The body is a full Clarity expression — it can access outer template variables and even use the filter pipeline (`|>`).

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

### Reduce and the implicit `value` parameter

`reduce` passes two arguments to its callable: the accumulator and the current element. Declare the accumulator name in the lambda; the current element is always available as `value`.

```twig
{# Sum a list of numbers (0 is the optional initial value) #}
{{ numbers |> reduce(sum => sum + value, 0) }}
{# Build a string #}
{{ words |> reduce(acc => acc ~ ' ' ~ value) }}
```

### Filter references

A quoted string resolves to a registered Clarity filter, allowing you to reuse existing filters as callbacks:

```twig
{# 'upper' is a built-in filter #}
{{ tags |> map("upper") |> join(', ') }}
{# Use a custom filter registered via addFilter() #}
{{ prices |> map("currency") |> join(', ') }}
```

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

**Exclusive range** (`..`) – last value is not included:

```twig
{% for i in 1..10 %} {{ i }} {% endfor %}
{# prints 1 2 3 4 5 6 7 8 9 #}
```

**Inclusive range** (`...`) – last value is included:

```twig
{% for i in 1...10 %} {{ i }} {% endfor %}
{# prints 1 2 3 4 5 6 7 8 9 10 #}
```

**With a step:**

```twig
{% for i in 0...100 step 10 %} {{ i }} {% endfor %}
{# prints 0 10 20 30 40 50 60 70 80 90 100 #}
```

Ranges can use variables:

```twig
{% for i in start...end step stride %} {{ i }} {% endfor %}
```

### Variable Assignment

```twig
{% set total = items.length %}
{% set label = user.firstName ~ ' ' ~ user.lastName %}

<p>{{ total }} items for {{ label }}</p>
```

---

## Template Inheritance

Clarity implements block-based template inheritance. A child template extends a parent layout and overrides named blocks.

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

---

## Includes

Embed another template inline using `{% include %}`. The included file shares the current variable scope.

```twig
{% include "partials/nav" %}
{% include "partials/user_card" %}
```

Included files are compiled and inlined at compile time. They do not create a separate render call.

Recursive include chains are rejected during compilation.

### Dynamic Include Function

Use `include()` when the target template or the include context must be decided dynamically at render time:

```twig
{{ include("partials/user_card", { role: "admin", ...context() }) }}
{{ include(selectedTemplate, context()) }}
```

Unlike `{% include %}`, the `include()` function performs a separate render call at runtime and returns the rendered markup directly.

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

Compiled PHP classes are written to the cache directory and served from there on subsequent requests. OPcache picks them up transparently, so warm-path rendering requires no file I/O.

Cache files are **automatically invalidated** when any source file they depend on (the template itself, extended layouts, included partials) is modified.

```php
// Custom cache location
$ctx->view()->setCachePath('/var/cache/clarity');

// Flush during development when template changes are not being picked up
$ctx->view()->flushCache();
```

> **Tip:** In production, point the cache to a persistent directory outside `/tmp` and ensure the web server user has write access.

---

## Security Sandbox

Clarity templates have **no access to PHP**:

- Direct PHP variables (`$name`) are rejected at compile time.
- Function calls (`strtoupper(x)`) are rejected at compile time – use the filter pipeline.
- Statement delimiters (`;`), backticks, heredocs, and PHP open/close tags are disallowed in expressions.
- Objects passed to `render()` are automatically cast to arrays (via `JsonSerializable`, `toArray()`, or `get_object_vars()`), preventing method calls from inside templates.
- `map`, `filter`, and `reduce` only accept lambda expressions or filter references — passing a callable via a template variable is rejected at compile time.

---

**Benchmark Results**

A micro-benchmark in the `azera-competition` repository compares Clarity
(compiled templates) with the other mainstream PHP template engines rendering
one identical page — a layout, an included partial, a loop over the items, and
nested loops inside it. `NativeEngine` and Clarity are engines inside Azera; the
rest are the template languages Azera adapts via its view adapter layer.

The chart and the table below are generated from the run's own JSON, so the
figures cannot drift from the data they came from:

<!-- view-engine:begin -->
Clarity is measured against other PHP template engines rendering the same page, on the same machine and PHP build. The chart and the table below are generated from the run's own JSON.

![Template engine benchmark](images/benchmarks/view-engine/render-time.svg)

Rows are ordered by median, fastest first. Two engines sitting next to each other at the top of the table are not thereby ranked: a difference of a few percent is still within the spread of a single engine's own runs, and a gap that small is a tie, not a win.

| Engine | First render (ms) | Mean (ms) | Median (ms) | Min (ms) | p95 (ms) | Retained (MB) | Peak (MB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Clarity | 14.726 | 0.432 | 0.418 | 0.397 | 0.501 | 1.03 | 1.52 |
| Stempler | 22.536 | 0.440 | 0.428 | 0.402 | 0.505 | 1.29 | 1.71 |
| Native | 0.661 | 0.460 | 0.447 | 0.422 | 0.526 | 0.79 | 1.52 |
| Plates | 2.532 | 0.547 | 0.529 | 0.500 | 0.633 | 0.86 | 1.52 |
| Blade | 30.786 | 0.760 | 0.734 | 0.692 | 0.888 | 1.57 | 2.05 |
| Twig | 36.686 | 1.289 | 1.248 | 1.193 | 1.507 | 1.48 | 1.79 |

**Environment** — PHP 8.3.33 · Linux 6.8.0-139-generic · SAPI cli · OPcache (`opcache.enable_cli`): yes · Memory probe: `a fresh process with opcache.enable_cli=1 but a per-process CLI segment, so engine source is compiled in that process`

**Budget** — 10,000 renders × 30 runs, 200 items per render

**Method** — Steady-state timings: the render loop for each (engine, page) cell runs in its own fresh process against a warm cache: one untimed warm-up render, then runs x iterations-per-run timed renders. No order: each (engine, page) cell is measured in its own process, so measurement order cannot affect a cell. The first render was measured as one render in a fresh process with a cold template cache: engine class loading, template compile, cache write and one render.

**Engines** — Clarity dev-main (0a1c64d) · NativeEngine (Azera) dev-main (v0.1.0+dirty) · Plates 3.6.0 · Blade 12.69.2 · Twig 3.27.0 · Stempler 3.17.2

_Measured 2026-09-29T22:42:39+00:00_

Full report — every chart, including the first render (measured in a fresh process per engine) and per-render memory: <https://sailantis.github.io/azera-competition/benchmarks/view-engine.html>
<!-- view-engine:end -->

The harness lives in `azera-competition` (not in this repository): run
`php benchmarks/view-engine/run.php` there, then
`php scripts/view-engine-report.php` to regenerate this section.

## Choosing Between ClarityEngine and NativeEngine

|                       | ClarityEngine _(default)_                                    | NativeEngine                             |
| --------------------- | ------------------------------------------------------------ | ---------------------------------------- |
| Template syntax       | Clarity DSL (`{{ }}`, `{% %}`)                               | Plain PHP (`<?= ?>`, `<?php ?>`)         |
| Sandboxed             | Yes                                                          | No                                       |
| Auto-escaping         | Always on by default                                         | Manual                                   |
| Compilation & caching | Yes                                                          | No                                       |
| Template inheritance  | `{% extends %}` / `{% block %}`                              | require/include                          |
| Filter pipeline       | Built-in                                                     | Not built-in                             |
| Suitable for          | User-facing views, team projects, untrusted template authors | Full PHP control, existing PHP templates |

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
