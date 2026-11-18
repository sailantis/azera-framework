<?php

declare(strict_types=1);

namespace Azera\Tests\Http;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Cookie;
use Azera\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Response header API, and the strict-mode switch that guards it.
 *
 * WHY THIS FILE EXISTS. Eight commits rewrote how Response stores headers --
 * by field name, with repeated values preserved, plus a new two-stage setter
 * API -- and not one of them was covered. That is how the RFC 9110 validation
 * came to be commented out while the CHANGELOG and the API docs still promised
 * that a malformed name or value raises InvalidArgumentException: nothing
 * failed, because nothing checked. The tests below are the check.
 *
 * The strict switch is tested in BOTH positions. A suite that only exercised
 * the enabled path would pass just as happily with the guard commented out
 * again -- which is exactly the state this file exists to make impossible.
 */
class ResponseHeaderTest extends TestCase
{
    /** Strict mode is process-wide, so every test starts from a known state. */
    protected function setUp(): void
    {
        Response::strict(false);
    }

    protected function tearDown(): void
    {
        Response::strict(false);
    }

    // --- The switch itself ---------------------------------------------------

    public function testStrictDefaultsToOff(): void
    {
        $this->assertFalse(Response::strict(), 'validation must not be on unless asked for');
    }

    public function testStrictSetterReturnsTheSettingInEffect(): void
    {
        $this->assertTrue(Response::strict(true));
        $this->assertTrue(Response::strict(), 'reading back must report the value that was set');
        $this->assertFalse(Response::strict(false));
    }

    // --- Header storage ------------------------------------------------------

    public function testSetHeaderReplacesEveryValueOfTheField(): void
    {
        $response = (new Response())
            ->addHeader('X-Trace', 'one')
            ->addHeader('X-Trace', 'two')
            ->setHeader('X-Trace', 'only');

        $this->assertSame(['only'], $response->getHeaderValues('X-Trace'));
    }

    public function testAddHeaderAppendsAndKeepsEveryValue(): void
    {
        $response = (new Response())
            ->addHeader('Set-Cookie', 'a=1')
            ->addHeader('Set-Cookie', 'b=2');

        $this->assertSame(['a=1', 'b=2'], $response->getHeaderValues('Set-Cookie'));
    }

    public function testRepeatedSetCookieSurvivesTheWireFormat(): void
    {
        $response = (new Response())
            ->addCookie(Cookie::create('a', '1'))
            ->addCookie(Cookie::create('b', '2'));

        $cookieLines = array_values(array_filter(
            $response->getHeaderLines(),
            static fn(string $line): bool => str_starts_with($line, 'Set-Cookie:')
        ));

        $this->assertCount(2, $cookieLines, 'a second Set-Cookie must not overwrite the first');
    }

    public function testFieldNamesAreCaseInsensitiveButKeepTheirFirstSpelling(): void
    {
        $response = (new Response())->setHeader('X-Trace-Id', 'abc');

        $this->assertSame('abc', $response->getHeader('x-trace-id'), 'lookup must ignore case');
        $this->assertSame(['X-Trace-Id' => ['abc']], $response->getHeaders(), 'the written spelling is kept');
    }

    public function testConstructorAcceptsNameKeyedMapAndLineList(): void
    {
        $map  = new Response(200, ['Content-Type' => 'text/plain']);
        $list = new Response(200, ['Content-Type: text/plain']);

        $this->assertSame('text/plain', $map->getHeader('Content-Type'));
        $this->assertSame('text/plain', $list->getHeader('Content-Type'), 'the older line-list form still works');
    }

    public function testConstructorKeepsRepeatedValuesFromLineList(): void
    {
        $response = new Response(200, ['Set-Cookie: a=1', 'Set-Cookie: b=2']);

        $this->assertSame(['a=1', 'b=2'], $response->getHeaderValues('Set-Cookie'));
    }

    public function testHeaderLineWithoutAColonIsRejectedInBothModes(): void
    {
        // Not a strict-mode concern: a line that is not a line cannot be parsed
        // into a field at all, so this throws either way.
        foreach ([false, true] as $strict) {
            Response::strict($strict);
            try {
                new Response(200, ['no colon here']);
                $this->fail('a header line without a colon must not be accepted (strict=' . var_export($strict, true) . ')');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('colon', $e->getMessage());
            }
        }
    }

    // --- Strict mode: off (the production default) ---------------------------

    /**
     * Nothing is validated when strict is off, and that is deliberate: the
     * switch must be able to remove the cost entirely, not merely make it
     * quieter.
     */
    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderIsAcceptedWhenStrictIsOff(string $name, string $value): void
    {
        $response = (new Response())->setHeader($name, $value);

        $this->assertSame([$value], $response->getHeaderValues($name));
    }

    // --- Strict mode: on (the development assertion) -------------------------

    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderIsRejectedWhenStrictIsOn(string $name, string $value): void
    {
        Response::strict(true);

        $this->expectException(\InvalidArgumentException::class);
        (new Response())->setHeader($name, $value);
    }

    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderIsRejectedByAddHeaderWhenStrictIsOn(string $name, string $value): void
    {
        Response::strict(true);

        $this->expectException(\InvalidArgumentException::class);
        (new Response())->addHeader($name, $value);
    }

