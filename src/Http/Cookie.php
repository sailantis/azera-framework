<?php

namespace Azera\Http;

use Azera\Exception;
use Azera\Security\Crypt\CipherProviderInterface;
use Azera\Security\Crypt\CipherRegistry;

/**
 * Represents a single HTTP cookie with optional transparent encryption.
 *
 * Use the static {@see create()} factory or construct directly. Read the value
 * with {@see value()}, which handles decryption automatically, and turn the
 * cookie into a header line with {@see toHeader()}, then add it to a
 * {@see Response}.
 */
class Cookie
{
    protected string $name;
    protected mixed $value = null;
    protected bool $loaded = false;

    /**
     * The raw value the client sent, when the cookie was built from a request.
     */
    protected ?string $rawValue = null;

    /**
     * Whether this cookie needs a `Set-Cookie` on the response.
     */
    protected bool $dirty = false;

    /** Whether this cookie is queued for removal rather than for a value. */
    protected bool $deletePending = false;

    protected int $expires = 0;
    protected string $path = '/';
    protected string $domain = '';
    protected bool $secure = false;
    protected bool $httpOnly = true;

    protected bool $encrypted = false;
    protected ?string $cipher = null;
    protected ?string $key = null;

    /** Registry used for this cookie; created on first use so that per-cookie providers stay per-cookie. */
    protected ?CipherRegistry $registry = null;

    // --- Factory Methods -----------------------------------------------------

    /**
     * Create a new Cookie instance with the given parameters.
     *
     * @param string $name The name of the cookie.
     * @param mixed $value The value of the cookie (optional).
     * @param int $expires Expiration timestamp (optional).
     * @param string $path Path for which the cookie is valid (optional).
     * @param string $domain Domain for which the cookie is valid (optional).
     * @param bool $secure Whether the cookie should only be sent over HTTPS (optional).
     * @param bool $httpOnly Whether the cookie should be inaccessible to JavaScript (optional).
     * @return static A new Cookie instance.
     */
    public static function create(
        string $name,
        mixed $value = null,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ): static {
        return new static($name, $value, $expires, $path, $domain, $secure, $httpOnly);
    }

    // --- Constructor ---------------------------------------------------------

    /**
     * Create a new Cookie instance.
     *
     * @param string $name     Cookie name.
     * @param mixed  $value    Initial value (null means "not yet loaded").
     * @param int    $expires  Expiration timestamp (0 = session cookie).
     * @param string $path     URL path scope.
     * @param string $domain   Domain scope.
     * @param bool   $secure   Send over HTTPS only.
     * @param bool   $httpOnly Inaccessible to JavaScript.
     */
    public function __construct(
        string $name,
        mixed $value = null,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ) {
        $this->name = $name;

        if ($value !== null) {
            $this->value  = $value;
            $this->loaded = true;
        }

        $this->expires  = $expires;
        $this->path     = $path;
        $this->domain   = $domain;
        $this->secure   = $secure;
        $this->httpOnly = $httpOnly;
    }

    // --- Value Handling ------------------------------------------------------

    /**
     * Read the cookie value, lazily loading it from the raw incoming value and
     * decrypting if needed.
     *
     * The raw value comes from {@see setRaw()}, which the
     * {@see Cookies} jar fills from {@see Request::cookies()}.
     *
     * @param mixed $default Value to return when the cookie is not present.
     * @return mixed
     */
    public function value(mixed $default = null): mixed
    {
        if ($this->loaded) {
            return $this->value;
        }

        if ($this->rawValue === null) {
            return $default;
        }

        $raw = $this->rawValue;

        if ($this->encrypted) {
            $decrypted = $this->decrypt($raw);

            // Authentication failed (tampered, forged or plaintext cookie).
            // Do not cache the failure so a later write still works.
            if ($decrypted === null) {
                return $default;
            }

            $raw = $decrypted;
        }

        $this->value  = $raw;
        $this->loaded = true;

        return $this->value;
    }

    /**
     * Set the cookie value and queue it for the response.
     *
     * @param mixed $value New value.
     * @return $this
     */
    public function set(mixed $value): static
    {
        $this->value         = $value;
        $this->loaded        = true;
        $this->rawValue      = null;
        $this->deletePending = false;
        $this->dirty         = true;
        return $this;
    }

