<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Exception;
use Azera\Http\Cookie;
use Azera\Security\Crypt\CipherPayload;
use Azera\Security\Crypt\CipherProviderInterface;
use Azera\Security\Crypt\CipherRegistry;
use Azera\Security\Crypt\OpenSslCipher;
use Azera\Security\Crypt\SodiumCipher;
use PHPUnit\Framework\TestCase;

/**
 * A provider that records which algorithm ran, to prove preference ordering.
 */
class StubCipher implements CipherProviderInterface
{
    public int $encryptions = 0;

    public function __construct(
        private string $id,
        private bool $available = true
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function encrypt(string $plaintext, string $key, string $associated = ''): string
    {
        $this->encryptions++;
        return CipherPayload::encode($this->id, 'STUB:' . $associated . ':' . $plaintext);
    }

    public function decrypt(string $payload, string $key, string $associated = ''): ?string
    {
        $decoded = CipherPayload::decode($payload);

        if ($decoded === null || $decoded[0] !== $this->id) {
            return null;
        }

        $prefix = 'STUB:' . $associated . ':';

        return str_starts_with($decoded[1], $prefix)
            ? substr($decoded[1], \strlen($prefix))
            : null;
    }
}

class CipherProviderTest extends TestCase
{
    private const KEY = 'test-key-2f8b1a9c4d7e6f05b3c8a1d9e7f2b4c6';

    /* ------------------------------------------------ payload self-description */

    public function testPayloadCarriesProviderId(): void
    {
        $plain = (new SodiumCipher())->encrypt('secret', self::KEY);

        $this->assertSame(SodiumCipher::ID, CipherPayload::providerIdOf($plain));
    }

    public function testPayloadIsNotPlaintextReadable(): void
    {
        $payload = (new SodiumCipher())->encrypt('top-secret-value', self::KEY);

        $this->assertStringNotContainsString('top-secret-value', $payload);
        $this->assertStringNotContainsString('top-secret-value', (string) base64_decode($payload, true));
    }

    public function testMalformedPayloadReturnsNull(): void
    {
        $registry = CipherRegistry::withDefaults();

        foreach (['', 'not-a-payload', '%%%', base64_encode('garbage')] as $bad) {
            $this->assertNull($registry->decrypt($bad, self::KEY), "payload: {$bad}");
        }
    }

    public function testTamperedCiphertextReturnsNull(): void
    {
        $provider = new SodiumCipher();
        $payload  = $provider->encrypt('value', self::KEY);

        // Flip one character in the middle of the payload.
        $idx     = intdiv(\strlen($payload), 2);
        $tampered = substr($payload, 0, $idx)
            . ($payload[$idx] === 'A' ? 'B' : 'A')
            . substr($payload, $idx + 1);

        $this->assertNull($provider->decrypt($tampered, self::KEY));
    }

    public function testWrongKeyReturnsNull(): void
    {
        $provider = new SodiumCipher();
        $payload  = $provider->encrypt('value', self::KEY);

        $this->assertNull($provider->decrypt($payload, 'a-different-key'));
    }

    /* ------------------------------------------- the portability regression */

    /**
     * The bug this design exists to fix: a payload written with one algorithm
     * must remain readable when the local machine would prefer another.
     */
    public function testDecryptionFollowsPayloadNotLocalPreference(): void
    {
        // Written by a machine that picked AES-GCM.
        $payload = (new OpenSslCipher())->encrypt('session-id', self::KEY);

        // Read on a machine whose preferred provider is ChaCha20.
        $registry = CipherRegistry::withDefaults();
        $this->assertSame(SodiumCipher::ID, $registry->preferredId());

        $this->assertSame('session-id', $registry->decrypt($payload, self::KEY));
    }

    public function testDecryptionAlsoWorksForTheSodiumDirection(): void
    {
        $payload = (new SodiumCipher())->encrypt('session-id', self::KEY);

        // A registry in the opposite preference order still reads the payload,
        // because routing follows the payload id, not the preference order.
        $registry = new CipherRegistry([new OpenSslCipher(), new SodiumCipher()]);

        $this->assertSame(OpenSslCipher::ID, $registry->preferredId());
        $this->assertSame('session-id', $registry->decrypt($payload, self::KEY));
    }

    public function testUnknownProviderIdIsRejectedNotGuessed(): void
    {
        $stub = new StubCipher('stub-only-on-other-node');
        $payload = $stub->encrypt('value', self::KEY);

        // A registry without that provider must not fall back to another cipher.
        $this->assertNull(CipherRegistry::withDefaults()->decrypt($payload, self::KEY));
    }

    /* ------------------------------------------------------------- cookie API */

