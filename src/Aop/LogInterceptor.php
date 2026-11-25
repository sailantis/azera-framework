<?php

namespace Azera\Aop;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs entry, exit, duration and failure of methods marked with {@see Log}.
 *
 * In explicit (pipeline) calls there is no attribute, so the constructor defaults apply:
 * `defaultLevel` (`info`) and `logArgs` (`false`).
 */
class LogInterceptor implements InterceptorInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $defaultLevel = 'info',
        private bool $logArgs = false,
    ) {}

    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $advice  = $invocation->advice(Log::class);
        $level   = $advice?->level ?? $this->defaultLevel;
        $logArgs = $advice?->logArgs ?? $this->logArgs;

        $name    = $this->nameFor($invocation);
        $context = ['method' => $name];

        if ($logArgs) {
            $context['args'] = $this->sanitize($invocation->args);
        }

        $this->logger->log($level, "Entering {$name}", $context);
        $start = hrtime(true);

        try {
            $result   = $next($invocation);
            $duration = (hrtime(true) - $start) / 1e6;
            $this->logger->log($level, "Completed {$name}", $context + ['duration_ms' => round($duration, 2)]);
            return $result;
        } catch (Throwable $e) {
            $duration = (hrtime(true) - $start) / 1e6;
            $this->logger->error(
                "Failed {$name}",
                $context + ['duration_ms' => round($duration, 2), 'exception' => $e->getMessage()],
            );
            throw $e;
        }
    }

    /** Closure identities are `path/to/file.php:12`; keep that readable in a log line. */
    private function nameFor(Invocation $invocation): string
    {
        $identity = $invocation->identity;

        if ($invocation->method !== null) {
            return $identity;
        }

        $pos = strrpos($identity, DIRECTORY_SEPARATOR);
        if ($pos === false) {
            $pos = strrpos($identity, '/');
        }

        return $pos === false ? $identity : substr($identity, $pos + 1);
    }

    /** Replace non-scalar values with a marker so logs stay serializable. */
    private function sanitize(array $args): array
    {
        $out = [];
        foreach ($args as $key => $value) {
            $out[$key] = is_scalar($value) || $value === null ? $value : 'object(' . get_debug_type($value) . ')';
        }

        return $out;
    }
}
