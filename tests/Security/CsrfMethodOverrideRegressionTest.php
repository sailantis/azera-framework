<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\AppContext;
use Azera\Http\Request;
use Azera\Http\Response;
use Azera\Http\Session;
use Azera\Security\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the CSRF method-override bypass of the 2026 security
 * review: a cross-site POST carrying `_method=GET` (or a method-override
 * header) used to skip the token check.
 */
class CsrfMethodOverrideRegressionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $sessionStore = [];

    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
        $this->sessionStore = [];
    }

    private function context(array $server = [], array $post = []): AppContext
    {
        $ctx = AppContext::instance();
        $ctx->set(Request::class, new Request($server, [], $post));
        $ctx->setSession(new Session($this->sessionStore));

        return $ctx;
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $post */
    private function attempt(array $server, array $post = []): array
    {
        $ctx    = $this->context($server, $post);
        $called = false;

        $result = (new CsrfMiddleware())->process($ctx, function () use (&$called) {
            $called = true;
            return null;
        });

        return [$called, $result];
    }

    public function testPostWithMethodOverrideToGetIsStillGuarded(): void
    {
        [$called, $result] = $this->attempt(
            ['REQUEST_METHOD' => 'POST'],
            ['_method' => 'GET', 'action' => 'delete']
        );

        $this->assertFalse($called, 'method override must not bypass CSRF');
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(419, $result->getStatus());
    }

    public function testPostWithMethodOverrideHeaderIsStillGuarded(): void
    {
        [$called, $result] = $this->attempt(
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_METHOD_OVERRIDE' => 'GET']
        );

        $this->assertFalse($called);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(419, $result->getStatus());
    }

    public function testPostWithOverrideAndValidTokenPasses(): void
    {
        $middleware = new CsrfMiddleware();

        $getCtx = $this->context(['REQUEST_METHOD' => 'GET']);
        $middleware->process($getCtx, fn() => null);
        $token = $this->sessionStore[CsrfMiddleware::SESSION_KEY];

        $ctx    = $this->context(
            ['REQUEST_METHOD' => 'POST'],
            ['_method' => 'PUT', CsrfMiddleware::TOKEN_NAME => $token]
        );
        $called = false;
        $result = $middleware->process($ctx, function () use (&$called) {
            $called = true;
            return null;
        });

        $this->assertTrue($called);
        $this->assertNull($result);
    }

    public function testGetStillPassesThrough(): void
    {
        [$called, $result] = $this->attempt(['REQUEST_METHOD' => 'GET']);

        $this->assertTrue($called);
        $this->assertNull($result);
    }

    public function testRealPostStillRequiresToken(): void
    {
        [$called, $result] = $this->attempt(['REQUEST_METHOD' => 'POST']);

        $this->assertFalse($called);
        $this->assertSame(419, $result->getStatus());
    }

    /**
     * `_method` only overrides POST in Request::method(), so a GET carrying
     * `_method=DELETE` is still a GET and stays unguarded — it cannot change
     * state. Documented here so the behaviour is not mistaken for a bypass.
     */
    public function testGetWithMethodOverrideIsStillAGet(): void
    {
        [$called, $result] = $this->attempt(
            ['REQUEST_METHOD' => 'GET'],
            ['_method' => 'DELETE']
        );

        $this->assertTrue($called);
        $this->assertNull($result);
    }
}
