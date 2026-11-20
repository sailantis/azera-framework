# Class: Kernel

**Full name:** [Azera\Core\Kernel](../../src/Core/Kernel.php)

Request in, response out.

The optional coordinator around [`Router`](Core_Router.md) and [`Dispatcher`](Core_Dispatcher.md). It owns the
three decisions every transport would otherwise repeat: what a missing route
answers, what a wrong method answers, and what an exception answers.

It is optional on purpose. `Router::match()` and `Dispatcher::dispatch()` stay
public and independently callable, so an application can keep calling them in
sequence and never build a Kernel. What such an application gives up is the
error boundary below — under FPM an uncaught exception is a fatal error, and
nothing in the framework installs a handler for it.

Nothing here sends output. A persistent worker reads the response through its
getters; only the FPM entry point calls `send()` on what this returns.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Kernel.php#L36)</small>

`public function __construct(Azera\AppContext $context, Azera\Core\Router|null $router = null, Azera\Core\Dispatcher|null $dispatcher = null, Azera\Core\NotFoundHandlerInterface|null $notFound = null, Azera\Core\ErrorHandlerInterface|null $error = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$context` | [AppContext](AppContext.md) | - | The application context the handlers resolve from. |
| `$router` | [Router](Core_Router.md)\|null | `null` | The router to match with; the context's when omitted. |
| `$dispatcher` | [Dispatcher](Core_Dispatcher.md)\|null | `null` | The dispatcher to invoke; the context's when omitted. |
| `$notFound` | [NotFoundHandlerInterface](Core_NotFoundHandlerInterface.md)\|null | `null` | Overrides the context's 404 policy; intended for tests. |
| `$error` | [ErrorHandlerInterface](Core_ErrorHandlerInterface.md)\|null | `null` | Overrides the context's error policy; intended for tests. |

**Return value**

- Type: `mixed`


---

### handle() · <small>[🗎](../../src/Core/Kernel.php#L58)</small>

`public function handle(Azera\Http\Request $request): Azera\Http\Response`

Handle one request and return the response to send.

A route that matches is dispatched. A path that matches nothing but would
serve another method answers 405 with an `Allow` header rather than 404 —
the router can tell the two apart, so this does. Anything thrown is passed
to the error handler.

An exception has no rethrow mode. Rethrowing would mean the same failure is
a rendered 500 in one deployment and a fatal error in another, which is the
split this class exists to end: the context always has a handler, because
[`PlainErrorHandler`](Core_PlainErrorHandler.md) is registered as its default. An application that
wants diagnostics replaces that default; it does not disable the boundary.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |

**Return value**

- Type: [Response](Http_Response.md)



---

[Back to the Index ⤴](README.md)
