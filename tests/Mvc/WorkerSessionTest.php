<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\WorkerSession;
use PHPUnit\Framework\TestCase;

/**
 * Sessions in a worker, where no SAPI is doing it for the application.
 *
 * Deliberately run *without* any `-d` session flags, and with the defaults
 * restored, because the point being made is that the class configures itself —
 * a worker started with a stock `php` command still gets working sessions. What
 * a CLI worker cannot do is deliver `header()` output, which is why the adapter
 * has to carry the cookie rather than PHP.
 */
final class WorkerSessionTest extends TestCase
{
    /** The ini values the suite found, so each test starts from a known state. */
    private string|false $useCookies;
    private string|false $cacheLimiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCookies = ini_get('session.use_cookies');
        $this->cacheLimiter = ini_get('session.cache_limiter');

        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();

        // Leave the process as it was found; a later test in the same run must
        // not inherit relaxed settings.
        ini_set('session.use_cookies', (string) $this->useCookies);
        ini_set('session.cache_limiter', (string) $this->cacheLimiter);

        parent::tearDown();
    }

    /**
     * Session state is global and outlives a test, exactly as it outlives a
     * request in the worker this class exists for.
     */
    private function resetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        session_id('');
        $_COOKIE = [];
    }

    /**
     * A worker's SAPI cannot deliver headers, which is why the cookie is carried
     * out by hand. Under CLI this is literal: `headers_list()` stays empty.
     */
    public function testAWorkerSapiCannotDeliverTheSessionCookieItself(): void
    {
        $this->assertSame('cli', PHP_SAPI, 'this test describes the CLI worker');
        $this->assertFalse(headers_sent(), 'headers are still unsent, so PHP *could* write in principle');

        session_start();
        session_write_close();

        $this->assertSame(
            [],
            headers_list(),
            'yet nothing PHP writes reaches a header list, so the cookie must be carried out'
        );
    }

    /**
     * The settings PHP needs relaxed are relaxed by the class, so a worker does
     * not have to be started with matching `-d` flags.
     */
    public function testTheClassRelaxesPhpCookieHandling(): void
    {
        ini_set('session.use_cookies', '1');
        ini_set('session.cache_limiter', 'nocache');

        $session = new WorkerSession();
        $session->begin([]);

        $this->assertFalse(WorkerSession::phpCookiesEnabled(), 'PHP will not try to send the cookie');
        $this->assertSame('', (string) ini_get('session.cache_limiter'), 'and emits no cache limiter headers');
    }

    public function testARequestWithNoSessionGetsNoCookie(): void
    {
        $session = new WorkerSession();
        $session->begin([]);

        $this->assertNull($session->cookie(), 'nothing started a session');
    }

    public function testTheClientsSessionIdIsAdoptedAndNotEchoedBack(): void
    {
        $session = new WorkerSession();
        $session->begin([(string) session_name() => 'abcdef0123456789']);

        $this->assertSame('abcdef0123456789', session_id());

        session_start();
        $this->assertSame('abcdef0123456789', session_id());

        // The client already has this id; another cookie would be a needless
        // `Set-Cookie` on every single response.
        $this->assertNull($session->cookie());
    }

    /**
     * The id from a cookie becomes part of a file name, so anything that is not
     * a session id must not reach the session handler.
     */
    public function testAnInvalidSessionIdFromTheClientIsRefused(): void
    {
        foreach (['../../etc/passwd', 'a b', 'id;rm -rf /', '', 'x/y', 'sid=1'] as $hostile) {
            $session = new WorkerSession();
            $session->begin([(string) session_name() => $hostile]);

            $this->assertSame('', session_id(), sprintf('refused: %s', var_export($hostile, true)));
        }
    }

    public function testANewSessionIsOfferedToTheClient(): void
    {
        $session = new WorkerSession();
        $session->begin([]);

        session_start();
        $id = session_id();
        $this->assertNotSame('', $id);

        $cookie = $session->cookie();

        $this->assertIsString($cookie);
        $this->assertStringStartsWith(rawurlencode((string) session_name()) . '=' . $id, $cookie);
        $this->assertStringContainsString('Path=/', $cookie);
    }

    /**
     * The reason the id is set on *every* request rather than only when a cookie
     * arrives: `session_id()` outlives the request in a persistent process, so a
     * client presenting nothing must not be handed the previous client's session.
     */
    public function testAClientWithNoCookieDoesNotInheritThePreviousClientsSession(): void
    {
        $first = new WorkerSession();
        $first->begin([(string) session_name() => 'aaaaaaaaaaaaaaaa']);
        session_start();
        $firstId = session_id();
        $first->end();

        $second = new WorkerSession();
        $second->begin([]);

        $this->assertSame('', session_id(), 'the leftover id was cleared');

        session_start();
        $this->assertNotSame($firstId, session_id());
    }

    /**
     * A session that ends must not leave its data behind for the next request to
     * read out of `$_SESSION`.
     */
    public function testEndForgetsTheSessionData(): void
    {
        $session = new WorkerSession();
        $session->begin([]);
        session_start();
        $_SESSION['flash'] = 'from the last request';

        $session->end();

        $this->assertSame([], $_SESSION);
        $this->assertSame('', session_id());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    /**
     * The cookie value is a pure function of PHP's settings, so it can be pinned
     * without depending on the machine's `php.ini`.
     */
    public function testTheCookieIsBuiltFromPhpsOwnSettings(): void
    {
        $this->assertSame(
            'PHPSESSID=abc123; Path=/',
            WorkerSession::header('abc123', 'PHPSESSID', []),
        );

        $this->assertSame(
            'PHPSESSID=abc123; Path=/; Domain=example.test; Secure; HttpOnly; SameSite=Lax',
            WorkerSession::header('abc123', 'PHPSESSID', [
                'path' => '/',
                'domain' => 'example.test',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]),
        );

        $this->assertSame(
            'PHPSESSID=abc123; Path=/',
            WorkerSession::header('abc123', false, ['path' => '']),
            'an unset session name and an empty path fall back to the defaults',
        );
    }

    public function testAPersistentCookieCarriesBothExpiryForms(): void
    {
        $cookie = WorkerSession::header('abc123', '_s', ['lifetime' => 3600, 'path' => '/']);

        $this->assertStringContainsString('Expires=', $cookie);
        $this->assertStringContainsString('Max-Age=3600', $cookie);
        $this->assertStringStartsWith('_s=abc123;', $cookie);
    }

    /**
     * A name or a value that would break the header must be encoded, not trusted.
     */
    public function testTheSessionNameAndIdAreEncoded(): void
    {
        $cookie = WorkerSession::header('a b;c', 'my name', []);

        $this->assertStringStartsWith('my%20name=a%20b%3Bc;', $cookie);
        $this->assertStringNotContainsString('a b;c', $cookie);
    }
}
