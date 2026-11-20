# Class: PlainNotFoundHandler

**Full name:** [Azera\Core\PlainNotFoundHandler](../../src/Core/PlainNotFoundHandler.php)

A bare 404, and the default.

An application replaces this by registering its own
[`NotFoundHandlerInterface`](Core_NotFoundHandlerInterface.md) on [`AppContext`](AppContext.md). The default exists so
that every entry point has something to call: without it, each transport would
keep inventing its own body, which is exactly the duplication this replaces.

## Public methods

### for() · <small>[🗎](../../src/Core/PlainNotFoundHandler.php#L18)</small>

`public function for(Azera\Http\Request $request): Azera\Http\Response`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |

**Return value**

- Type: [Response](Http_Response.md)



---

[Back to the Index ⤴](README.md)
