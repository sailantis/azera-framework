# Class: Response

**Full name:** [Azera\Http\Response](../../src/Http/Response.php)

Represents an HTTP response.

Build a response by chaining setters and finish by calling `send()`,
or use one of the static factory methods (`json()`, `html()`,
`redirect()`, etc.) for common cases.

## Public methods

### strict() · <small>[🗎](../../src/Http/Response.php#L124)</small>

`public static function strict(?bool $on = null): bool`

Enable or disable header validation, or read the current setting.

This is a **development assertion about the application's own code**, not a
security control. PHP's `header()` already refuses a value containing CR or LF,
and the PSR-7 adapters (RoadRunner, Volucer) reject one at construction, so what
this check adds is an early, framework-owned error naming the offending field
when the application builds a malformed header itself — `'Location: ' . $url`
where `$url` came from a request, or a field name with a space in it.

**Off by default**, and the default is deliberate: the value of catching the
mistake early is a better message, not a guarantee, so it is paid for only where
it is wanted. Enable it from the application's own debug switch, once at boot:

```php
// public/index.php, rr-worker.php, volucer-worker.php, task.php
Response::strict(DEBUG_MODE);
```

Both the name/value setters and the `Name: value` line form are validated, so
`new Response(headers: ['ETag: ' . $etag])` is covered as well as
`setHeader('ETag', $etag)`.

**The setting is process-wide**, so set it once — never per request. A resident
worker (RoadRunner, Volucer) keeps the value for every request it serves after
the one that changed it. No environment lookup happens in a header setter: the
entry script knows its own debug flag and states it once.

When enabled, a value containing CR, LF or NUL, or a name that is not an RFC 9110
token, raises `InvalidArgumentException`. When disabled, headers are stored as
given and the cost is one static read per header.

| Name | Type | Default | Description |
|---|---|---|---|
| `$on` | bool\|null | `null` | `true`/`false` to set; omit to read. |

**Return value**

- Type: `bool` — the setting in effect after the call.

---

### __construct() · <small>[🗎](../../src/Http/Response.php#L22)</small>

`public function __construct(int $status = 200, array $headers = [], string $body = ''): mixed`

Create a new Response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$status` | int | `200` | HTTP status code. |
| `$headers` | array | `[]` | Header values by field<br>name. A string is one value; a list is a repeated field such as<br>several `Set-Cookie` lines. |
| `$body` | string | `''` | Response body. |

**Return value**

- Type: `mixed`


---

### setStatus() · <small>[🗎](../../src/Http/Response.php#L92)</small>

`public function setStatus(int $code): static`

Set the HTTP status code.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | int | - | HTTP status code (e.g. 200, 404). |

**Return value**

- Type: `static`


---

### getStatus() · <small>[🗎](../../src/Http/Response.php#L103)</small>

`public function getStatus(): int`

Get the HTTP status code.

**Return value**

- Type: `int`


---

### getBody() · <small>[🗎](../../src/Http/Response.php#L111)</small>

`public function getBody(): string`

Get the response body.

**Return value**

- Type: `string`


---

### setHeader() · <small>[🗎](../../src/Http/Response.php#L129)</small>

`public function setHeader(string $name, string|null $value = null): static`

Set a header field, replacing every value already stored for that name.

A complete line (`"Content-Type: application/json"`) may be passed instead of
a name and value; a field name can never contain a colon, so the two forms
cannot be confused. Use `addHeader()` to append a second value to a
repeated field such as `Set-Cookie` instead of replacing it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name, or a complete `Name: value` line. |
| `$value` | string\|null | `null` | Header value; omit when passing a complete line. |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When the line has no colon.


---

### setHeaders() · <small>[🗎](../../src/Http/Response.php#L163)</small>

`public function setHeaders(array $headers): static`

Set several header fields, each with `setHeader()` semantics.

Accepts a list of complete lines (`['X-Trace: abc']`) or a name => value map
(`['X-Trace' => 'abc']`). A list of values replaces the field and keeps every
one, as PSR-7's `withHeader()` does; `addHeaders()` appends instead.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | Fields to set. |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When a line has no colon.


---

### addHeader() · <small>[🗎](../../src/Http/Response.php#L199)</small>

`public function addHeader(string $name, string|null $value = null): static`

Append values to a header field without replacing the ones already set.

This is what [`Cookies::applyTo()`](Http_Cookies.md#applyto) and `addCookie()` use, because a
second `Set-Cookie` must not overwrite the first.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name, or a complete `Name: value` line. |
| `$value` | string\|null | `null` | Header value; omit when passing a complete line. |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When a `Name: value` line has no colon.


---

### addHeaders() · <small>[🗎](../../src/Http/Response.php#L228)</small>

`public function addHeaders(array $headers): static`

Append several header fields, each with `addHeader()` semantics.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | Fields to append. |

**Return value**

- Type: `static`


---

### addCookie() · <small>[🗎](../../src/Http/Response.php#L254)</small>

`public function addCookie(Azera\Http\Cookie $cookie): static`

Append a cookie as a `Set-Cookie` header.

The formatting lives in [`Cookie::toHeader()`](Http_Cookie.md#toheader), so this is a delegation and
not a second implementation. A cookie the application read is never sent —
that decision belongs to [`Cookies::applyTo()`](Http_Cookies.md#applyto), which only emits written
cookies; this method always writes what it is given.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$cookie` | [Cookie](Http_Cookie.md) | - |  |

