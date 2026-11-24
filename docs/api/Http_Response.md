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

### strict() · <small>[🗎](../../src/Http/Response.php#L150)</small>

`public static function strict(bool|null $on = null): bool`

Enable or disable header validation, or read the current setting.

Enable it in development so a malformed header fails loudly and names the
field; leave it off elsewhere. See {@see \self::$strict} for why the
default is off and what the check does and does not protect.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$on` | bool\|null | `null` | `true`/`false` to set, `null` (default) to read. |

**Return value**

- Type: `bool`
- Description: The setting in effect after the call.


---

### xSendfile() · <small>[🗎](../../src/Http/Response.php#L172)</small>

`public static function xSendfile(bool|null $on = null): bool`

Enable or disable `X-Sendfile` delegation for file bodies, or read it.

With it on, `send()` emits `X-Sendfile: <path>` for a response built by
`setFileToSend()` and sends no body, leaving the transfer to the web
server. That is the cheap path for large downloads: PHP returns after
reading the file's size instead of streaming every byte. Persistent
workers ignore it — there is no web server to delegate to — and stream the
file themselves.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$on` | bool\|null | `null` | `true`/`false` to set, `null` (default) to read. |

**Return value**

- Type: `bool`
- Description: The setting in effect after the call.


---

### setStatus() · <small>[🗎](../../src/Http/Response.php#L187)</small>

`public function setStatus(int $code): static`

Set the HTTP status code.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | int | - | HTTP status code (e.g. 200, 404). |

**Return value**

- Type: `static`


---

### getStatus() · <small>[🗎](../../src/Http/Response.php#L198)</small>

`public function getStatus(): int`

Get the HTTP status code.

**Return value**

- Type: `int`


---

### getBody() · <small>[🗎](../../src/Http/Response.php#L214)</small>

`public function getBody(): string`

Get the response body.

For a response built by `setFileToSend()` this reads the file and
returns its bytes, so a transport that only knows how to send a string (the
RoadRunner and Volucer workers) still transmits it. A caller that wants the
efficient path should use `send()` under PHP-FPM or check `getFile()`
and stream it itself.

**Return value**

- Type: `string`

**Throws**

- RuntimeException  When the file has disappeared since it was set.


---

### setHeader() · <small>[🗎](../../src/Http/Response.php#L242)</small>

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

### setHeaders() · <small>[🗎](../../src/Http/Response.php#L275)</small>

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

### addHeader() · <small>[🗎](../../src/Http/Response.php#L316)</small>

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

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When the line has no colon.


---

### addHeaders() · <small>[🗎](../../src/Http/Response.php#L344)</small>

`public function addHeaders(array $headers): static`

Append several header fields, each with `addHeader()` semantics.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | Fields to append. |

**Return value**

- Type: `static`


---

### addCookie() · <small>[🗎](../../src/Http/Response.php#L370)</small>

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

### getHeaders() · <small>[🗎](../../src/Http/Response.php#L387)</small>

`public function getHeaders(): array`

Get the value of every header field, keyed by field name.

Each name maps to a list of values, in the order they were added, so a
repeated field such as `Set-Cookie` keeps every one. This is the shape
PSR-7's `getHeaders()` uses. Use `getHeader()` for a single value and
`getHeaderLines()` for the wire format.

**Return value**

- Type: `array`


---

### getHeaderValues() · <small>[🗎](../../src/Http/Response.php#L398)</small>

`public function getHeaderValues(string $name): array`

Get every value of one header field, or an empty list when it is absent.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Header field name (matched case-insensitively). |

**Return value**

- Type: `array`


---

### getHeader() · <small>[🗎](../../src/Http/Response.php#L414)</small>

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

### getHeaderLines() · <small>[🗎](../../src/Http/Response.php#L433)</small>

`public function getHeaderLines(): array`

Get all headers in wire format, e.g. `['Content-Type: application/json']`.

A repeated field appears once per value. This is what `header()` and the
worker adapters write; nothing in this class depends on it.

**Return value**

- Type: `array`


---

### write() · <small>[🗎](../../src/Http/Response.php#L452)</small>

`public function write(string $text): static`

Append text to the response body.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - | Content to append. |

**Return value**

- Type: `static`


---

### setEtag() · <small>[🗎](../../src/Http/Response.php#L472)</small>

`public function setEtag(string $etag): static`

Set the `ETag` validator.

The value is a quoted string per RFC 9110. Pass an unquoted value (a hash,
a revision, a version) and it is quoted for you; a value that is already
quoted is kept as-is, and the weak form (`W/"..."`) is preserved.

Pair this with `isNotModified()` for the actual 304 decision.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$etag` | string | - | Strong or weak entity tag. |

**Return value**

- Type: `static`


---

### setLastModified() · <small>[🗎](../../src/Http/Response.php#L487)</small>

`public function setLastModified(DateTimeInterface|int $lastModified): static`

Set the `Last-Modified` validator.

Truncated to whole seconds: the header has second resolution, so a value
with microseconds would never compare equal to what the client sends back
in `If-Modified-Since`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$lastModified` | DateTimeInterface\|int | - | Timestamp or date object. |

