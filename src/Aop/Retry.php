<?php

namespace Azera\Aop;

/**
 * Marks a method as retryable.
 *
 * The {@see RetryInterceptor} retries the method on failure (Throwable)
 * up to the specified number of times, with optional backoff between
 * attempts.
 *
 * Example:
 * ```php
 * #[Advised]
 * class ApiService
 * {
 *     #[Retry(times: 3, backoff: 100)]
 *     public function callExternalApi(): Response { ... }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Retry extends Advice
{
    public function __construct(
        public readonly int $times = 3,
        public readonly int $backoff = 0,
    ) {}
}