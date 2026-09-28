<?php

declare(strict_types=1);

namespace Azera\Aop;

/**
 * Internal stand-in target for {@see Pipeline}.
 *
 * The explicit pipeline wraps plain callables, which have no target method for
 * interceptors to introspect, so it reflects this class instead. It must be a
 * **named** class: an anonymous class reports a name that embeds the declaring
 * file's path — `Pipeline.php:150$5` on Windows, but the full absolute path on
 * Linux, where `getShortName()` has no backslash to split on. That path length
 * leaks into cache keys and log context and is platform-dependent.
 *
 * @internal
 */
final class PipelineHandler
{
    public function __invoke(): mixed
    {
        return null;
    }
}
