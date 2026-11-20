# Interface: ErrorHandlerInterface

**Full name:** [Azera\Core\ErrorHandlerInterface](../../src/Core/ErrorHandlerInterface.php)

The application's answer for a request that raised an exception.

Separate from [`NotFoundHandlerInterface`](Core_NotFoundHandlerInterface.md) on purpose: this handler exists to
turn a failure into a response, and must not be shareable with the code that
decides what a missing URL looks like.

Registering one is what gives a request a response instead of a fatal error.
Under FPM an uncaught exception terminates the process, so without this the
response is whatever PHP's error handler prints; under a persistent worker the
loop catches it and sends a bare 500. Both are replaced by the registered
handler, which can log, render and pick a status.

## Public methods

### for() · <small>[🗎](../../src/Core/ErrorHandlerInterface.php#L34)</small>

`public function for(Throwable $error, Azera\Http\Request $request): Azera\Http\Response`

Build the response for an exception raised while handling a request.

The response is returned, never sent. Implementations decide how much of the
throwable is safe to show; [`PlainErrorHandler`](Core_PlainErrorHandler.md) shows none of it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$error` | Throwable | - | The exception that escaped the pipeline. |
| `$request` | [Request](Http_Request.md) | - | The request being handled when it escaped. |

**Return value**

- Type: [Response](Http_Response.md)
- Description: The response to send to the client.



---

[Back to the Index ⤴](README.md)
