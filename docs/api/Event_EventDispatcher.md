# Class: EventDispatcher

**Full name:** [Azera\Event\EventDispatcher](../../src/Event/EventDispatcher.php)

Default implementation of an event dispatcher.

Listeners are registered with `listen()` and resolved on dispatch.
A listener may be a callable (closure, invokable object, `[$obj, 'method']`)
or a class-string that is resolved through [`AppContext`](AppContext.md) (allowing
DI for listener constructors). Class-string listeners must implement
`__invoke(object $event): void`.

Priority: higher priority numbers run first (default 0). Listeners with
the same priority run in registration order.

Example:
```php
$dispatcher = new EventDispatcher();
$dispatcher->listen(UserCreated::class, function (UserCreated $e) {
    // send welcome email
});
$dispatcher->listen(UserCreated::class, SendWelcomeEmailListener::class, priority: 10);
$dispatcher->dispatch(new UserCreated($user));
```

## Public methods

### __construct() · <small>[🗎](../../src/Event/EventDispatcher.php#L58)</small>

`public function __construct(Azera\AppContext|null $context = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$context` | [AppContext](AppContext.md)\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### listen() · <small>[🗎](../../src/Event/EventDispatcher.php#L71)</small>

`public function listen(string $eventClass, callable|string $handler, int $priority = 0): void`

Register a listener for an event class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$eventClass` | string | - | Fully-qualified class name of the event. |
| `$handler` | callable\|string | - | A callable, or a class-string resolved<br>via AppContext (must implement __invoke). |
| `$priority` | int | `0` | Higher runs first (default 0). |

**Return value**

- Type: `void`


---

### dispatch() · <small>[🗎](../../src/Event/EventDispatcher.php#L77)</small>

`public function dispatch(object $event): object`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$event` | object | - |  |

**Return value**

- Type: `object`



---

[Back to the Index ⤴](README.md)
