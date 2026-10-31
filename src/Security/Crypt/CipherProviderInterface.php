<?php

namespace Azera\Security\Crypt;

/**
 * Contract for an authenticated-encryption provider.
 *
 * A provider owns both the algorithm *and* the payload envelope. Every
 * ciphertext it produces begins with its {@see id()}, so a value can be
 * routed back to the provider that wrote it regardless of what the current
 * machine happens to have compiled in.
 *
 * That self-description is the reason for the interface rather than a bare
 * `encrypt()`/`decrypt()` pair: ChaCha20-Poly1305 and AES-256-GCM both use a
 * 12-byte nonce, so their payloads are structurally indistinguishable. Without
 * an embedded identifier, a cookie written on a node with libsodium cannot be
 * read on a node without it, and the failure is silent.
 *
 * Implementations must be authenticated (AEAD). Decryption returns `null` —
 * never a partial or best-effort value — when authentication fails.
 *
 * @see CipherRegistry   Routes payloads to the provider that wrote them.
 * @see SodiumCipher     ChaCha20-Poly1305 via libsodium.
 * @see OpenSslCipher    AES-256-GCM via OpenSSL.
 */
interface CipherProviderInterface
{
    /**
     * Stable identifier for this algorithm and payload format.
     *
     * Written verbatim into every payload, so it must never change for an
     * existing format. Include a version suffix when the envelope changes
     * (e.g. `sodium-chacha20poly1305.v2`).
     *
     * @return string Provider identifier.
     */
    public function id(): string;

    /**
     * Whether the backing extension is available on this machine.
     *
     * Used to pick a default and to fail with a clear message rather than a
     * "call to undefined function".
     *
     * @return bool True when the provider can be used.
     */
    public function isAvailable(): bool;

    /**
     * Encrypt a value with authenticated encryption.
     *
     * @param string $plaintext  Value to protect.
     * @param string $key        Secret key material (any length; providers derive).
     * @param string $associated Additional authenticated data, bound but not encrypted.
     * @throws \Azera\Exception When encryption fails or the provider is unavailable.
     * @return string Ciphertext that includes this provider's {@see id()}.
     */
    public function encrypt(string $plaintext, string $key, string $associated = ''): string;

    /**
     * Decrypt a value and verify its authentication tag.
     *
     * @param string $payload    Payload previously produced by this provider.
     * @param string $key        Secret key material.
     * @param string $associated Additional authenticated data used at encryption time.
     * @return string|null Plaintext, or null when authentication fails.
     */
    public function decrypt(string $payload, string $key, string $associated = ''): ?string;
}
