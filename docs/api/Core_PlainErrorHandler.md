# Class: PlainErrorHandler

**Full name:** [Azera\Core\PlainErrorHandler](../../src/Core/PlainErrorHandler.php)

Default handler for 500 responses.

The `log_errors` and `display_errors` ini settings control logging and
diagnostic output, following PHP's fatal-error configuration.

`log_errors` (default On) — write one line to the error log: the class, message,
method, path and throw site. When disabled, this handler does not write an
error log entry.

`display_errors` (default Off in production, On in development) — return the
exception details in the response instead of a plain 500 status. When enabled,
the body includes the throwable in a PHP-style format, with HTML values escaped
when `html_errors` is enabled.

`error_reporting` does not control this handler. It handles throwables passed to
it by the Kernel, and logging and response details are controlled by the ini
settings above.

Applications can register a custom [`ErrorHandlerInterface`](Core_ErrorHandlerInterface.md) to provide
features such as PSR-3 logging, structured context, or redaction.

## Public methods

### for() · <small>[🗎](../../src/Core/PlainErrorHandler.php#L33)</small>

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
