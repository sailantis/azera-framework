<?php

namespace Azera\Aop;

use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Intercepts methods marked with {@see Log} and logs their execution.
 *
 * Logs method entry (with optional arguments), exit (with duration),
 * and any exceptions thrown.
 */
class LogInterceptor implements InterceptorInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed
    {
        $advice     = $this->getAdvice($method);
        $level      = $advice->level;
        $methodName = $method->getName();
        $className  = self::declaringName($target, $method);

        $context = ['method' => "{$className}::{$methodName}"];

        if ($advice->logArgs) {
            $context['args'] = $args;
        }

        $this->logger->log($level, "Entering {$className}::{$methodName}", $context);
        $start = microtime(true);

        try {
            $result   = $next($args);
            $duration = (microtime(true) - $start) * 1000.0;
            $this->logger->log($level, "Completed {$className}::{$methodName}", $context + ['duration_ms' => round($duration, 2)]);
            return $result;
        } catch (Throwable $e) {
            $duration = (microtime(true) - $start) * 1000.0;
            $this->logger->error(
                "Failed {$className}::{$methodName}",
                $context + ['duration_ms' => round($duration, 2), 'exception' => $e->getMessage()]
            );
            throw $e;
        }
    }

    private function getAdvice(ReflectionMethod $method): Log
    {
        $attrs = $method->getAttributes(Log::class);
        if ($attrs === []) {
            return new Log();
        }
        return $attrs[0]->newInstance();
    }

    /**
     * Name to log for the invoked unit.
     *
     * In the explicit pipeline every call reflects one shared dummy class, so
     * the declaring name would log `PipelineHandler::__invoke` for unrelated
     * callables. {@see PipelineHandler} carries the real identity; use it.
     *
     * The identity for a closure is `file:line`, and a full path is correct for
     * a cache key but unreadable in a log line — trim it to the file name.
     */
    private static function declaringName(object $target, ReflectionMethod $method): string
    {
        if ($target instanceof PipelineHandler) {
            $identity = $target->identity();
            if ($identity !== null) {
                // Closure identities are `file:line`; keep only the file name.
                $parts = preg_split('#[\\\\/]#', $identity);
                $tail  = $parts === false ? $identity : end($parts);

                return $tail === false || $tail === '' ? $identity : $tail;
            }
        }

        return $method->getDeclaringClass()->getShortName();
    }
}