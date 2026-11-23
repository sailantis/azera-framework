<?php

declare(strict_types=1);

namespace Azera\Tests\Http;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Session;
use PHPUnit\Framework\TestCase;

/**
 * The session lifecycle helpers: `regenerateId()`, `destroy()`, `id()`,
 * `isActive()`.
 *
 * These exist because the wrapper exposed only the value accessors, so every
 * application reached past it into `session_regenerate_id()` / `session_destroy()`
 * and then had to remember that `session_destroy()` leaves `$_SESSION` populated
 * for the rest of the request.
 *
 * Each test starts from a known session state: the helpers are no-ops when no
 * session is active, so leaving one active from a previous test would make a
 * "does nothing" assertion pass for the wrong reason.
 */
class SessionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        $this->endSession();
    }

    protected function tearDown(): void
    {
        $this->endSession();
    }

    private function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }

        $_SESSION   = [];
        session_id('');
    }

    private function startSession(): Session
    {
        self::assertTrue(@session_start(), 'precondition: a session can be started');

        return new Session($_SESSION);
    }

    // --- isActive() ---------------------------------------------------------

    public function testIsActiveIsFalseWithoutASession(): void
    {
        $session = new Session($_SESSION);

        $this->assertFalse($session->isActive());
    }

    public function testIsActiveIsTrueOnceStarted(): void
    {
        $this->assertTrue($this->startSession()->isActive());
    }

    // --- id() ---------------------------------------------------------------

    public function testIdIsEmptyWithoutASession(): void
    {
        $session = new Session($_SESSION);

        $this->assertSame('', $session->id());
    }

    public function testIdMatchesThePhpSessionId(): void
    {
        $session = $this->startSession();

        $this->assertSame(session_id(), $session->id());
        $this->assertNotSame('', $session->id());
    }

    // --- regenerateId() -----------------------------------------------------

    public function testRegenerateIdIssuesANewIdAndKeepsTheData(): void
    {
        $session = $this->startSession();
        $session->set('user_id', 42);
        $before = $session->id();

        $this->assertTrue($session->regenerateId());

        $this->assertNotSame($before, $session->id());
        $this->assertSame(42, $session->get('user_id'));
        $this->assertSame(session_id(), $session->id());
    }

    public function testRegenerateIdIsANoOpWithoutASession(): void
    {
        $session = new Session($_SESSION);

        $this->assertFalse($session->regenerateId());
        $this->assertSame('', $session->id());
    }

    // --- destroy() ----------------------------------------------------------

    /**
     * The reason this helper exists: PHP drops the server-side data but leaves the
     * superglobal intact, so a read after `session_destroy()` would still return
     * the logged-out user's data. Both halves must be gone afterwards.
     */
    public function testDestroyEmptiesTheStoreAndEndsTheSession(): void
    {
        $session = $this->startSession();
        $session->set('user_id', 42);

        $session->destroy();

        $this->assertFalse($session->isActive());
        $this->assertNull($session->get('user_id'));
        $this->assertSame([], $_SESSION);
        $this->assertSame('', $session->id());
    }

    public function testDestroyIsANoOpWithoutASession(): void
    {
        $session = new Session($_SESSION);

        $session->destroy();

        $this->assertSame([], $_SESSION);
        $this->assertFalse($session->isActive());
    }

    /**
     * The store is held by reference, so data written after `clear()` still lands
     * in the superglobal — the behaviour every other accessor relies on.
     */
    public function testClearKeepsTheStoreWiredToTheSuperglobal(): void
    {
        $session = $this->startSession();
        $session->set('a', 1);

        $session->clear();
        $this->assertSame([], $_SESSION);

        $session->set('b', 2);
        $this->assertSame(['b' => 2], $_SESSION);
    }
}
