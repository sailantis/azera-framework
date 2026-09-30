# Class: NullLogger

**Full name:** [Azera\Log\NullLogger](../../src/Log/NullLogger.php)

No-op logger that discards every message.

This is the default logger returned by [`AppContext::logger()`](AppContext.md#logger)
when no concrete logger has been registered. It exists so that calling
code can safely invoke `$ctx->logger()->info(...)` without null-checks
or errors, paying only the cost of a single method call that does
nothing.

Implements the PSR-3 `LoggerInterface`, so it is interchangeable
with any PSR-3 logger (e.g. Monolog). Register a real logger via
`AppContext::set(LoggerInterface::class, $logger)`.

## Public methods

### emergency() · <small>[🗎](../../src/Log/NullLogger.php#L22)</small>

`public function emergency(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### alert() · <small>[🗎](../../src/Log/NullLogger.php#L23)</small>

`public function alert(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### critical() · <small>[🗎](../../src/Log/NullLogger.php#L24)</small>

`public function critical(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### error() · <small>[🗎](../../src/Log/NullLogger.php#L25)</small>

`public function error(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### warning() · <small>[🗎](../../src/Log/NullLogger.php#L26)</small>

`public function warning(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### notice() · <small>[🗎](../../src/Log/NullLogger.php#L27)</small>

`public function notice(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### info() · <small>[🗎](../../src/Log/NullLogger.php#L28)</small>

`public function info(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### debug() · <small>[🗎](../../src/Log/NullLogger.php#L29)</small>

`public function debug(Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`


---

### log() · <small>[🗎](../../src/Log/NullLogger.php#L30)</small>

`public function log(mixed $level, Stringable|string $message, array $context = []): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$level` | mixed | - |  |
| `$message` | Stringable\|string | - |  |
| `$context` | array | `[]` |  |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
