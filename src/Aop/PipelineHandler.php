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
 * Because every pipeline call shares this one class, `getDeclaringClass()` plus
 * the method name is identical for every wrapped callable — which is how two
 * unrelated callables could key onto a single cache entry. The optional
 * `$identity` carries the real callable's name (file:line for a closure,
 * `Class::method` otherwise) so interceptors can tell them apart.
 *
 * @internal
 */
final class PipelineHandler
{
    public function __construct(
        private readonly ?string $identity = null,
    ) {}

    /**
     * Stable identifier of the wrapped callable, or null when the pipeline
     * could not derive one.
     */
    public function identity(): ?string
    {
        return $this->identity;
    }

    public function __invoke(): mixed
    {
        return null;
    }
}
