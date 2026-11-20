<?php

namespace Azera\Core;

use Azera\Http\Request;
use Azera\Http\Response;

/**
 * The application's answer for a request that matched no route.
 *
 * Registered on {@see \Azera\AppContext} so the policy exists once, whichever
 * transport is serving: the FPM entry point, a RoadRunner worker and a Volucer
 * worker all resolve the same instance instead of each carrying its own copy.
 *
 * Deliberately separate from {@see ErrorHandlerInterface}. A not-found handler
 * decides what a missing URL looks like; letting it also see exceptions would
 * make it possible for a `TypeError` to be answered by a 404 policy.
 */
interface NotFoundHandlerInterface
{
    /**
     * Build the response for a request that matched no route.
     *
     * The response is returned, never sent: a persistent worker writes it to its
     * own transport, and only the FPM entry point calls `send()` on the result.
     *
     * @param Request $request The request that did not match.
     * @return Response The response to send to the client.
     */
    public function for(Request $request): Response;
}
