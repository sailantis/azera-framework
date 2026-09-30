# Class: CsrfMiddleware

**Full name:** [Azera\Security\CsrfMiddleware](../../src/Security/CsrfMiddleware.php)

CSRF protection middleware using the synchronizer (double-submit) token
pattern.

For state-changing requests (POST, PUT, PATCH, DELETE) the middleware
validates a token submitted by the client against the one stored in
the session. The token is generated lazily and exposed to view layers
via [`CsrfMiddleware::token()`](Security_CsrfMiddleware.md#token) and the `csrf_token` session key.

GET, HEAD, and OPTIONS requests are always allowed through.

The middleware is opt-in: register it in the pipeline only when you
want CSRF protection. It carries no cost when not wired.

## Public Constants

- **SESSION_KEY** = `'_csrf_token'`
- **TOKEN_NAME** = `'_csrf_token'`

## Public methods

### __construct() · <small>[🗎](../../src/Security/CsrfMiddleware.php#L43)</small>

`public function __construct(string $tokenName = '_csrf_token', array $guardedMethods = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$tokenName` | string | `'_csrf_token'` | Name of the field / header / cookie that<br>carries the submitted token. Defaults to `TOKEN_NAME`. |
| `$guardedMethods` | array | `[]` | HTTP methods that require<br>a valid token. Defaults to the common state-changing methods. |

**Return value**

- Type: `mixed`


---

### process() · <small>[🗎](../../src/Security/CsrfMiddleware.php#L48)</small>

`public function process(Azera\AppContext $context, callable $next): Azera\Http\Response|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$context` | [AppContext](AppContext.md) | - |  |
| `$next` | callable | - |  |

**Return value**

- Type: [Response](Http_Response.md)|`null`


---

### ensureToken() · <small>[🗎](../../src/Security/CsrfMiddleware.php#L84)</small>

`public function ensureToken(Azera\Http\Session $session): string`

Get (and lazily generate) the current CSRF token from the session.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$session` | [Session](Http_Session.md) | - |  |

**Return value**

- Type: `string`
- Description: The CSRF token.



---

[Back to the Index ⤴](README.md)
