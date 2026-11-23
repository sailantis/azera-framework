<?php

declare(strict_types=1);

namespace Azera\Tests\Http;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Cookie;
use Azera\Http\Cookies;
use Azera\Http\Request;
use Azera\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `SameSite` support on {@see Cookie}.
 *
 * The attribute was absent, so every application that needed it — including the
 * Sailantis homepage — assembled the `Set-Cookie` line by hand instead of going
 * through {@see Cookie::toHeader()}, which also meant losing the name/path
 * validation that lives there.
 */
class CookieSameSiteTest extends TestCase
{
    public function testSameSiteIsAppendedAfterHttpOnly(): void
    {
        $cookie = Cookie::create('theme')->set('dark')->sameSite('Lax');

        $this->assertSame('theme=dark; Path=/; HttpOnly; SameSite=Lax', $cookie->toHeader());
    }

    /**
     * Browsers compare case-insensitively but echo a canonical spelling, so the
     * setter normalises rather than trusting what the caller typed.
     *
     * `None` is paired with `Secure` because {@see Cookie::toHeader()} refuses the
     * combination browsers reject.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function canonicalSpellings(): array
    {
        return [
            'lax'    => ['lax', 'Lax', false],
            'LAX'    => ['LAX', 'Lax', false],
            'lAx'    => ['lAx', 'Lax', false],
            'strict' => ['strict', 'Strict', false],
            'none'   => ['none', 'None', true],
        ];
    }

    #[DataProvider('canonicalSpellings')]
    public function testSameSiteIsNormalisedToTheCanonicalSpelling(string $input, string $expected, bool $secure): void
    {
        $cookie = Cookie::create('theme')->set('dark')->sameSite($input);

        if ($secure) {
            $cookie->secure(true);
        }

        $this->assertSame($expected, $cookie->getSameSite());
        $this->assertStringEndsWith('SameSite=' . $expected, $cookie->toHeader());
    }

    public function testInvalidSameSiteValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cookie::create('theme')->sameSite('bogus');
    }

    /**
     * `SameSite=None` without `Secure` is a combination browsers reject outright.
     * Failing here names the cookie; failing at the client looks like a login that
     * silently did not stick.
     */
    public function testSameSiteNoneWithoutSecureIsRejected(): void
    {
        $cookie = Cookie::create('theme')->set('dark')->sameSite('None');

        $this->expectException(\InvalidArgumentException::class);
        $cookie->toHeader();
    }

    public function testSameSiteNoneWithSecureIsEmitted(): void
    {
        $cookie = Cookie::create('theme')->set('dark')->sameSite('None')->secure(true);

        $this->assertSame(
            'theme=dark; Path=/; Secure; HttpOnly; SameSite=None',
            $cookie->toHeader()
        );
    }

    /**
     * Clearing the attribute must not leave a stray `SameSite=` behind, and must
     * make the cookie dirty so the change reaches the response.
     */
    public function testSameSiteCanBeCleared(): void
    {
        $cookie = Cookie::create('theme')->set('dark')->sameSite('Lax');
        $cookie->markClean();

        $cookie->sameSite('');

        $this->assertSame('', $cookie->getSameSite());
        $this->assertSame('theme=dark; Path=/; HttpOnly', $cookie->toHeader());
        $this->assertTrue($cookie->isDirty());
    }

    public function testSettingSameSiteMarksTheCookieDirty(): void
    {
        $cookie = Cookie::create('theme')->set('dark');
        $cookie->markClean();

        $cookie->sameSite('Strict');

        $this->assertTrue($cookie->isDirty());
    }

    /**
     * A cookie loaded from the request starts clean; adding `SameSite` is an
     * attribute change, so it must be re-emitted exactly once.
     */
    public function testSettingSameSiteOnAReadCookieReEmitsIt(): void
    {
        $jar = new Cookies(new Request([], [], [], [], false, ['theme' => 'dark']));
        $jar->cookie('theme')->sameSite('Lax');

        $lines = $jar->applyTo(new Response())->getHeaderLines();

        $this->assertSame(['Set-Cookie: theme=dark; Path=/; HttpOnly; SameSite=Lax'], $lines);
    }

    public function testCreateAcceptsSameSite(): void
    {
        $cookie = Cookie::create('theme', 'dark', sameSite: 'Strict');

        $this->assertSame('theme=dark; Path=/; HttpOnly; SameSite=Strict', $cookie->toHeader());
    }

    public function testCreateWithNoSameSiteEmitsNoAttribute(): void
    {
        $cookie = Cookie::create('theme', 'dark');

        $this->assertSame('theme=dark; Path=/; HttpOnly', $cookie->toHeader());
    }

    public function testJarSetAcceptsSameSite(): void
    {
        $jar = new Cookies(new Request());
        $jar->set('theme', 'dark', sameSite: 'Lax');

        $lines = $jar->applyTo(new Response())->getHeaderLines();

        $this->assertSame(['Set-Cookie: theme=dark; Path=/; HttpOnly; SameSite=Lax'], $lines);
    }

    /**
     * Deletion must stay a valid clearing cookie: same attributes, past expiry.
     */
    public function testDeletedCookieKeepsSameSite(): void
    {
        $header = Cookie::create('theme')->sameSite('Lax')->delete()->toHeader();

        $this->assertStringContainsString('Max-Age=0', $header);
        $this->assertStringEndsWith('SameSite=Lax', $header);
    }
}
