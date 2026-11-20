<?php

namespace Azera\Core;

use Azera\Http\Request;
use Azera\Http\Response;
use Throwable;

/**
 * A bare 500 that discloses nothing, and the default.
 *
 * The throwable is not rendered and not echoed: its message may contain a
 * connection string, a file path, or user input that reached an exception
 * constructor. An application that wants a diagnostic body registers its own
 * {@see ErrorHandlerInterface}, which is also where logging belongs — the handler
 * is resolved from the container, so it can take a PSR-3 logger as a dependency.
 */
final class PlainErrorHandler implements ErrorHandlerInterface
{
    public function for(Throwable $error, Request $request): Response
    {
        return Response::text("500 Internal Server Error\n", 500);
    }
}