    /**
     * Load the value the client sent, as read from {@see Request::cookies()}.
     *
     * Unlike {@see set()}, this does not queue a `Set-Cookie`. It replaces cached
     * state and keeps the cookie clean, so it is only re-sent after an attribute
     * change.
     *
     * @param string|null $raw The raw (still encrypted, if applicable) cookie value.
     * @return $this
     */
    public function setRaw(?string $raw): static
    {
        $this->rawValue      = $raw;
        $this->value         = null;
        $this->loaded        = false;
        $this->deletePending = false;
        $this->dirty         = false;
        return $this;
    }

    /**
     * Whether this cookie was written or modified in this request.
     *
     * Used by {@see Cookies::applyTo()} so that reading a cookie never sends it.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Mark the cookie as already emitted, so a repeated drain cannot duplicate it.
     */
    public function markClean(): void
    {
        $this->dirty = false;
    }

    // --- Sending -------------------------------------------------------------

    /**
     * Build the `Set-Cookie` header value for this cookie.
     *
     * This is the one place that knows the header format. It works without a jar
     * and without a request, so an application can set the header itself:
     *
     *     $response->addHeader('Set-Cookie: ' . $cookie->toHeader());
     *
     * Encrypts the value when enabled and URL-encodes it for transport. Rejects
     * invalid cookie names or unsafe path/domain attributes.
     *
     * @throws \InvalidArgumentException When a name or attribute is invalid.
     */
    public function toHeader(): string
    {
        $this->validateHeaderComponents();

        $header = $this->name . '=';
        if ($this->deletePending) {
            $header .= '; Expires=Thu, 01 Jan 2012 00:00:00 GMT';
            $header .= '; Max-Age=0';
        } else {
            $value = $this->value();

            if ($this->encrypted && $value !== null) {
                $value = $this->encrypt((string) $value);
            }

            if ($value !== null) {
                $header .= rawurlencode((string) $value);
            }

            if ($this->expires !== 0) {
                $header .= '; Expires=' . gmdate('D, d M Y H:i:s', $this->expires) . ' GMT';
                $header .= '; Max-Age=' . max(0, $this->expires - time());
            }
        }

        if ($this->path !== '') {
            $header .= '; Path=' . $this->path;
        }

        if ($this->domain !== '') {
            $header .= '; Domain=' . $this->domain;
        }

        if ($this->secure) {
            $header .= '; Secure';
        }

        if ($this->httpOnly) {
            $header .= '; HttpOnly';
        }

        return $header;
    }

