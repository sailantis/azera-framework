<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Exception;
use Azera\Http\Cookie;
use Azera\Http\Cookies;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the cookie findings of the 2026 security review.
 *
 *  - encrypted cookies used to fall back to the raw (plaintext) cookie value
 *  - the default encryption key was derivable from php_uname()
 */
class CookieSecurityRegressionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalCookie;

    protected function setUp(): void
    {
        $this->originalCookie = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookie;
    }

    private const KEY = 'test-key-8f3a1c9e2b7d4f60a5e8c1b3d7f9a2e4';

    /**
     * A plaintext cookie must never be accepted for an encrypted cookie.
     */
    public function testPlaintextCookieIsIgnoredWhenEncryptionIsEnabled(): void
    {
        $_COOKIE['auth'] = 'admin';

        $jar    = new Cookies();
        $cookie = $jar->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertNull($cookie->value());
        $this->assertSame('fallback', $jar->get('auth', 'fallback'));
    }

    /**
     * A forged/tampered ciphertext must not be returned either.
     */
    public function testTamperedCiphertextIsIgnored(): void
    {
        $_COOKIE['auth'] = base64_encode(str_repeat("\0", 64));

        $jar = new Cookies();
        $jar->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertSame('fallback', $jar->get('auth', 'fallback'));
    }

    /**
     * A legitimately encrypted cookie still round-trips.
     */
    public function testValidEncryptedCookieRoundTrips(): void
    {
        $cookie = Cookie::make('auth')->encrypted()->key(self::KEY);
        $cookie->set('admin');

        // Emulate the browser sending back the Set-Cookie value.
        $ref = new \ReflectionMethod(Cookie::class, 'encrypt');
        $_COOKIE['auth'] = $ref->invoke($cookie, 'admin');

        $reader = new Cookies();
        $reader->cookie('auth')->encrypted()->key(self::KEY);

        $this->assertSame('admin', $reader->get('auth'));
    }

    /**
     * Reading an unregistered cookie name must not raise a null dereference.
     */
    public function testGetForUnknownCookieReturnsDefault(): void
    {
        $_COOKIE['other'] = 'x';

        $jar = new Cookies();

        $this->assertSame('x', $jar->get('other'));
        $this->assertSame('d', $jar->get('missing', 'd'));
    }

    /**
     * Encrypting without an explicit key must fail loudly instead of silently
     * deriving a predictable key.
     */
    public function testEncryptionWithoutExplicitKeyThrows(): void
    {
        $cookie = Cookie::make('auth')->encrypted();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/explicit key/i');

        $cookie->set('admin');
        $cookie->send();
    }

    /**
     * The key must not be derived from php_uname() any more, i.e. a cookie
     * forged with that derivation must be rejected.
     */
    public function testPhpUnameDerivedKeyIsNoLongerAccepted(): void
    {
        $forgedKey = hash('sha256', php_uname(), true);

        $forger = Cookie::make('sid')->encrypted()->key($forgedKey);
        $forger->set('attacker');
        $ref = new \ReflectionMethod(Cookie::class, 'encrypt');
        $_COOKIE['sid'] = $ref->invoke($forger, 'attacker');

        // The victim uses a proper key; the forged cookie must not verify.
        $reader = new Cookies();
        $reader->cookie('sid')->encrypted()->key(self::KEY);

        $this->assertNull($reader->get('sid'));
    }
}
