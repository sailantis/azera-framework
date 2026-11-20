# Interface: NotFoundHandlerInterface

**Full name:** [Azera\Core\NotFoundHandlerInterface](../../src/Core/NotFoundHandlerInterface.php)

The application's answer for a request that matched no route.

Registered on [`AppContext`](AppContext.md) so the policy exists once, whichever
transport is serving: the FPM entry point, a RoadRunner worker and a Volucer
worker all resolve the same instance instead of each carrying its own copy.

Deliberately separate from [`ErrorHandlerInterface`](Core_ErrorHandlerInterface.md). A not-found handler
decides what a missing URL looks like; letting it also see exceptions would
make it possible for a `TypeError` to be answered by a 404 policy.

## Public methods

### for() · <small>[🗎](../../src/Core/NotFoundHandlerInterface.php#L30)</small>

`public function for(Azera\Http\Request $request): Azera\Http\Response`

Build the response for a request that matched no route.

The response is returned, never sent: a persistent worker writes it to its
own transport, and only the FPM entry point calls `send()` on the result.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - | The request that did not match. |

**Return value**

- Type: [Response](Http_Response.md)
- Description: The response to send to the client.



---

[Back to the Index ⤴](README.md)
