# HTTP Request

`Azera\Http\Request` provides access to query and POST data, uploaded files,
headers, and server information.

Obtain the request object from `AppContext` rather than instantiating it directly:

```php
$request = \Azera\AppContext::instance()->request();
// or inside a controller:
$request = $this->request();
```

---

## Reading Input

All input accessors accept an optional name and default value. Omit the name to get the full array.

```php
// Input ($_GET + $_POST)
$q       = $request->input('q');
$all     = $request->input();

// Query string ($_GET)
$page    = $request->query('page', 1);
$allGet  = $request->query();

// POST body ($_POST)
$email   = $request->post('email');
$allPost = $request->post();

// Raw $_SERVER value
$ua = $request->server('HTTP_USER_AGENT', '');
```

### Checking Parameter Existence

Use the `has*` helpers to distinguish a missing field from values such as
`null` or `0`:

```php
$request->hasInput('token');     // isset in $_GET + $_POST
$request->hasQuery('page');      // isset in $_GET
$request->hasPost('_method');    // isset in $_POST
```

---

## Method and URL

```php
$method = $request->method();   // 'GET', 'POST', 'PUT', …
$uri    = $request->uri();      // '/users/5?tab=profile'
$path   = $request->path();     // '/users/5'

$request->isPost();    // true when method is POST
$request->isSecure();  // true when HTTPS
$request->isJson();    // true for Content-Type: application/json or Accept: application/json
$request->isAjax();    // true for fetch/axios/jQuery XHR (see note below)
```

> **Method override** – `method()` recognises an `X-HTTP-Method-Override` header (or a `_method` body field) sent with a POST request and returns the overridden method, which allows method tunnelling through form submissions.

> **AJAX detection** — `isAjax()` returns `true` for JSON requests or when
> `X-Requested-With: XMLHttpRequest` is present.

---

## Server and Client Information

```php
$host       = $request->host();          // 'example.com' or 'example.com:8080'
$scheme     = $request->scheme();            // 'http' or 'https'
$port       = $request->port();              // 443
$userAgent  = $request->userAgent();
$clientIp   = $request->clientIp();     // REMOTE_ADDR
$clientIp   = $request->clientIp(true); // trust X-Forwarded-For / HTTP_CLIENT_IP

$contentType = $request->contentType();
```

Only trust forwarded client-IP headers when the request comes through a
trusted proxy; clients can spoof those headers otherwise.

---

## Content Negotiation

Parse `Accept`, `Accept-Language`, and `Accept-Charset` headers into quality-sorted arrays.

```php
// Accept
$types       = $request->acceptableContent();       // unsorted
$types       = $request->acceptableContent(true);   // sorted by quality
$bestType    = $request->bestAccept();               // e.g. 'text/html'

// Accept-Language
$languages   = $request->languages();
$bestLang    = $request->bestLanguage();             // e.g. 'en'

// Accept-Charset
$charsets    = $request->charsets();
$bestCharset = $request->bestCharset();              // e.g. 'utf-8'
```

Each entry in the returned arrays contains the value and its `quality` key (0–1).

---

## JSON Body

```php
$raw  = $request->body();             // raw php://input string (cached)
$data = $request->jsonBody();         // decoded as associative array (default)
$obj  = $request->jsonBody(false);    // decoded as stdClass objects
```

`jsonBody()` throws `\RuntimeException` if the body is not valid JSON.

---

## HTTP Authentication

```php
// HTTP Basic Auth
$auth = $request->basicAuth();
// ['username' => '...', 'password' => '...'] or null

// Any HTTP auth scheme (e.g. "Bearer", "Digest", "Custom")
$auth = $request->authorization();
// ['scheme' => '...', 'token' => '...'] or null
```

---

## File Uploads

`file()` returns a single `UploadedFile` (or `null`). `files()` always returns an array, which is convenient for multi-file inputs.

```php
$file = $request->file('avatar');
if ($file && $file->isValid()) {
    $file->moveTo(__DIR__ . '/uploads/' . $file->clientFilename());
}

// Multi-file input (<input type="file" name="docs[]" multiple>)
foreach ($request->files('docs') as $doc) {
    if ($doc->isValid()) {
        $doc->moveTo('/storage/' . $doc->clientFilename());
    }
}
```

`UploadedFile` API:

| Method                 | Returns  | Description                                                 |
| ---------------------- | -------- | ----------------------------------------------------------- |
| `isValid()`            | `bool`   | `true` when `UPLOAD_ERR_OK`                                 |
| `clientFilename()`     | `string` | Original filename from the browser (sanitise before use)    |
| `clientMediaType()`    | `string` | MIME type reported by the client (not verified server-side) |
| `size()`               | `int`    | File size in bytes                                          |
| `moveTo(string $path)` | `void`   | Move to destination; throws `\RuntimeException` on failure  |

---

## Controller Example

```php
class UserController extends \Azera\Core\Controller
{
    public function createAction(): array
    {
        $request = $this->request();

        if (!$request->hasPost('email')) {
            return ['ok' => false, 'error' => 'email is required'];
        }

        $email = $request->post('email');
        User::create(['email' => $email]);

        return ['ok' => true];
    }

    public function apiAction(): array
    {
        $data = $this->request()->jsonBody();
        // process $data ...
        return ['ok' => true];
    }
}
```

---

## Related

- [src/Http/Request.php](../src/Http/Request.php)
- [src/Http/UploadedFile.php](../src/Http/UploadedFile.php)
- [Controllers & Views](03-CONTROLLERS-VIEWS.md)
- [Validation](07-VALIDATION.md)
- [Security](09-SECURITY.md)
