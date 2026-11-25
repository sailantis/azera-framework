<?php

namespace Azera\Tests\Event;

use PHPUnit\Framework\TestCase;
use Azera\Event\NullEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

class NullEventDispatcherTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(EventDispatcherInterface::class, new NullEventDispatcher());
    }

    public function testDispatchReturnsEventUnchanged(): void
    {
        $dispatcher   = new NullEventDispatcher();
        $event        = new \stdClass();
        $event->value = 42;

        $result = $dispatcher->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertSame(42, $result->value);
    }
}
