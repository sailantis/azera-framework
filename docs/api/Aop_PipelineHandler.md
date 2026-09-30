# Class: PipelineHandler

**Full name:** [Azera\Aop\PipelineHandler](../../src/Aop/PipelineHandler.php)

Internal stand-in target for [`Pipeline`](Aop_Pipeline.md).

The explicit pipeline wraps plain callables, which have no target method for
interceptors to introspect, so it reflects this class instead. It must be a
**named** class: an anonymous class reports a name that embeds the declaring
file's path — `Pipeline.php:150$5` on Windows, but the full absolute path on
Linux, where `getShortName()` has no backslash to split on. That path length
leaks into cache keys and log context and is platform-dependent.

## Public methods

### __invoke() · <small>[🗎](../../src/Aop/PipelineHandler.php#L21)</small>

`public function __invoke(): mixed`

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
