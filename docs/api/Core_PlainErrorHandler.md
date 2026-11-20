# Class: PlainErrorHandler

**Full name:** [Azera\Core\PlainErrorHandler](../../src/Core/PlainErrorHandler.php)

A bare 500 that discloses nothing, and the default.

The throwable is not rendered and not echoed: its message may contain a
connection string, a file path, or user input that reached an exception
constructor. An application that wants a diagnostic body registers its own
[`ErrorHandlerInterface`](Core_ErrorHandlerInterface.md), which is also where logging belongs — the handler
is resolved from the container, so it can take a PSR-3 logger as a dependency.

## Public methods

### for() · <small>[🗎](../../src/Core/PlainErrorHandler.php#L20)</small>

`public function for(Throwable $error, Azera\Http\Request $request): Azera\Http\Response`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$error` | Throwable | - |  |
| `$request` | [Request](Http_Request.md) | - |  |

**Return value**

- Type: [Response](Http_Response.md)



---

[Back to the Index ⤴](README.md)
