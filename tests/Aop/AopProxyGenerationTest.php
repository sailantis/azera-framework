<?php

namespace Azera\Tests\Aop;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Aop\Advised;
use Azera\Aop\Advice;
use Azera\Aop\AopRuntime;
use Azera\Aop\Cache;
use Azera\Aop\CacheInterceptor;
use Azera\Aop\ProxyFactory;
use Azera\Aop\Retry;
use Azera\Aop\RetryInterceptor;
use Azera\AppContext;
use Azera\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

/**
 * One advice whose only argument selects a marker, so a test can prove which
 * ARGUMENTS the runtime actually constructed.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Marker extends Advice
{
    public function __construct(public readonly string $marker = 'unset')
    {
    }
}

/** Records the marker it was given, per invocation. */
class MarkerInterceptor implements \Azera\Aop\InterceptorInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function intercept(\Azera\Aop\Invocation $invocation, callable $next): mixed
    {
        $advice = $invocation->advice(Marker::class);
        $this->seen[] = $advice?->marker ?? 'none';

        return $next($invocation);
    }
}

#[Advised]
class TtlSixtyService
{
    #[Cache(ttl: 60)]
    #[Marker('sixty')]
    public function load(int $id): string
    {
        return 'v' . $id;
    }
}

#[Advised]
class TtlNineThousandService
{
    #[Cache(ttl: 9000)]
    #[Marker('ninetysix')]
    public function load(int $id): string
    {
        return 'v' . $id;
    }
}

#[Advised]
class PositionalAdviceService
{
    #[Retry(3, 0)]
    public function work(): string
    {
        return 'done';
    }
}

#[Advised]
class EnumArgumentService
{
    #[Marker(marker: 'enum-carrying')]
    public function work(): string
    {
        return 'done';
    }
}

#[Advised]
class PrunableService
{
    #[Marker('prunable')]
    public function work(): string
    {
        return 'done';
    }
}

/**
 * Two classes with the SAME advice set but REVERSED declaration order.
 *
 * Declaration order is the chain order (first declared = outermost), so these must
 * generate different sources. Sorting the advice map for tidiness would make them
 * identical and silently swap the chain.
 */
#[Advised]
class MarkerThenRetryService
{
    #[Marker('first')]
    #[Retry(times: 2, backoff: 0)]
    public function work(): string
    {
        return 'done';
    }
}

#[Advised]
class RetryThenMarkerService
{
    #[Retry(times: 2, backoff: 0)]
    #[Marker('first')]
    public function work(): string
    {
        return 'done';
    }
}

#[Advised]
class OtherPrunableService
{
    #[Marker('other')]
    public function work(): string
    {
        return 'done';
    }
}

#[Advised]
class WhitespaceArgumentService
{
    #[Marker("a  b\tc\nd")]
    public function work(): string
    {
        return 'done';
    }
}

#[Advised]
class ArrayArgumentService
{
    #[\Azera\Aop\Cache(ttl: 60, key: 'user_{id}')]
    public function work(): string
    {
        return 'done';
    }
}

/**
 * Coverage for the proxy GENERATOR cache: what makes a proxy file, when a new one
 * is minted, and how superseded ones are reclaimed.
 *
 * The properties asserted here are the ones that fail silently in production. A
 * wrong digest still produces a working proxy — it just never refreshes, or mints
 * a new file per request — so nothing surfaces without a test.
 */
class AopProxyGenerationTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
        $this->cacheDir = sys_get_temp_dir() . '/azera_aop_gen_' . bin2hex(random_bytes(6));
        $this->resetLoadedProxies();
    }

    protected function tearDown(): void
    {
        AppContext::reset();
        $this->resetLoadedProxies();

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($this->cacheDir)) {
            @rmdir($this->cacheDir);
        }
    }

    private function resetLoadedProxies(): void
    {
        $ref = new \ReflectionProperty(ProxyFactory::class, 'loadedProxies');
        $ref->setValue(null, []);
    }

    /**
     * @param array<class-string<Advice>, \Azera\Aop\InterceptorInterface> $interceptors
     */
    private function factory(array $interceptors): ProxyFactory
    {
        $factory = new ProxyFactory();
        $factory->setCacheDir($this->cacheDir);

        foreach ($interceptors as $adviceClass => $interceptor) {
            $factory->register($adviceClass, $interceptor);
        }

        return $factory;
    }

    /** @return array<class-string<Advice>, \Azera\Aop\InterceptorInterface> */
    private function defaultInterceptors(): array
    {
        return [
            Cache::class => new CacheInterceptor(new ArrayCache()),
            Marker::class => new MarkerInterceptor(),
        ];
    }

    private function source(ProxyFactory $factory, string $class): string
    {
        $ref = new \ReflectionClass($class);
        $factory->buildProxyClass($ref);

        $path = $factory->proxyPath($ref);
        self::assertNotNull($path, 'the fixture must produce a proxy path');

        return (string) file_get_contents($path);
    }

    // ------------------------------------------------------- why not mtime keys

    /**
     * A cache NAME may never be derived from a timestamp.
     *
     * This is the poisoning scenario that rules out an `mtime`-keyed layout, and it
     * is specific to PHP classes rather than templates. In a resident worker the
     * class is already loaded, so `getAttributes()` keeps reporting the OLD
     * arguments forever — PHP never re-reads metadata for a loaded class. If the
     * filename were derived from the class file's mtime, that worker would notice
     * the new mtime, emit a body from the STALE metadata, and publish it under a
     * name derived from the NEW version. Every other process would then see a file
     * at the current name and load stale advice: the old version could never come
     * back, because the name no longer matches the content.
     *
     * Content addressing makes that unrepresentable: the stale metadata yields the
     * stale digest, which names the body that is correct for this process, and a
     * fresh process derives its own name. This test pins the invariant — the name
     * follows the emitted body, and the same body always yields the same name,
     * independently of any file timestamp.
     */
    public function testProxyNameFollowsContentNotTime(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass($ref);
        $path = (string) $factory->proxyPath($ref);

        self::assertMatchesRegularExpression(
            '/^Proxy_[0-9a-f]+\.php$/',
            basename($path),
            'the name must be a content digest, not a timestamp',
        );

        // Re-deriving the name for the SAME loaded class must be stable no matter how
        // the class file's timestamps change: a worker that has the class loaded can
        // never be made to mint a new name by touching the file.
        $sourceFile = $ref->getFileName();
        self::assertNotFalse($sourceFile);

        touch((string) $sourceFile, time() + 5);
        clearstatcache(true, (string) $sourceFile);

        self::assertSame(
            basename($path),
            basename((string) $factory->proxyPath($ref)),
            'an mtime change must not produce a different name for unchanged metadata',
        );
    }

    /**
     * A pointer must not be keyed by the generator version.
     *
     * If it were, a version bump would leave the old pointer behind naming the old
     * body. Pruning treats every pointer's `body` as live, so that orphan would keep
     * a superseded body alive forever — the one leak the pointer design could
     * introduce. One pointer per (namespace, class) means a bump overwrites it, and
     * the body it described becomes collectable.
     */
    public function testPointerPathIsNotKeyedByGeneratorVersion(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass($ref);

        $pointer = (string) $factory->pointerFileFor($ref);
        self::assertFileExists($pointer);

        // The name is a digest of (namespace, class) ONLY. Asserted against an
        // independently computed hash, because that is the whole property: if the
        // generator version were folded in, a bump would leave the previous pointer
        // behind, still naming the body it described, and prune — which treats every
        // pointer's `body` as live — would protect that superseded body forever.
        self::assertSame(
            'Proxy_' . hash('xxh3', $factory->namespace() . "\0" . $ref->getName()) . '.ptr',
            basename($pointer),
        );

        // The version lives INSIDE the record, so a pointer left by a different
        // generator still reads as "no pointer" and is simply rewritten.
        $data = json_decode((string) file_get_contents($pointer), true);
        self::assertIsArray($data);
        self::assertSame(7, $data['v']);

        // One pointer per class, whatever the interceptor map and whatever the class.
        $wider = $this->factory($this->defaultInterceptors() + [Retry::class => new RetryInterceptor()]);
        $wider->buildProxyClass($ref);
        $factory->buildProxyClass(new \ReflectionClass(MarkerThenRetryService::class));

        self::assertSame($pointer, (string) $factory->pointerFileFor($ref), 'a map change must reuse the pointer');

        $forThisClass = array_filter(
            glob($this->cacheDir . DIRECTORY_SEPARATOR . 'Proxy_*.ptr') ?: [],
            function (string $p) use ($ref): bool {
                $data = json_decode((string) file_get_contents($p), true);

                return is_array($data) && ($data['class'] ?? null) === $ref->getName();
            },
        );

        self::assertCount(1, $forThisClass);
    }

    /**
     * The same emitted body must yield the same filename from any process.
     *
     * The digest covers the generator version, the class and the emitted source
     * only. Anything else in it — a timestamp, a pid, a random salt — would make two
     * processes disagree about the name and defeat OPcache reuse.
     */
    public function testProxyNameIsStableAcrossFactories(): void
    {
        $ref = new \ReflectionClass(PrunableService::class);

        $first = $this->factory($this->defaultInterceptors());
        $first->buildProxyClass($ref);

        $this->resetLoadedProxies();

        $second = $this->factory($this->defaultInterceptors());
        $second->buildProxyClass($ref);

        self::assertSame(
            basename((string) $first->proxyPath($ref)),
            basename((string) $second->proxyPath($ref)),
        );
    }

    // ------------------------------------------------- advice arguments in source

    /**
     * The generator must bake the attribute's arguments into the source.
     *
     * This is the whole mechanism: if the arguments are not in the body they are
     * not in the digest, so an attribute-only edit produces a byte-identical file
     * and nothing on disk can notice it.
     */
    public function testAdviceArgumentsAreEmittedIntoTheProxySource(): void
    {
        $source = $this->source($this->factory($this->defaultInterceptors()), TtlSixtyService::class);

        self::assertStringContainsString(
            "'ttl' => 60",
            $source,
            'a named argument must be baked in, and must stay named',
        );
        self::assertStringContainsString(
            "'sixty'",
            $source,
            'a positional argument must be baked in too',
        );
    }

    /**
     * Changing only an attribute argument must change the PROXY BODY.
     *
     * Comparing two differently-named fixtures would pass even if the arguments
     * were ignored, because the class name is part of the digest. So both sources
     * are normalized — namespace, target class and proxy class name removed — and
     * what remains must still differ. The only thing left to differ is the baked
     * argument map, which is exactly the mechanism under test.
     */
    public function testChangingOnlyAnAdviceArgumentChangesTheProxyBody(): void
    {
        $factory = $this->factory($this->defaultInterceptors());

        $sixty = $this->normalizedSource($factory, TtlSixtyService::class);
        $nineK = $this->normalizedSource($factory, TtlNineThousandService::class);

        self::assertNotSame(
            $sixty,
            $nineK,
            'an attribute-argument change must survive into the generated body',
        );

        // And the difference is the argument, not incidental noise.
        self::assertStringContainsString("'ttl' => 60", $sixty);
        self::assertStringContainsString("'ttl' => 9000", $nineK);

        // Everything except the advice map is identical, which proves the
        // normalization removed all other sources of difference.
        $stripAdvice = static fn(string $source): string => (string) preg_replace(
            '/^ *advice: \[.*\],$/m',
            'advice: MAP,',
            $source,
        );

        self::assertSame($stripAdvice($sixty), $stripAdvice($nineK));
    }

    /**
     * The proxy source with every class name and cache path normalized away.
     */
    private function normalizedSource(ProxyFactory $factory, string $class): string
    {
        $source = $this->source($factory, $class);

        $source = str_replace(
            [str_replace('\\', '\\\\', $class), $class, $this->cacheDir],
            ['NS_CLASS', 'NS_CLASS', 'CACHE_DIR'],
            $source,
        );

        return (string) preg_replace('/AzeraAopProxy_\w+/', 'PROXY_CLASS', $source);
    }

    /**
     * Two builds of the same class must produce byte-identical source.
     *
     * A digest is only useful if it is stable: any per-process variation (an
     * unordered attribute list, an exporter that wraps at different widths) would
     * mint a new proxy file on every request and never reuse one.
     */
    public function testGeneratedSourceIsByteIdenticalAcrossBuilds(): void
    {
        $first = $this->source($this->factory($this->defaultInterceptors()), TtlSixtyService::class);

        // A fresh factory in the same process: same digest, so the file is reused.
        $this->resetLoadedProxies();
        $second = $this->source($this->factory($this->defaultInterceptors()), TtlSixtyService::class);

        self::assertSame($first, $second);
    }

    /**
     * Positional attribute syntax must survive into the emitted constructor call.
     *
     * `#[Retry(3, 0)]` and `#[Retry(times: 3, backoff: 0)]` construct the same
     * object but are different source; preserving the form the developer wrote
     * keeps the digest faithful to the declaration and avoids reordering bugs.
     */
    public function testPositionalAttributeArgumentsArePreserved(): void
    {
        $source = $this->source(
            $this->factory([
                Retry::class => new RetryInterceptor(),
                Marker::class => new MarkerInterceptor(),
            ]),
            PositionalAdviceService::class,
        );

        self::assertMatchesRegularExpression(
            "/'Azera\\\\\\\\Aop\\\\\\\\Retry' => \[3, 0\]/",
            $source,
            'positional arguments must be emitted positionally',
        );
    }

    /**
     * String arguments must reach the plan BYTE FOR BYTE.
     *
     * The obvious way to make the emitted source single-line is to collapse
     * whitespace in `var_export()`'s output. That corrupts every string value
     * containing a space, tab or newline — `'a  b'` silently becomes `'a b'` — so a
     * `#[Cache(key: 'user  {id}')]` would resolve to a different cache key than the
     * one the application declared. This asserts the value the runtime constructs,
     * not the source text, because that is what actually breaks.
     */
    public function testWhitespaceInStringArgumentsReachesTheRuntimeUnchanged(): void
    {
        $factory = $this->factory($this->defaultInterceptors());
        $source  = $this->source($factory, WhitespaceArgumentService::class);

        self::assertStringContainsString('a  b', $source, 'the source must keep the doubled space');

        $plan = AopRuntime::plan(
            $factory->namespace(),
            WhitespaceArgumentService::class,
            'work',
            [Marker::class => ['marker' => "a  b\tc\nd"]],
        );

        self::assertSame("a  b\tc\nd", $plan->advice[Marker::class]->marker);
    }

    /**
     * An ARRAY argument must be emitted as a single-line literal.
     *
     * `var_export()` spreads a non-empty array across lines; that is harmless for
     * the digest (it is deterministic) but it makes the generated file unreadable
     * and it was what invited the whitespace-collapsing bug above. Composing lists
     * and maps directly keeps the body on one line without touching string content.
     */
    public function testArrayArgumentsAreEmittedOnASingleLine(): void
    {
        $source = $this->source($this->factory($this->defaultInterceptors()), ArrayArgumentService::class);

        self::assertStringContainsString("'ttl' => 60", $source);
        self::assertStringContainsString("'key' => 'user_{id}'", $source);

        // The whole advice map lives on one line; a spread array would break it.
        self::assertMatchesRegularExpression(
            "/advice: \[.*\],\n\s*parameterNames:/",
            $source,
            'the advice map must be one line',
        );
    }

    // ------------------------------------------------------- runtime constructs
    /**
     * The runtime must CONSTRUCT the advice from the baked arguments.
     *
     * This is what closes the resident-worker hole: the plan no longer reflects on
     * the target class, so the value it constructs is the one the proxy was
     * generated with — which is the one the source file declares.
     */
    public function testRuntimeConstructsAdviceFromTheBakedArguments(): void
    {
        $factory = $this->factory($this->defaultInterceptors());

        $plan = AopRuntime::plan(
            $factory->namespace(),
            TtlNineThousandService::class,
            'load',
            [Marker::class => ['marker' => 'from-proxy']],
            ['id'],
        );

        self::assertInstanceOf(Marker::class, $plan->advice[Marker::class]);
        self::assertSame('from-proxy', $plan->advice[Marker::class]->marker);
        self::assertSame(['id'], $plan->parameterNames);
    }

    /**
     * A resolved proxy must run the advice with the values from its own source.
     */
    public function testProxyExecutesWithItsOwnBakedArguments(): void
    {
        $marker = new MarkerInterceptor();

        $context = AppContext::instance();
        $context->setAopCacheDir($this->cacheDir);
        $context->registerInterceptor(Marker::class, $marker);

        $service = $context->get(TtlSixtyService::class);
        $service->load(1);

        self::assertSame(['sixty'], $marker->seen, 'the advice must see the declared marker');
    }

    /**
     * Enum arguments must round-trip through the generated source.
     *
     * `var_export()` renders an enum case as `\Fqcn::Case`, which resolves
     * identically in any process — unlike an object, which would need
     * `__set_state()`.
     */
    public function testEnumAttributeArgumentsSurviveGeneration(): void
    {
        $source = $this->source($this->factory($this->defaultInterceptors()), EnumArgumentService::class);

        self::assertStringContainsString('enum-carrying', $source);
    }

    /**
     * The advice map must keep DECLARATION order, because that is the chain order.
     *
     * The first declared attribute is the outermost interceptor. Sorting the map so
     * the emitted source is "tidy" reverses the chain for any application that
     * declares its advice in a particular order — a silent behavioural change, not
     * a formatting one.
     */
    public function testAdviceMapPreservesDeclarationOrder(): void
    {
        $interceptors = $this->defaultInterceptors() + [
            Retry::class => new RetryInterceptor(),
        ];

        $markerFirst = $this->source($this->factory($interceptors), MarkerThenRetryService::class);
        $retryFirst  = $this->source($this->factory($interceptors), RetryThenMarkerService::class);

        $order = static function (string $source): array {
            preg_match('/advice: (\[.*\]),\n\s*parameterNames/s', $source, $matches);
            self::assertArrayHasKey(1, $matches, 'the advice map must be on its own line');

            // Walk the literal tracking array depth: only keys at depth 1 are
            // advice classes. A regex cannot tell `'Retry' => [` from the nested
            // `'times' =>` inside its arguments.
            $order  = [];
            $depth  = 0;
            $length = strlen($matches[1]);

            for ($i = 0; $i < $length; $i++) {
                $char = $matches[1][$i];

                if ($char === '[') {
                    $depth++;
                    continue;
                }

                if ($char === ']') {
                    $depth--;
                    continue;
                }

                if ($char !== "'" || $depth !== 1) {
                    continue;
                }

                // A quoted token at depth 1 that is followed by ` =>` is a key.
                if (preg_match('/^\'([^\']+)\' => /', substr($matches[1], $i), $key) === 1) {
                    $order[] = substr((string) strrchr('\\' . $key[1], '\\'), 1);
                    $i += strlen($key[0]) - 1;
                }
            }

            return $order;
        };

        self::assertSame(['Marker', 'Retry'], $order($markerFirst));
        self::assertSame(['Retry', 'Marker'], $order($retryFirst));
    }

    // ------------------------------------------------------------------ pruning

    /**
     * The pointer is written next to the body and names it.
     *
     * Everything else about pruning and warm-start rests on this file existing and
     * being accurate: it is the only record of which body is current for a class,
     * so a missing or wrong pointer is always a silent full regeneration.
     */
    public function testPointerNamesTheGeneratedBody(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass($ref);

        $pointer = $factory->pointerFileFor($ref);
        self::assertNotNull($pointer);
        self::assertFileExists($pointer);

        $data = json_decode((string) file_get_contents($pointer), true);

        self::assertIsArray($data);
        self::assertSame(7, $data['v'], 'the pointer format version tracks GENERATOR_VERSION');
        self::assertSame(PrunableService::class, $data['class']);
        self::assertSame(basename((string) $factory->proxyPath($ref)), $data['body']);
        self::assertSame($factory->proxyPath($ref), $this->cacheDir . DIRECTORY_SEPARATOR . $data['body']);
    }

    /**
     * A pointer whose source signature matches must let a COLD factory skip
     * generation entirely.
     *
     * The signature is `mtime:size` of the class file. It is only ever a shortcut,
     * so a match means "the body on disk is still what this metadata would emit" —
     * no method scan, no source generation, no digest.
     */
    public function testMatchingSourceSignatureReusesTheBodyWithoutGenerating(): void
    {
        $ref = new \ReflectionClass(PrunableService::class);

        $first = $this->factory($this->defaultInterceptors());
        $first->buildProxyClass($ref);

        $path = $first->proxyPath($ref);
        self::assertNotNull($path);
        $body = (string) file_get_contents($path);

        // A brand-new factory, as a fresh FPM request would create: no memo, no
        // registry entry. It must still avoid generation.
        $this->resetLoadedProxies();

        $second = $this->factory($this->defaultInterceptors());
        $class  = $second->buildProxyClass($ref);

        self::assertNotNull($class);
        self::assertFileExists($path, 'the very same body must be reused, not rewritten');
        self::assertSame($body, (string) file_get_contents($path), 'the body must be untouched');
    }

    /**
     * A pointer that names a body which is gone must trigger regeneration, and the
     * dangling pointer must be cleaned up rather than trusted.
     */
    public function testPointerToAMissingBodyForcesRegeneration(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass($ref);

        $path = $factory->proxyPath($ref);
        self::assertNotNull($path);
        @unlink($path);

        $this->resetLoadedProxies();

        $second = $this->factory($this->defaultInterceptors());
        $class  = $second->buildProxyClass($ref);

        self::assertNotNull($class);
        self::assertFileExists($path, 'the body must be regenerated');
    }

    /**
     * A pointer written by a different generator version must be ignored.
     *
     * The format version is folded into the record so a future change to the shape
     * reads as "no pointer" and regenerates, instead of being silently misread.
     */
    public function testPointerWithAForeignFormatVersionIsIgnored(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass($ref);

        $pointer = $factory->pointerFileFor($ref);
        self::assertNotNull($pointer);

        $data = json_decode((string) file_get_contents($pointer), true);
        self::assertIsArray($data);

        $data['v']     = 999;
        $data['sig']   = 'bogus';
        $data['body']  = 'Proxy_' . str_repeat('0', 32) . '.php';

        file_put_contents($pointer, (string) json_encode($data));

        $this->resetLoadedProxies();

        $second = $this->factory($this->defaultInterceptors());
        $class  = $second->buildProxyClass($ref);

        self::assertNotNull($class);

        $rewritten = json_decode((string) file_get_contents($pointer), true);
        self::assertIsArray($rewritten);
        self::assertSame(7, $rewritten['v'], 'the pointer must be rewritten by this generator');
        self::assertSame(basename((string) $factory->proxyPath($ref)), $rewritten['body']);
    }

    /**
     * The per-factory memo must be consulted before any reflection, generation or I/O.
     *
     * This is the warm-worker cost, and the point of the ordering. A second call for
     * the same class on the same factory must not re-derive anything: deleting the
     * body AND the pointer after the first build must go unnoticed, because the answer
     * is already known. Only a memo consulted first can achieve that.
     *
     * The process-wide class registry is cleared first, so the ONLY thing that can
     * answer is the instance memo. Leaving it populated would let the registry mask a
     * missing memo entirely — which is exactly the regression this guards against.
     */
    public function testTheMemoIsKeyedByClassAndSkipsRegeneration(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $first   = $factory->buildProxyClass($ref);
        $path    = $factory->proxyPath($ref);
        $pointer = $factory->pointerFileFor($ref);
        self::assertNotNull($path);
        self::assertNotNull($pointer);

        // Remove everything a non-memoised path would need, and the registry entry
        // that would otherwise answer instead of the memo.
        @unlink($path);
        @unlink($pointer);
        $this->resetLoadedProxies();

        $second = $factory->buildProxyClass($ref);

        self::assertSame($first, $second);
        self::assertFileDoesNotExist($path, 'a memoised answer must not touch the disk');
        self::assertFileDoesNotExist($pointer, 'a memoised answer must not rewrite the pointer');
    }

    /**
     * A late registration must invalidate the memo, or the second interceptor never
     * reaches a class the first one already covered.
     */
    public function testRegisterInvalidatesTheMemo(): void
    {
        $ref     = new \ReflectionClass(MarkerThenRetryService::class);
        $factory = new ProxyFactory();
        $factory->setCacheDir($this->cacheDir);
        $factory->register(Marker::class, new MarkerInterceptor());

        $first = (string) $factory->buildProxyClass($ref);

        $factory->register(Retry::class, new RetryInterceptor());

        $second = (string) $factory->buildProxyClass($ref);

        self::assertNotSame($first, $second, 'the emitted body must change when the map changes');
    }

    /**
     * Pruning must keep the body every pointer names.
     */
    public function testPruneKeepsLiveProxyFiles(): void
    {
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass(new \ReflectionClass(PrunableService::class));
        $live = $factory->proxyPath(new \ReflectionClass(PrunableService::class));

        self::assertNotNull($live);

        $result = $factory->prune();

        self::assertSame(0, $result['removed']);
        self::assertFileExists($live);
    }

    /**
     * Pruning must remove superseded bodies — the ones no pointer names any more.
     *
     * The file is produced by a REAL earlier generation rather than planted: build
     * with one interceptor map, then with a larger one. The second map emits a
     * different body for the same class, so the first filename becomes unreachable
     * when the pointer is rewritten.
     */
    public function testPruneRemovesSupersededProxyFiles(): void
    {
        $ref = new \ReflectionClass(MarkerThenRetryService::class);

        $earlier = new ProxyFactory();
        $earlier->setCacheDir($this->cacheDir);
        $earlier->register(Marker::class, new MarkerInterceptor());
        $earlier->buildProxyClass($ref);

        $superseded = $earlier->proxyPath($ref);
        self::assertNotNull($superseded);
        self::assertFileExists($superseded);

        $later = $this->factory($this->defaultInterceptors() + [Retry::class => new RetryInterceptor()]);
        $later->buildProxyClass($ref);

        $current = $later->proxyPath($ref);
        self::assertNotNull($current);
        self::assertNotSame($superseded, $current, 'the advice set change must mint a new file');

        $result = $later->prune();

        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($superseded);
        self::assertFileExists($current, 'the proxy in use must survive');
    }

    /**
     * Pruning needs no class list any more: the evidence is on disk.
     *
     * The previous rule required the caller to supply every proxied class, which a
     * maintenance command that booted no services cannot do. Pointers make "live"
     * a fact about the directory instead of a guess about the caller.
     */
    public function testPruneNeedsNoClassList(): void
    {
        $ref = new \ReflectionClass(MarkerThenRetryService::class);

        $earlier = new ProxyFactory();
        $earlier->setCacheDir($this->cacheDir);
        $earlier->register(Marker::class, new MarkerInterceptor());
        $earlier->buildProxyClass($ref);

        $superseded = $earlier->proxyPath($ref);
        self::assertNotNull($superseded);

        $later = $this->factory($this->defaultInterceptors() + [Retry::class => new RetryInterceptor()]);
        $later->buildProxyClass($ref);

        $result = $later->prune();

        self::assertArrayNotHasKey('reason', $result);
        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($superseded);
    }

    /**
     * A body that ANY pointer names must survive pruning, even one this factory did
     * not write.
     *
     * This is the cross-application guard. Applications are isolated by directory
     * (`AppContext::defaultAopCacheDir()` appends the project name), but a directory
     * can still legitimately hold pointers this factory has no knowledge of: an
     * earlier generator version whose pointers have not been swept, a second
     * deployment root sharing a volume, or a process that generated a body and died
     * before writing its own pointer. Pruning therefore derives "live" from the
     * pointer files on disk, never from what this process happens to know.
     */
    public function testPruneKeepsBodiesNamedByAnyPointer(): void
    {
        $factory = $this->factory($this->defaultInterceptors());
        $factory->buildProxyClass(new \ReflectionClass(PrunableService::class));

        // A body and a pointer this factory never wrote, standing in for another
        // application's record in the same directory.
        $foreignBody = 'Proxy_' . str_repeat('c', 16) . '.php';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . $foreignBody, "<?php return 'foreign';\n");
        file_put_contents(
            $this->cacheDir . DIRECTORY_SEPARATOR . 'Proxy_' . str_repeat('d', 16) . '.ptr',
            (string) json_encode(['v' => 7, 'class' => 'Other\\App\\Service', 'body' => $foreignBody]),
        );

        $result = $factory->prune();

        self::assertSame(0, $result['removed'], 'a referenced body is never collected');
        self::assertFileExists($this->cacheDir . DIRECTORY_SEPARATOR . $foreignBody);
    }

    /**
     * A body nothing points at, in a directory this factory never wrote to, is
     * still collected — that is what makes `cache:prune` work after a deployment
     * whose pointers were lost with the old release directory.
     */
    public function testPruneCollectsUnreferencedBodiesRegardlessOfWhoWroteThem(): void
    {
        $factory = $this->factory($this->defaultInterceptors());
        $factory->buildProxyClass(new \ReflectionClass(PrunableService::class));

        $orphan = $this->cacheDir . DIRECTORY_SEPARATOR . 'Proxy_' . str_repeat('a', 32) . '.php';
        file_put_contents($orphan, "<?php return 'x';\n");
        self::assertFileExists($orphan);

        $result = $factory->prune();

        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($orphan);
    }

    /**
     * Anything that is not a generated body must be left alone.
     *
     * The directory is the application's, and a stray file there is not this class's
     * business: deleting by exclusion is how the previous implementation removed
     * things it did not understand.
     */
    public function testPruneIgnoresFilesThatAreNotGeneratedBodies(): void
    {
        $factory = $this->factory($this->defaultInterceptors());
        $factory->buildProxyClass(new \ReflectionClass(PrunableService::class));

        $keeper = $this->cacheDir . DIRECTORY_SEPARATOR . 'notes.txt';
        file_put_contents($keeper, 'not a proxy');
        self::assertFileExists($keeper);

        $factory->prune();

        self::assertFileExists($keeper);
    }

    /**
     * A pointer whose body is gone must be collected, or a lost body hides forever
     * behind a record that claims it exists.
     */
    public function testPruneRemovesPointersWhoseBodyIsGone(): void
    {
        $ref     = new \ReflectionClass(PrunableService::class);
        $factory = $this->factory($this->defaultInterceptors());

        $factory->buildProxyClass($ref);

        $pointer = $factory->pointerFileFor($ref);
        $body    = $factory->proxyPath($ref);
        self::assertNotNull($pointer);
        self::assertNotNull($body);

        @unlink($body);

        $factory->prune();

        self::assertFileDoesNotExist($pointer);
    }

    /**
     * `--older-than` must protect a body another worker wrote moments ago.
     */
    public function testPruneRespectsTheAgeCutoff(): void
    {
        $ref = new \ReflectionClass(MarkerThenRetryService::class);

        $earlier = new ProxyFactory();
        $earlier->setCacheDir($this->cacheDir);
        $earlier->register(Marker::class, new MarkerInterceptor());
        $earlier->buildProxyClass($ref);

        $superseded = $earlier->proxyPath($ref);
        self::assertNotNull($superseded);

        $later = $this->factory($this->defaultInterceptors() + [Retry::class => new RetryInterceptor()]);
        $later->buildProxyClass($ref);

        self::assertNotSame($superseded, $later->proxyPath($ref));

        // Written moments ago: an hour-long cutoff keeps it.
        $result = $later->prune(3600);

        self::assertSame(0, $result['removed']);
        self::assertFileExists($superseded);

        // Backdate past the cutoff: now it is eligible.
        touch($superseded, time() - 7200);

        $result = $later->prune(3600);

        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($superseded);
    }
    // ------------------------------------------------------- cache directory

    /**
     * A freshly written proxy must be backdated past OPcache's protection window.
     *
     * OPcache refuses to CACHE a file whose mtime is within
     * `opcache.file_update_protection`, so a proxy written on the request that
     * first needed it is compiled and discarded by every worker until the window
     * passes. The file is content-addressed, so a young mtime carries no
     * information — backdating it is free correctness.
     */
    public function testFreshProxyFileIsBackdatedForOpcache(): void
    {
        $window = @ini_get('opcache.file_update_protection');

        if ($window === false) {
            self::markTestSkipped('OPcache is not available in this PHP build.');
        }

        $factory = $this->factory($this->defaultInterceptors());
        $factory->buildProxyClass(new \ReflectionClass(PrunableService::class));

        $path = $factory->proxyPath(new \ReflectionClass(PrunableService::class));
        self::assertNotNull($path);
        self::assertFileExists($path);

        $age = time() - (int) filemtime($path);

        self::assertGreaterThan(
            (int) $window,
            $age,
            'the proxy mtime must sit behind opcache.file_update_protection, or it is never cached',
        );
    }

    /**
     * The default cache directory is one directory per host, by design.
     *
     * Deriving it from an application identity made the path depend on how the
     * process resolved that identity, which is what let the CLI and the web runtime
     * disagree and one app's maintenance command act on a directory nobody wrote to.
     * Sharing is safe because pointers record the interceptor map and source
     * signature: a mismatch regenerates instead of serving another app's advice.
     */
    public function testDefaultCacheDirIsHostWide(): void
    {
        $dir = AppContext::instance()->aopCacheDir();

        self::assertSame(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_aop',
            $dir,
            'the default must not depend on resolving an application identity',
        );
    }

    /**
     * Pruning must not need the application's interceptor map.
     *
     * A pointer's path depends on the directory only, and bodies are found through
     * the pointers, so a factory that registered nothing collects exactly the same
     * files. Requiring the map meant a maintenance command invoked every lazy
     * interceptor factory — constructing interceptors, opening a database connection
     * — to delete a file.
     */
    public function testPruneDoesNotNeedTheInterceptorMap(): void
    {
        $ref = new \ReflectionClass(MarkerThenRetryService::class);

        $first = $this->factory($this->defaultInterceptors());
        $first->buildProxyClass($ref);

        $superseded = (string) $first->proxyPath($ref);

        $wider = $this->factory($this->defaultInterceptors() + [Retry::class => new RetryInterceptor()]);
        $wider->buildProxyClass($ref);

        $bare = new ProxyFactory();
        $bare->setCacheDir($this->cacheDir);

        self::assertFalse($bare->hasInterceptors());

        $result = $bare->prune();

        self::assertArrayNotHasKey('reason', $result);
        self::assertSame(1, $result['removed'], 'the empty map must still see the superseded body');
        self::assertFileDoesNotExist($superseded);
    }

    /**
     * A maintenance command must not construct the application's interceptors.
     *
     * `cache:prune` gets its factory from `CacheTask::aopFactory()`. Registering the
     * application's map there bought nothing — pointer names come from the directory —
     * but it ran every lazy interceptor factory, so pruning a file could open a
     * database connection. The factory must therefore stay empty.
     */
    public function testCacheTaskPruneBuildsAFactoryWithoutResolvingInterceptors(): void
    {
        $context = AppContext::instance();
        $context->setAopCacheDir($this->cacheDir);

        $constructed = 0;

        $context->registerInterceptor(
            Marker::class,
            static function () use (&$constructed): MarkerInterceptor {
                $constructed++;

                return new MarkerInterceptor();
            },
        );

        $task = new \Azera\Cli\Tasks\CacheTask();
        $factory = (new \ReflectionMethod($task, 'aopFactory'))->invoke($task, $this->cacheDir);

        self::assertInstanceOf(ProxyFactory::class, $factory);
        self::assertFalse($factory->hasInterceptors(), 'the maintenance factory carries no map');
        self::assertSame(0, $constructed, 'pruning must not construct the application\'s interceptors');
    }
}
