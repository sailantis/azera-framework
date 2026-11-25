# Class: LogInterceptor

**Full name:** [Azera\Aop\LogInterceptor](../../src/Aop/LogInterceptor.php)

Logs entry, exit, duration and failure of methods marked with [`Log`](Aop_Log.md).

In explicit (pipeline) calls there is no attribute, so the constructor defaults apply:
`defaultLevel` (`info`) and `logArgs` (`false`).

## Public methods

### __construct() · <small>[🗎](../../src/Aop/LogInterceptor.php#L16)</small>

`public function __construct(Psr\Log\LoggerInterface $logger, string $defaultLevel = 'info', bool $logArgs = false): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$logger` | Psr\Log\LoggerInterface | - |  |
| `$defaultLevel` | string | `'info'` |  |
| `$logArgs` | bool | `false` |  |

**Return value**

- Type: `mixed`


---

### intercept() · <small>[🗎](../../src/Aop/LogInterceptor.php#L22)</small>

`public function intercept(Azera\Aop\Invocation $invocation, callable $next): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$invocation` | [Invocation](Aop_Invocation.md) | - |  |
| `$next` | callable | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
