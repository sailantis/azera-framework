# Class: Controller

**Full name:** [Azera\Core\Controller](../../src/Core/Controller.php)

Base class for controllers in Azera.

## Public methods

### getMiddlewares() · <small>[🗎](../../src/Core/Controller.php#L46)</small>

`public function getMiddlewares(): array`

Get the middleware for the controller. Usually used by the Dispatcher to build the middleware pipeline for the current request.

**Return value**

- Type: `array`


---

### getActionMiddlewares() · <small>[🗎](../../src/Core/Controller.php#L56)</small>

`public function getActionMiddlewares(string $action): array`

Get the middleware for a specific action. Usually used by the Dispatcher to build the middleware pipeline for the current request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$action` | string | - | The name of the action (e.g. "editAction") |

**Return value**

- Type: `array`


---

### context() · <small>[🗎](../../src/Core/Controller.php#L67)</small>

`public function context(): Azera\AppContext`

Get the current AppContext instance. Useful for accessing services or route info from the controller.

**Return value**

- Type: [AppContext](AppContext.md)


---

### request() · <small>[🗎](../../src/Core/Controller.php#L76)</small>

`public function request(): Azera\Http\Request`

Get the current Request object from the context.

**Return value**

- Type: [Request](Http_Request.md)


---

### view() · <small>[🗎](../../src/Core/Controller.php#L85)</small>

`public function view(): Azera\Core\ViewEngine`

Get the ViewEngine from the context for rendering views.

**Return value**

- Type: [ViewEngine](Core_ViewEngine.md)


---

### session() · <small>[🗎](../../src/Core/Controller.php#L94)</small>

`public function session(): Azera\Http\Session|null`

Get the Session from the context. May return null if no session is available.

**Return value**

- Type: [Session](Http_Session.md)|`null`


---

### flash() · <small>[🗎](../../src/Core/Controller.php#L113)</small>

`public function flash(): Azera\Http\Flash`

Get the one-request message bag.

Sugar over `AppContext::flash()`:

```php
// POST /account — queue for the page after the redirect
$this->flash()->success('Account settings updated.');
return Response::redirect('/account');

// GET /account — show once
$messages = $this->flash()->consume();
```

**Return value**

- Type: [Flash](Http_Flash.md)


---

### translator() · <small>[🗎](../../src/Core/Controller.php#L121)</small>

`public function translator(): Azera\Localization\Translator`

Get the application-wide translation service.

**Return value**

- Type: [Translator](Localization_Translator.md)


---

### t() · <small>[🗎](../../src/Core/Controller.php#L137)</small>

`public function t(string $key, array $vars = [], string|null $domain = null, string|null $locale = null): string`

Translate a key for this controller's output.

Sugar over `translator()`; templates bound through
`$ctx->translator()->bind($ctx->view())` resolve the same catalogue.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Message key (e.g. "nav.home"). |
| `$vars` | array | `[]` | Placeholder values for `{name}` substitution. |
| `$domain` | string\|null | `null` | Domain override for this call. |
| `$locale` | string\|null | `null` | Locale override for this call. |

**Return value**

- Type: `string`


---

### intl() · <small>[🗎](../../src/Core/Controller.php#L145)</small>

`public function intl(): Azera\Localization\Intl`

Get the application-wide locale-aware formatting service.

**Return value**

- Type: [Intl](Localization_Intl.md)


---

### cookies() · <small>[🗎](../../src/Core/Controller.php#L154)</small>

`public function cookies(): Azera\Http\Cookies`

Get the Cookies service from the context for managing cookies.

**Return value**

- Type: [Cookies](Http_Cookies.md)


---

### resolve() · <small>[🗎](../../src/Core/Controller.php#L166)</small>

`public function resolve(string $id): object`

Get a service from the context by class name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - |  |

**Return value**

- Type: `object`


---

### db() · <small>[🗎](../../src/Core/Controller.php#L174)</small>

`public function db(): Azera\Db\DatabaseManager`

Get the database manager for persistence access.

**Return value**

- Type: [DatabaseManager](Db_DatabaseManager.md)


---

### cache() · <small>[🗎](../../src/Core/Controller.php#L182)</small>

`public function cache(): Psr\SimpleCache\CacheInterface`

Get the cache service.

**Return value**

- Type: `Psr\SimpleCache\CacheInterface`


---

### logger() · <small>[🗎](../../src/Core/Controller.php#L190)</small>

`public function logger(): Psr\Log\LoggerInterface`

Get the logger.

**Return value**

- Type: `Psr\Log\LoggerInterface`


---

### events() · <small>[🗎](../../src/Core/Controller.php#L198)</small>

`public function events(): Psr\EventDispatcher\EventDispatcherInterface`

Get the event dispatcher.

**Return value**

- Type: `Psr\EventDispatcher\EventDispatcherInterface`



---

[Back to the Index ⤴](README.md)
