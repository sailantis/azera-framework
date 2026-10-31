<?php

namespace Azera\Security\Crypt;

use Azera\Exception;

/**
 * Collection of {@see CipherProviderInterface} instances, keyed by id.
 *
 * Deliberately *not* wired into the DI container: different call sites may
 * legitimately need different algorithms (a cookie, a short-lived signed
 * token and a long-lived archival blob do not share a threat model), so a
 * registry is constructed where it is used and kept next to the thing that
 * uses it rather than being resolved globally.
 *
 * The registry is what makes decryption portable. A payload names the provider
 * that produced it; {@see decrypt()} follows that name. A node with only
 * OpenSSL can therefore read a cookie written by a node with libsodium, as
 * long as both providers are registered.
 *
 * ```php
 * $registry = CipherRegistry::withDefaults();
 * $sealed   = $registry->encrypt('value', $key);        // preferred provider
 * $plain    = $registry->decrypt($sealed, $key);        // routed by payload id
 * ```
 */
final class CipherRegistry
{
    /** @var array<string, CipherProviderInterface> */
    private array $providers = [];

    /** @var array<string, CipherProviderInterface>|null Lazily built defaults. */
    private static ?array $defaults = null;

    /**
     * Create a registry from a set of providers.
     *
     * @param iterable<CipherProviderInterface> $providers Providers in preference order.
     * @throws Exception When two providers claim the same id.
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    /**
     * Registry with the built-in providers, preferred one first.
     *
     * Registration order is preference order: ChaCha20-Poly1305 before
     * AES-256-GCM, and unavailable providers are skipped. A provider that
     * cannot encrypt here is still registered when its id is known, so
     * payloads from other machines remain readable whenever the extension is
     * present at read time.
     */
    public static function withDefaults(): self
    {
        return new self(self::defaultProviders());
    }

    /**
     * The providers shipped with the framework, in preference order.
     *
     * @return CipherProviderInterface[]
     */
    public static function defaultProviders(): array
    {
        self::$defaults ??= [new SodiumCipher(), new OpenSslCipher()];

        return self::$defaults;
    }

    /**
     * The id of the provider that would be used to encrypt right now.
     *
     * @throws Exception When no registered provider is available.
     * @return string Provider id.
     */
    public function preferredId(): string
    {
        return $this->preferred()->id();
    }

    /**
     * The provider that would be used to encrypt right now.
     *
     * @throws Exception When no registered provider is available.
     */
    public function preferred(): CipherProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                return $provider;
            }
        }

        throw new Exception(
            'No cipher provider is available. Install the sodium extension, or an OpenSSL build with aes-256-gcm.'
        );
    }

    /**
     * Encrypt with the preferred provider.
     *
     * @param string $plaintext  Value to protect.
     * @param string $key        Secret key material.
     * @param string $associated Additional authenticated data.
     * @param string|null $providerId Force a specific provider instead of the preferred one.
     * @throws Exception When the requested provider is unknown or unavailable.
     * @return string Self-describing payload.
     */
    public function encrypt(
        string $plaintext,
        string $key,
        string $associated = '',
        ?string $providerId = null
    ): string {
        $provider = $providerId === null
            ? $this->preferred()
            : $this->get($providerId);

        return $provider->encrypt($plaintext, $key, $associated);
    }

    /**
     * Decrypt a payload by following the provider id it carries.
     *
     * This is the method that removes the portability hazard: the provider is
     * chosen from the payload, not from local capability.
     *
     * @param string $payload    Payload produced by any registered provider.
     * @param string $key        Secret key material.
     * @param string $associated Additional authenticated data.
     * @return string|null Plaintext, or null when the payload is malformed,
     *         names an unregistered provider, or fails authentication.
     */
    public function decrypt(string $payload, string $key, string $associated = ''): ?string
    {
        $providerId = CipherPayload::providerIdOf($payload);

        if ($providerId === null) {
            return null;
        }

        $provider = $this->providers[$providerId] ?? null;

        if ($provider === null || !$provider->isAvailable()) {
            // Unknown or unusable here. Null — never a fallback attempt with a
            // different algorithm, which would mask the real cause.
            return null;
        }

        return $provider->decrypt($payload, $key, $associated);
    }

    /**
     * Add (or replace) a provider.
     *
     * @param CipherProviderInterface $provider Provider to register.
     * @throws Exception When a *different* provider already claims this id.
     * @return $this
     */
    public function add(CipherProviderInterface $provider): self
    {
        $id = $provider->id();

        if (isset($this->providers[$id]) && $this->providers[$id] !== $provider) {
            throw new Exception("A cipher provider with id '{$id}' is already registered.");
        }

        $this->providers[$id] = $provider;

        return $this;
    }

    /**
     * Fetch a provider by id.
     *
     * @param string $id Provider id.
     * @throws Exception When the id is not registered.
     */
    public function get(string $id): CipherProviderInterface
    {
        if (!isset($this->providers[$id])) {
            $known = implode(', ', array_keys($this->providers)) ?: 'none';

            throw new Exception("Unknown cipher provider '{$id}'. Registered: {$known}.");
        }

        return $this->providers[$id];
    }

    /**
     * Whether a provider with this id is registered.
     *
     * @param string $id Provider id.
     */
    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    /**
     * Registered provider ids, in preference order.
     *
     * @return string[]
     */
    public function ids(): array
    {
        return array_keys($this->providers);
    }
}