    private function validateHeaderComponents(): void
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $this->name) !== 1) {
            throw new \InvalidArgumentException('Cookie name must be a valid token.');
        }

        foreach (['path' => $this->path, 'domain' => $this->domain] as $attribute => $value) {
            if (preg_match('/[\x00-\x1F\x7F;]/', $value) === 1) {
                throw new \InvalidArgumentException(
                    sprintf('Cookie %s must not contain control characters or semicolons.', $attribute)
                );
            }
        }
    }

    /**
     * Delete the cookie by queueing a past-expiry `Set-Cookie` for the response.
     *
     * This is the only way a cookie is deleted. There is no `setcookie()` call
     * anywhere: a worker is not a SAPI, and under `php-fpm` the response carries the
     * header anyway — doing both would send two `Set-Cookie` lines for one delete.
     */
    public function delete(): static
    {
        $this->deletePending = true;
        $this->dirty         = true;

        return $this;
    }

    // --- Encryption ----------------------------------------------------------

    /**
     * Enable or disable transparent encryption for this cookie.
     *
     * @param bool $state True to enable encryption (default), false to disable.
     * @return $this
     */
    public function encrypted(bool $state = true): static
    {
        $this->encrypted = $state;
        return $this;
    }

    /**
     * Set the encryption key.
     *
     * Required before reading or writing an encrypted cookie. Use a random,
     * secret value (e.g. base64_encode(random_bytes(32))) persisted outside
     * the web root. There is deliberately no default: a derivable key would
     * make the AEAD protection meaningless.
     *
     * @param string|null $key Encryption key, or null to clear it.
     * @return $this
     */
    public function key(?string $key): static
    {
        $this->key = $key;
        return $this;
    }

    // --- Metadata ------------------------------------------------------------

    /**
     * Get the cookie name.
     *
     * @return string Cookie name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Set the expiration timestamp.
     *
     * @param int $timestamp Unix timestamp (0 = session cookie).
     * @return $this
     */
    public function expires(int $timestamp): static
    {
        $this->expires = $timestamp;
        $this->dirty   = true;
        return $this;
    }

    /**
     * Set the URL path scope for the cookie.
     *
     * @param string $path URL path (e.g. "/").
     * @return $this
     */
    public function path(string $path): static
    {
        $this->path  = $path;
        $this->dirty = true;
        return $this;
    }

    /**
     * Set the domain scope for the cookie.
     *
     * @param string $domain Domain (e.g. ".example.com").
     * @return $this
     */
    public function domain(string $domain): static
    {
        $this->domain = $domain;
        $this->dirty  = true;
        return $this;
    }

    /**
     * Restrict the cookie to HTTPS connections only.
     *
     * @param bool $state True to require HTTPS.
     * @return $this
     */
    public function secure(bool $state): static
    {
        $this->secure = $state;
        $this->dirty  = true;
        return $this;
    }

    /**
     * Make the cookie inaccessible to JavaScript (HttpOnly flag).
     *
     * @param bool $state True to set the HttpOnly flag.
     * @return $this
     */
    public function httpOnly(bool $state): static
    {
        $this->httpOnly = $state;
        $this->dirty    = true;
        return $this;
    }

    /**
     * Return the cookie value as a string (useful for string-casting).
     *
     * @return string Cookie value, or empty string when not set.
     */
    public function __toString(): string
    {
        return (string) $this->value();
    }

    // region Encryption/Decryption

    /**
     * Set the cipher provider used for this cookie.
     *
     * Accepts any registered provider id (see
     * {@see \Azera\Security\Crypt\SodiumCipher::ID} and
     * {@see \Azera\Security\Crypt\OpenSslCipher::ID}), or null to let the
     * registry pick the best available algorithm.
     *
     * Decryption never depends on this setting: a payload names the provider
     * that produced it, so a cookie written with ChaCha20 stays readable after
     * switching to AES-GCM.
     *
     * @param string|null $cipher Provider id, or null for automatic selection.
     * @return $this
     */
    public function cipher(?string $cipher): static
    {
        $this->cipher = $cipher;
        return $this;
    }

    /**
     * Use a specific cipher provider instance for this cookie.
     *
     * For consumers that bring their own provider (KMS-backed, HSM-backed, or
     * an algorithm the framework does not ship). Registering it here also makes
     * it available for reading payloads that name it.
     *
     * @param CipherProviderInterface $provider Provider to use.
     * @return $this
     */
    public function cipherProvider(CipherProviderInterface $provider): static
    {
        $this->registry(true)->add($provider);
        $this->cipher = $provider->id();

        return $this;
    }

    /**
     * Replace the registry used by this cookie.
     *
     * Different cookies may need different algorithms, so the registry is owned
     * per cookie (and can be shared explicitly) rather than resolved globally.
     *
     * @param CipherRegistry $registry Registry to use.
     * @return $this
     */
    public function ciphers(CipherRegistry $registry): static
    {
        $this->registry = $registry;
        return $this;
    }

    /**
     * The registry used by this cookie, created on first use.
     *
     * @param bool $fresh Discard any existing registry and build a new one.
     */
    public function registry(bool $fresh = false): CipherRegistry
    {
        if ($fresh || $this->registry === null) {
            $this->registry = CipherRegistry::withDefaults();
        }

        return $this->registry;
    }

    protected function encrypt(string $value): string
    {
        return $this->registry()->encrypt(
            $value,
            $this->resolveKey(),
            $this->associatedData(),
            $this->cipher
        );
    }

    protected function decrypt(string $value): ?string
    {
        return $this->registry()->decrypt($value, $this->resolveKey(), $this->associatedData());
    }

    /**
     * Bind the cookie's identity into the authentication tag.
     *
     * A ciphertext copied from one cookie to another then fails to
     * authenticate, instead of silently decrypting under the wrong name.
     */
    protected function associatedData(): string
    {
        return 'azera.cookie:' . $this->name;
    }

    protected function resolveKey(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        throw new Exception(
            'Cookie encryption requires an explicit key: call Cookie::key() with a random, secret value (e.g. base64_encode(random_bytes(32))) stored outside the web root.'
        );
    }

    // endregion
}
