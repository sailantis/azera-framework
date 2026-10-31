<?php

namespace Azera\Security\Crypt;

use Azera\Exception;

/**
 * The wire format shared by all {@see CipherProviderInterface} implementations.
 *
 * A payload has the following form:
 *
 *     base64url( provider-id "|" raw-ciphertext )
 *
 * The provider identifier is stored inside the payload, so each value is
 * associated with the provider that produced it. This allows cookies to be
 * read on machines where different extensions are installed: the decoder
 * relies on the provider named in the payload rather than on the providers
 * available on the current machine.
 */
final class CipherPayload
{
    /** Separator between the provider id and the raw ciphertext. */
    private const SEPARATOR = '|';

    /** Matches a well-formed provider id. */
    private const ID_PATTERN = '/^[a-z0-9._-]{1,64}$/';

    /**
     * Encode a provider id and its raw ciphertext into a transport-safe string.
     *
     * @param string $providerId    Provider that produced the ciphertext.
     * @param string $ciphertext    Raw binary ciphertext.
     * @throws Exception When the provider id is not well formed.
     * @return string Base64url-encoded payload, safe inside a cookie value.
     */
    public static function encode(string $providerId, string $ciphertext): string
    {
        if (!preg_match(self::ID_PATTERN, $providerId)) {
            throw new Exception("Invalid cipher provider id: {$providerId}");
        }

        return self::base64UrlEncode($providerId . self::SEPARATOR . $ciphertext);
    }

    /**
     * Split a payload into its provider id and raw ciphertext.
     *
     * @param string $payload Payload produced by {@see encode()}.
     * @return array{0: string, 1: string}|null [providerId, ciphertext], or null
     *         when the payload is malformed.
     */
    public static function decode(string $payload): ?array
    {
        $raw = self::base64UrlDecode($payload);

        if ($raw === null) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $raw, 2);

        if (\count($parts) !== 2) {
            return null;
        }

        [$providerId, $ciphertext] = $parts;

        if (!preg_match(self::ID_PATTERN, $providerId) || $ciphertext === '') {
            return null;
        }

        return [$providerId, $ciphertext];
    }

    /**
     * Read the provider id from a payload without decrypting it.
     *
     * @param string $payload Payload produced by {@see encode()}.
     * @return string|null Provider id, or null when absent/malformed.
     */
    public static function providerIdOf(string $payload): ?string
    {
        return self::decode($payload)[0] ?? null;
    }

    /** Base64url without padding: safe in a cookie and shorter than base64. */
    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Strict base64url decode; null when the input is not valid. */
    private static function base64UrlDecode(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $b64 = strtr($payload, '-_', '+/');
        $pad = \strlen($b64) % 4;

        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($b64, true);

        return $raw === false ? null : $raw;
    }
}
