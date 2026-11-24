# Class: Flash

**Full name:** [Azera\Http\Flash](../../src/Http/Flash.php)

One-request messages shown on the page that follows a redirect.

The POST-redirect-GET shape every form needs: an action records a message and
redirects; the page it lands on shows it once and the next refresh shows
nothing. Storage is the session, so no message is ever carried in a URL.

```php
// POST /account
$this->flashBag()->success('Account settings updated.');
return Response::redirect('/account');

// GET /account
$messages = $this->flashBag()->consume();   // [['type' => 'success', 'message' => '…']]
```

## When a message becomes visible

Messages are queued for the **next** request, which is why this class is not a
plain session key: a message written while rendering would otherwise appear on
the page the user is already looking at, and again after the refresh. Two
buckets make the boundary explicit — `add()` (and the type helpers) writes
to the bucket for the next request, `now()` writes to the one visible
immediately.

The boundary is crossed by `age()`, which must run **once per request**.
This class does that itself, the first time a session is available: when it is
constructed with a `Session` that is the constructor, and when it is constructed
without one (as `AppContext` does) it is the first read or write after
`useSessionResolver()` resolves a session. Either way the promotion happens
exactly once, so the order in which the application touches the bag and the
middleware that binds the session cannot matter.

Without a session (a CLI task, a unit test, a middleware that runs before
`SessionMiddleware`) messages are kept in memory and live for the life of the
instance, so the same code runs unguarded.

## Public Constants

- **SESSION_KEY** = `'_flash'`

## Public methods

### __construct() · <small>[🗎](../../src/Http/Flash.php#L105)</small>

`public function __construct(Azera\Http\Session|null $session = null, string $sessionKey = '_flash', array $cssClasses = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$session` | [Session](Http_Session.md)\|null | `null` | Session store, or null for in-memory. |
| `$sessionKey` | string | `'_flash'` | Session key holding the two buckets. |
| `$cssClasses` | array | `[]` | Type => CSS class map. |

**Return value**

- Type: `mixed`


---

### useSessionResolver() · <small>[🗎](../../src/Http/Flash.php#L121)</small>

`public function useSessionResolver(Closure $resolver): static`

Bind a lazily-resolved session and age the bag on first use.

`AppContext` uses this so the bag can be built before `SessionMiddleware`
has run without losing the session or skipping the request boundary.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$resolver` | Closure | - | Returns the current session, or null. |

**Return value**

- Type: `static`


---

### age() · <small>[🗎](../../src/Http/Flash.php#L137)</small>

`public static function age(Azera\Http\Session|null $session, string $sessionKey = '_flash'): void`

Promote the messages queued by the previous request, discarding the ones
that were already visible during it.

Call once per request, before the application reads or writes a message.
`AppContext::flash()` does it when it builds the service.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$session` | [Session](Http_Session.md)\|null | - |  |
| `$sessionKey` | string | `'_flash'` |  |

**Return value**

- Type: `void`


---

### add() · <small>[🗎](../../src/Http/Flash.php#L165)</small>

`public function add(string $type, string $message): void`

Queue a message for the next request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - | Message type; an unknown type is allowed and uses its<br>own name as the CSS class. |
| `$message` | string | - | Untrusted text is escaped on output unless<br>`setAutoescape()` turned that off. |

**Return value**

- Type: `void`


---

### now() · <small>[🗎](../../src/Http/Flash.php#L180)</small>

`public function now(string $type, string $message): void`

Show a message on this request's page as well as queueing it for the next.

