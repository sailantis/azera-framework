<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\AppContext;
use Azera\Core\Controller;
use Azera\Core\Kernel;
use Azera\Core\NotFoundHandlerInterface;
use Azera\Core\ErrorHandlerInterface;
use Azera\Http\Request;
use Azera\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class KernelController extends Controller
{
    public function okAction(): Response
    {
        return Response::text('ok');
    }

    public function explodesAction(): Response
    {
        throw new RuntimeException('boom with a secret: password=hunter2');
    }

    public function setsCookieAction(): Response
    {
        $this->cookies()->set('flavour', 'oatmeal');

        return Response::text('set');
    }
}

class KernelNotFound implements NotFoundHandlerInterface
{
    public function for(Request $request): Response
    {
        return Response::text('custom 404 for ' . $request->path(), 404);
    }
}

class KernelError implements ErrorHandlerInterface
{
    public function for(Throwable $error, Request $request): Response
    {
        return Response::text('custom 500: ' . $error->getMessage(), 500);
    }
}

class KernelTest extends TestCase
{
    private function context(): AppContext
    {
        AppContext::reset();

        $ctx = AppContext::instance();
        $ctx->dispatcher()->setBaseNamespace(__NAMESPACE__);
        $ctx->dispatcher()->setDefaultController('KernelController');

        return $ctx;
    }

    private function request(string $path = '/', string $method = 'GET'): Request
    {
        return new Request(
            server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method],
            get: [],
            post: [],
            files: [],
        );
    }

    // -------------------------------------------------------------------------
    //  Routing
    // -------------------------------------------------------------------------

    public function testAMatchedRouteIsDispatched(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/ok', 'KernelController::okAction');

        $response = (new Kernel($ctx))->handle($this->request('/ok'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ok', $response->getBody());
    }

    public function testAnUnknownPathUsesTheRegisteredNotFoundHandler(): void
    {
        $ctx = $this->context();
        $ctx->set(NotFoundHandlerInterface::class, new KernelNotFound());

        $response = (new Kernel($ctx))->handle($this->request('/missing'));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('custom 404 for /missing', $response->getBody());
    }

    public function testTheConstructorOverrideBeatsTheContainer(): void
    {
        $ctx = $this->context();
        $ctx->set(NotFoundHandlerInterface::class, new KernelNotFound());

        $response = (new Kernel($ctx, notFound: new class implements NotFoundHandlerInterface {
            public function for(Request $request): Response
            {
                return Response::text('from override', 404);
            }
        }))->handle($this->request('/missing'));

        $this->assertSame('from override', $response->getBody());
    }

    public function testAPathThatExistsUnderAnotherMethodAnswers405WithAllow(): void
    {
        $ctx = $this->context();
        $ctx->router()->post('/form', 'KernelController::okAction');

        $response = (new Kernel($ctx))->handle($this->request('/form', 'DELETE'));

        $this->assertSame(405, $response->getStatus());
        $this->assertSame('POST', $response->getHeader('Allow'));
    }

    public function testThe405AllowListMatchesTheRouter(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/thing', 'KernelController::okAction');
        $ctx->router()->post('/thing', 'KernelController::okAction');

        $response = (new Kernel($ctx))->handle($this->request('/thing', 'PUT'));

        $this->assertSame(
            implode(', ', $ctx->router()->allowedMethods('/thing')),
            $response->getHeader('Allow'),
        );
    }

    // -------------------------------------------------------------------------
    //  Error boundary
    // -------------------------------------------------------------------------

    public function testAThrowingActionBecomes500WhenAnErrorHandlerIsRegistered(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesAction');
        $ctx->set(ErrorHandlerInterface::class, new KernelError());

        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('custom 500: boom with a secret: password=hunter2', $response->getBody());
    }

    public function testNoErrorHandlerRegisteredStillAnswers500(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesAction');

        // The context registers PlainErrorHandler as a default, so the boundary is
        // always there. Rethrowing would make the same failure fatal under FPM and a
        // rendered 500 under a worker, which is the split the Kernel exists to end.
        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('hunter2', $response->getBody());
    }

    public function testANotFoundHandlerCannotSwallowAnException(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesAction');
        $ctx->set(NotFoundHandlerInterface::class, new KernelNotFound());

        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        // A 404 handler is not an error handler: it must not answer this, and the
        // response must not look like a missing URL.
        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('custom 404', $response->getBody());
    }

    public function testThePlainErrorHandlerLeaksNothing(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesAction');

        // The default is registered in the context, so an application that wants it
        // gets it without writing a handler.
        $ctx->set(ErrorHandlerInterface::class, new \Azera\Core\PlainErrorHandler());

        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('hunter2', $response->getBody());
        $this->assertStringNotContainsString('RuntimeException', $response->getBody());
    }

    // -------------------------------------------------------------------------
    //  Cookies apply to every exit
    // -------------------------------------------------------------------------

    public function testCookiesAreAppliedOnTheMatchedPath(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/cookie', 'KernelController::setsCookieAction');

        $response = (new Kernel($ctx))->handle($this->request('/cookie'));

        $this->assertStringContainsString(
            'flavour=oatmeal',
            implode("\n", $response->getHeaderValues('Set-Cookie')),
        );
    }

    public function testCookiesAreNotDuplicatedOnTheMatchedPath(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/cookie', 'KernelController::setsCookieAction');

        // Dispatcher::finalize() already drained the jar; the Kernel drains it again.
        $response = (new Kernel($ctx))->handle($this->request('/cookie'));

        $lines = array_filter(
            $response->getHeaderLines(),
            static fn(string $line): bool => str_starts_with($line, 'Set-Cookie:'),
        );

        $this->assertCount(1, $lines);
    }

    // -------------------------------------------------------------------------
    //  It must not send
    // -------------------------------------------------------------------------

    public function testHandleProducesNoOutput(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/ok', 'KernelController::okAction');

        ob_start();
        (new Kernel($ctx))->handle($this->request('/ok'));
        $output = ob_get_clean();

        // A persistent worker writes to its own transport; only the FPM entry calls
        // send(). Anything echoed here would be written into a capture buffer.
        $this->assertSame('', $output);
    }

    public function testHandleProducesNoOutputOnAnError(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesAction');
        $ctx->set(ErrorHandlerInterface::class, new KernelError());

        ob_start();
        (new Kernel($ctx))->handle($this->request('/boom'));
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }
}
