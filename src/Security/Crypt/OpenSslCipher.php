<?php

namespace Azera\Security\Crypt;

use Azera\Exception;

/**
 * AES-256-GCM authenticated encryption via OpenSSL.
 *
 * Fallback for environments without libsodium. GCM is a sound AEAD, but the
 * Sodium provider is preferred because OpenSSL's GCM path has historically
 * been more prone to implementation-side pitfalls in userland PHP.
 *
 * Payload layout, inside the {@see CipherPayload} envelope:
 *
 *     iv (12 bytes) || tag (16 bytes) || ciphertext
 */
final class OpenSslCipher implements CipherProviderInterface
{
    public const ID = 'openssl-aes256gcm.v1';

    private const METHOD    = 'aes-256-gcm';
    private const TAG_BYTES = 16;

    public function id(): string
    {
        return self::ID;
    }

    public function isAvailable(): bool
    {
        return \function_exists('openssl_encrypt')
            && \in_array(self::METHOD, openssl_get_cipher_methods(), true);
    }

    public function encrypt(string $plaintext, string $key, string $associated = ''): string
    {
        $this->assertAvailable();

        $ivSize = openssl_cipher_iv_length(self::METHOD);
        $iv     = random_bytes($ivSize);
        $tag    = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::METHOD,
            self::deriveKey($key),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $associated,
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new Exception('AES-256-GCM encryption failed.');
        }

        return CipherPayload::encode(self::ID, $iv . $tag . $ciphertext);
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

        $raw    = $decoded[1];
        $ivSize = openssl_cipher_iv_length(self::METHOD);

        if (\strlen($raw) <= $ivSize + self::TAG_BYTES) {
            return null;
        }

        $iv         = substr($raw, 0, $ivSize);
        $tag        = substr($raw, $ivSize, self::TAG_BYTES);
        $ciphertext = substr($raw, $ivSize + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::METHOD,
            self::deriveKey($key),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $associated
        );

        return $plaintext === false ? null : $plaintext;
    }

    /** Normalise arbitrary-length key material to the 32 bytes AES-256 requires. */
    private static function deriveKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new Exception(
                'OpenSSL with ' . self::METHOD . ' is required for ' . self::ID . ' but is not available.'
            );
        }
    }
}
