# Interface: RequestScoped

**Full name:** [Azera\Lifecycle\RequestScoped](../../src/Lifecycle/RequestScoped.php)

Opt-in contract for services that hold per-request state.

Traditional PHP-FPM runs one request per process, so anything request-scoped
is naturally destroyed when the process exits. Long-lived application
servers (RoadRunner, Swoole, FrankenPHP, Laravel Octane, …) keep workers —
and the shared [`AppContext`](AppContext.md) singleton — alive across many
requests. Anything that would normally be re-created per request must
therefore be reset between requests to avoid leaking state from one request
into the next.

Implement [`RequestScoped`](Lifecycle_RequestScoped.md) on a service, register it on the context, and
it is flushed automatically whenever [`AppContext::clearRequestScope()`](AppContext.md#clearrequestscope)
is called (after each request in a persistent worker).

Infrastructure services that own persistent connections (database manager,
cache/Redis backends, queue, logger, event dispatcher) can stay resident
across requests and should NOT implement this interface.

## Public methods

### resetState() · <small>[🗎](../../src/Lifecycle/RequestScoped.php#L36)</small>

`public function resetState(): void`

Reset all per-request state held by this service.

Called once after the worker finishes processing a request, before the
next one is read. The service may keep its persistent handles and
connections, but must clear any data that belongs to the request that
has just finished.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
