<?php

declare(strict_types=1);

namespace Azera\Tests\Http;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Request;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Conditional GET (validators, `304`) and file bodies on {@see Response}.
 *
 * Before this, `Response` could only carry a string body and plain headers, so a
 * rendered docs page had no way to say "you already have this" and a download had
 * to build `Content-Disposition` by hand. Every assertion here is a header the
 * client acts on, so a regression is a caching or download bug rather than a
 * cosmetic one.
 */
class ResponseConditionalTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }

        $this->tmpFiles = [];
        Response::xSendfile(false);
    }

    /** A request carrying the given headers (name => value). */
    private function request(string $method = 'GET', array $headers = []): Request
    {
        $server = ['REQUEST_METHOD' => $method];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request($server);
    }

    private function tempFile(string $contents, string $extension = 'txt'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'azera_resp_');

        if ($path === false) {
            self::fail('cannot create a temp file');
        }

        $target = $path . '.' . $extension;
        rename($path, $target);
        file_put_contents($target, $contents);
        $this->tmpFiles[] = $target;

        return $target;
    }

    // --- Validators ----------------------------------------------------------

    public function testEtagIsQuoted(): void
    {
        $response = (new Response())->setEtag('abc123');

        $this->assertSame('"abc123"', $response->getHeader('ETag'));
    }

    public function testAlreadyQuotedEtagIsKept(): void
    {
        $response = (new Response())->setEtag('"abc123"');

        $this->assertSame('"abc123"', $response->getHeader('ETag'));
    }

    public function testWeakEtagKeepsItsPrefix(): void
    {
        $response = (new Response())->setEtag('W/"abc123"');

        $this->assertSame('W/"abc123"', $response->getHeader('ETag'));
    }

    public function testLastModifiedIsAnHttpDate(): void
    {
        $response = (new Response())->setLastModified(1700000000);

        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->getHeader('Last-Modified'));
    }

    public function testLastModifiedAcceptsADateTimeAndConvertsToGmt(): void
    {
        $date     = new \DateTimeImmutable('2023-11-14 23:13:20', new \DateTimeZone('Europe/Berlin'));
        $response = (new Response())->setLastModified($date);

        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->getHeader('Last-Modified'));
    }

    public function testExpiresIsAnHttpDate(): void
    {
        $response = (new Response())->setExpires(1700000000);

        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->getHeader('Expires'));
    }

    // --- Cache-Control -------------------------------------------------------

    public function testSetCacheEmitsPrivateMaxAgeAndExpires(): void
    {
        $response = (new Response())->setCache(600);

        $this->assertSame('private, max-age=600', $response->getHeader('Cache-Control'));
        $this->assertNotNull($response->getHeader('Expires'));
    }

    public function testSetCacheSharedAndImmutable(): void
    {
        $response = (new Response())->setCache(31536000, shared: true, immutable: true);

        $this->assertSame(
            'public, max-age=31536000, immutable',
            $response->getHeader('Cache-Control')
        );
    }

    public function testSetCacheClampsNegativeSeconds(): void
    {
        $response = (new Response())->setCache(-5);

        $this->assertSame('private, max-age=0', $response->getHeader('Cache-Control'));
    }

    public function testSetNoCacheForbidsStoring(): void
    {
        $response = (new Response())->setNoCache();

        $this->assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->getHeader('Cache-Control')
        );
        $this->assertSame('no-cache', $response->getHeader('Pragma'));
        $this->assertSame('Thu, 01 Jan 1970 00:00:00 GMT', $response->getHeader('Expires'));
    }

    // --- isNotModified() -----------------------------------------------------

    public function testMatchingEtagIsNotModified(): void
    {
        $response = (new Response())->setEtag('v1');

        $this->assertTrue($response->isNotModified($this->request(headers: ['If-None-Match' => '"v1"'])));
    }

    public function testDifferentEtagIsModified(): void
    {
        $response = (new Response())->setEtag('v1');

        $this->assertFalse($response->isNotModified($this->request(headers: ['If-None-Match' => '"v2"'])));
    }

    public function testEtagListMatchesAnyMember(): void
    {
        $response = (new Response())->setEtag('v2');

        $this->assertTrue(
            $response->isNotModified($this->request(headers: ['If-None-Match' => '"v1", "v2", "v3"']))
        );
    }

    public function testEtagWildcardMatches(): void
    {
        $response = (new Response())->setEtag('anything');

        $this->assertTrue($response->isNotModified($this->request(headers: ['If-None-Match' => '*'])));
    }

    /**
     * RFC 9110 weak comparison: `W/"x"` and `"x"` are the same representation for
     * this direction, so a weak tag must not be treated as a different one.
     */
    public function testWeakAndStrongTagsAreComparedWeakly(): void
    {
        $response = (new Response())->setEtag('"v1"');

        $this->assertTrue($response->isNotModified($this->request(headers: ['If-None-Match' => 'W/"v1"'])));
    }

    public function testNoValidatorMeansModified(): void
    {
        $response = new Response();

        $this->assertFalse($response->isNotModified($this->request(headers: ['If-None-Match' => '"v1"'])));
    }

    /**
     * RFC 9110: when both conditional headers are present the entity tag wins, so a
     * stale tag cannot be masked by a fresh date.
     */
    public function testEtagWinsOverIfModifiedSince(): void
    {
        $response = (new Response())
            ->setEtag('v2')
            ->setLastModified(1700000000);

        $this->assertFalse($response->isNotModified($this->request(headers: [
            'If-None-Match'     => '"v1"',
            'If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT',
        ])));
    }

    public function testIfModifiedSinceAtOrAfterLastModifiedIsNotModified(): void
    {
        $response = (new Response())->setLastModified(1700000000);

        $this->assertTrue($response->isNotModified($this->request(headers: [
            'If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT',
        ])));

        $this->assertTrue($response->isNotModified($this->request(headers: [
            'If-Modified-Since' => 'Wed, 15 Nov 2023 00:00:00 GMT',
        ])));
    }

    public function testIfModifiedSinceBeforeLastModifiedIsModified(): void
    {
        $response = (new Response())->setLastModified(1700000000);

        $this->assertFalse($response->isNotModified($this->request(headers: [
            'If-Modified-Since' => 'Mon, 13 Nov 2023 00:00:00 GMT',
        ])));
    }

    public function testGarbageIfModifiedSinceIsIgnored(): void
    {
        $response = (new Response())->setLastModified(1700000000);

        $this->assertFalse($response->isNotModified($this->request(headers: [
            'If-Modified-Since' => 'not a date',
        ])));
    }

    public function testPostIsNeverNotModified(): void
    {
        $response = (new Response())->setEtag('v1');

        $this->assertFalse($response->isNotModified(
            $this->request('POST', ['If-None-Match' => '"v1"'])
        ));
    }

    public function testHeadIsEligibleForNotModified(): void
    {
        $response = (new Response())->setEtag('v1');

        $this->assertTrue($response->isNotModified(
            $this->request('HEAD', ['If-None-Match' => '"v1"'])
        ));
    }

    // --- notModifiedIfMatches() ----------------------------------------------

    public function testNotModifiedIfMatchesTurnsTheResponseIntoA304(): void
    {
        $response = (new Response(200, ['Content-Type' => 'text/html'], '<p>big page</p>'))
            ->setEtag('v1')
            ->setCache(3600, shared: true)
            ->notModifiedIfMatches($this->request(headers: ['If-None-Match' => '"v1"']));

        $this->assertSame(304, $response->getStatus());
        $this->assertSame('', $response->getBody());
        $this->assertSame('"v1"', $response->getHeader('ETag'));
        $this->assertSame('public, max-age=3600', $response->getHeader('Cache-Control'));
        $this->assertNull($response->getHeader('Content-Type'), 'a 304 has no body to describe');
    }

    public function testNotModifiedIfMatchesLeavesAMismatchAlone(): void
    {
        $response = (new Response(200, ['Content-Type' => 'text/html'], '<p>page</p>'))
            ->setEtag('v1')
            ->notModifiedIfMatches($this->request(headers: ['If-None-Match' => '"v2"']));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('<p>page</p>', $response->getBody());
        $this->assertSame('text/html', $response->getHeader('Content-Type'));
    }

    // --- File bodies ---------------------------------------------------------

    public function testSetFileToSendDescribesTheFile(): void
    {
        $path     = $this->tempFile('hello world', 'txt');
        $response = (new Response())->setFileToSend($path);

        $this->assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertSame('11', $response->getHeader('Content-Length'));
        $this->assertStringContainsString('attachment', (string) $response->getHeader('Content-Disposition'));
        $this->assertNotNull($response->getHeader('Last-Modified'));
        $this->assertNotNull($response->getHeader('ETag'));
        $this->assertSame($path, $response->getFile());
    }

    public function testSetFileToSendStreamsTheFileThroughGetBody(): void
    {
        $path     = $this->tempFile('hello world');
        $response = (new Response())->setFileToSend($path);

        $this->assertSame('hello world', $response->getBody());
        $this->assertSame('hello world', $response->getBody(), 'repeated reads still work');
    }

    public function testInlineDispositionUsesGivenName(): void
    {
        $path     = $this->tempFile('<svg/>', 'svg');
        $response = (new Response())->setFileToSend($path, 'logo.svg', attachment: false);

        $this->assertSame('image/svg+xml', $response->getHeader('Content-Type'));
        $this->assertSame('inline; filename="logo.svg"', $response->getHeader('Content-Disposition'));
    }

    /**
     * A name outside ASCII needs the RFC 5987 form, and the plain `filename=` must
     * not carry raw bytes. Both are emitted so old and new clients agree.
     */
    public function testNonAsciiDownloadNameGetsBothForms(): void
    {
        $path     = $this->tempFile('x');
        $response = (new Response())->setFileToSend($path, 'Bericht März.pdf');

        $disposition = (string) $response->getHeader('Content-Disposition');

        $this->assertStringContainsString('filename="Bericht M_rz.pdf"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''Bericht%20M%C3%A4rz.pdf", $disposition);
    }

    /**
     * A crafted name must not be able to end the header or add a second one; only
     * the file's basename ever reaches the header, and quotes are neutralised.
     */
    public function testDownloadNameCannotInjectAHeader(): void
    {
        $path     = $this->tempFile('x');
        $response = (new Response())->setFileToSend($path, "evil\"; x=y\r\nSet-Cookie: a=b");

        $disposition = (string) $response->getHeader('Content-Disposition');

        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('"; x=y', $disposition);
    }

    public function testUnknownExtensionFallsBackToOctetStream(): void
    {
        $response = (new Response())->setFileToSend($this->tempFile('x', 'binfile'));

        $this->assertSame('application/octet-stream', $response->getHeader('Content-Type'));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Response())->setFileToSend(sys_get_temp_dir() . '/azera-does-not-exist-' . uniqid());
    }

    public function testExplicitEtagSurvivesSetFileToSend(): void
    {
        $path     = $this->tempFile('x');
        $response = (new Response())->setEtag('"build-42"')->setFileToSend($path);

        $this->assertSame('"build-42"', $response->getHeader('ETag'));
    }

    public function testFileBodyCanBeSentAs304(): void
    {
        $path     = $this->tempFile('hello');
        $response = (new Response())->setFileToSend($path);

        $etag     = (string) $response->getHeader('ETag');
        $response->notModifiedIfMatches($this->request(headers: ['If-None-Match' => $etag]));

        $this->assertSame(304, $response->getStatus());
        $this->assertNull($response->getFile());
        $this->assertSame('', $response->getBody());
    }

    // --- X-Sendfile switch ---------------------------------------------------

    public function testXSendfileIsOffByDefault(): void
    {
        $this->assertFalse(Response::xSendfile());
    }

    public function testXSendfileCanBeToggledProcessWide(): void
    {
        $this->assertTrue(Response::xSendfile(true));
        $this->assertTrue(Response::xSendfile());
        $this->assertFalse(Response::xSendfile(false));
    }

    /**
     * `send()` under a SAPI writes headers and then the body. With `X-Sendfile` on
     * it must emit the delegation header instead of reading the file. The body is
     * asserted through output buffering: PHPUnit captures output already, so a
     * body written here would land in the test's buffer.
     */
    public function testSendDelegatesToTheWebServerWhenXSendfileIsOn(): void
    {
        $path = $this->tempFile('hello');

        Response::xSendfile(true);
        $response = (new Response())->setFileToSend($path);

        ob_start();
        $response->send();
        $body = ob_get_clean();

        // No web server here, so the header is the only observable difference, and
        // the body must not have been written.
        $this->assertSame('', $body);
        $this->assertSame('5', $response->getHeader('Content-Length'));
    }

    public function testSendWritesTheFileWhenXSendfileIsOff(): void
    {
        $path = $this->tempFile('hello');

        Response::xSendfile(false);
        $response = (new Response())->setFileToSend($path);

        ob_start();
        $response->send();
        $body = ob_get_clean();

        $this->assertSame('hello', $body);
    }
}
