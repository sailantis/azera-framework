<?php

namespace Azera\Core;

use Azera\AppContext;
use Azera\Http\Cookies;
use Azera\Http\Request;
use Azera\Http\Response;
use Throwable;

/**
 * Request in, response out.
 *
 * The optional coordinator around {@see Router} and {@see Dispatcher}. It owns the
 * three decisions every transport would otherwise repeat: what a missing route
 * answers, what a wrong method answers, and what an exception answers.
 *
 * It is optional on purpose. `Router::match()` and `Dispatcher::dispatch()` stay
 * public and independently callable, so an application can keep calling them in
 * sequence and never build a Kernel. What such an application gives up is the
 * error boundary below — under FPM an uncaught exception is a fatal error, and
 * nothing in the framework installs a handler for it.
 *
 * Nothing here sends output. A persistent worker reads the response through its
 * getters; only the FPM entry point calls `send()` on what this returns.
 */
final class Kernel
{
    /**
     * @param AppContext                    $context    The application context the handlers resolve from.
     * @param Router|null                   $router     The router to match with; the context's when omitted.
     * @param Dispatcher|null               $dispatcher The dispatcher to invoke; the context's when omitted.
     * @param NotFoundHandlerInterface|null $notFound   Overrides the context's 404 policy; intended for tests.
     * @param ErrorHandlerInterface|null    $error      Overrides the context's error policy; intended for tests.
     */
    public function __construct(
        private readonly AppContext $context,
        private readonly ?Router $router = null,
        private readonly ?Dispatcher $dispatcher = null,
        private readonly ?NotFoundHandlerInterface $notFound = null,
        private readonly ?ErrorHandlerInterface $error = null,
    ) {}

    /**
     * Handle one request and return the response to send.
     *
     * A route that matches is dispatched. A path that matches nothing but would
     * serve another method answers 405 with an `Allow` header rather than 404 —
     * the router can tell the two apart, so this does. Anything thrown is passed
     * to the error handler.
     *
     * An exception has no rethrow mode. Rethrowing would mean the same failure is
     * a rendered 500 in one deployment and a fatal error in another, which is the
     * split this class exists to end: the context always has a handler, because
     * {@see PlainErrorHandler} is registered as its default. An application that
     * wants diagnostics replaces that default; it does not disable the boundary.
     */
    public function handle(Request $request): Response
    {
        // Publish the request the caller handed us. Controllers, middleware and the
        // view layer all reach the current request through the context
        // (`AppContext::request()`, `Controller::request()`), so a transport that
        // built a Request and passed it here would otherwise leave every one of them
        // resolving a different — usually empty — instance. Done once, at the single
        // entry point, so no transport has to remember to do it.
        $this->context->set(Request::class, $request);

        try {
            // The router and dispatcher are taken from the constructor when given:
            // a worker may be handed its own instances instead of the context's,
            // and matching against the wrong one would 404 every request.
            $router = $this->router ?? $this->context->router();
            $route  = $router->match($request->path(), $request->method());

            if ($route !== null) {
                $dispatcher = $this->dispatcher ?? $this->context->dispatcher();

                return $this->finalize($dispatcher->dispatch($route));
            }

            $allowed = $router->allowedMethods($request->path());

            return $this->finalize(
                $allowed === null
                    ? $this->notFound($request)
                    : Response::text("405 Method Not Allowed\n", 405)->addHeader('Allow', implode(', ', $allowed)),
            );
        } catch (Throwable $error) {
            // `dd()` in halt mode is a control-flow signal, not a failure: the
            // rendered dump *is* the response, and only the worker knows how to
            // wrap it. Treating it as an error would replace the dump with a 500.
            // Referenced by name so this file does not depend on Clarity.
            if ($error instanceof \Clarity\Debug\DumpHaltException) {
                throw $error;
            }

            return $this->finalize($this->errorHandler()->for($error, $request));
        }
    }

    /**
     * The not-found handler: the explicit override, else the context's.
     *
     * The fallback body is the same shape as {@see PlainNotFoundHandler}, and only
     * reachable if an application replaced the default with something that is not a
     * handler — a broken container entry should not turn a 404 into a fatal.
     */
    private function notFound(Request $request): Response
    {
        $handler = $this->notFound ?? $this->context->getOrNull(NotFoundHandlerInterface::class);

        return $handler instanceof NotFoundHandlerInterface
            ? $handler->for($request)
            : (new PlainNotFoundHandler())->for($request);
    }

    /**
     * The error handler: the explicit override, else the context's.
     *
     * The context always resolves one, because {@see PlainErrorHandler} is a default
     * service. The local fallback only covers an application that replaced that
     * registration with something that is not a handler; a broken container entry
     * should not turn a handled failure back into a fatal.
     */
    private function errorHandler(): ErrorHandlerInterface
    {
        if ($this->error !== null) {
            return $this->error;
        }

        $handler = $this->context->getOrNull(ErrorHandlerInterface::class);

        return $handler instanceof ErrorHandlerInterface ? $handler : new PlainErrorHandler();
    }

    /**
     * Apply the cookies the application queued during this request.
     *
     * Every exit passes through here, including the 404, 405 and 500 paths, so a
     * response that is *not* the controller's still carries what the application
     * set. `Cookies::applyTo()` drains the jar, so a matched route that already
     * passed through {@see Dispatcher::dispatch()} is not affected twice.
     */
    private function finalize(Response $response): Response
    {
        $cookies = $this->context->resolved(Cookies::class);

        return $cookies instanceof Cookies ? $cookies->applyTo($response) : $response;
    }
}
