<?php

namespace Azera\Core;

use Azera\Http\Request;
use Azera\Http\Response;
use Throwable;

/**
 * The application's answer for a request that raised an exception.
 *
 * Separate from {@see NotFoundHandlerInterface} on purpose: this handler exists to
 * turn a failure into a response, and must not be shareable with the code that
 * decides what a missing URL looks like.
 *
 * Registering one is what gives a request a response instead of a fatal error.
 * Under FPM an uncaught exception terminates the process, so without this the
 * response is whatever PHP's error handler prints; under a persistent worker the
 * loop catches it and sends a bare 500. Both are replaced by the registered
 * handler, which can log, render and pick a status.
 */
interface ErrorHandlerInterface
{
    /**
     * Build the response for an exception raised while handling a request.
     *
     * The response is returned, never sent. Implementations decide how much of the
     * throwable is safe to show; {@see PlainErrorHandler} shows none of it.
     *
     * @param Throwable $error   The exception that escaped the pipeline.
     * @param Request   $request The request being handled when it escaped.
     * @return Response The response to send to the client.
     */
    public function for(Throwable $error, Request $request): Response;
}
