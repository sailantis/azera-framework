<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * `header()` and the two headers CGI does not prefix.
 *
 * Almost every header reaches the server array as `HTTP_*`, but the CGI
 * specification carves out two names: `Content-Type` becomes `CONTENT_TYPE` and
 * `Content-Length` becomes `CONTENT_LENGTH`, both unprefixed. A lookup that only
 * ever builds `HTTP_*` therefore cannot find them.
 *
 * This is not a theoretical distinction. `php-cgi` — and the CGI environment the
 * volucer server builds — sends `CONTENT_TYPE` and never `HTTP_CONTENT_TYPE`,
 * so `header('Content-Type')` used to return null on every real request while
 * `contentType()` and `isJson()`, which read `CONTENT_TYPE` directly, disagreed
 * with it.
 */
final class RequestHeadersTest extends TestCase
{
    public function testContentTypeIsFoundUnderItsCgiName(): void
    {
        $request = new Request(['CONTENT_TYPE' => 'application/json']);

        $this->assertSame('application/json', $request->header('Content-Type'));

        // Names are case-insensitive as HTTP requires, whichever convention the
        // caller uses.
        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertSame('application/json', $request->header('CONTENT-TYPE'));
    }

    public function testContentLengthIsFoundUnderItsCgiName(): void
    {
        $request = new Request(['CONTENT_LENGTH' => 7]);

        $this->assertSame('7', $request->header('Content-Length'));
    }

    /**
     * The name-to-key map is static, so it outlives the request that filled it. A
     * cached key must not change the answer for a *different* request: the key is a
     * function of the name alone, and the value still comes from this request's own
     * server array.
     *
     * This is the failure the cache could cause — one request's header answered from
     * another's array — and it is worth a test because the map is process-wide.
     */
    public function testACachedHeaderKeyDoesNotLeakAnotherRequestsValue(): void
    {
        $first  = new Request(['HTTP_X_TRACE' => 'from-first']);
        $second = new Request(['HTTP_X_TRACE' => 'from-second']);

        $this->assertSame('from-first', $first->header('X-Trace'), 'fills the key cache');
        $this->assertSame('from-second', $second->header('X-Trace'), 'must read its own array');
        $this->assertSame('from-first', $first->header('X-Trace'), 'and not be overwritten');
    }

    /** A header that is absent in a later request stays absent after the key is cached. */
    public function testACachedHeaderKeyStillReturnsTheDefaultWhenAbsent(): void
    {
        $with    = new Request(['HTTP_X_OPTIONAL' => 'present']);
        $without = new Request([]);

        $this->assertSame('present', $with->header('X-Optional'));
        $this->assertNull($without->header('X-Optional'));
        $this->assertSame('fallback', $without->header('X-Optional', 'fallback'));
    }

    /**
     * The prefixed spelling is deliberately not consulted, because no SAPI
     * produces it. Accepting it as a fallback would hide exactly the mistake
     * that made this a bug: a server building the environment wrongly would
     * still appear to work.
     */
    public function testThePrefixedSpellingIsNotAContentType(): void
    {
        $request = new Request(['HTTP_CONTENT_TYPE' => 'application/json']);

        $this->assertNull($request->header('Content-Type'));
        $this->assertSame('fallback', $request->header('Content-Type', 'fallback'));
    }

    public function testOrdinaryHeadersStillComeFromThePrefixedName(): void
    {
        $request = new Request([
            'HTTP_X_REQUEST_ID' => 'abc-123',
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $this->assertSame('abc-123', $request->header('X-Request-Id'));
        $this->assertSame('text/html', $request->header('Accept'));
        $this->assertNull($request->header('X-Missing'));
    }

    /**
     * `header()` and the accessors that read `CONTENT_TYPE` directly must agree,
     * since they are two views of one request.
     */
    public function testTheContentTypeAgreesWithTheDedicatedAccessors(): void
    {
        $request = new Request(['CONTENT_TYPE' => 'application/json; charset=utf-8']);

        $this->assertSame($request->contentType(), $request->header('Content-Type'));
        $this->assertTrue($request->isJson());
    }

    public function testAnAbsentContentTypeIsNullRatherThanEmpty(): void
    {
        $request = new Request([]);

        $this->assertNull($request->header('Content-Type'));
        $this->assertSame('', $request->contentType());
        $this->assertFalse($request->isJson());
    }
}
