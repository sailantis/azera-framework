<?php

namespace Azera\Tests\Event;

use PHPUnit\Framework\TestCase;
use Azera\AppContext;
use Azera\Event\EventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

class EventDispatcherTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(EventDispatcherInterface::class, new EventDispatcher());
    }

    public function testDispatchInvokesCallableListener(): void
    {
        $dispatcher = new EventDispatcher();
        $received   = [];

        $dispatcher->listen(\stdClass::class, function (object $e) use (&$received) {
            $received[] = $e;
        });

        $event = new \stdClass();
        $dispatcher->dispatch($event);

        $this->assertCount(1, $received);
        $this->assertSame($event, $received[0]);
    }

    public function testDispatchReturnsEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $event      = new \stdClass();

        $result = $dispatcher->dispatch($event);

        $this->assertSame($event, $result);
    }

    public function testMultipleListenersRunInOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $order      = [];

        $dispatcher->listen(\stdClass::class, function () use (&$order) {
            $order[] = 'first';
        });
        $dispatcher->listen(\stdClass::class, function () use (&$order) {
            $order[] = 'second';
        });
        $dispatcher->listen(\stdClass::class, function () use (&$order) {
            $order[] = 'third';
        });

        $dispatcher->dispatch(new \stdClass());

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function testHigherPriorityRunsFirst(): void
    {
        $dispatcher = new EventDispatcher();
        $order      = [];

        $dispatcher->listen(\stdClass::class, function () use (&$order) {
            $order[] = 'low';
        }, priority: 0);
        $dispatcher->listen(\stdClass::class, function () use (&$order) {
            $order[] = 'high';
        }, priority: 10);
        $dispatcher->listen(\stdClass::class, function () use (&$order) {
            $order[] = 'mid';
        }, priority: 5);

        $dispatcher->dispatch(new \stdClass());

        $this->assertSame(['high', 'mid', 'low'], $order);
    }

    public function testStoppableEventHaltsPropagation(): void
    {
        $dispatcher = new EventDispatcher();
        $called     = [];

        $dispatcher->listen(StoppableEvent::class, function () use (&$called) {
            $called[] = 'first';
        });
        $dispatcher->listen(StoppableEvent::class, function () use (&$called) {
            $called[] = 'second';
        });

        $event = new StoppableEvent(stopAfter: 1);
        $dispatcher->dispatch($event);

        $this->assertSame(['first'], $called);
    }

    public function testListenersForParentClassFire(): void
    {
        $dispatcher = new EventDispatcher();
        $called     = false;

        $dispatcher->listen(ParentEvent::class, function () use (&$called) {
            $called = true;
        });

        $dispatcher->dispatch(new ChildEvent());

        $this->assertTrue($called);
    }

    public function testListenersForInterfaceFire(): void
    {
        $dispatcher = new EventDispatcher();
        $called     = false;

        $dispatcher->listen(EventMarker::class, function () use (&$called) {
            $called = true;
        });

        $dispatcher->dispatch(new MarkedEvent());

        $this->assertTrue($called);
    }

    public function testNoListenersReturnsEventUnchanged(): void
    {
        $dispatcher = new EventDispatcher();
        $event      = new \stdClass();

        $result = $dispatcher->dispatch($event);

        $this->assertSame($event, $result);
    }

    public function testListenerAddedAfterDispatchIsPickedUp(): void
    {
        $dispatcher = new EventDispatcher();
        $called     = [];

        $dispatcher->listen(ParentEvent::class, function () use (&$called) {
            $called[] = 'parent';
        });

        $dispatcher->dispatch(new ChildEvent());
        $this->assertSame(['parent'], $called);

        // Adding a listener for the child must invalidate the cached list built
        // for ChildEvent, which previously walked up to ParentEvent only.
        $dispatcher->listen(ChildEvent::class, function () use (&$called) {
            $called[] = 'child';
        });

        $dispatcher->dispatch(new ChildEvent());
        // Own-class listeners are collected before parent listeners.
        $this->assertSame(['parent', 'child', 'parent'], $called);
    }

    public function testInterfaceListenerAddedAfterDispatchIsPickedUp(): void
    {
        $dispatcher = new EventDispatcher();
        $called     = [];

        $dispatcher->listen(MarkedEvent::class, function () use (&$called) {
            $called[] = 'class';
        });

        $dispatcher->dispatch(new MarkedEvent());

        $dispatcher->listen(EventMarker::class, function () use (&$called) {
            $called[] = 'interface';
        });

        $dispatcher->dispatch(new MarkedEvent());

        $this->assertSame(['class', 'class', 'interface'], $called);
    }

    public function testDispatchedListenerOrderIsStableAcrossCachedCalls(): void
    {
        $dispatcher = new EventDispatcher();
        $order      = [];

        $dispatcher->listen(MarkerSecond::class, function () use (&$order) {
            $order[] = 'second';
        });
        $dispatcher->listen(MarkerFirst::class, function () use (&$order) {
            $order[] = 'first';
        }, priority: 5);
        $dispatcher->listen(BothMarkersEvent::class, function () use (&$order) {
            $order[] = 'own';
        }, priority: 5);

        $dispatcher->dispatch(new BothMarkersEvent());
        $first = $order;

        $dispatcher->dispatch(new BothMarkersEvent());

        $this->assertSame($first, array_slice($order, 0, count($first)));
        $this->assertSame(['own', 'first', 'second'], $first);
    }

    public function testClassStringListenerIsResolvedOnce(): void
    {
        $previous           = AppContext::instance();
        AppContext::setInstance(new AppContext());
        CountingListener::$instances = 0;

        try {
            $dispatcher = new EventDispatcher();
            CountingListener::$calls = 0;

            $dispatcher->listen(ChildEvent::class, CountingListener::class);
            $dispatcher->dispatch(new ChildEvent());
            $dispatcher->dispatch(new ChildEvent());

            $this->assertSame(1, CountingListener::$instances);
            $this->assertSame(2, CountingListener::$calls);
        } finally {
            AppContext::setInstance($previous);
        }
    }

    public function testUnreachedClassStringListenerIsNotInstantiated(): void
    {
        $previous = AppContext::instance();
        AppContext::setInstance(new AppContext());
        CountingListener::$instances = 0;

        try {
            $dispatcher = new EventDispatcher();

            $dispatcher->listen(StoppableEvent::class, function () {}, priority: 10);
            $dispatcher->listen(StoppableEvent::class, CountingListener::class, priority: 0);

            // The first listener stops propagation, so the class-string listener
            // is never reached and must not be instantiated.
            $dispatcher->dispatch(new StoppableEvent(stopAfter: 1));

            $this->assertSame(0, CountingListener::$instances);
        } finally {
            AppContext::setInstance($previous);
        }
    }
}

// --- Test fixtures ---

class StoppableEvent implements StoppableEventInterface
{
    private int $callCount = 0;
    public function __construct(private int $stopAfter = 1) {}
    public function isPropagationStopped(): bool
    {
        return ++$this->callCount >= $this->stopAfter;
    }
}

class ParentEvent
{
}

class ChildEvent extends ParentEvent
{
}

interface EventMarker
{
}

class MarkedEvent implements EventMarker
{
}

interface MarkerFirst
{
}

interface MarkerSecond
{
}

class BothMarkersEvent implements MarkerFirst, MarkerSecond
{
}

class CountingListener
{
    public static int $instances = 0;
    public static int $calls     = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public function __invoke(object $event): void
    {
        self::$calls++;
    }
}