    public function testCookieSwitchesAlgorithmWithoutInvalidatingExistingCookies(): void
    {
        $jar = new \Azera\Http\Cookies();

        // Issued under ChaCha20.
        $writer = $jar->cookie('sid')->encrypted()->key(self::KEY)->cipher(SodiumCipher::ID);
        $writer->set('user-42');
        $ref = new \ReflectionMethod(Cookie::class, 'encrypt');
        $issued = $ref->invoke($writer, 'user-42');

        // Read back under AES-GCM: the payload still names its own provider.
        $reader = $jar->cookie('sid')->encrypted()->key(self::KEY)->cipher(OpenSslCipher::ID);
        $dec = new \ReflectionMethod(Cookie::class, 'decrypt');

        $this->assertSame('user-42', $dec->invoke($reader, $issued));
    }

    /**
     * A ciphertext lifted from one cookie must not authenticate under another
     * name, because the cookie name is bound as associated data.
     */
    public function testCiphertextCopiedToAnotherCookieNameIsRejected(): void
    {
        $jar  = new \Azera\Http\Cookies();
        $prov = new SodiumCipher();

        $enc = new \ReflectionMethod(Cookie::class, 'encrypt');

        $source = Cookie::make('role')->encrypted()->key(self::KEY);
        $source->set('admin');
        $payload = $enc->invoke($source, 'admin');

        $victim = Cookie::make('theme')->encrypted()->key(self::KEY);
        $dec = new \ReflectionMethod(Cookie::class, 'decrypt');

        $this->assertNull($dec->invoke($victim, $payload));
    }

    public function testCookieAcceptsCustomProvider(): void
    {
        $stub   = new StubCipher('custom-kms.v1');
        $cookie = Cookie::make('sid')->encrypted()->key(self::KEY)->cipherProvider($stub);

        $enc = new \ReflectionMethod(Cookie::class, 'encrypt');
        $payload = $enc->invoke($cookie, 'value');

        $this->assertSame('custom-kms.v1', CipherPayload::providerIdOf($payload));
        $this->assertSame(1, $stub->encryptions);
    }

    public function testCookieAcceptsSharedRegistry(): void
    {
        $stub     = new StubCipher('shared.v1');
        $registry = new CipherRegistry([$stub]);

        $cookie = Cookie::make('sid')->encrypted()->key(self::KEY)->ciphers($registry);
        $cookie->set('x');

        $this->assertSame(['shared.v1'], $cookie->registry()->ids());
    }

    /* ------------------------------------------------------------- registry */

    public function testPreferredSkipsUnavailableProviders(): void
    {
        $unavailable = new StubCipher('down.v1', false);
        $usable      = new StubCipher('up.v1', true);

        $registry = new CipherRegistry([$unavailable, $usable]);

        $this->assertSame('up.v1', $registry->preferredId());
        $this->assertSame(0, $unavailable->encryptions);
    }

    public function testRegistryWithoutAvailableProviderThrows(): void
    {
        $registry = new CipherRegistry([new StubCipher('down.v1', false)]);

        $this->expectException(Exception::class);
        $registry->encrypt('value', self::KEY);
    }

    public function testDuplicateProviderIdIsRejected(): void
    {
        $registry = new CipherRegistry([new StubCipher('dup.v1')]);

        $this->expectException(Exception::class);
        $registry->add(new StubCipher('dup.v1'));
    }

    public function testSameInstanceMayBeRegisteredTwice(): void
    {
        $stub     = new StubCipher('same.v1');
        $registry = new CipherRegistry([$stub]);

        $registry->add($stub);

        $this->assertSame(['same.v1'], $registry->ids());
    }

    public function testUnknownProviderLookupThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unknown cipher provider/');

        CipherRegistry::withDefaults()->get('nope.v1');
    }

    /* --------------------------------------------------------- cipher pinning */

    public function testPinnedProviderIdIsUsed(): void
    {
        $jar = new \Azera\Http\Cookies();

        foreach ([SodiumCipher::ID => SodiumCipher::ID, OpenSslCipher::ID => OpenSslCipher::ID] as $pin => $expected) {
            $cookie = $jar->cookie('x')->encrypted()->key(self::KEY)->cipher($pin);

            $enc = new \ReflectionMethod(Cookie::class, 'encrypt');
            $payload = $enc->invoke($cookie, 'v');

            $this->assertSame($expected, CipherPayload::providerIdOf($payload));
        }
    }

    public function testNullCipherUsesPreferredProvider(): void
    {
        $cookie = Cookie::make('x')->encrypted()->key(self::KEY)->cipher(null);

        $enc = new \ReflectionMethod(Cookie::class, 'encrypt');
        $payload = $enc->invoke($cookie, 'v');

        $this->assertSame(
            CipherRegistry::withDefaults()->preferredId(),
            CipherPayload::providerIdOf($payload)
        );
    }

    /**
     * Pinning an id that is not registered must fail loudly rather than
     * silently falling back to another algorithm.
     */
    public function testPinningUnknownProviderThrows(): void
    {
        $cookie = Cookie::make('x')->encrypted()->key(self::KEY)->cipher('does-not-exist.v1');

        $enc = new \ReflectionMethod(Cookie::class, 'encrypt');

        $this->expectException(Exception::class);
        $enc->invoke($cookie, 'v');
    }
}
