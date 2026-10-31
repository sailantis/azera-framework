<?php

declare(strict_types=1);

namespace Azera\Security;

use Azera\AppContext;
use Azera\Core\MiddlewareInterface;
use Azera\Http\Response;
use Azera\Http\Session;

/**
 * CSRF protection middleware using the synchronizer (double-submit) token
 * pattern.
 *
 * For state-changing requests (POST, PUT, PATCH, DELETE) the middleware
 * validates a token submitted by the client against the one stored in
 * the session. The token is generated lazily and exposed to view layers
 * via {@see CsrfMiddleware::token()} and the `csrf_token` session key.
 *
 * GET, HEAD, and OPTIONS requests are always allowed through.
 *
 * The middleware is opt-in: register it in the pipeline only when you
 * want CSRF protection. It carries no cost when not wired.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /** Session key under which the token is stored. */
    public const SESSION_KEY = '_csrf_token';

    /** Default name of the cookie / form field / header carrying the token. */
    public const TOKEN_NAME = '_csrf_token';

    /** Default request methods that require a valid token. */
    private const GUARDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param string $tokenName Name of the field / header / cookie that
     *   carries the submitted token. Defaults to {@see TOKEN_NAME}.
     * @param array<int,string> $guardedMethods HTTP methods that require
     *   a valid token. Defaults to the common state-changing methods.
     */
    public function __construct(
        private string $tokenName = self::TOKEN_NAME,
        private array $guardedMethods = self::GUARDED_METHODS,
    ) {}

    public function process(AppContext $context, callable $next): ?Response
    {
        $request = $context->request();
        $session = $context->session();

        // Guard on the method the client actually used on the wire, not the
        // (attacker-controllable) overridden one. A cross-site form POST that
        // carries _method=GET must still be required to present a token.
        $method = $this->effectiveMethod($request);

        // Without a session there is nothing to store a token against;
        // fail closed for state-changing requests, pass through others.
        if ($session === null) {
            if (in_array($method, $this->guardedMethods, true)) {
                return $this->denied($method);
            }
            return $next($context);
        }

        // Ensure a token always exists so views can render it.
        $token = $this->ensureToken($session);

        if (!in_array($method, $this->guardedMethods, true)) {
            return $next($context);
        }

        $submitted = $this->extractToken($request, $session);

        if ($submitted === null || !hash_equals($token, $submitted)) {
            return $this->denied($method);
        }

        return $next($context);
    }

    /**
     * Determine the HTTP method used for CSRF guarding.
     *
     * The method the client really used is authoritative. `Request::method()`
     * honours `_method` / `X-HTTP-Method-Override`, which an attacker who can
     * craft a cross-site form submission controls. An override may therefore
     * never downgrade a state-changing request into an unguarded one: if the
     * underlying method is guarded, it stays guarded.
     *
     * @param \Azera\Http\Request $request
     * @return string Effective method, uppercased.
     */
    private function effectiveMethod($request): string
    {
        // Use the method recorded on the request object only. In a worker
        // (RoadRunner, Volucer) $_SERVER describes the worker process, not the
        // request being handled, so reading it here would be wrong.
        $raw = strtoupper((string) $request->server('REQUEST_METHOD', ''));

        if ($raw !== '' && in_array($raw, $this->guardedMethods, true)) {
            return $raw;
        }

        // Either an unguarded transport method (GET/HEAD/…) or no method at
        // all. Fall back to the effective method, which can only escalate to a
        // guarded method — never de-escalate.
        return strtoupper((string) $request->method());
    }

    /**
     * Get (and lazily generate) the current CSRF token from the session.
     *
     * @param Session $session
     * @return string The CSRF token.
     */
    public function ensureToken(Session $session): string
    {
        $token = $session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Pull the submitted token from the request, checking, in order:
     * the POST body, the request header, and the cookie.
     *
     * @param \Azera\Http\Request $request
     * @param Session             $session
     * @return string|null The submitted token or null when absent.
     */
    private function extractToken($request, Session $session): ?string
    {
        $post = $request->post($this->tokenName);
        if (is_string($post) && $post !== '') {
            return $post;
        }

        $header = $request->header('X-CSRF-Token');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        // Double-submit cookie fallback.
        $cookie = $_COOKIE[$this->tokenName] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return null;
    }

    /**
     * Build the response for a failed CSRF validation.
     *
     * Defaults to a 419 "Authentication Timeout" response (the de-facto
     * standard for CSRF failures) with a small JSON body. Subclasses may
     * override this to render a custom error page.
     *
     * @param string $method The request method that was rejected.
     */
    protected function denied(string $method): Response
    {
        return Response::json(
            ['error' => 'CSRF token mismatch', 'method' => $method],
            419,
        );
    }
}