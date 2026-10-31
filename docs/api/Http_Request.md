# Class: Request

**Full name:** [Azera\Http\Request](../../src/Http/Request.php)

Class Request
A simple HTTP request handler that abstracts away PHP's superglobals and provides convenient methods to access request data.

It also handles method overrides, proxy headers, content negotiation, and file uploads in a consistent way.

## Public methods

### __construct() · <small>[🗎](../../src/Http/Request.php#L25)</small>

`public function __construct(array|null $server = null, array|null $get = null, array|null $post = null, array|null $files = null, bool $trustProxyHeaders = false): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$server` | array\|null | `null` |  |
| `$get` | array\|null | `null` |  |
| `$post` | array\|null | `null` |  |
| `$files` | array\|null | `null` |  |
| `$trustProxyHeaders` | bool | `false` |  |

**Return value**

- Type: `mixed`


---

### body() · <small>[🗎](../../src/Http/Request.php#L46)</small>

`public function body(): string`

Get the raw request body
Caches the body since php://input can only be read once

**Return value**

- Type: `string`


---

### setRawBody() · <small>[🗎](../../src/Http/Request.php#L68)</small>

`public function setRawBody(string $body): static`

Set the raw request body.

Exists for application-server workers (RoadRunner, Volucer), where the
request does not arrive through the SAPI and `php://input` is not the
body. In a worker, stdin carries the server's own protocol frames, so
letting `body()` fall back to `php://input` would consume protocol
bytes and desynchronise the stream permanently. A worker adapter injects
the body it was handed, and `body()` then never reads the pipe.

Set it even when the body is empty. `body()` only falls back when the
value is null, so an empty string is what stops the read — a bodyless
GET in a worker is exactly the case that must not touch `php://input`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$body` | string | - |  |

**Return value**

- Type: `static`


---

### jsonBody() · <small>[🗎](../../src/Http/Request.php#L81)</small>

`public function jsonBody(bool $assoc = true): mixed`

Get and parse JSON request body

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$assoc` | bool | `true` | When true, returns associative arrays. When false, returns objects |

**Return value**

- Type: `mixed`
- Description: Returns the parsed JSON data, or null on error

**Throws**

- RuntimeException  if the JSON body cannot be parsed


---

### input() · <small>[🗎](../../src/Http/Request.php#L101)</small>

`public function input(string|null $name = null, mixed $default = null): mixed`

Get an input parameter from the request (POST takes precedence over GET)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `mixed`


---

### query() · <small>[🗎](../../src/Http/Request.php#L115)</small>

`public function query(string|null $name = null, mixed $default = null): mixed`

Get a query parameter from the request

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `mixed`


---

### get() · <small>[🗎](../../src/Http/Request.php#L129)</small>

`public function get(string|null $name = null, mixed $default = null): mixed`

Get a GET parameter from the request

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `mixed`


---

### post() · <small>[🗎](../../src/Http/Request.php#L143)</small>

`public function post(string|null $name = null, mixed $default = null): mixed`

Get a POST parameter from the request

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `mixed`


---

### server() · <small>[🗎](../../src/Http/Request.php#L157)</small>

`public function server(string|null $name = null, mixed $default = null): mixed`

Get a server variable from the request

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` |  |
| `$default` | mixed | `null` |  |

**Return value**

- Type: `mixed`


---

### header() · <small>[🗎](../../src/Http/Request.php#L171)</small>

`public function header(string $name, mixed $default = null): mixed`

Get a request header value

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the header (case-insensitive) |
| `$default` | mixed | `null` | The default value to return if the header is not present |

**Return value**

- Type: `mixed`
- Description: The header value, or the default if not present


---

### hasInput() · <small>[🗎](../../src/Http/Request.php#L199)</small>

`public function hasInput(string $name): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### hasQuery() · <small>[🗎](../../src/Http/Request.php#L204)</small>

`public function hasQuery(string $name): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### hasPost() · <small>[🗎](../../src/Http/Request.php#L209)</small>

`public function hasPost(string $name): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `bool`


---

### method() · <small>[🗎](../../src/Http/Request.php#L218)</small>

`public function method(): string`

Get the HTTP method of the request, accounting for method overrides in POST requests

**Return value**

- Type: `string`


---

### isPost() · <small>[🗎](../../src/Http/Request.php#L240)</small>

`public function isPost(): bool`

Checks whether the request method is POST

**Return value**

- Type: `bool`


---

### scheme() · <small>[🗎](../../src/Http/Request.php#L249)</small>

`public function scheme(): string`

Get the request scheme (http or https)

**Return value**

- Type: `string`


---

### isSecure() · <small>[🗎](../../src/Http/Request.php#L265)</small>

`public function isSecure(): bool`

Checks whether request has been made using HTTPS

**Return value**

- Type: `bool`


---

### host() · <small>[🗎](../../src/Http/Request.php#L274)</small>

`public function host(): string`

Get the host name of the request, accounting for proxy headers and Host header

**Return value**

- Type: `string`


---

### port() · <small>[🗎](../../src/Http/Request.php#L292)</small>

