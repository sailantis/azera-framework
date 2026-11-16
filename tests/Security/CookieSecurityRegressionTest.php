<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Exception;
use Azera\Http\Cookie;
use Azera\Http\Cookies;
use Azera\Http\Request;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the cookie findings of the 2026 security review.
 *
 *  - encrypted cookies used to fall back to the raw (plaintext) cookie value
 *  - the default encryption key was derivable from php_uname()
 *  - cookies were read from `$_COOKIE`, so a worker that opts out of CGI globals
 *    saw none of them
 *  - outgoing cookies went through `setcookie()`, which does nothing under a worker
 */
class CookieSecurityRegressionTest extends TestCase
{
    private const KEY = 'test-key-8f3a1c9e2b7d4f60a5e8c1b3d7f9a2e4';

    /** @var array<string,mixed> */
    private array $originalCookie;

    protected function setUp(): void
    {
        $this->originalCookie = $_COOKIE;
        // A worker that installs no CGI globals: the superglobal is empty, and the
        // request is the only source of incoming cookies.
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookie;
    }

    /** A jar that reads from a request carrying the given cookies. */
    private function jar(array $cookies = []): Cookies
    {
        return new Cookies(new Request([], [], [], [], false, $cookies));
    }

    /**
     * A plaintext cookie must never be accepted for an encrypted cookie.
     */
    public function testPlaintextCookieIsIgnoredWhenEncryptionIsEnabled(): void
    {
        $jar    = $this->jar(['auth' => 'admin']);
        $cookie = $jar->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertNull($cookie->value());
        $this->assertSame('fallback', $jar->get('auth', 'fallback'));
    }

    /**
     * A forged/tampered ciphertext must not be returned either.
     */
    public function testTamperedCiphertextIsIgnored(): void
    {
        $jar = $this->jar(['auth' => base64_encode(str_repeat("\0", 64))]);
        $jar->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertSame('fallback', $jar->get('auth', 'fallback'));
    }

    /**
     * A legitimately encrypted cookie still round-trips.
     */
    public function testValidEncryptedCookieRoundTrips(): void
    {
        $cookie = Cookie::create('auth')->encrypted()->key(self::KEY);
        $cookie->set('admin');

        // Emulate the browser sending back the Set-Cookie value.
        $ref    = new \ReflectionMethod(Cookie::class, 'encrypt');
        $sealed = $ref->invoke($cookie, 'admin');

        $jar = $this->jar(['auth' => $sealed]);
        $jar->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertSame('admin', $jar->get('auth'));
    }

    /**
     * Reading an unregistered cookie name must not raise a null dereference.
     */
    public function testGetForUnknownCookieReturnsDefault(): void
    {
        $jar = $this->jar(['other' => 'x']);

        $this->assertSame('x', $jar->get('other'));
        $this->assertSame('d', $jar->get('missing', 'd'));
    }

    public function testGetRequiresEncryptionConfigurationBeforeReadingEncryptedCookies(): void
    {
        $jar = $this->jar(['auth' => 'untrusted']);

        $this->assertSame('untrusted', $jar->get('auth'));

        $jar->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertSame('fallback', $jar->get('auth', 'fallback'));
    }

    /**
     * Encrypting without an explicit key must fail loudly instead of silently
     * deriving a predictable key.
     */
    public function testEncryptionWithoutExplicitKeyThrows(): void
    {
        $cookie = Cookie::create('auth')->encrypted();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/explicit key/i');

        $cookie->set('admin');

        // Rendering the header is what encrypts, so the check fires here — there is
        // no `send()` any more, by design.
        $cookie->toHeader();
    }

    /**
     * There is exactly one way to emit a cookie: the response header. `setcookie()`
     * is gone, so `send()`/`sendAll()` must not come back.
     */
    public function testNoSapiEmissionPathExists(): void
    {
        $this->assertFalse(
            method_exists(Cookie::class, 'send'),
            'Cookie::send() was removed: the response carries the header instead'
        );
        $this->assertFalse(
            method_exists(Cookies::class, 'sendAll'),
            'Cookies::sendAll() was removed: use applyTo()'
        );
    }

