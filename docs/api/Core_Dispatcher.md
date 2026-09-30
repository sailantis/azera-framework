# Class: Dispatcher

**Full name:** [Azera\Core\Dispatcher](../../src/Core/Dispatcher.php)

Dispatcher is responsible for handling the execution of controller actions based on the current routing information. It builds a middleware pipeline, and invokes the action, returning a Response object. It supports global and group-based middleware, as well as controller and action-specific middleware.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Dispatcher.php#L23)</small>

`public function __construct(): mixed`

Create a new Dispatcher and bind it to the current [`AppContext`](AppContext.md) singleton.

**Return value**

- Type: `mixed`


---

### addMiddleware() · <small>[🗎](../../src/Core/Dispatcher.php#L39)</small>

`public function addMiddleware(Azera\Core\MiddlewareInterface $mw): void`

Register a middleware that runs on every dispatched request.

Global middleware is prepended to the pipeline before any group,
controller, or action middleware.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$mw` | [MiddlewareInterface](Core_MiddlewareInterface.md) | - | Middleware instance to add. |

**Return value**

- Type: `void`


---

### defineMiddlewareGroup() · <small>[🗎](../../src/Core/Dispatcher.php#L56)</small>

`public function defineMiddlewareGroup(string $name, array $middleware): void`

Define a named middleware group that can be referenced from route definitions.

Groups are applied after global middleware and before controller/action
middleware. If several middleware groups are active for a route, they are
applied in the order they are listed on the route.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Unique group name (e.g. "auth", "admin"). |
| `$middleware` | array | - | Array of middleware definitions accepted by the pipeline normalizer. |

**Return value**

- Type: `void`


---

### getBaseNamespace() · <small>[🗎](../../src/Core/Dispatcher.php#L70)</small>

`public function getBaseNamespace(): string`

Get the base namespace for controllers.

**Return value**

- Type: `string`
- Description: The base namespace for controllers.


---

### setBaseNamespace() · <small>[🗎](../../src/Core/Dispatcher.php#L81)</small>

`public function setBaseNamespace(string $baseNamespace): static`

Set the base namespace for controllers. This namespace will be prefixed to all controller class names when dispatching.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$baseNamespace` | string | - | The base namespace for controllers (e.g. "App\\Controllers") |

**Return value**

- Type: `static`


---

### getDefaultController() · <small>[🗎](../../src/Core/Dispatcher.php#L92)</small>

`public function getDefaultController(): string`

Get the default controller name used when a route doesn't provide one.

**Return value**

- Type: `string`
- Description: Default controller class name (without namespace)


---

### setDefaultController() · <small>[🗎](../../src/Core/Dispatcher.php#L103)</small>

`public function setDefaultController(string $defaultController): static`

Set the default controller name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$defaultController` | string | - | Controller class name to use as default |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  If given name is empty


---

### getDefaultAction() · <small>[🗎](../../src/Core/Dispatcher.php#L117)</small>

`public function getDefaultAction(): string`

Get the default action name used when a route doesn't provide one.

**Return value**

- Type: `string`
- Description: Default action method name


---

### setDefaultAction() · <small>[🗎](../../src/Core/Dispatcher.php#L128)</small>

`public function setDefaultAction(string $defaultAction): static`

Set the default action name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$defaultAction` | string | - | Action method name to use as default |

**Return value**

- Type: `static`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  If given name is empty


---

### dispatch() · <small>[🗎](../../src/Core/Dispatcher.php#L145)</small>

`public function dispatch(array $routeInfo): Azera\Http\Response`

Dispatch a request to the appropriate controller and action based on the provided routing information. This method will determine the controller class and action method to invoke, build the middleware pipeline, and execute the controller action, returning the resulting Response.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$routeInfo` | array | - | An associative array containing routing information, including 'namespace', 'controller', 'action', and any route parameters. |

**Return value**

- Type: [Response](Http_Response.md)

**Throws**

- [ControllerNotFoundException](Core_Exceptions_ControllerNotFoundException.md)
- [InvalidControllerException](Core_Exceptions_InvalidControllerException.md)
- [ActionNotFoundException](Core_Exceptions_ActionNotFoundException.md)



---

[Back to the Index ⤴](README.md)
