<?php

namespace Azera\Core;

use Azera\Http\Request;
use Azera\Http\Response;

/**
 * A bare 404, and the default.
 *
 * An application replaces this by registering its own
 * {@see NotFoundHandlerInterface} on {@see \Azera\AppContext}. The default exists so
 * that every entry point has something to call: without it, each transport would
 * keep inventing its own body, which is exactly the duplication this replaces.
 */
final class PlainNotFoundHandler implements NotFoundHandlerInterface
{
    public function for(Request $request): Response
    {
        return Response::text("404 Not Found\n", 404);
    }
}