    /**
     * The key must not be derived from php_uname() any more, i.e. a cookie
     * forged with that derivation must be rejected.
     */
    public function testPhpUnameDerivedKeyIsNoLongerAccepted(): void
    {
        $forgedKey = hash('sha256', php_uname(), true);

        $forger = Cookie::create('sid')->encrypted()->key($forgedKey);
        $forger->set('attacker');

        $ref    = new \ReflectionMethod(Cookie::class, 'encrypt');
        $sealed = $ref->invoke($forger, 'attacker');

        // The victim uses a proper key; the forged cookie must not verify.
        $jar = $this->jar(['sid' => $sealed]);
        $jar->cookie('sid')->encrypted()->key(self::KEY);

        $this->assertNull($jar->get('sid'));
    }

    /**
     * The regression this change is about: a cookie the client sent is readable
     * even though `$_COOKIE` is empty.
     */
    public function testCookieIsReadWithEmptySuperglobal(): void
    {
        $this->assertSame([], $_COOKIE, 'precondition: no CGI globals installed');

        $jar = $this->jar(['session' => 'abc123']);

        $this->assertSame('abc123', $jar->get('session'));
        $this->assertSame('abc123', $jar->cookie('session')->value());
        $this->assertSame(
            'abc123',
            (new Request([], [], [], [], false, ['session' => 'abc123']))->cookie('session')
        );
    }

    // --- Output ---------------------------------------------------------------

    /**
     * A cookie the application only read must not come back as a `Set-Cookie`.
     *
     * This is the hazard an automatic drain introduces: `cookie()` registers into
     * the jar, so emission has to be driven by the dirty flag and not by presence.
     */
    public function testReadingACookieEmitsNothing(): void
    {
        $jar = $this->jar(['auth' => 'value']);

        $jar->cookie('auth')->value();
        $jar->get('auth');

        $response = $jar->applyTo(new Response());

        $this->assertSame([], $response->getHeaders());
    }

    /**
     * A written cookie reaches the response as a `Set-Cookie` line.
     */
    public function testSetCookieReachesTheResponse(): void
    {
        $jar = $this->jar();
        $jar->set('theme', 'dark');

        $response = $jar->applyTo(new Response());

        $this->assertSame(['Set-Cookie: theme=dark; Path=/; HttpOnly'], $response->getHeaders());
    }

    public function testSettingAfterDeleteCancelsThePendingDeletion(): void
    {
        $cookie = Cookie::create('theme')->delete()->set('dark');

        $this->assertSame('theme=dark; Path=/; HttpOnly', $cookie->toHeader());
    }

    public function testSetRawReplacesAnAlreadyLoadedValue(): void
    {
        $cookie = Cookie::create('theme')->setRaw('first');

        $this->assertSame('first', $cookie->value());
        $cookie->setRaw('second');

        $this->assertSame('second', $cookie->value());
        $this->assertFalse($cookie->isDirty());
    }

    public function testCookieValueIsEncodedForTheHeader(): void
    {
        $cookie = Cookie::create('theme')->set('dark; mode = yes');

        $this->assertSame('theme=dark%3B%20mode%20%3D%20yes; Path=/; HttpOnly', $cookie->toHeader());
    }

