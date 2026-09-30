# Class: NullEventDispatcher

**Full name:** [Azera\Event\NullEventDispatcher](../../src/Event/NullEventDispatcher.php)

No-op event dispatcher that returns every event unchanged.

This is the default dispatcher returned by [`AppContext::events()`](AppContext.md#events)
when no concrete dispatcher has been registered. It guarantees that
`$ctx->events()->dispatch($event)` never fails even in apps that have
no event listeners wired up — the cost is a single method return.

## Public methods

### dispatch() · <small>[🗎](../../src/Event/NullEventDispatcher.php#L17)</small>

`public function dispatch(object $event): object`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$event` | object | - |  |

**Return value**

- Type: `object`



---

[Back to the Index ⤴](README.md)
