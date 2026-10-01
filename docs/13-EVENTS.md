# Events

PSR-14 compatible event dispatching through `AppContext::events()`. The dispatcher resolves listeners by event class name — including parent classes and implemented interfaces — so a listener for a base event catches all subtypes.

## PSR-14 Interfaces

Azera uses the PSR-14 interfaces directly from the `psr/event-dispatcher` package:

- `\Psr\EventDispatcher\EventDispatcherInterface` — `dispatch(object $event): object`
- `\Psr\EventDispatcher\StoppableEventInterface` — `isPropagationStopped(): bool`

No adapter or wrapper is needed. Any PSR-14 dispatcher works as a drop-in replacement.

## The `events()` accessor

`AppContext::events()` returns a `EventDispatcherInterface`. When none is registered, it returns a `NullEventDispatcher` that passes every event through unchanged — so `dispatch()` is always safe:

```php
$ctx->events()->dispatch(new UserCreated($user));
```

Register a real dispatcher:

```php
use Azera\Event\EventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

$ctx->set(EventDispatcherInterface::class, function () {
    $dispatcher = new EventDispatcher();
    $dispatcher->listen(UserCreated::class, SendWelcomeEmailListener::class);
    return $dispatcher;
});
```

## EventDispatcher

`Azera\Event\EventDispatcher` is the default implementation.

### Registering listeners

```php
$dispatcher->listen(string $eventClass, callable|string $handler, int $priority = 0): void;
```

- `$eventClass` — fully-qualified class name of the event
- `$handler` — a callable (closure, invokable object, `[$obj, 'method']`) or a class-string resolved through `AppContext` (must implement `__invoke(object $event): void`)
- `$priority` — higher numbers run first (default 0); same priority runs in registration order

### Callable listeners

```php
$dispatcher->listen(OrderPlaced::class, function (OrderPlaced $event) {
    $ctx->logger()->info('Order placed', ['id' => $event->order->id]);
});
```

### Class-string listeners (autowired)

Class-string listeners are resolved through `AppContext::get()`, so their constructor dependencies are injected:

```php
class SendWelcomeEmailListener
{
    public function __construct(
        private SmtpMailer $mailer,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(UserCreated $event): void
    {
        $this->mailer->send($event->user->email, 'Welcome!');
        $this->logger->info('Welcome email sent', ['user' => $event->user->id]);
    }
}

$dispatcher->listen(UserCreated::class, SendWelcomeEmailListener::class);
```

### Dispatching events

```php
$event = new UserCreated($user);
$ctx->events()->dispatch($event);
// Listeners may have mutated $event — it's the same object
```

### Listener resolution

The dispatcher resolves listeners for the event's class, all parent classes, and all implemented interfaces:

```php
// Listen to an interface — catches all events implementing it
$dispatcher->listen(\App\Events\LoggableEvent::class, function (LoggableEvent $event) {
    error_log($event->logMessage());
});
```

## Stoppable events

Implement `\Psr\EventDispatcher\StoppableEventInterface` to allow listeners to stop propagation:

```php
use Psr\EventDispatcher\StoppableEventInterface;

class CacheLookupEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
```

The dispatcher checks `isPropagationStopped()` after each listener and stops if it returns `true`.

## Database Events

Azera dispatches typed PSR-14 events for database operations. All events live in `Azera\Db\Event\*`:

| Event class | Fired when |
|---|---|
| `QueryExecuted` | After a query completes (always, even on error) |
| `StatementPrepared` | After a prepared statement is created |
| `StatementExecuted` | After a prepared statement is executed |
| `TransactionStarted` | After `begin()` |
| `TransactionCommitted` | After `commit()` |
| `TransactionRolledBack` | After `rollback()` |
| `DatabaseOperationFailed` | When a `PDOException` is caught |
| `ReconnectAttempt` | Before a reconnect attempt |
| `Reconnected` | After a successful reconnect |
| `ReconnectFailed` | When a reconnect attempt fails |
| `ReconnectAborted` | When reconnect is aborted (max attempts reached) |

### Listening to database events

```php
use Azera\Db\Event\QueryExecuted;
use Azera\Db\Event\TransactionRolledBack;

$dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($ctx) {
    if ($event->durationMs > 100) {
        $ctx->logger()->warning('Slow query', [
            'sql' => $event->query,
            'duration_ms' => $event->durationMs,
        ]);
    }
});

$dispatcher->listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use ($ctx) {
    $ctx->logger()->warning('Transaction rolled back', ['level' => $event->level]);
});
```

> **Reconnect events** only fire when auto-reconnect is enabled via `Database::setAutoReconnect()`.