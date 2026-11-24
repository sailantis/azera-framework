<?php

declare(strict_types=1);

namespace Azera\Tests\Http;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\AppContext;
use Azera\Http\Flash;
use Azera\Http\Session;
use PHPUnit\Framework\TestCase;

/**
 * The one-request message bag.
 *
 * The load-bearing behaviour is the request boundary: a message written while
 * handling a redirect must appear on the *next* request and not on the one that
 * wrote it, and it must be gone after that. A flash that leaks into the request
 * that wrote it, or survives two renders, is the bug this file exists to catch.
 *
 * `Flash::age()` is that boundary, so most tests drive two real PHP sessions
 * instead of hand-building the internal state — the state layout is an
 * implementation detail, the visible behaviour is not.
 */
class FlashLifecycleTest extends TestCase
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

    /** An in-memory bag with no session — the CLI/test case. */
    private function memory(): Flash
    {
        return new Flash();
    }

    /** A session-backed bag, aged as the start of a request would age it. */
    private function sessionFlash(): Flash
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::assertTrue(@session_start());
        }

        $session = new Session($_SESSION);

        return new Flash($session);
    }

    // --- Boundary ------------------------------------------------------------

    public function testMessageQueuedForTheNextRequestIsNotVisibleYet(): void
    {
        $flash = $this->memory();
        $flash->success('Saved.');

        $this->assertFalse($flash->has());
        $this->assertSame([], $flash->consume());
        $this->assertCount(1, $flash->pending());
    }

    public function testQueuedMessageBecomesVisibleAfterAging(): void
    {
        $session = new Session($_SESSION);
        (new Flash($session))->success('Saved.');

        Flash::age($session);

        $messages = (new Flash($session))->consume();

        $this->assertSame([['type' => 'success', 'message' => 'Saved.']], $messages);
    }

    public function testMessageIsGoneAfterItWasConsumed(): void
    {
        $session = new Session($_SESSION);
        (new Flash($session))->success('Saved.');

        Flash::age($session);
        (new Flash($session))->consume();
        Flash::age($session);

        $this->assertSame([], (new Flash($session))->consume());
    }

    /**
     * The second age must drop the message rather than promote it again — that is
     * the whole point of two buckets, and one bucket would fail here.
     */
    public function testASecondAgeDoesNotResurrectAConsumedMessage(): void
    {
        $session = new Session($_SESSION);
        (new Flash($session))->success('Saved.');

        Flash::age($session);                 // request 2: visible
        (new Flash($session))->consume();
        Flash::age($session);                 // request 3

        $this->assertSame([], (new Flash($session))->consume());
        $this->assertSame([], (new Flash($session))->pending());
    }

    public function testNowIsVisibleImmediatelyButNotAfterTheNextRequest(): void
    {
        $session = new Session($_SESSION);
        $flash   = new Flash($session);

        $flash->now('error', 'Fix the form.');

        $this->assertTrue($flash->has());
        $this->assertSame('Fix the form.', $flash->first()['message'] ?? null);

        Flash::age($session);
        $this->assertSame([], (new Flash($session))->consume());
    }

    public function testReflashCarriesAMessageOverOneMoreRequest(): void
    {
        $session = new Session($_SESSION);
        (new Flash($session))->success('Saved.');
        Flash::age($session);

        $flash = new Flash($session);
        $this->assertTrue($flash->has());
        $flash->reflash();

        Flash::age($session);
        $this->assertSame('Saved.', (new Flash($session))->first()['message'] ?? null);
    }

    // --- Types ---------------------------------------------------------------

    public function testTypeHelpersQueueTheMatchingType(): void
    {
        $flash = $this->memory();
        $flash->success('s');
        $flash->error('e');
        $flash->warning('w');
        $flash->notice('n');
        $flash->info('i');

        $this->assertSame(
            ['success', 'error', 'warning', 'notice', 'info'],
            array_column($flash->pending(), 'type')
        );
    }

    public function testConsumeCanFilterByType(): void
    {
        $flash = $this->memory();
        $flash->now('error', 'bad');
        $flash->now('success', 'good');

        $this->assertSame([['type' => 'error', 'message' => 'bad']], $flash->consume('error'));
        $this->assertSame([['type' => 'success', 'message' => 'good']], $flash->consume());
    }

    public function testHasCanFilterByType(): void
    {
        $flash = $this->memory();
        $flash->now('success', 'good');

        $this->assertTrue($flash->has('success'));
        $this->assertFalse($flash->has('error'));
    }

    public function testConsumeWithoutConsumingKeepsTheMessages(): void
    {
        $flash = $this->memory();
        $flash->now('success', 'good');

        $this->assertCount(1, $flash->consume(null, false));
        $this->assertCount(1, $flash->consume(null, false));
        $this->assertCount(1, $flash->consume());
        $this->assertCount(0, $flash->consume());
    }

    public function testFirstRemovesOnlyTheMessageItReturns(): void
    {
        $flash = $this->memory();
        $flash->now('success', 'one');
        $flash->now('error', 'two');

        $this->assertSame('one', $flash->first()['message'] ?? null);
        $this->assertSame([['type' => 'error', 'message' => 'two']], $flash->consume());
    }

    public function testFirstWithoutConsumingKeepsTheMessage(): void
    {
        $flash = $this->memory();
        $flash->now('success', 'one');

        $this->assertSame('one', $flash->first(null, false)['message'] ?? null);
        $this->assertTrue($flash->has());
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->memory()->first());
    }

    public function testClearDropsVisibleAndQueuedMessages(): void
    {
        $flash = $this->memory();
        $flash->success('queued');
        $flash->now('error', 'shown');

        $flash->clear();

        $this->assertFalse($flash->has());
        $this->assertSame([], $flash->pending());
    }

    // --- Rendering -----------------------------------------------------------

    public function testRenderEscapesTheMessageByDefault(): void
    {
        $flash = $this->memory();
        $flash->now('error', '<script>alert(1)</script>');

        $html = $flash->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderCanBeToldNotToEscape(): void
    {
        $flash = $this->memory();
        $flash->now('success', '<strong>Saved</strong>');

        $html = $flash->setAutoescape(false)->render();

        $this->assertStringContainsString('<strong>Saved</strong>', $html);
    }

    /**
     * The default template is framework-neutral, but the type still selects a
     * distinct class — `error` must not render as `success`.
     */
    public function testDefaultClassesMapErrorToDanger(): void
    {
        $flash = $this->memory();
        $flash->now('error', 'bad');
        $flash->now('notice', 'heads up');

        $html = $flash->render();

        $this->assertStringContainsString('class="danger"', $html);
        $this->assertStringContainsString('class="info"', $html);
    }

    public function testUnknownTypeUsesItsOwnNameAsClass(): void
    {
        $flash = $this->memory();
        $flash->now('primary', 'hi');

        $this->assertStringContainsString('class="primary"', $flash->render());
    }

    public function testCssClassesAndTemplateCanBeReplaced(): void
    {
        $flash = $this->memory();
        $flash
            ->setCssClasses(['error' => 'alert alert-danger'])
            ->setTemplate('<p class="%class%" data-type="%type%">%message%</p>');

        $flash->now('error', 'bad');

        $this->assertSame(
            '<p class="alert alert-danger" data-type="error">bad</p>',
            $flash->render()
        );
    }

    public function testRenderEmitsOneBlockPerMessageInOrder(): void
    {
        $flash = $this->memory();
        $flash->now('success', 'one');
        $flash->now('error', 'two');

        $html = $flash->render();

        $this->assertSame(2, substr_count($html, 'role="alert"'));
        $this->assertLessThan(strpos($html, 'two'), strpos($html, 'one'));
    }

    public function testRenderCanTakeAnExplicitListWithoutTouchingTheStore(): void
    {
        $flash = $this->memory();
        $flash->now('success', 'kept');

        $html = $flash->render([['type' => 'success', 'message' => 'explicit']]);

        $this->assertStringContainsString('explicit', $html);
        $this->assertTrue($flash->has(), 'an explicit list must not consume the store');
    }

    // --- Storage -------------------------------------------------------------

    /**
     * An emptied bag leaves no key behind, so an idle session does not accumulate
     * an empty array on every request.
     */
    public function testEmptyStateRemovesTheSessionKey(): void
    {
        $session = new Session($_SESSION);
        (new Flash($session))->now('success', 'x');
        (new Flash($session))->consume();

        $this->assertFalse($session->has(Flash::SESSION_KEY));
    }

    public function testAgeWithNoQueuedMessagesClearsTheKey(): void
    {
        $session = new Session($_SESSION);
        $session->set(Flash::SESSION_KEY, ['old' => [['type' => 'success', 'message' => 'x']], 'new' => []]);

        Flash::age($session);

        $this->assertFalse($session->has(Flash::SESSION_KEY));
    }

    public function testCorruptSessionStateIsIgnored(): void
    {
        $session = new Session($_SESSION);
        $session->set(Flash::SESSION_KEY, 'not an array');

        $flash = new Flash($session);

        $this->assertFalse($flash->has());
        $flash->success('queued');
        $this->assertCount(1, $flash->pending());
    }

    public function testAgeWithoutASessionIsANoOp(): void
    {
        Flash::age(null);

        $this->assertSame([], $_SESSION);
    }

    // --- AppContext integration ----------------------------------------------

    /**
     * `AppContext::flash()` must age on construction, or the messages written
     * before a redirect would never become visible. The bag is built exactly once
     * per request, so a queued message is promoted on that single build.
     */
    public function testAppContextAgesWhenItBuildsTheFlash(): void
    {
        AppContext::reset();
        $context = AppContext::instance();
        $session = new Session($_SESSION);
        $context->setSession($session);

        // Previous request: queue a message for the one that follows.
        (new Flash($session))->success('Saved.');

        $this->assertSame('Saved.', $context->flash()->first()['message'] ?? null);

        AppContext::reset();
    }

    /**
     * The bag must not be pinned to "no session" when something touches it first.
     *
     * A middleware ordered before `SessionMiddleware` is exactly that case: it
     * resolves the bag, the session arrives later, and the queued message still
     * has to be promoted and visible.
     */
    public function testFlashResolvesTheSessionLazily(): void
    {
        AppContext::reset();
        $context = AppContext::instance();

        // Resolved before any session exists.
        $bag = $context->flash();
        $this->assertFalse($bag->has());

        $session = new Session($_SESSION);
        (new Flash($session))->success('Arrived late.');
        $context->setSession($session);

        $this->assertSame('Arrived late.', $bag->first()['message'] ?? null);

        AppContext::reset();
    }

    /**
     * The age must happen once, even when the bag is read repeatedly: a second
     * promotion would resurrect a message the client already saw.
     */
    public function testFlashAgesOnlyOncePerRequest(): void
    {
        AppContext::reset();
        $context = AppContext::instance();
        $session = new Session($_SESSION);
        $context->setSession($session);

        (new Flash($session))->success('Once.');
        $bag = $context->flash();

        $this->assertSame('Once.', $bag->first()['message'] ?? null);
        $this->assertFalse($bag->has(), 'a second read must not promote it again');
        $this->assertSame([], $bag->pending());

        AppContext::reset();
    }

    public function testAppContextReturnsTheSameBagForOneRequest(): void
    {
        AppContext::reset();
        $context = AppContext::instance();
        $context->setSession(new Session($_SESSION));

        $this->assertSame($context->flash(), $context->flash());

        AppContext::reset();
    }

    public function testAppContextClearsTheFlashBetweenRequests(): void
    {
        AppContext::reset();
        $context = AppContext::instance();
        $context->setSession(new Session($_SESSION));

        $first = $context->flash();
        $first->now('success', 'x');

        $context->clearRequestScope();
        $context->setSession(new Session($_SESSION));

        $this->assertNotSame($first, $context->flash());
        $this->assertFalse($context->flash()->has(), 'the old bag must not leak into the next request');

        AppContext::reset();
    }

    public function testFlashWorksWithoutASession(): void
    {
        AppContext::reset();
        $context = AppContext::instance();

        $context->flash()->now('success', 'no session needed');

        $this->assertTrue($context->flash()->has());

        AppContext::reset();
    }
}