**Return value**

- Type: `static`


---

### setExpires() · <small>[🗎](../../src/Http/Response.php#L501)</small>

`public function setExpires(DateTimeInterface|int $expires): static`

Set the `Expires` header.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$expires` | DateTimeInterface\|int | - | Timestamp or date object. |

**Return value**

- Type: `static`


---

### setCache() · <small>[🗎](../../src/Http/Response.php#L527)</small>

`public function setCache(int $seconds, bool $shared = false, bool $immutable = false): static`

Mark the response cacheable for a number of seconds.

Sets both `Cache-Control: max-age=N` and `Expires`, because an HTTP/1.0
cache only understands the latter while an HTTP/1.1 one ignores it in
favour of the former. `Cache-Control` wins where both are present, which is
what makes the pair safe.

`$shared` adds `public` (a shared cache such as a CDN may store it); without
it the response is `private`, which keeps a personalised page out of a CDN
and in the browser. `$immutable` marks the body as never changing under that
URL — correct for a fingerprinted asset, wrong for anything else.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$seconds` | int | - | Freshness lifetime in seconds (0 = must revalidate). |
| `$shared` | bool | `false` | Whether a shared cache may store the response. |
| `$immutable` | bool | `false` | Whether the body never changes under this URL. |

**Return value**

- Type: `static`


---

### setNoCache() · <small>[🗎](../../src/Http/Response.php#L551)</small>

`public function setNoCache(): static`

Mark the response as non-storable.

`no-store` is the directive a sensitive page needs: it forbids writing the
response to any cache, which `no-cache` does not (that one allows storing,
only requiring revalidation). Also emits the two headers an HTTP/1.0 cache
understands.

**Return value**

- Type: `static`


---

### isNotModified() · <small>[🗎](../../src/Http/Response.php#L574)</small>

`public function isNotModified(Azera\Http\Request $request): bool`

Whether the request's validators match this response's, i.e. answer 304.

`If-None-Match` is checked first and, when present, wins outright: RFC 9110
says a server that receives both validators MUST use the entity tag and
ignore `If-Modified-Since` — otherwise a cache holding a stale ETag but a
fresh date could be served a body it does not need. `*` matches any existing
representation.

Comparison is weak for this direction: `W/"x"` and `"x"` are the same
representation, so weakening both sides is correct.

Only GET and HEAD are eligible, and only when this response carries at least
one validator (an unconditional 304 would be a lie).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |

**Return value**

- Type: `bool`


---

### notModifiedIfMatches() · <small>[🗎](../../src/Http/Response.php#L635)</small>

`public function notModifiedIfMatches(Azera\Http\Request $request): static`

Turn this into a `304 Not Modified` when the request's validators match.

The one-call form of the conditional-GET dance:

```php
$response = Response::html($rendered)
    ->setEtag($revision)
    ->setLastModified($mtime)
    ->setCache(3600, shared: true);

return $response->notModifiedIfMatches($request);
```

On a match the status becomes 304 and the body is dropped, while the
validators and `Cache-Control` stay — a cache needs them to refresh its own
copy. Per RFC 9110 the remaining representation headers (such as
`Content-Type`) are dropped, because a 304 has no body for them to describe.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |

**Return value**

- Type: `static`


---

### setFileToSend() · <small>[🗎](../../src/Http/Response.php#L676)</small>

`public function setFileToSend(string $path, string|null $downloadName = null, bool $attachment = true): static`

Replace the body with the given file and describe it as a download.

The file is not read here. `getBody()` streams it into the response string
(which is what the RoadRunner and Volucer workers send), and `send()`
streams it directly under PHP-FPM — or delegates to the web server when
`xSendfile()` is on.

`Content-Type`, `Content-Length` and `Content-Disposition` are filled in from
the file itself; `Last-Modified` is set to its mtime so the download also
participates in conditional GET, and `ETag` is derived from size + mtime
unless one is already set.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to an existing, readable file. |
| `$downloadName` | string\|null | `null` | Name the client should save it as (default: basename). |
| `$attachment` | bool | `true` | `true` downloads, `false` renders inline. |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When the path is not a readable file.


---

### getFile() · <small>[🗎](../../src/Http/Response.php#L706)</small>

`public function getFile(): string|null`

The path of a file body, or `null` when the body is a string.

**Return value**

- Type: `string`|`null`


---

### send() · <small>[🗎](../../src/Http/Response.php#L719)</small>

`public function send(): void`

Send the response: emit the status code, headers, and body.

A file body is delegated to the web server when `xSendfile()` is on
(one `X-Sendfile` header, no body) and streamed otherwise, so a large
download never becomes a PHP string in the second case and never costs PHP
a copy in the first.

**Return value**

- Type: `void`


---

### json() · <small>[🗎](../../src/Http/Response.php#L988)</small>

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

### text() · <small>[🗎](../../src/Http/Response.php#L1004)</small>

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

### html() · <small>[🗎](../../src/Http/Response.php#L1020)</small>

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

### redirect() · <small>[🗎](../../src/Http/Response.php#L1036)</small>

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

### status() · <small>[🗎](../../src/Http/Response.php#L1051)</small>

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
