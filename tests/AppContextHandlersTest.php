<?php

namespace Azera\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use Azera\AppContext;
use Azera\Core\ErrorHandlerInterface;
use Azera\Core\NotFoundHandlerInterface;
use Azera\Core\PlainErrorHandler;
use Azera\Core\PlainNotFoundHandler;
use Azera\Http\Request;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;
use Throwable;

class AppContextHandlersTest extends TestCase
{
    protected function setUp(): void
    {
        AppContext::reset();
    }

    protected function tearDown(): void
    {
        AppContext::reset();
    }

    public function testTheNotFoundDefaultIsRegisteredAndUsable(): void
    {
        $ctx = AppContext::instance();

        $handler = $ctx->get(NotFoundHandlerInterface::class);

        $this->assertInstanceOf(PlainNotFoundHandler::class, $handler);
        $this->assertSame(404, $handler->for(new Request(server: []))->getStatus());
    }

    public function testTheErrorDefaultIsRegisteredAndLeaksNothing(): void
    {
        $ctx = AppContext::instance();

        $handler = $ctx->get(ErrorHandlerInterface::class);

        $this->assertInstanceOf(PlainErrorHandler::class, $handler);

        $response = $handler->for(
            new \RuntimeException('connection string: user=root password=hunter2'),
            new Request(server: []),
        );

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('hunter2', $response->getBody());
    }

    public function testAnApplicationHandlerReplacesTheDefault(): void
    {
        $ctx = AppContext::instance();

        $ctx->set(NotFoundHandlerInterface::class, new class implements NotFoundHandlerInterface {
            public function for(Request $request): Response
            {
                return Response::text('mine', 404);
            }
        });

        $handler = $ctx->get(NotFoundHandlerInterface::class);

        $this->assertNotInstanceOf(PlainNotFoundHandler::class, $handler);
        $this->assertSame('mine', $handler->for(new Request(server: []))->getBody());
    }

    public function testTheDefaultsAreLazyFactories(): void
    {
        $ctx  = AppContext::instance();
        $path = 'not built';

        $ctx->set(NotFoundHandlerInterface::class, static function () use (&$path) {
            $path = 'built';

            return new PlainNotFoundHandler();
        });

        // Registering a factory must not resolve it: a boot that never serves a
        // request must not build the handlers that request would have used.
        $this->assertSame('not built', $path);
        $this->assertNull($ctx->resolved(NotFoundHandlerInterface::class));

        $ctx->get(NotFoundHandlerInterface::class);

        $this->assertSame('built', $path);
    }
}