    public function testInvalidCookieNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cookie::create('bad;name')->set('value')->toHeader();
    }

    public function testInvalidCookieAttributeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cookie::create('theme')->path("/bad\npath")->toHeader();
    }

    public function testFailedCookieSerializationKeepsItDirtyForRetry(): void
    {
        $cookie   = Cookie::create('auth')->set('admin')->encrypted();
        $response = new Response();

        try {
            $response->addCookie($cookie);
            $this->fail('Expected missing encryption key to throw.');
        } catch (Exception) {
            $this->assertTrue($cookie->isDirty());
        }

        $cookie->key(self::KEY);
        $response->addCookie($cookie);

        $this->assertCount(1, $response->getHeaders());
    }

    /** Repeated cookies and ordinary headers share one ordered header collection. */
    public function testTwoCookiesProduceTwoHeaderLines(): void
    {
        $jar = $this->jar();
        $jar->set('a', '1');
        $jar->set('b', '2');

        $response = $jar->applyTo(new Response());

        $this->assertCount(2, $response->getHeaders());
        $this->assertSame(
            ['Set-Cookie: a=1; Path=/; HttpOnly', 'Set-Cookie: b=2; Path=/; HttpOnly'],
            $response->getHeaders()
        );
    }

    public function testReplaceHeaderMatchesCaseInsensitivelyOnTheFieldName(): void
    {
        $response = new Response(headers: ['Content-Type: text/plain', 'X-Trace: one']);
        $response->addHeader('Set-Cookie: a=1');
        $response->addHeader('Set-Cookie: b=2');
        $response->replaceHeader('content-type: application/json');

        $this->assertSame(
            [
                'content-type: application/json',
                'X-Trace: one',
                'Set-Cookie: a=1',
                'Set-Cookie: b=2',
            ],
            $response->getHeaders()
        );
    }

    public function testReplaceHeaderCollapsesAllLinesSharingTheFieldName(): void
    {
        $response = (new Response(headers: ['X-Trace: one']))
            ->replaceHeader('X-Trace: four');

        $this->assertSame(['X-Trace: four'], $response->getHeaders());
    }

    public function testReplaceHeadersAppliesEachLine(): void
    {
        $response = (new Response(headers: ['Content-Type: text/plain']))
            ->replaceHeaders(['X-Trace: one', 'content-type: application/json']);

        $this->assertSame(
            ['content-type: application/json', 'X-Trace: one'],
            $response->getHeaders()
        );
    }

    public function testAddHeadersKeepsRepeatedLines(): void
    {
        $response = (new Response())->addHeaders(['Set-Cookie: a=1', 'Set-Cookie: b=2']);

        $this->assertSame(['Set-Cookie: a=1', 'Set-Cookie: b=2'], $response->getHeaders());
    }

    public function testConstructorStoresCompleteLinesAsGiven(): void
    {
        $response = new Response(headers: ['Content-Type: application/json']);

        $this->assertSame(['Content-Type: application/json'], $response->getHeaders());
    }

    public function testGetHeaderReturnsTheValueWithoutTheName(): void
    {
        $response = new Response(headers: ['Content-Type: application/json', 'X-Trace: one']);

        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame('one', $response->getHeader('x-trace'));
        $this->assertNull($response->getHeader('Missing'));
    }

    public function testGetHeaderSplitsOnTheFirstColon(): void
    {
        $response = new Response(headers: ['Set-Cookie: expires=Thu, 01 Jan 2026 00:00:00 GMT']);

        $this->assertSame('expires=Thu, 01 Jan 2026 00:00:00 GMT', $response->getHeader('Set-Cookie'));
    }

    public function testGetHeaderDistinguishesAnEmptyValueFromAnAbsentHeader(): void
    {
        $response = new Response(headers: ['X-Empty: ']);

        $this->assertSame('', $response->getHeader('X-Empty'));
        $this->assertNull($response->getHeader('X-Absent'));
    }

    public function testHeaderFactoryMethodsEmitCompleteLines(): void
    {
        $this->assertSame(['Content-Type: application/json'], Response::json(['a' => 1])->getHeaders());
        $this->assertSame(['Content-Type: text/html; charset=utf-8'], Response::html('<p>x</p>')->getHeaders());
        $this->assertSame(['Location: /next'], Response::redirect('/next')->getHeaders());
    }

    /**
     * `applyTo()` hands back the same instance, so a caller can return it directly.
     */
    public function testApplyToReturnsTheSameResponse(): void
    {
        $jar      = $this->jar();
        $response = new Response();

        $this->assertSame($response, $jar->applyTo($response));
    }

    /**
     * Draining twice must not duplicate the header.
     */
    public function testDrainingTwiceDoesNotDuplicateTheCookie(): void
    {
        $jar = $this->jar();
        $jar->set('theme', 'dark');

        $response = new Response();
        $jar->applyTo($response);
        $jar->applyTo($response);

        $this->assertCount(1, $response->getHeaders());
    }

    /**
     * `clear()` lets a response go out with no cookies, while reads keep working.
     */
    public function testClearSuppressesEmissionButNotReads(): void
    {
        $jar = $this->jar(['existing' => 'kept']);
        $jar->set('flash', 'saved');

        $jar->clear();

        $response = $jar->applyTo(new Response());

        $this->assertSame([], $response->getHeaders());
        $this->assertSame('kept', $jar->get('existing'));
    }

    /**
     * Deleting queues a past-expiry line without relying on `setcookie()`.
     */
    public function testDeleteEmitsAPastExpiryHeader(): void
    {
        $jar = $this->jar();
        $jar->delete('theme');

        $response = $jar->applyTo(new Response());
        $lines    = $response->getHeaders();

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('Set-Cookie: theme=;', $lines[0]);
        $this->assertStringContainsString('Max-Age=0', $lines[0]);
    }

    /**
     * Renewing a cookie by changing only an attribute must re-send the value the
     * client sent, not an empty one.
     *
     * The `Cookie` request header carries no attributes, so the jar rebuilds the
     * object with defaults. Emitting `name=` would wipe the cookie, and emitting the
     * defaults would downgrade a persistent cookie to a session cookie.
     */
    public function testRenewingAnAttributeKeepsTheIncomingValue(): void
    {
        $jar = $this->jar(['remember' => 'tok123']);
        $jar->cookie('remember')->expires(time() + 86400);

        $lines = $jar->applyTo(new Response())->getHeaders();

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('Set-Cookie: remember=tok123;', $lines[0]);
        $this->assertStringContainsString('Max-Age=86400', $lines[0]);
    }

    /**
     * Renewing an encrypted cookie re-encrypts the incoming value, and the result
     * still decrypts. A fresh nonce per emission is expected; a lost value is not.
     */
    public function testRenewingAnEncryptedCookiePreservesTheValue(): void
    {
        $sealed = Cookie::create('auth')->set('admin')->encrypted()->key(self::KEY)->toHeader();
        preg_match('/^auth=([^;]+)/', $sealed, $m);

        $jar = $this->jar(['auth' => rawurldecode($m[1])]);
        $jar->cookie('auth')->encrypted()->key(self::KEY)->secure(true);

        $lines = $jar->applyTo(new Response())->getHeaders();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Secure', $lines[0]);

        preg_match('/^Set-Cookie: auth=([^;]+)/', $lines[0], $renewed);
        $check = $this->jar(['auth' => rawurldecode($renewed[1])]);
        $check->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertSame('admin', $check->get('auth'));
    }

    /**
     * A read-only cookie is never emitted, so its attributes cannot be rewritten
     * from this jar's defaults (which would drop `Expires` and `Secure`).
     */
    public function testReadOnlyCookieIsNotDowngraded(): void
    {
        $jar = $this->jar(['remember' => 'tok']);
        $jar->get('remember');
        $jar->cookie('remember')->value();

        $this->assertSame([], $jar->applyTo(new Response())->getHeaders());
    }

    /**
     * Two emissions of the same encrypted cookie differ (fresh nonce) but both
     * decrypt — the reason a read must not be re-emitted on every response.
     */
    public function testEncryptionUsesAFreshNoncePerEmission(): void
    {
        $a = Cookie::create('auth')->set('admin')->encrypted()->key(self::KEY);
        $b = Cookie::create('auth')->set('admin')->encrypted()->key(self::KEY);

        $this->assertNotSame($a->toHeader(), $b->toHeader());
    }
    public function testAddCookieMatchesTheHeaderForm(): void
    {
        $cookie = Cookie::create('theme')->set('dark');

        $viaAddCookie = (new Response())->addCookie($cookie)->getHeaders();
        $viaAddHeader = (new Response())
            ->addHeader('Set-Cookie: ' . $cookie->toHeader())
            ->getHeaders();

        $this->assertSame($viaAddHeader, $viaAddCookie);
        $this->assertSame(['Set-Cookie: theme=dark; Path=/; HttpOnly'], $viaAddCookie);
    }

    /**
     * `__toString()` keeps returning the value, so a template that echoes a cookie
     * does not suddenly emit header text into the body.
     */
    public function testStringCastStillReturnsTheValue(): void
    {
        $cookie = Cookie::create('theme')->set('dark');

        $this->assertSame('dark', (string) $cookie);
    }

    /**
     * The factory was renamed; the old name must be gone rather than aliased.
     */
    public function testMakeIsNoLongerAvailable(): void
    {
        $this->assertFalse(
            method_exists(Cookie::class, 'make'),
            'Cookie::make() was renamed to Cookie::create()'
        );
    }
}
