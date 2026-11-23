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
    private ?string $logFile = null;

    private string|false $previousErrorLog = false;

    /** @var array<string, string|false> ini switches this test pinned, with their originals. */
    private array $previousIni = [];

    protected function setUp(): void
    {
        AppContext::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->previousIni as $name => $value) {
            ini_set($name, $value === false ? '' : (string) $value);
        }
        $this->previousIni = [];

        $this->restoreErrorLog();
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

        // display_errors is On by default under CLI, which is the opposite of the
        // production default this test is about.
        $this->forceIni('display_errors', '0');
        $logFile = $this->redirectErrorLog();

        try {
            $response = $handler->for(
                new \RuntimeException('connection string: user=root password=hunter2'),
                new Request(server: []),
            );
        } finally {
            $this->restoreErrorLog();
        }

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('hunter2', $response->getBody());
    }

    /**
     * The default failure path must always leave a record.
     *
     * A bare 500 with no log entry is undisclosable and undiagnosable at once: the
     * only evidence is the SAPI access log reading `500 <bytes>`, which says
     * nothing about what threw or where. An application that never registers its
     * own handler — the common case, since it is optional — would otherwise have
     * no way to find out why a page is failing.
     */
    public function testTheErrorDefaultLogsWhatThrewAndWhere(): void
    {
        $logFile = $this->redirectErrorLog();

        try {
            (new PlainErrorHandler())->for(
                new \RuntimeException('database is down'),
                new Request(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/phpthunder/dashboard']),
            );
        } finally {
            $log = $this->readAndRestoreErrorLog();
        }

        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('database is down', $log, 'the message is what identifies the failure');
        $this->assertStringContainsString('/phpthunder/dashboard', $log, 'the route says which page broke');
        $this->assertMatchesRegularExpression(
            '#in .+:\d+#',
            $log,
            'the throw site is what makes the entry actionable',
        );
    }

    /**
     * Logging is a side effect of handling, never a second failure.
     *
     * `for()` runs inside the Kernel's catch block. A throw here would replace a
     * handled 500 with an empty response and no status handling at all.
     */
    public function testALoggingFailureStillReturnsTheErrorResponse(): void
    {
        $this->forceIni('display_errors', '0');
        $original = ini_set('error_log', '/nonexistent-directory/azera-test/cannot-write.log');

        try {
            $response = (new PlainErrorHandler())->for(new \RuntimeException('boom'), new Request());
        } finally {
            ini_set('error_log', $original === false ? '' : $original);
        }

        $this->assertSame(500, $response->getStatus());
        $this->assertSame("500 Internal Server Error\n", $response->getBody());
    }

    /**
     * `log_errors` is the switch PHP uses for its own fatal log, so honour it here
     * too: an operator who turned it off asked for silence, and the framework
     * overriding that is not "better defaults", it is ignoring configuration.
     */
    public function testLogErrorsOffSilencesTheLog(): void
    {
        $this->forceIni('display_errors', '0');
        $this->redirectErrorLog();
        $this->forceIni('log_errors', '0');

        try {
            $response = (new PlainErrorHandler())->for(new \RuntimeException('quiet'), new Request());
        } finally {
            $log = $this->readAndRestoreErrorLog();
        }

        $this->assertSame('', $log, 'log_errors=Off must produce no log entry');
        $this->assertSame(500, $response->getStatus());
    }

    /**
     * With `display_errors` on, the exception is the response.
     *
     * A bare 500 forces a trip to the log for every failure, which is the friction
     * that leads to developer mode being left on in staging. This is the switch
     * that is meant to trade disclosure for that convenience.
     */
    public function testDisplayErrorsOnReturnsTheException(): void
    {
        $this->forceIni('display_errors', '1');
        $this->forceIni('html_errors', '0');
        $this->redirectErrorLog();

        try {
            $response = (new PlainErrorHandler())->for(
                new \RuntimeException('could not reach the queue'),
                new Request(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x']),
            );
        } finally {
            $this->restoreErrorLog();
        }

        $body = $response->getBody();

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString('RuntimeException', $body);
        $this->assertStringContainsString('could not reach the queue', $body);
        $this->assertMatchesRegularExpression(
            '#in .+:\d+#',
            $body,
            'the throw site is the part that makes the page usable for debugging',
        );
    }

    /**
     * `html_errors=1` must escape. PHP's own plain-text mode echoes the message
     * into a `text/html` response unescaped, which turns any exception message
     * containing user input into reflected XSS. That is a bug, not behaviour worth
     * copying.
     */
    public function testDisplayErrorsWithHtmlEscapesTheMessage(): void
    {
        $this->forceIni('display_errors', '1');
        $this->forceIni('html_errors', '1');
        $this->redirectErrorLog();

        try {
            $response = (new PlainErrorHandler())->for(
                new \RuntimeException('bad value <script>alert(1)</script>'),
                new Request(),
            );
        } finally {
            $this->restoreErrorLog();
        }

        $body = $response->getBody();

        $this->assertStringNotContainsString('<script>', $body, 'the message must not execute');
        $this->assertStringContainsString('&lt;script&gt;', $body, 'it should still be readable');
    }

    /**
     * Plain mode answers as text/plain, so the body cannot execute even if the
     * message contains markup.
     */
    public function testDisplayErrorsWithoutHtmlDoesNotClaimToBeHTML(): void
    {
        $this->forceIni('display_errors', '1');
        $this->forceIni('html_errors', '0');
        $this->redirectErrorLog();

        try {
            $response = (new PlainErrorHandler())->for(
                new \RuntimeException('<script>alert(1)</script>'),
                new Request(),
            );
        } finally {
            $this->restoreErrorLog();
        }

        $this->assertStringContainsString(
            'text/plain',
            (string) $response->getHeader('Content-Type'),
            'unescaped output must not be served as HTML',
        );
    }

    /**
     * The ini switches are read with PHP's own rules, not a naive cast.
     *
     * `ini_get()` returns a string, and its accepted forms are not one canonical
     * shape: `"On"`, `"yes"`, `"2"` and `"-1"` all mean on; `"Off"`, `"false"`, `""`
     * and an unrecognised word all mean off. `boolval("Off")` is true, so a cast
     * logs when the operator asked for silence. `filter_var(FILTER_VALIDATE_BOOLEAN)`
     * gets the words right but answers false for `"2"`, where PHP logs — so relying
     * on it would lose the record of a failure in a configuration PHP considers on.
     *
     * Every expectation below was measured against PHP itself with a real
     * `trigger_error()`, not assumed from the documentation.
     */
    public function testIniSwitchesFollowPHPsOwnRules(): void
    {
        $this->forceIni('display_errors', '0');
        $this->redirectErrorLog();

        $expectations = [
            '1'       => true,
            '0'       => false,
            'On'      => true,
            'Off'     => false,
            'yes'     => true,
            'no'      => false,
            'true'    => true,
            'false'   => false,
            ''        => false,
            'garbage' => false,
            'stderr'  => false,
            '-1'      => true,
            '2'       => true,
            '10'      => true,
            'ON'      => true,
            '  Off  ' => false,
        ];

        foreach ($expectations as $value => $shouldLog) {
            ini_set('log_errors', (string) $value);
            @unlink((string) $this->logFile);

            (new PlainErrorHandler())->for(new \RuntimeException('probe'), new Request());

            clearstatcache(true, (string) $this->logFile);
            $logged = is_file((string) $this->logFile) && filesize((string) $this->logFile) > 0;

            $this->assertSame(
                $shouldLog,
                $logged,
                sprintf(
                    'log_errors=%s should %s, matching PHP itself',
                    var_export((string) $value, true),
                    $shouldLog ? 'log' : 'stay silent',
                ),
            );
        }
    }

    /**
     * `error_reporting` is deliberately not consulted. PHP applies it only to its
     * own uncaught-fatal log line, a code path any installed handler disables — so
     * gating on it would emulate something that never runs. Worse, libraries set it
     * to 0 around noisy calls, so reading it at throw time would let an unrelated
     * library silently delete the record of a later failure.
     */
    public function testErrorReportingDoesNotSilenceTheLog(): void
    {
        $this->forceIni('display_errors', '0');
        $this->redirectErrorLog();
        $this->forceIni('error_reporting', '0');

        try {
            (new PlainErrorHandler())->for(new \RuntimeException('still recorded'), new Request());
        } finally {
            $log = $this->readAndRestoreErrorLog();
        }

        $this->assertStringContainsString(
            'still recorded',
            $log,
            'a muted error_reporting must not delete the only record of the failure',
        );
    }

    /** @return string The log file this test should read. */
    private function redirectErrorLog(): string
    {
        $this->logFile = sys_get_temp_dir() . '/azera-plain-error-' . getmypid() . '.log';
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
        $this->forceIni('log_errors', '1');

        return $this->logFile;
    }

    /**
     * Pin every ini switch this handler reads, so a test never depends on the SAPI
     * it happens to run under. The CLI defaults differ from FPM: `display_errors`
     * is on there, which would make the "leaks nothing" assertion below fail for a
     * reason that has nothing to do with the code.
     */
    private function forceIni(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->previousIni)) {
            $this->previousIni[$name] = ini_get($name);
        }
        ini_set($name, $value);
    }

    private function readAndRestoreErrorLog(): string
    {
        clearstatcache(true, (string) $this->logFile);
        $log = is_file((string) $this->logFile) ? (string) file_get_contents((string) $this->logFile) : '';
        $this->restoreErrorLog();

        return $log;
    }

    private function restoreErrorLog(): void
    {
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
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
