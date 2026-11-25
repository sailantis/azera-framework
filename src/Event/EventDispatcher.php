<?php

namespace Azera\Event;

use Azera\AppContext;
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
     * Map of event class => listener entries.
     * Each listener entry is ['handler' => callable|string, 'priority' => int].
     * Entries are appended in registration order; ordering by priority happens
     * once when building the per-event dispatch list.
     *
     * @var array<string, list<array{handler: callable|string, priority: int}>>
     */
    private array $listeners = [];

    /**
     * Map of event class => ordered listener entries for that event, including
     * listeners registered for its parents and interfaces. Cleared on every
     * {@see listen()} because a new listener can affect any event class.
     *
     * @var array<string, list<array{handler: callable|string, priority: int}>>
     */
    private array $ordered = [];

    /**
     * Lazily-resolved listener cache for class-string handlers.
     *
     * @var array<string, callable>
     */
    private array $resolvedListeners = [];

    /**
     * Maximum number of event classes kept in the dispatch cache, so dispatching
     * throw-away event classes (e.g. anonymous classes) cannot grow it unbounded.
     */
    private const CACHE_LIMIT = 1024;

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

        // A new listener may apply to any cached event class through inheritance,
        // so drop every cached dispatch list. Resolved handler instances survive.
        $this->ordered = [];
    }

    public function dispatch(object $event): object
    {
        foreach ($this->resolveListeners($event::class) as $entry) {
            $handler = $entry['handler'];

            // Class-string handlers are resolved (and cached) on first use, so a
            // stoppable event never instantiates listeners it will not reach.
            if (is_string($handler)) {
                $handler = $this->resolveHandler($handler);
            }

            $handler($event);

            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }

    /**
     * Build the ordered listener list for an event class, considering parent
     * classes and implemented interfaces. The list is cached until the next
     * {@see listen()} call.
     *
     * @return list<array{handler: callable|string, priority: int}>
     */
    private function resolveListeners(string $eventClass): array
    {
        if ($this->listeners === []) {
            return [];
        }

        if (isset($this->ordered[$eventClass])) {
            return $this->ordered[$eventClass];
        }

        // Candidate event types: the class itself, then parents, then interfaces.
        $collected = $this->listeners[$eventClass] ?? [];

        foreach (class_parents($eventClass, false) ?: [] as $parent) {
            if (isset($this->listeners[$parent])) {
                foreach ($this->listeners[$parent] as $entry) {
                    $collected[] = $entry;
                }
            }
        }

        foreach (class_implements($eventClass, false) ?: [] as $interface) {
            if (isset($this->listeners[$interface])) {
                foreach ($this->listeners[$interface] as $entry) {
                    $collected[] = $entry;
                }
            }
        }

        // Higher priority first; PHP's sort is stable, so equal priorities keep
        // their collection order (own listeners, then parents, then interfaces,
        // each in registration order).
        usort($collected, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        if (count($this->ordered) >= self::CACHE_LIMIT) {
            return $collected;
        }

        return $this->ordered[$eventClass] = $collected;
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
            return $this->resolvedListeners[$handler] = $handler;
        }

        // Class-string handler â€” resolve via AppContext.
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

        return $this->resolvedListeners[$handler] = $instance;
    }
}
