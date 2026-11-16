# Class: Cookies

**Full name:** [Azera\Http\Cookies](../../src/Http/Cookies.php)

Cookie jar that manages a collection of [`Cookie`](Http_Cookie.md) instances for the current request.

## Public methods

### __construct() · <small>[🗎](../../src/Http/Cookies.php#L16)</small>

`public function __construct(Azera\Http\Request|null $request = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md)\|null | `null` | The request whose cookies this jar reads. |

**Return value**

- Type: `mixed`


---

### get() · <small>[🗎](../../src/Http/Cookies.php#L35)</small>

`public function get(string $name, mixed $default = null): mixed`

Read a cookie value from this jar or the incoming request.

A cookie obtained through `cookie()` uses its configured value and
encryption settings. Otherwise, the request value is returned unchanged.
Configure encrypted cookies before calling this method; the jar cannot infer
which cookie names require encryption:

    $cookies->cookie('auth')->encrypted()->key($key);
    $value = $cookies->get('auth');

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Cookie name. |
| `$default` | mixed | `null` | Default value when the cookie is absent. |

**Return value**

- Type: `mixed`


---

### cookie() · <small>[🗎](../../src/Http/Cookies.php#L54)</small>

`public function cookie(string $name): Azera\Http\Cookie`

Get (or lazily create) a [`Cookie`](Http_Cookie.md) instance for the given name.

Use this when you need to configure encryption, path, etc. before reading or
sending the cookie. The cookie is loaded with the incoming value, so
[`Cookie::value()`](Http_Cookie.md#value) decrypts it as usual.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Cookie name. |

**Return value**

- Type: [Cookie](Http_Cookie.md)


---

### set() · <small>[🗎](../../src/Http/Cookies.php#L73)</small>

`public function set(string $name, mixed $value, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true): Azera\Http\Cookie`

Create and register a new [`Cookie`](Http_Cookie.md) with the given parameters.

The cookie is not sent until `applyTo()` drains the jar.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Cookie name. |
| `$value` | mixed | - | Cookie value. |
| `$expires` | int | `0` | Expiration timestamp (0 = session cookie). |
| `$path` | string | `'/'` | URL path scope. |
| `$domain` | string | `''` | Domain scope. |
| `$secure` | bool | `false` | Send over HTTPS only. |
| `$httpOnly` | bool | `true` | Inaccessible to JavaScript. |

**Return value**

- Type: [Cookie](Http_Cookie.md)
- Description: The newly created Cookie instance for further configuration.


---

### delete() · <small>[🗎](../../src/Http/Cookies.php#L96)</small>

`public function delete(string $name): void`

Delete a cookie by making the response carry a past-expiry `Set-Cookie`.

Works for a cookie that was never registered, and does not depend on
`setcookie()` — a worker is not a SAPI, so `setcookie()` does nothing there.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Cookie name. |

**Return value**

- Type: `void`


---

### clear() · <small>[🗎](../../src/Http/Cookies.php#L112)</small>

`public function clear(): void`

Drop every queued cookie so this response carries none.

The counterpart to the automatic drain: writing a cookie and then deciding the
response should not carry it (a 304, an error path) needs an off switch.

Reading is unaffected — the incoming values live on the [`Request`](Http_Request.md), not in
this jar — so `clear()` only stops *emission*.

**Return value**

- Type: `void`


---

### applyTo() · <small>[🗎](../../src/Http/Cookies.php#L131)</small>

`public function applyTo(Azera\Http\Response $response): Azera\Http\Response`

Write the cookies that changed in this request onto the response.

Only dirty cookies are emitted: a cookie the application merely read must not
come back as a `Set-Cookie`. The dirty flag is cleared afterwards, so calling
this twice in one request cannot duplicate a header.

Returns the same instance so a caller can chain it, and so
`Dispatcher::finalize()` can simply `return` the result.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$response` | [Response](Http_Response.md) | - |  |

**Return value**

- Type: [Response](Http_Response.md)



---

[Back to the Index ⤴](README.md)