    /**
     * The map form of setHeaders() bypasses setHeader(), so it needs its own
     * guard. Without one, `setHeaders(['X-Trace' => $tainted])` would be the
     * single way to reach the header store unchecked in strict mode.
     */
    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderIsRejectedBySetHeadersMapFormWhenStrictIsOn(string $name, string $value): void
    {
        Response::strict(true);

        $this->expectException(\InvalidArgumentException::class);
        (new Response())->setHeaders([$name => $value]);
    }

    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderIsAcceptedBySetHeadersMapFormWhenStrictIsOff(string $name, string $value): void
    {
        $response = (new Response())->setHeaders([$name => $value]);

        $this->assertSame([$value], $response->getHeaderValues($name));
    }

    /**
     * The line form is how code actually builds a header from a variable --
     * `new Response(headers: ['ETag: ' . $etag])` -- so the guard has to reach
     * it. Validating only the two-argument setters would leave the most common
     * style unchecked.
     */
    #[DataProvider('invalidHeaderLines')]
    public function testInvalidLineFormIsRejectedByTheConstructorWhenStrictIsOn(string $line): void
    {
        Response::strict(true);

        $this->expectException(\InvalidArgumentException::class);
        new Response(200, [$line]);
    }

    #[DataProvider('invalidHeaderLines')]
    public function testInvalidLineFormIsRejectedBySetHeadersWhenStrictIsOn(string $line): void
    {
        Response::strict(true);

        $this->expectException(\InvalidArgumentException::class);
        (new Response())->setHeaders([$line]);
    }

    #[DataProvider('invalidHeaderLines')]
    public function testInvalidLineFormIsRejectedByAddHeadersWhenStrictIsOn(string $line): void
    {
        Response::strict(true);

        $this->expectException(\InvalidArgumentException::class);
        (new Response())->addHeaders([$line]);
    }

    #[DataProvider('invalidHeaderLines')]
    public function testInvalidLineFormIsAcceptedWhenStrictIsOff(string $line): void
    {
        $response = new Response(200, [$line]);

        $this->assertNotSame([], $response->getHeaders());
    }

    // --- Strict mode must never reject a legitimate header -------------------

    /**
     * The failure mode that matters more than a missed injection is a guard
     * that rejects something valid: it would break a working application in the
     * one environment where the switch is on. Each case here is a real header
     * an application writes.
     */
    #[DataProvider('validHeaders')]
    public function testValidHeaderIsAcceptedInBothModes(string $name, string $value): void
    {
        foreach ([false, true] as $strict) {
            Response::strict($strict);
            $response = (new Response())->setHeader($name, $value);
            $this->assertSame([$value], $response->getHeaderValues($name), 'strict=' . var_export($strict, true));
        }
    }

    #[DataProvider('validHeaderLines')]
    public function testValidLineFormIsAcceptedInBothModes(string $line): void
    {
        foreach ([false, true] as $strict) {
            Response::strict($strict);
            $response = new Response(200, [$line]);
            $this->assertNotSame([], $response->getHeaders(), 'strict=' . var_export($strict, true));
        }
    }

    public function testEmptyValueIsValid(): void
    {
        Response::strict(true);

        $this->assertSame([''], (new Response())->setHeader('X-Empty', '')->getHeaderValues('X-Empty'));
    }

    public function testCookieIsAcceptedInStrictMode(): void
    {
        Response::strict(true);

        $response = (new Response())->addCookie(Cookie::create('session', 'abc123'));

        $this->assertCount(1, $response->getHeaderValues('Set-Cookie'));
    }

    // --- Providers -----------------------------------------------------------

    /**
     * Each of these would split one header into two, or emit an unparseable
     * field, if it reached the SAPI or a worker adapter.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function invalidHeaders(): array
    {
        return [
            'CRLF in value (response splitting)' => ['X-Trace', "ok\r\nX-Injected: 1"],
            'LF in value'                        => ['X-Trace', "ok\nX-Injected: 1"],
            'NUL in value'                       => ['X-Trace', "ok\x00tail"],
            'space in name'                      => ['Bad Name', 'value'],
            'colon in name'                      => ['Bad:Name', 'value'],
            'empty name'                         => ['', 'value'],
        ];
    }

    /**
     * The same payloads, as they arrive through the line form.
     *
     * @return array<string,array{0:string}>
     */
    public static function invalidHeaderLines(): array
    {
        return [
            'CRLF in value (response splitting)' => ["X-Trace: ok\r\nX-Injected: 1"],
            'LF in value'                        => ["X-Trace: ok\nX-Injected: 1"],
            'NUL in value'                       => ["X-Trace: ok\x00tail"],
            'space in name'                      => ['Bad Name: value'],
        ];
    }

    /**
     * Legitimate headers, including the characters a naive check gets wrong.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function validHeaders(): array
    {
        return [
            'content type with parameter' => ['Content-Type', 'text/html; charset=utf-8'],
            'value with a colon'          => ['X-Server-Time', '12:34:56'],
            'value with a semicolon'      => ['Content-Disposition', 'attachment; filename="report.txt"'],
            'value with quotes'           => ['ETag', '"9f8e7d6c5b4a"'],
            'value with non-ASCII'        => ['X-Name', 'Jörg Müller'],
            'value with a comma'          => ['Cache-Control', 'public, max-age=3600'],
            'quoted-string with a space'  => ['Content-Disposition', 'attachment; filename="my report.txt"'],
            'name with digits and dashes' => ['X-Trace-Id-2', 'abc'],
            'single-letter name'          => ['X', 'v'],
        ];
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function validHeaderLines(): array
    {
        return [
            'etag'              => ['ETag: "9f8e7d6c5b4a"'],
            'location with url' => ['Location: https://example.test/a?b=1&c=2'],
            'content type'      => ['Content-Type: application/json; charset=UTF-8'],
            'vary'              => ['Vary: Accept-Encoding'],
        ];
    }
}