**Return value**

- Type: `static`


---

### getHeaders() · <small>[🗎](../../src/Http/Response.php#L271)</small>

`public function getHeaders(): array`

Get the value of every header field, keyed by field name.

Each name maps to a list of values, in the order they were added, so a
repeated field such as `Set-Cookie` keeps every one. This is the shape
PSR-7's `getHeaders()` uses. Use `getHeader()` for a single value and
`getHeaderLines()` for the wire format.

**Return value**

- Type: `array`


---

### getHeaderValues() · <small>[🗎](../../src/Http/Response.php#L282)</small>

`public function getHeaderValues(string $name): array`

Get every value of one header field, or an empty list when it is absent.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name (matched case-insensitively). |

**Return value**

- Type: `array`


---

### getHeader() · <small>[🗎](../../src/Http/Response.php#L298)</small>

`public function getHeader(string $name, string|null $default = null): string|null`

Get the first value of the given header field.

A header present with an empty value returns `''`; an absent header returns
`$default` (`null` unless given).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name (matched case-insensitively). |
| `$default` | string\|null | `null` | Value to return when the header is absent. |

**Return value**

- Type: `string`|`null`


---

### getHeaderLines() · <small>[🗎](../../src/Http/Response.php#L317)</small>

`public function getHeaderLines(): array`

Get all headers in wire format, e.g. `['Content-Type: application/json']`.

A repeated field appears once per value. This is what `header()` and the
worker adapters write; nothing in this class depends on it.

**Return value**

- Type: `array`


---

### write() · <small>[🗎](../../src/Http/Response.php#L336)</small>

`public function write(string $text): static`

Append text to the response body.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - | Content to append. |

**Return value**

- Type: `static`


---

### send() · <small>[🗎](../../src/Http/Response.php#L345)</small>

`public function send(): void`

Send the response: emit the status code, headers, and body.

**Return value**

- Type: `void`


---

### json() · <small>[🗎](../../src/Http/Response.php#L444)</small>

`public static function json(mixed $data, int $status = 200): static`

Create a JSON response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | mixed | - | Data to JSON-encode. |
| `$status` | int | `200` | HTTP status code (default 200). |

**Return value**

- Type: `static`


---

### text() · <small>[🗎](../../src/Http/Response.php#L460)</small>

`public static function text(string $text, int $status = 200): static`

Create a plain-text response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - | Response body. |
| `$status` | int | `200` | HTTP status code (default 200). |

**Return value**

- Type: `static`


---

### html() · <small>[🗎](../../src/Http/Response.php#L476)</small>

`public static function html(string $html, int $status = 200): static`

Create an HTML response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$html` | string | - | HTML content. |
| `$status` | int | `200` | HTTP status code (default 200). |

**Return value**

- Type: `static`


---

### redirect() · <small>[🗎](../../src/Http/Response.php#L492)</small>

`public static function redirect(string $url, int $status = 302): static`

Create a redirect response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$url` | string | - | URL to redirect to. |
| `$status` | int | `302` | HTTP redirect status code (default 302). |

**Return value**

- Type: `static`


---

### status() · <small>[🗎](../../src/Http/Response.php#L507)</small>

`public static function status(int $status): static`

Create a response with only a status code and an empty body.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$status` | int | - | HTTP status code. |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
