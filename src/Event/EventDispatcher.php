<?php

namespace Azera\Event;

use Azera\AppContext;
use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use RuntimeException;

/**
 * Default implementation of an event dispatcher.
 *
 * Listeners are registered with {@see listen()} and resolved on dispatch.
 * A listener may be a callable (closure, invokable object, `[$obj, 'method']`)
 * or a class-string that is resolved through {@see AppContext} (allowing
 * DI for listener constructors). Class-string listeners must implement
 * `__invoke(object $event): void`.
 *
 * Priority: higher priority numbers run first (default 0). Listeners with
 * the same priority run in registration order.
 *
 * Example:
 * ```php
 * $dispatcher = new EventDispatcher();
 * $dispatcher->listen(UserCreated::class, function (UserCreated $e) {
 *     // send welcome email
 * });
 * $dispatcher->listen(UserCreated::class, SendWelcomeEmailListener::class, priority: 10);
 * $dispatcher->dispatch(new UserCreated($user));
 * ```
 */
class EventDispatcher implements EventDispatcherInterface
{
    /**
     * Map of event class => sorted listener array.
     * Each listener entry is ['handler' => callable|string, 'priority' => int].
     *
     * @var array<string, array{handler: callable|string, priority: int}>
     */
    private array $listeners = [];

    /**
     * Map of event class => boolean indicating the listener list needs
     * re-sorting by priority before the next dispatch.
     *
     * @var array<string, bool>
     */
    private array $dirty = [];

    /**
     * Lazily-resolved listener cache for class-string handlers.
     *
     * @var array<string, callable>
     */
    private array $resolvedListeners = [];

    public function __construct(
        private ?AppContext $context = null,
    ) {}

    /**
     * Register a listener for an event class.
     *
     * @param string          $eventClass Fully-qualified class name of the event.
     * @param callable|string $handler     A callable, or a class-string resolved
     *   via AppContext (must implement __invoke).
     * @param int             $priority    Higher runs first (default 0).
     * @return void
     */
    public function listen(string $eventClass, callable|string $handler, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = ['handler' => $handler, 'priority' => $priority];
        $this->dirty[$eventClass]       = true;
    }

    public function dispatch(object $event): object
    {
        $eventClass = $event::class;

        foreach ($this->resolveListeners($eventClass) as $listener) {
            $listener($event);

            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }

    /**
     * Resolve all listeners applicable to the given event class,
     * considering parent classes and implemented interfaces.
     *
     * @return iterable<callable>
     */
    private function resolveListeners(string $eventClass): iterable
    {
        // Collect candidate event types: the class itself, parents, interfaces.
        $types = [$eventClass];
        $types = array_merge($types, class_parents($eventClass, false) ?: []);
        $types = array_merge($types, class_implements($eventClass, false) ?: []);

        $collected = [];

        foreach ($types as $type) {
            if (!isset($this->listeners[$type])) {
                continue;
            }

            if ($this->dirty[$type] ?? false) {
                usort(
                    $this->listeners[$type],
                    static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']
                );
                $this->dirty[$type] = false;
            }

            foreach ($this->listeners[$type] as $entry) {
                $collected[] = $entry;
            }
        }

        // Sort the merged collection by priority (stable for same priority).
        usort($collected, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        foreach ($collected as $entry) {
            yield $this->resolveHandler($entry['handler']);
        }
    }

    /**
     * Resolve a handler to a callable.
     *
     * Class-string handlers are resolved through AppContext (if available)
     * and cached for reuse.
     */
    private function resolveHandler(callable|string $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        // Class-string handler — resolve via AppContext.
        if (isset($this->resolvedListeners[$handler])) {
            return $this->resolvedListeners[$handler];
        }

        if ($this->context === null) {
            $this->context = AppContext::instance();
        }

        $instance = $this->context->get($handler);

        if (!is_callable($instance)) {
            throw new RuntimeException(
                "Listener '$handler' must implement __invoke()."
            );
        }

        return $this->resolvedListeners[$handler] = Closure::fromCallable($instance);
    }
}