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

    public function explodesWithMarkupAction(): Response
    {
        // Stands in for a message carrying user input -- a query fragment, a search
        // term, a header value. Diagnostic output embeds this in a page.
        throw new RuntimeException('bad value <script>alert(1)</script> & "quoted"');
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

    /**
     * Pin an ini switch for one test, restoring it afterwards.
     *
     * `display_errors` decides how much a failure discloses, and its default differs
     * between the FPM and CLI SAPIs and between distributions. A test that reads it
     * without pinning it asserts something about the machine it runs on.
     */
    private function withIni(string $name, string $value): void
    {
        $previous = ini_get($name);
        ini_set($name, $value);

        $this->restoreIni[$name] = $previous;
    }

    /** @var array<string, string|false> */
    private array $restoreIni = [];

    protected function tearDown(): void
    {
        foreach ($this->restoreIni as $name => $value) {
            ini_set($name, $value === false ? '' : (string) $value);
        }
        $this->restoreIni = [];
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
        //
        // Pinned off because `display_errors` is a deployment choice, not a property
        // of the Kernel: the CLI default varies by machine and distribution, and this
        // test is about the boundary, not about that switch.
        $this->withIni('display_errors', '0');

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

        // The production posture. With display_errors on the handler deliberately
        // returns the exception instead — that is the switch's whole purpose, and it
        // is asserted separately.
        $this->withIni('display_errors', '0');

        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('hunter2', $response->getBody());
        $this->assertStringNotContainsString('RuntimeException', $response->getBody());
    }

    /**
     * Diagnostic mode must not become an XSS vector.
     *
     * PHP's own `display_errors` echoes the exception message into a `text/html`
     * response unescaped when `html_errors` is off, so any message containing user
     * input executes. The framework cannot copy that: the message may be a query
     * fragment, a header value, or a search term the visitor controls. Unescaped
     * diagnostics are therefore served as `text/plain`, where nothing executes.
     */
    public function testDiagnosticOutputIsNeverServedAsUnescapedHTML(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesAction');
        $ctx->set(ErrorHandlerInterface::class, new \Azera\Core\PlainErrorHandler());

        $this->withIni('display_errors', '1');
        $this->withIni('html_errors', '0');

        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString('hunter2', $response->getBody(), 'diagnostics are the point of the switch');
        $this->assertStringContainsString(
            'text/plain',
            (string) $response->getHeader('Content-Type'),
            'the body is unescaped, so it must not be served as HTML',
        );
    }

    /**
     * And with `html_errors` on, it is HTML — so it must be escaped.
     */
    public function testDiagnosticHTMLModeEscapesTheMessage(): void
    {
        $ctx = $this->context();
        $ctx->router()->get('/boom', 'KernelController::explodesWithMarkupAction');
        $ctx->set(ErrorHandlerInterface::class, new \Azera\Core\PlainErrorHandler());

        $this->withIni('display_errors', '1');
        $this->withIni('html_errors', '1');

        $response = (new Kernel($ctx))->handle($this->request('/boom'));

        $this->assertStringContainsString('text/html', (string) $response->getHeader('Content-Type'));
        $this->assertStringContainsString('&lt;script&gt;', $response->getBody(), 'markup must be escaped');
        $this->assertStringNotContainsString('<script>', $response->getBody(), 'and must not execute');
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
