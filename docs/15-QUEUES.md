# Queues

A minimal queue abstraction for deferring work: synchronous (inline) or asynchronous (background) processing behind one contract, so drivers are swappable without application changes.

There is no PHP-FIG standard for queues, so Azera defines its own contracts.

## QueueInterface

```php
namespace Azera\Queue;

interface QueueInterface
{
    /**
     * Push a job onto the queue.
     *
     * @param JobInterface  $job      The job to enqueue.
     * @param array<string, mixed> $options Backend-specific options
     *   (delay, priority, queue override, etc.).
     * @return mixed The job identifier or result. For {@see SyncQueue}
     *   this is null (job ran synchronously).
     */
    public function push(JobInterface $job, array $options = []): mixed;

    /**
     * Register a worker callback for a specific job class.
     *
     * For async backends this maps serialized jobs back to their handlers;
     * for {@see SyncQueue} it is a no-op (jobs handle themselves).
     */
    public function registerWorker(string $jobClass, ?callable $handler = null): void;
}
```

The framework's `QueueInterface` is intentionally minimal — just `push()` and `registerWorker()`. Async backends additionally need to reserve, release, and delete jobs; that richer contract (`reserve()`, `delete()`, `release()`, `count()`) lives in the companion package as `Azera\Queue\Backend\Contract\ReservableQueueInterface`.

## JobInterface and Job

`JobInterface` is the contract for executable units of work. It declares the work (`handle()`) plus the retry policy (`tries()`, `backoff()`, `retryUntil()`), a failure hook (`failed()`), and identity/routing metadata (`id()`, `queue()`):

```php
namespace Azera\Queue;

interface JobInterface
{
    public function handle(): void;
    public function tries(): int;          // max attempts, default 1
    public function backoff(): int|array;  // seconds (or progressive) before each retry, default 0
    public function retryUntil(): ?int;    // Unix timestamp cap, default null
    public function failed(Throwable $exception): void;
    public function id(): ?string;         // idempotency key, default null
    public function queue(): string;       // target queue name, default 'default'
}
```

`Job` is a convenience base class that supplies the defaults, so you only implement `handle()`:

```php
use Azera\Queue\Job;

class SendEmailJob extends Job
{
    public function __construct(
        private string $to,
        private string $subject,
        private string $body,
    ) {}

    public function handle(): void
    {
        // Perform the work
        $mailer->send($this->to, $this->subject, $this->body);
    }
}
```

The retry policy is only honored by async backends (the companion `Worker`). `SyncQueue` runs `handle()` once and rethrows on failure.

## The `queue()` accessor

`AppContext::queue()` returns a `QueueInterface`. Unlike the other subsystems, the queue has **no null implementation** — silently dropping jobs would be dangerous. If no queue is registered, `queue()` throws a `LogicException` with an install hint:

```php
// Without a registered queue:
$ctx->queue(); // throws LogicException
```

```php
use Azera\Queue\QueueInterface;
use Azera\Queue\SyncQueue;

// Development: synchronous processing (jobs run inline)
$ctx->set(QueueInterface::class, fn() => new SyncQueue());

// Production: async driver (companion package)
// $ctx->set(QueueInterface::class, fn() => new RedisQueue($redis));
```

## SyncQueue

Processes jobs immediately when `push()` is called — useful for development and testing, with full stack traces:

```php
use Azera\Queue\SyncQueue;

$queue = new SyncQueue();
$queue->push(new SendEmailJob('user@example.com', 'Welcome', 'Hello!'));
// handle() is called immediately
```

## Dispatching jobs

The simplest form pushes directly:

```php
$job = new ProcessPaymentJob($orderId, $amount);
$ctx->queue()->push($job);
```

With `SyncQueue`, the job runs inline. With an async driver, it's serialized and a worker picks it up later.

The companion package also provides a fluent `Dispatcher` for queue/delay/priority options:

```php
use Azera\Queue\Dispatch\Dispatcher;

Dispatcher::dispatch(new SendEmailJob('user@example.com'))
    ->onQueue('emails')
    ->delay(60)
    ->priority('high')
    ->send();
```

## QueueException

`Azera\Queue\QueueException` is thrown for queue-level errors (e.g., serialisation failures, connection issues).

## Companion package: `azera-queue`

Async drivers and the worker live in the `sailantis/azera-queue` companion package:

- `Azera\Queue\Backend\DatabaseQueue` — SQL-backed queue using the framework's `Database` (at-least-once delivery, visibility timeouts, delayed jobs). Default for apps already on MySQL/Postgres. Reserves jobs with a row lock (`FOR UPDATE` / `SKIP LOCKED`) so concurrent workers cannot double-claim; `countAvailable()` reports the genuine backlog excluding in-flight jobs.
- `Azera\Queue\Backend\RedisQueue` — `ext-redis`-backed queue with delayed jobs and priorities.
- `Azera\Queue\Backend\AmqpQueue` — RabbitMQ-backed queue.
- `Azera\Queue\Worker\Worker` — long-running pop → deserialize → `handle()` → ack loop honoring `tries()`, `backoff()`, `retryUntil()`.
- `Azera\Queue\Task\QueueTask` — `azera queue:work|failed|retry|flush` CLI task.
- `Azera\Queue\FailedJob\*` — persist permanently failed jobs for replay.
- `Azera\Queue\Dispatch\Dispatcher` — fluent dispatch (see above).
- `Azera\Queue\Event\*` — PSR-14 `JobPushed`, `JobReserved`, `JobProcessed`, `JobFailed`, `JobRetried`, `JobReleased`.

The `SyncQueue` is always available from the core framework.

## Deduplication

If a job implements `id()` (returns a non-null key), the `DatabaseQueue` stores that key in a unique `dedup_key` column. Pushing a job with an id that is already queued is idempotent — the backend returns the existing row's id instead of inserting a duplicate. Jobs that return `null` from `id()` are never deduplicated.