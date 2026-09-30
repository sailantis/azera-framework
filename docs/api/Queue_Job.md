# Class: Job

**Full name:** [Azera\Queue\Job](../../src/Queue/Job.php)

Base implementation of [`JobInterface`](Queue_JobInterface.md) with sensible defaults.

Extend this class to create a job without boilerplate:

```php
class SendWelcomeEmailJob extends Job
{
    public function __construct(private string $email) }

    public function handle(): void
    {
        Mailer::send($this->email, 'Welcome!');
    }
}
```

## Public methods

### tries() · <small>[🗎](../../src/Queue/Job.php#L24)</small>

`public function tries(): int`

**Return value**

- Type: `int`


---

### backoff() · <small>[🗎](../../src/Queue/Job.php#L29)</small>

`public function backoff(): array|int`

**Return value**

- Type: `array`|`int`


---

### retryUntil() · <small>[🗎](../../src/Queue/Job.php#L34)</small>

`public function retryUntil(): int|null`

**Return value**

- Type: `int`|`null`


---

### failed() · <small>[🗎](../../src/Queue/Job.php#L39)</small>

`public function failed(Throwable $exception): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$exception` | Throwable | - |  |

**Return value**

- Type: `void`


---

### id() · <small>[🗎](../../src/Queue/Job.php#L44)</small>

`public function id(): string|null`

**Return value**

- Type: `string`|`null`


---

### queue() · <small>[🗎](../../src/Queue/Job.php#L49)</small>

`public function queue(): string`

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
