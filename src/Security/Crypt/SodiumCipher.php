<?php

namespace Azera\Security\Crypt;

use Azera\Exception;

/**
 * ChaCha20-Poly1305 (IETF) authenticated encryption via libsodium.
 *
 * Preferred default: it is the AEAD built into modern PHP, has no padding
 * oracle, and is constant-time on all supported platforms.
 *
 * Payload layout, inside the {@see CipherPayload} envelope:
 *
 *     nonce (12 bytes) || ciphertext || tag (16 bytes)
 */
final class SodiumCipher implements CipherProviderInterface
{
    public const ID = 'sodium-chacha20poly1305.v1';

    private const NONCE_BYTES = 12;
    private const TAG_BYTES   = 16;

    public function id(): string
    {
        return self::ID;
    }

    public function isAvailable(): bool
    {
        return \function_exists('sodium_crypto_aead_chacha20poly1305_ietf_encrypt');
    }

    public function encrypt(string $plaintext, string $key, string $associated = ''): string
    {
        $this->assertAvailable();

        $nonce      = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
            $plaintext,
            $associated,
            $nonce,
            self::deriveKey($key)
        );

        return CipherPayload::encode(self::ID, $nonce . $ciphertext);
    }

    public function decrypt(string $payload, string $key, string $associated = ''): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $decoded = CipherPayload::decode($payload);

        if ($decoded === null || $decoded[0] !== self::ID) {
            return null;
        }

        $raw = $decoded[1];

        if (\strlen($raw) <= self::NONCE_BYTES) {
            return null;
        }

        $nonce      = substr($raw, 0, self::NONCE_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES);

        $plaintext = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
            $ciphertext,
            $associated,
            $nonce,
            self::deriveKey($key)
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Normalise arbitrary-length key material to the 32 bytes Sodium requires.
     *
     * A single SHA-256 pass is used deliberately: the input is already a
     * high-entropy secret supplied by the application, not a user password, so
     * a slow KDF would add latency without adding strength.
     */
    private static function deriveKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new Exception(
                'The libsodium extension is required for ' . self::ID . ' but is not available.'
            );
        }
    }
}
