# Class: SyncQueue

**Full name:** [Azera\Queue\SyncQueue](../../src/Queue/SyncQueue.php)

A queue that processes jobs synchronously, immediately in the current
process.

This is the default fallback when no async queue backend is
registered. It allows application code to be written against
[`QueueInterface`](Queue_QueueInterface.md) from day one — switching to Redis/AMQP later
is a one-line bootstrap change with no code rewrite.

SyncQueue is also the correct choice in tests and in single-request
PHP-FPM deployments where no background worker is available.

## Public methods

### push() · <small>[🗎](../../src/Queue/SyncQueue.php#L24)</small>

`public function push(Azera\Queue\JobInterface $job, array $options = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$job` | [JobInterface](Queue_JobInterface.md) | - |  |
| `$options` | array | `[]` |  |

**Return value**

- Type: `mixed`


---

### registerWorker() · <small>[🗎](../../src/Queue/SyncQueue.php#L48)</small>

`public function registerWorker(string $jobClass, callable|null $handler = null): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$jobClass` | string | - |  |
| `$handler` | callable\|null | `null` |  |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