`public function port(): int`

Get the port number of the request, accounting for proxy headers and Host header

**Return value**

- Type: `int`


---

### uri() · <small>[🗎](../../src/Http/Request.php#L315)</small>

`public function uri(): string`

Get the full URI of the request

**Return value**

- Type: `string`


---

### path() · <small>[🗎](../../src/Http/Request.php#L324)</small>

`public function path(): string`

Get the path component of the request URI (without query string)

**Return value**

- Type: `string`


---

### clientIp() · <small>[🗎](../../src/Http/Request.php#L335)</small>

`public function clientIp(bool $trustForwarded = false): string|false`

Get the client IP address, accounting for proxy headers if trusted

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$trustForwarded` | bool | `false` |  |

**Return value**

- Type: `string`|`false`


---

### acceptableContent() · <small>[🗎](../../src/Http/Request.php#L418)</small>

`public function acceptableContent(bool $sort = false): array`

Get the list of acceptable content types from the Accept header, with quality factors

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sort` | bool | `false` | Whether to sort by quality (highest first) |

**Return value**

- Type: `array`
- Description: An array of ['accept' => string, 'quality' => float, ...] entries


---

### bestAccept() · <small>[🗎](../../src/Http/Request.php#L427)</small>

`public function bestAccept(): string`

Get the best acceptable content type from the Accept header

**Return value**

- Type: `string`


---

### languages() · <small>[🗎](../../src/Http/Request.php#L437)</small>

`public function languages(bool $sort = false): array`

Get the list of acceptable languages from the Accept-Language header, with quality factors

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sort` | bool | `false` | Whether to sort by quality (highest first) |

**Return value**

- Type: `array`
- Description: An array of ['language' => string, 'quality' => float, ...] entries


---

### bestLanguage() · <small>[🗎](../../src/Http/Request.php#L446)</small>

`public function bestLanguage(): string`

Get the best acceptable language from the Accept-Language header

**Return value**

- Type: `string`


---

### encodings() · <small>[🗎](../../src/Http/Request.php#L456)</small>

`public function encodings(bool $sort = false): array`

Get the list of acceptable encodings from the Accept-Encoding header, with quality factors

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sort` | bool | `false` | Whether to sort by quality (highest first) |

**Return value**

- Type: `array`
- Description: An array of ['encoding' => string, 'quality' => float, ...] entries


---

### bestEncoding() · <small>[🗎](../../src/Http/Request.php#L465)</small>

`public function bestEncoding(): string`

Get the best acceptable encoding from the Accept-Encoding header

**Return value**

- Type: `string`


---

### charsets() · <small>[🗎](../../src/Http/Request.php#L475)</small>

`public function charsets(bool $sort = false): array`

Get the list of acceptable charsets from the Accept-Charset header, with quality factors

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sort` | bool | `false` | Whether to sort by quality (highest first) |

**Return value**

- Type: `array`
- Description: An array of ['charset' => string, 'quality' => float, ...] entries


---

### bestCharset() · <small>[🗎](../../src/Http/Request.php#L484)</small>

`public function bestCharset(): string`

Get the best acceptable charset from the Accept-Charset header

**Return value**

- Type: `string`


---

### isJson() · <small>[🗎](../../src/Http/Request.php#L530)</small>

`public function isJson(): bool`

Checks whether the request expects a JSON response based on Content-Type or Accept headers

**Return value**

- Type: `bool`


---

### isAjax() · <small>[🗎](../../src/Http/Request.php#L547)</small>

`public function isAjax(): bool`

Checks whether the request is an AJAX request based on X-Requested-With header or if it expects JSON

**Return value**

- Type: `bool`


---

### basicAuth() · <small>[🗎](../../src/Http/Request.php#L559)</small>

`public function basicAuth(): array|null`

Get Basic Auth credentials from the request, accounting for different server configurations

**Return value**

- Type: `array`|`null`
- Description: Returns ['username' => string, 'password' => string] or null if not present


---

### authorization() · <small>[🗎](../../src/Http/Request.php#L593)</small>

`public function authorization(): array|null`

Get any HTTP auth scheme from the request

**Return value**

- Type: `array`|`null`
- Description: Returns ['scheme' => string, 'token' => string] or null if not present


---

### userAgent() · <small>[🗎](../../src/Http/Request.php#L618)</small>

`public function userAgent(): string`

Get the User-Agent string from the request headers

**Return value**

- Type: `string`


---

### contentType() · <small>[🗎](../../src/Http/Request.php#L627)</small>

`public function contentType(): string`

Get the Content-Type header from the request

**Return value**

- Type: `string`


---

### file() · <small>[🗎](../../src/Http/Request.php#L680)</small>

`public function file(string $key): Azera\Http\UploadedFile|null`

Get the first uploaded file for a given field name, or null if not present

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |

**Return value**

- Type: [UploadedFile](Http_UploadedFile.md)|`null`


---

### files() · <small>[🗎](../../src/Http/Request.php#L698)</small>

`public function files(string $key): array`

Get all uploaded files for a given field name, or an empty array if not present

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
