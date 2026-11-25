<?php

namespace Azera\Aop;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retries methods marked with {@see Retry} on failure.
 *
 * `times` is the total number of attempts (including the first). Between attempts the
 * interceptor sleeps for `backoff` milliseconds. If every attempt fails, the last exception
 * is re-thrown.
 *
 * Explicit (pipeline) calls have no attribute; `defaultTimes` / `defaultBackoff` apply.
 */
class RetryInterceptor implements InterceptorInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null,
        private int $defaultTimes = 3,
        private int $defaultBackoff = 0,
    ) {}

    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $advice  = $invocation->advice(Retry::class);
        $times   = $advice?->times ?? $this->defaultTimes;
        $backoff = $advice?->backoff ?? $this->defaultBackoff;

        return $this->attempt($invocation, $next, max(1, $times), $backoff);
    }

    /**
     * @param callable(Invocation): mixed $next
     */
    private function attempt(Invocation $invocation, callable $next, int $times, int $backoffMs): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $times; $attempt++) {
            try {
                return $next($invocation);
            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt < $times) {
                    $this->logger?->debug(
                        "Retrying {$invocation->identity} (attempt {$attempt}/{$times})",
                        ['exception' => $e->getMessage(), 'backoff_ms' => $backoffMs],
                    );

                    if ($backoffMs > 0) {
                        usleep($backoffMs * 1000);
                    }
                }
            }
        }

        throw $lastException;
    }
}
