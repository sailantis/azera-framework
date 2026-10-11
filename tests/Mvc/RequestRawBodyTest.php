<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * `setRawBody()` is what makes the framework usable from an application-server
 * worker.
 *
 * In a worker the request does not arrive through the SAPI: stdin carries the
 * server's own protocol frames, so `body()`'s fallback to `php://input` would
 * read protocol bytes and desynchronise the stream. These tests pin the two
 * properties a worker depends on — the injected body is what `body()` returns,
 * and an injected *empty* body still counts as injected.
 */
final class RequestRawBodyTest extends TestCase
{
    public function testBodyReturnsTheInjectedBody(): void
    {
        $request = (new Request([], [], [], []))->setRawBody('{"a":1}');

        $this->assertSame('{"a":1}', $request->body());
    }

    public function testJsonBodyParsesTheInjectedBody(): void
    {
        $request = (new Request([], [], [], []))->setRawBody('{"a":1,"b":[2,3]}');

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $request->jsonBody());
    }

    /**
     * The subtle one. `body()` falls back to `php://input` only when the value
     * is null, so an empty body must be stored rather than skipped — otherwise a
     * bodyless GET in a worker is precisely the request that reads the protocol
     * pipe. Asserting on the property is the only way to observe that from CLI,
     * where `php://input` is always empty and the read would be invisible.
     */
    public function testAnEmptyInjectedBodyIsNotTreatedAsUnset(): void
    {
        $request = (new Request([], [], [], []))->setRawBody('');

        $property = new \ReflectionProperty(Request::class, 'rawBody');
        $property->setAccessible(true);

        $this->assertSame('', $property->getValue($request));
        $this->assertSame('', $request->body());
    }

    public function testSetRawBodyIsChainable(): void
    {
        $request = new Request([], [], [], []);

        $this->assertSame($request, $request->setRawBody('x'));
    }
}
