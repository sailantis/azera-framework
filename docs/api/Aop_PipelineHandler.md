# Class: PipelineHandler

**Full name:** [Azera\Aop\PipelineHandler](../../src/Aop/PipelineHandler.php)

Internal stand-in target for [`Pipeline`](Aop_Pipeline.md).

The explicit pipeline wraps plain callables, which have no target method for
interceptors to introspect, so it reflects this class instead. It must be a
**named** class: an anonymous class reports a name that embeds the declaring
file's path — `Pipeline.php:150$5` on Windows, but the full absolute path on
Linux, where `getShortName()` has no backslash to split on. That path length
leaks into cache keys and log context and is platform-dependent.

Because every pipeline call shares this one class, `getDeclaringClass()` plus
the method name is identical for every wrapped callable — which is how two
unrelated callables could key onto a single cache entry. The optional
`$identity` carries the real callable's name (file:line for a closure,
`Class::method` otherwise) so interceptors can tell them apart.

## Public methods

### __construct() · <small>[🗎](../../src/Aop/PipelineHandler.php#L27)</small>

`public function __construct(?string $identity = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$identity` | `?string` | `null` |  |

**Return value**

- Type: `mixed`


---

### identity() · <small>[🗎](../../src/Aop/PipelineHandler.php#L35)</small>

`public function identity(): ?string`

Stable identifier of the wrapped callable, or null when the pipeline
could not derive one.

**Return value**

- Type: `?string`


---

### __invoke() · <small>[🗎](../../src/Aop/PipelineHandler.php#L40)</small>

`public function __invoke(): mixed`

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
