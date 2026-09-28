<?php

namespace Azera\Aop;

use Throwable;

/**
 * A simple interceptor pipeline that wraps a callable.
 *
 * This is the explicit, no-proxy alternative to AOP attributes. It lets
 * users compose interceptors around any callable without generating proxy
 * classes. The same {@see InterceptorInterface} implementations that work
 * with the proxy AOP also work here — just pass them as an array.
 *
 * The innermost handler calls the target callable. Each interceptor wraps
 * the next, exactly like HTTP middleware or the Dispatcher's middleware
 * pipeline.
 *
 * Example:
 * <code>
 * $result = $ctx->pipeline()
 *     ->through([new RetryInterceptor(3), new LogInterceptor($logger)])
 *     ->call(fn() => $service->chargeCard(100));
 * </code>
 *
 * Or the short form:
 * <code>
 * $result = Pipeline::wrap(
 *     [new RetryInterceptor(3), new LogInterceptor($logger)],
 *     fn() => $service->chargeCard(100),
 * );
 * </code>
 */
class Pipeline
{
    /** @var InterceptorInterface[] */
    private array $interceptors = [];

    /**
     * @param InterceptorInterface[] $interceptors
     */
    public function __construct(array $interceptors = [])
    {
        foreach ($interceptors as $interceptor) {
            if (!$interceptor instanceof InterceptorInterface) {
                throw new \InvalidArgumentException(
                    'All pipeline interceptors must implement InterceptorInterface.'
                );
            }
            $this->interceptors[] = $interceptor;
        }
    }

    /**
     * Add interceptors to the pipeline.
     *
     * @param InterceptorInterface[] $interceptors
     * @return $this
     */
    public function through(array $interceptors): static
    {
        foreach ($interceptors as $interceptor) {
            if (!$interceptor instanceof InterceptorInterface) {
                throw new \InvalidArgumentException(
                    'All pipeline interceptors must implement InterceptorInterface.'
                );
            }
            $this->interceptors[] = $interceptor;
        }
        return $this;
    }

    /**
     * Run the pipeline around a callable.
     *
     * Each interceptor wraps the next. The innermost call invokes `$callable`
     * with the provided arguments.
     *
     * @param callable $callable The target callable to execute.
     * @param array $args Arguments to pass to the callable.
     * @return mixed The callable's return value.
     * @throws Throwable If the callable (or any interceptor) throws.
     */
    public function call(callable $callable, array $args = []): mixed
    {
        // Build the interceptor chain. The innermost handler calls the callable.
        $handler = function (array $a) use ($callable) {
            return $callable(...$a);
        };

        // Wrap interceptors in reverse order (first registered = outermost).
        for ($i = count($this->interceptors) - 1; $i >= 0; $i--) {
            $interceptor = $this->interceptors[$i];
            $handler     = $this->wrapInterceptor($interceptor, $handler);
        }

        return $handler($args);
    }

    /**
     * Static shortcut: wrap a callable with interceptors and call it.
     *
     * @param InterceptorInterface[] $interceptors
     * @param callable $callable
     * @param array $args
     * @return mixed
     */
    public static function wrap(array $interceptors, callable $callable, array $args = []): mixed
    {
        return (new self($interceptors))->call($callable, $args);
    }

    /**
     * Wrap an interceptor around a handler, providing a fake ReflectionMethod
     * so interceptors that read advice attributes can still function.
     *
     * For the explicit pipeline, there is no target method with attributes.
     * Interceptors that need advice configuration (e.g., RetryInterceptor
     * reads #[Retry]) should use a default when no attribute is present.
     */
    private function wrapInterceptor(InterceptorInterface $interceptor, callable $handler): callable
    {
        return function (array $args) use ($interceptor, $handler): mixed {
            // Create a lightweight invocation context. Interceptors that
            // look for advice attributes will find none — they should
            // fall back to sensible defaults.
            $ref    = $this->createDummyReflection();
            $target = new PipelineHandler();

            return $interceptor->intercept($target, $ref, $args, $handler);
        };
    }

    /**
     * Create a dummy ReflectionMethod for interceptors that need one.
     *
     * The interceptors use it to read advice attributes. For the explicit
     * pipeline, there are no attributes — interceptors should handle this
     * gracefully by checking $attrs === [] and using defaults.
     *
     * Reflects {@see PipelineHandler} — a named class — rather than an
     * anonymous one. An anonymous class name embeds its declaring file path,
     * which is short on Windows ("Pipeline.php:150$5") but the full absolute
     * path on Linux (getShortName() has no backslash to split on). That leaked
     * platform-dependent path into cache keys and could push them past
     * {@see \Azera\Cache\ArrayCache}'s 64-character limit.
     */
    private function createDummyReflection(): \ReflectionMethod
    {
        return new \ReflectionMethod(PipelineHandler::class, '__invoke');
    }
}
