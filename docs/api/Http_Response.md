# Class: Response

**Full name:** [Azera\Http\Response](../../src/Http/Response.php)

Represents an HTTP response.

Build a response by chaining setters and finish by calling `send()`,
or use one of the static factory methods (`json()`, `html()`,
`redirect()`, etc.) for common cases.

## Public methods

### __construct() · <small>[🗎](../../src/Http/Response.php#L20)</small>

`public function __construct(int $status = 200, array $headers = [], string $body = ''): mixed`

Create a new Response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$status` | int | `200` | HTTP status code. |
| `$headers` | array | `[]` | Complete header lines. |
| `$body` | string | `''` | Response body. |

**Return value**

- Type: `mixed`


---

### setStatus() · <small>[🗎](../../src/Http/Response.php#L37)</small>

`public function setStatus(int $code): static`

Set the HTTP status code.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | int | - | HTTP status code (e.g. 200, 404). |

**Return value**

- Type: `static`


---

### getStatus() · <small>[🗎](../../src/Http/Response.php#L48)</small>

`public function getStatus(): int`

Get the HTTP status code.

**Return value**

- Type: `int`


---

### getBody() · <small>[🗎](../../src/Http/Response.php#L56)</small>

`public function getBody(): string`

Get the response body.

**Return value**

- Type: `string`


---

### replaceHeader() · <small>[🗎](../../src/Http/Response.php#L67)</small>

`public function replaceHeader(string $line): static`

Replace a header line with the same field name, or append if none exists.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$line` | string | - | Complete header line, e.g. "Content-Type: application/json". |

**Return value**

- Type: `static`


---

### replaceHeaders() · <small>[🗎](../../src/Http/Response.php#L95)</small>

`public function replaceHeaders(array $lines): static`

Replace several header lines, each with `replaceHeader()` semantics.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$lines` | array | - | Complete header lines. |

**Return value**

- Type: `static`


---

### addHeader() · <small>[🗎](../../src/Http/Response.php#L113)</small>

`public function addHeader(string $line): static`

Append a header line without replacing existing lines of the same name.

This is what [`Cookies::applyTo()`](Http_Cookies.md#applyto) and `addCookie()` use, because a
second `Set-Cookie` must not overwrite the first.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$line` | string | - | Complete header line, e.g. "Set-Cookie: theme=dark". |

**Return value**

- Type: `static`


---

### addHeaders() · <small>[🗎](../../src/Http/Response.php#L125)</small>

`public function addHeaders(array $lines): static`

Append several header lines, each with `addHeader()` semantics.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$lines` | array | - | Complete header lines. |

**Return value**

- Type: `static`


---

### addCookie() · <small>[🗎](../../src/Http/Response.php#L141)</small>

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

### getHeaders() · <small>[🗎](../../src/Http/Response.php#L156)</small>

`public function getHeaders(): array`

Get all header lines in transmission order, including repeated names.

Each entry is a complete `Name: value` line. Use `getHeader()` for the
value of one header.

**Return value**

- Type: `array`


---

### getHeader() · <small>[🗎](../../src/Http/Response.php#L171)</small>

`public function getHeader(string $name): string|null`

Get the value of the first header line matching the given name.

Returns the text after `name:`, trimmed. A header present with an empty
value returns `''`; an absent header returns `null`. The full line is
available from `getHeaders()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name (matched case-insensitively). |

**Return value**

- Type: `string`|`null`
- Description: The header value, or null when not set.


---

### write() · <small>[🗎](../../src/Http/Response.php#L189)</small>

`public function write(string $text): static`

Append text to the response body.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - | Content to append. |

**Return value**

- Type: `static`


---

### send() · <small>[🗎](../../src/Http/Response.php#L198)</small>

`public function send(): void`

Send the response: emit the status code, headers, and body.

**Return value**

- Type: `void`


---

### json() · <small>[🗎](../../src/Http/Response.php#L216)</small>

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

### text() · <small>[🗎](../../src/Http/Response.php#L232)</small>

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

### html() · <small>[🗎](../../src/Http/Response.php#L248)</small>

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

### redirect() · <small>[🗎](../../src/Http/Response.php#L264)</small>

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

### status() · <small>[🗎](../../src/Http/Response.php#L279)</small>

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
