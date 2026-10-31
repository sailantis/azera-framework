<?php

namespace Azera\Http;

/**
 * Cookie jar that manages a collection of {@see Cookie} instances for the current request.
 *
 * Acts as a central registry for reading incoming cookies and building/sending
 * outgoing Set-Cookie headers.
 */
class Cookies
{
    /** @var array<string, Cookie> */
    protected array $cookies = [];

    /**
     * Read a cookie value from the incoming request.
     *
     * If the cookie was set in this request via {@see set()}, its in-memory value is
     * returned; otherwise the value is read from $_COOKIE.
     *
     * For encrypted cookies the value is only ever returned when it decrypts
     * and authenticates successfully. The raw cookie is never returned as a
     * fallback: doing so would let an attacker supply a plaintext cookie and
     * have it accepted verbatim, defeating the integrity check entirely.
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

        return $_COOKIE[$name] ?? $default;
    }

    /**
     * Get (or lazily create) a {@see Cookie} instance for the given name.
     *
     * Use this when you need to configure encryption, path, etc. before reading
     * or sending the cookie.
     *
     * @param string $name Cookie name.
     * @return Cookie
     */
    public function cookie(string $name): Cookie
    {
        return $this->cookies[$name]
            ??= new Cookie($name);
    }

    /**
     * Create and register a new {@see Cookie} with the given parameters.
     *
     * The cookie is not sent until {@see sendAll()} (or {@see Cookie::send()}) is called.
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
        $cookie = new Cookie($name, $value, $expires, $path, $domain, $secure, $httpOnly);
        $this->cookies[$name] = $cookie;
        return $cookie;
    }

    /**
     * Delete a cookie by emitting a Set-Cookie header with an expiration in the past.
     *
     * @param string $name Cookie name.
     */
    public function delete(string $name): void
    {
        if (isset($this->cookies[$name])) {
            $this->cookies[$name]->delete();
        } else {
            (new Cookie($name))->delete();
        }
    }

    /**
     * Send all registered cookies by emitting their Set-Cookie headers.
     */
    public function sendAll(): void
    {
        foreach ($this->cookies as $cookie) {
            $cookie->send();
        }
    }
}
