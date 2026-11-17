# Class: Response

**Full name:** [Azera\Http\Response](../../src/Http/Response.php)

Represents an HTTP response.

Build a response by chaining setters and finish by calling `send()`,
or use one of the static factory methods (`json()`, `html()`,
`redirect()`, etc.) for common cases.

## Public methods

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

### setStatus() · <small>[🗎](../../src/Http/Response.php#L118)</small>

`public function setStatus(int $code): static`

Set the HTTP status code.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | int | - | HTTP status code (e.g. 200, 404). |

**Return value**

- Type: `static`


---

### getStatus() · <small>[🗎](../../src/Http/Response.php#L129)</small>

`public function getStatus(): int`

Get the HTTP status code.

**Return value**

- Type: `int`


---

### getBody() · <small>[🗎](../../src/Http/Response.php#L137)</small>

`public function getBody(): string`

Get the response body.

**Return value**

- Type: `string`


---

### replaceHeader() · <small>[🗎](../../src/Http/Response.php#L154)</small>

`public function replaceHeader(string $name, string|null $value = null): static`

Replace the values of a header field, or add the field if absent.

A complete line (`"Content-Type: application/json"`) may be passed instead of
a name and value; a field name can never contain a colon, so the two forms
cannot be confused.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name, or a complete `Name: value` line. |
| `$value` | string\|null | `null` | Header value; omit when passing a complete line. |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When the name or value is not a valid field.


---

### replaceHeaders() · <small>[🗎](../../src/Http/Response.php#L186)</small>

`public function replaceHeaders(array $headers): static`

Replace several header fields, each with `replaceHeader()` semantics.

Accepts a list of complete lines (`['X-Trace: abc']`) and a name => value map
(`['X-Trace' => 'abc']`) in any mix; an integer key is read as a line. A list
of values replaces the field and keeps every one, as PSR-7's `withHeader()`
does.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | Fields to replace. |

**Return value**

- Type: `static`


---

### addHeader() · <small>[🗎](../../src/Http/Response.php#L221)</small>

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

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When the name or value is not a valid field.


---

### addHeaders() · <small>[🗎](../../src/Http/Response.php#L248)</small>

`public function addHeaders(array $headers): static`

Append several header fields, each with `addHeader()` semantics.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | Fields to append. |

**Return value**

- Type: `static`


---

### setHeader() · <small>[🗎](../../src/Http/Response.php#L271)</small>

`public function setHeader(string $name, string $value): static`

Set a header with the given name and value, replacing any existing header of the same name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name. |
| `$value` | string | - | Header value. |

**Return value**

- Type: `static`


---

### setHeaders() · <small>[🗎](../../src/Http/Response.php#L282)</small>

`public function setHeaders(array $headers): static`

Set multiple headers, replacing any existing headers with the same names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | Associative array of header names and values. |

**Return value**

- Type: `static`


---

### addCookie() · <small>[🗎](../../src/Http/Response.php#L297)</small>

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

### getHeaders() · <small>[🗎](../../src/Http/Response.php#L314)</small>

`public function getHeaders(): array`

Get the value of every header field, keyed by field name.

Each name maps to a list of values, in the order they were added, so a
repeated field such as `Set-Cookie` keeps every one. This is the shape
PSR-7's `getHeaders()` uses. Use `getHeader()` for a single value and
`getHeaderLines()` for the wire format.

**Return value**

- Type: `array`


---

### getHeaderValues() · <small>[🗎](../../src/Http/Response.php#L325)</small>

`public function getHeaderValues(string $name): array`

Get every value of one header field, or an empty list when it is absent.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name (matched case-insensitively). |

**Return value**

- Type: `array`


---

### getHeader() · <small>[🗎](../../src/Http/Response.php#L341)</small>

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

### getHeaderLines() · <small>[🗎](../../src/Http/Response.php#L360)</small>

`public function getHeaderLines(): array`

Get all headers in wire format, e.g. `['Content-Type: application/json']`.

A repeated field appears once per value. This is what `header()` and the
worker adapters write; nothing in this class depends on it.

**Return value**

- Type: `array`


---

### write() · <small>[🗎](../../src/Http/Response.php#L379)</small>

`public function write(string $text): static`

Append text to the response body.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - | Content to append. |

**Return value**

- Type: `static`


---

### send() · <small>[🗎](../../src/Http/Response.php#L388)</small>

`public function send(): void`

Send the response: emit the status code, headers, and body.

**Return value**

- Type: `void`


---

### json() · <small>[🗎](../../src/Http/Response.php#L489)</small>

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

### text() · <small>[🗎](../../src/Http/Response.php#L505)</small>

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

### html() · <small>[🗎](../../src/Http/Response.php#L521)</small>

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

### redirect() · <small>[🗎](../../src/Http/Response.php#L537)</small>

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

### status() · <small>[🗎](../../src/Http/Response.php#L552)</small>

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
