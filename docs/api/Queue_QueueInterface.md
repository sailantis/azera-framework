# Interface: QueueInterface

**Full name:** [Azera\Queue\QueueInterface](../../src/Queue/QueueInterface.php)

Interface for queue backends.

Implementations push jobs onto a backend (Redis, AMQP, DB, in-process)
and provide the mechanics for a worker to pop and acknowledge them.

The core ships with [`SyncQueue`](Queue_SyncQueue.md), which processes jobs immediately
in the current process. Real async backends live in the
`sailantis/azera-queue` companion package.

## Public methods

### push() · <small>[🗎](../../src/Queue/QueueInterface.php#L27)</small>

`public function push(Azera\Queue\JobInterface $job, array $options = []): mixed`

Push a job onto the queue.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$job` | [JobInterface](Queue_JobInterface.md) | - | The job to enqueue. |
| `$options` | array | `[]` | Backend-specific options<br>(delay, priority, queue override, etc.). |

**Return value**

- Type: `mixed`
- Description: The job identifier or result. For [`SyncQueue`](Queue_SyncQueue.md)<br>this is null (job ran synchronously).

**Throws**

- [QueueException](Queue_QueueException.md)  On failure to enqueue.


---

### registerWorker() · <small>[🗎](../../src/Queue/QueueInterface.php#L39)</small>

`public function registerWorker(string $jobClass, callable|null $handler = null): void`

Register a worker callback for a specific job class.

For async backends, this is used by the worker process to map
serialized jobs back to their handlers. For [`SyncQueue`](Queue_SyncQueue.md),
this is a no-op (jobs handle themselves).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$jobClass` | string | - | The job class to register. |
| `$handler` | callable\|null | `null` | Optional explicit handler. |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
