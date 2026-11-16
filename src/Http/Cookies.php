<?php

namespace Azera\Http;

/**
 * Cookie jar that manages a collection of {@see Cookie} instances for the current request.
 */
class Cookies
{
    /** @var array<string, Cookie> */
    protected array $cookies = [];

    /**
     * @param Request|null $request The request whose cookies this jar reads.
     */
    public function __construct(
        protected ?Request $request = null
    ) {}

    /**
     * Read a cookie value from this jar or the incoming request.
     *
     * A cookie obtained through {@see cookie()} uses its configured value and
     * encryption settings. Otherwise, the request value is returned unchanged.
     * Configure encrypted cookies before calling this method; the jar cannot infer
     * which cookie names require encryption:
     *
     *     $cookies->cookie('auth')->encrypted()->key($key);
     *     $value = $cookies->get('auth');
     *
     * @param string $name    Cookie name.
     * @param mixed  $default Default value when the cookie is absent.
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (isset($this->cookies[$name])) {
            return $this->cookies[$name]->value($default);
        }

        return $this->request?->cookie($name, $default) ?? $default;
    }

    /**
     * Get (or lazily create) a {@see Cookie} instance for the given name.
     *
     * Use this when you need to configure encryption, path, etc. before reading or
     * sending the cookie. The cookie is loaded with the incoming value, so
     * {@see Cookie::value()} decrypts it as usual.
     *
     * @param string $name Cookie name.
     * @return Cookie
     */
    public function cookie(string $name): Cookie
    {
        return $this->cookies[$name] ??= $this->newCookie($name);
    }

    /**
     * Create and register a new {@see Cookie} with the given parameters.
     *
     * The cookie is not sent until {@see applyTo()} drains the jar.
     *
     * @param string $name     Cookie name.
     * @param mixed  $value    Cookie value.
     * @param int    $expires  Expiration timestamp (0 = session cookie).
     * @param string $path     URL path scope.
     * @param string $domain   Domain scope.
     * @param bool   $secure   Send over HTTPS only.
     * @param bool   $httpOnly Inaccessible to JavaScript.
     * @return Cookie The newly created Cookie instance for further configuration.
     */
    public function set(
        string $name,
        mixed $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ): Cookie {
        $cookie = $this->newCookie($name, $expires, $path, $domain, $secure, $httpOnly);
        $cookie->set($value);

        return $this->cookies[$name] = $cookie;
    }

    /**
     * Delete a cookie by making the response carry a past-expiry `Set-Cookie`.
     *
     * Works for a cookie that was never registered, and does not depend on
     * `setcookie()` — a worker is not a SAPI, so `setcookie()` does nothing there.
     *
     * @param string $name Cookie name.
     */
    public function delete(string $name): void
    {
        $cookie = $this->cookies[$name] ?? $this->newCookie($name);

        $this->cookies[$name] = $cookie->delete();
    }

    /**
     * Drop every queued cookie so this response carries none.
     *
     * The counterpart to the automatic drain: writing a cookie and then deciding the
     * response should not carry it (a 304, an error path) needs an off switch.
     *
     * Reading is unaffected — the incoming values live on the {@see Request}, not in
     * this jar — so `clear()` only stops *emission*.
     */
    public function clear(): void
    {
        foreach ($this->cookies as $cookie) {
            $cookie->markClean();
        }

        $this->cookies = [];
    }

    /**
     * Write the cookies that changed in this request onto the response.
     *
     * Only dirty cookies are emitted: a cookie the application merely read must not
     * come back as a `Set-Cookie`. The dirty flag is cleared afterwards, so calling
     * this twice in one request cannot duplicate a header.
     *
     * Returns the same instance so a caller can chain it, and so
     * `Dispatcher::finalize()` can simply `return` the result.
     */
    public function applyTo(Response $response): Response
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie->isDirty()) {
                $response->addCookie($cookie);
            }
        }

        return $response;
    }

    /**
     * Build a cookie already loaded with the value the client sent.
     *
     * The incoming value is attached with {@see Cookie::setRaw()} so the cookie
     * starts clean: it is readable but not pending.
     */
    private function newCookie(
        string $name,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ): Cookie {
        $rawValue = $this->request?->cookie($name);

        return (new Cookie($name, null, $expires, $path, $domain, $secure, $httpOnly))
            ->setRaw(is_string($rawValue) ? $rawValue : null);
    }
}
