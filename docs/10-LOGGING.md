# Logging

PSR-3 compatible logging through `AppContext::logger()`, plus typed database events (PSR-14) for query monitoring, transaction tracking, and connection diagnostics.

## PSR-3 Logger

### The `logger()` accessor

Always returns a PSR-3 `LoggerInterface`:

```php
$ctx->logger()->info('User registered', ['id' => $user->id]);
$ctx->logger()->error('Payment failed', ['invoice' => $invoiceId]);
```

When no logger is registered, the accessor returns a `NullLogger` that silently discards every message — calling code can always log without null-checks.

### Registering a real logger

Register any PSR-3 logger via the container. **Monolog** is the most common choice:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

$ctx->set(LoggerInterface::class, function () {
    $logger = new Logger('app');
    $logger->pushHandler(new StreamHandler(BASE_DIR . '/logs/app.log', Logger::DEBUG));
    return $logger;
});
```

Because Azera uses `\Psr\Log\LoggerInterface` directly (no framework-specific interface), any PSR-3 logger — Monolog, Symfony's `Logger`, Laminas's `Logger` — works without an adapter.

### NullLogger

`Azera\Log\NullLogger` is the default — it implements `Psr\Log\LoggerInterface` and does nothing:

```php
// Before any logger is registered:
$ctx->logger()->debug('silently discarded');
// After registration:
$ctx->set(LoggerInterface::class, fn() => new Logger('app'));
$ctx->logger()->debug('goes to Monolog'); // same call, no code change
```

### Using the logger in services

Services that need a logger should type-hint `Psr\Log\LoggerInterface` in their constructor:

```php
use Psr\Log\LoggerInterface;

class PaymentService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function charge(int $amount): void
    {
        $this->logger->info('Charging customer', ['amount' => $amount]);
    }
}
```

Register the service for autowiring and the logger is injected automatically:

```php
$ctx->set(PaymentService::class); // autowired — LoggerInterface resolved from container
```

### Exception handling middleware

Capture and log exceptions globally via middleware:

```php
use Azera\Core\MiddlewareInterface;
use Azera\AppContext;
use Azera\Http\Response;

class ExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug = false) {}

    public function process(AppContext $context, callable $next): ?Response
    {
        try {
            return $next($context);
        } catch (\Throwable $e) {
            $context->logger()->error('Unhandled exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if ($this->debug) {
                return Response::json([
                    'error' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                ], 500);
            }

            return Response::json(['error' => 'Internal Server Error'], 500);
        }
    }
}
```

## Database Events (PSR-14)

Typed PSR-14 events fire for every database operation. Listen via `AppContext::events()` to log queries, measure duration, or build audit trails.

### Available events

All events live in `Azera\Db\Event\*`:

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
use Azera\Event\EventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

$dispatcher = new EventDispatcher();
$dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($ctx) {
    $ctx->logger()->debug('Query executed', [
        'sql' => $event->query,
        'params' => $event->params,
        'duration_ms' => $event->durationMs,
    ]);
});

$ctx->set(EventDispatcherInterface::class, $dispatcher);
```

### Measuring query duration

```php
use Azera\Db\Event\QueryExecuted;

$dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($ctx) {
    if ($event->durationMs > 100) {
        $ctx->logger()->warning('Slow query detected', [
            'sql' => $event->query,
            'duration_ms' => $event->durationMs,
        ]);
    }
});
```

### Transaction monitoring

```php
use Azera\Db\Event\TransactionRolledBack;

$dispatcher->listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use ($ctx) {
    $ctx->logger()->warning('Transaction rolled back', ['level' => $event->level]);
});
```

### Reconnect events

Reconnect events only fire when auto-reconnect is enabled via `Database::setAutoReconnect()`:

```php
use Azera\Db\Event\ReconnectFailed;

$dispatcher->listen(ReconnectFailed::class, function (ReconnectFailed $event) use ($ctx) {
    $ctx->logger()->error('Database reconnect failed', [
        'attempt' => $event->attempt,
    ]);
});
```

See [Events](13-EVENTS.md) for full PSR-14 event documentation.

## Practical recommendations

- Log all query lifecycle events in development; restrict to high-signal events (`DatabaseOperationFailed`, reconnect failures) in production.
- Redact sensitive values and PII from logged parameters before writing to any sink.
- Add a correlation/request ID at the middleware level and include it in every log message.
- Use transaction events to trace transaction boundaries in audit logs.

## Related

- [Events](13-EVENTS.md)
- [Database Queries](05-DATABASE-QUERIES.md)
- [Security](09-SECURITY.md)