Use it when the action renders instead of redirecting (a re-rendered form
with a validation error). The message is consumed with the current request,
so it is not shown twice.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$message` | string | - |  |

**Return value**

- Type: `void`


---

### success() · <small>[🗎](../../src/Http/Flash.php#L189)</small>

`public function success(string $message): void`

Queue a success message for the next request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - |  |

**Return value**

- Type: `void`


---

### error() · <small>[🗎](../../src/Http/Flash.php#L195)</small>

`public function error(string $message): void`

Queue an error message for the next request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - |  |

**Return value**

- Type: `void`


---

### warning() · <small>[🗎](../../src/Http/Flash.php#L201)</small>

`public function warning(string $message): void`

Queue a warning message for the next request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - |  |

**Return value**

- Type: `void`


---

### notice() · <small>[🗎](../../src/Http/Flash.php#L207)</small>

`public function notice(string $message): void`

Queue a notice message for the next request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - |  |

**Return value**

- Type: `void`


---

### info() · <small>[🗎](../../src/Http/Flash.php#L213)</small>

`public function info(string $message): void`

Queue an informational message for the next request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | string | - |  |

**Return value**

- Type: `void`


---

### reflash() · <small>[🗎](../../src/Http/Flash.php#L224)</small>

`public function reflash(): void`

Keep the messages visible this request available for one more.

Moves them back into the next-request bucket, which is what "show this
again on the page I am redirecting to" needs.

**Return value**

- Type: `void`


---

### has() · <small>[🗎](../../src/Http/Flash.php#L240)</small>

`public function has(string|null $type = null): bool`

Whether any message is visible on this request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string\|null | `null` | Restrict the check to one type. |

**Return value**

- Type: `bool`


---

### consume() · <small>[🗎](../../src/Http/Flash.php#L259)</small>

`public function consume(string|null $type = null, bool $consume = true): array`

The messages visible on this request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string\|null | `null` | Restrict to one type. |
| `$consume` | bool | `true` | Remove them, so a refresh shows nothing. On by<br>default, because that is what makes a flash a flash. |

**Return value**

- Type: `array`


---

### pending() · <small>[🗎](../../src/Http/Flash.php#L288)</small>

`public function pending(): array`

The messages queued for the next request, without consuming them.

**Return value**

- Type: `array`


---

### first() · <small>[🗎](../../src/Http/Flash.php#L303)</small>

`public function first(string|null $type = null, bool $consume = true): array|null`

The first visible message, or null.

Unlike `consume()`, this removes only the message it returns, so
`first()` followed by `consume()` still yields the rest.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string\|null | `null` | Restrict to one type. |
| `$consume` | bool | `true` | Remove it from the store. |

**Return value**

- Type: `array`|`null`


---

### clear() · <small>[🗎](../../src/Http/Flash.php#L331)</small>

`public function clear(): void`

Drop every message, visible and queued.

**Return value**

- Type: `void`


---

### render() · <small>[🗎](../../src/Http/Flash.php#L347)</small>

`public function render(array|null $messages = null): string`

Render messages as HTML.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$messages` | array\|null | `null` | Messages to<br>render; the visible ones are consumed when omitted. |

**Return value**

- Type: `string`
- Description: One block per message, in order.


---

### cssClass() · <small>[🗎](../../src/Http/Flash.php#L374)</small>

`public function cssClass(string $type): string`

The CSS class for a message type.

Falls back to the type itself, so `add('primary', …)` works without touching
the map.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |

**Return value**

- Type: `string`


---

### cssClasses() · <small>[🗎](../../src/Http/Flash.php#L380)</small>

`public function cssClasses(): array`

The type => CSS class map in effect.

**Return value**

- Type: `array`


---

### setCssClasses() · <small>[🗎](../../src/Http/Flash.php#L390)</small>

`public function setCssClasses(array $classes): static`

Replace the type => CSS class map.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$classes` | array | - | Type => CSS class. |

**Return value**

- Type: `static`


---

### setTemplate() · <small>[🗎](../../src/Http/Flash.php#L403)</small>

`public function setTemplate(string $template): static`

Replace the HTML wrapper.

`%class%`, `%type%` and `%message%` are substituted; everything else is
emitted verbatim, so the template is trusted markup, not data.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$template` | string | - |  |

**Return value**

- Type: `static`


---

### getTemplate() · <small>[🗎](../../src/Http/Flash.php#L411)</small>

`public function getTemplate(): string`

The HTML wrapper in effect.

**Return value**

- Type: `string`


---

### setAutoescape() · <small>[🗎](../../src/Http/Flash.php#L422)</small>

`public function setAutoescape(bool $autoescape): static`

Whether messages are escaped before rendering.

On by default. Turn it off only for messages the application built itself
and deliberately marked up — never for anything a user typed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$autoescape` | bool | - |  |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
