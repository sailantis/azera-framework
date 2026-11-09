<?php
namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The route table is a description of how to find a handler, never the handler
 * itself. That invariant is what makes `routes:cache` safe, so it is enforced at
 * registration rather than checked at export, and these tests pin the enforcement
 * and the round trip.
 *
 * Before the enforcement existed, an array of object middleware reached the table
 * and `serialize()` on it threw "Serialization of 'class@anonymous' is not
 * allowed" -- so this is a regression guard, not a hypothetical.
 */
class RouterStateTest extends TestCase
{
    // -------------------------------------------------------------------------
    //  Nothing that is code may enter the table
    // -------------------------------------------------------------------------

    public function testMiddlewareArrayRejectsAnObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be strings');

        (new Router())->middleware([new \stdClass()]);
    }

    public function testMiddlewareArrayRejectsAClosure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Router())->middleware([static fn() => null]);
    }

    public function testMiddlewareArrayRejectsAMixedArrayNamingTheOffender(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass');

        (new Router())->middleware(['auth', new \stdClass()]);
    }

    public function testMiddlewareAcceptsStringsAndArraysOfStrings(): void
    {
        $router = (new Router())->middleware('auth');
        $this->assertSame(['auth'], $router->exportState()['middlewareGroupStack']);

        $router = (new Router())->middleware(['auth', 'csrf']);
        $this->assertSame(['auth', 'csrf'], $router->exportState()['middlewareGroupStack']);
    }

    public function testHandlerArrayRejectsAnObjectValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('controller');

        (new Router())->get('/a', ['controller' => new \stdClass()]);
    }

    public function testHandlerArrayRejectsAClosureValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Router())->get('/a', ['action' => static fn() => null]);
    }

    public function testHandlerCannotBeAClosureAtAll(): void
    {
        // The signature refuses it before any check runs, which is the strongest
        // form of this guarantee.
        $this->expectException(\TypeError::class);

        (new Router())->get('/a', static fn() => null);
    }

    public function testHandlerArrayAcceptsStringsAndInts(): void
    {
        $router = new Router();
        $router->get('/a', ['controller' => 'A', 'action' => 'b']);

        $state = $router->exportState();
        $this->assertSame('A', $state['static']['GET']['/a']['handler']['controller']);
    }

    // -------------------------------------------------------------------------
    //  Round trip
    // -------------------------------------------------------------------------

    /** Build a table that exercises statics, params, groups, middleware and names. */
    private function buildRouter(): Router
    {
        $router = new Router();

        $router->controller('App\\Controllers\\HomeController', function (Router $r): void {
            $r->get('/', '::index');
            $r->get('/echo', '::echo');
        });

        $router->middleware('session', function (Router $r): void {
            $r->get('/login', 'Auth::login');
        });

        $router->get('/items/{id:int}', 'Items::show');
        $router->get('/blog/{slug}', 'Blog::view');
        $router->post('/items', 'Items::create');

        return $router;
    }

    public function testExportIsPureData(): void
    {
        $state = $this->buildRouter()->exportState();

        // The property that makes a route cache safe: var_export round-trips it.
        $this->assertStringNotContainsString('__set_state', var_export($state, true));
        $this->assertIsString(serialize($state));
    }

    public function testExportOmitsCodeAndMemo(): void
    {
        $state = $this->buildRouter()->exportState();

        $this->assertArrayNotHasKey('types', $state, 'validators are closures and must not be persisted');
        $this->assertArrayNotHasKey('resolvedOverrides', $state, 'the memo is derived from the table');
    }

    public function testRestoreReproducesMatching(): void
    {
        $source = $this->buildRouter();

        $restored = new Router();
        $restored->restoreState($source->exportState());

        $probes = [
            ['/', 'GET'],
            ['/echo', 'GET'],
            ['/login', 'GET'],
            ['/items/7', 'GET'],
            ['/items/abc', 'GET'],
            ['/blog/hello', 'GET'],
            ['/items', 'POST'],
            ['/items', 'GET'],
            ['/nope', 'GET'],
        ];

        foreach ($probes as [$uri, $method]) {
            $this->assertEquals(
                $source->match($uri, $method),
                $restored->match($uri, $method),
                "match mismatch for {$method} {$uri}",
            );
        }
    }

    public function testRestoreKeepsTheBuiltInValidatorsWorking(): void
    {
        $restored = new Router();
        $restored->restoreState($this->buildRouter()->exportState());

        // `{id:int}` needs the `int` validator, which is a closure and was
        // deliberately not persisted -- the constructor has to supply it.
        $this->assertNotNull($restored->match('/items/7', 'GET'));
        $this->assertNull($restored->match('/items/abc', 'GET'));
    }

    public function testRestoreIgnoresUnknownKeys(): void
    {
        $restored = new Router();
        $restored->restoreState([
            'static' => ['GET' => ['/x' => ['handler' => 'X::y', 'tokens' => [], 'groups' => []]]],
            'types'  => ['evil' => static fn() => null],
            'bogus'  => 'ignored',
        ]);

        $this->assertNotNull($restored->match('/x', 'GET'));
        $this->assertArrayNotHasKey('evil', $restored->exportState()['types'] ?? []);
    }

    public function testARebuildKeepsCustomValidators(): void
    {
        $cache  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-rebuild-' . bin2hex(random_bytes(6)) . '.php';
        $source = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-rebuild-src-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($source, "<?php\n");
        touch($source, time() - 60);

        try {
            $router = (new Router())->setCachePath($cache);
            $router->type('slug', static fn($v) => preg_match('/^[a-z-]+$/', $v) === 1);
            $router->cached($source, static fn(Router $r) => $r->get('/blog/{slug:slug}', 'Blog::view'));

            // A rebuild empties the table but must not empty the validators: they are
            // code, identical either way, and dropping them would break the rebuild.
            $this->assertSame(1, $router->compileCache());
            $this->assertSame(1, $router->compileCache(), 'a rebuild reuses the units, so the count is stable');

            $this->assertNotNull($router->match('/blog/ok', 'GET'));
            $this->assertNull($router->match('/blog/NOT-OK', 'GET'), 'the validator still runs');
            $this->assertSame(['slug'], $router->customTypes());
        } finally {
            @unlink($cache);
            @unlink($source);
        }
    }

    public function testCustomTypesAreReportedAndBuiltInsAreNot(): void
    {
        $router = new Router();
        $this->assertSame([], $router->customTypes());

        $router->type('slug', static fn($v) => true);
        $router->type('hex', static fn($v) => true);

        $this->assertSame(['slug', 'hex'], $router->customTypes());
    }

    public function testRestoredCustomTypeRouteNeedsTheTypeReRegistered(): void
    {
        $source = new Router();
        $source->type('slug', static fn($v) => preg_match('/^[a-z-]+$/', $v) === 1);
        $source->get('/blog/{slug:slug}', 'Blog::view');

        // Without re-registration the match must fail loudly rather than accept
        // anything -- a dropped validation constraint is a security bug.
        $restored = new Router();
        $restored->restoreState($source->exportState());

        $this->expectException(\Throwable::class);
        $restored->match('/blog/anything', 'GET');
    }


    // -------------------------------------------------------------------------
    //  cached() units and deferral
    // -------------------------------------------------------------------------

    /** A temp directory that cleans itself up. */
    private array $tmpFiles = [];

    private function tmpPath(string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-routes-' . bin2hex(random_bytes(6)) . $suffix;
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function tmpSource(string $contents = "<?php\n", ?int $mtime = null): string
    {
        $path = $this->tmpPath('.php');
        file_put_contents($path, $contents);
        touch($path, $mtime ?? time() - 60);
        clearstatcache(true, $path);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];

        // A derived default path leaves a file in the system temp directory, whose
        // name the test cannot predict from here. Sweep the ones this test process
        // created rather than leaking a table per run.
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_routes'
            . DIRECTORY_SEPARATOR . 'routes-*.php') ?: [] as $file) {
            if (@filemtime($file) >= $this->startedAt) {
                @unlink($file);
            }
        }
    }

    private int $startedAt;

    protected function setUp(): void
    {
        $this->startedAt = time();
    }

    public function testCachedOptsTheCacheInAndDefersUntilTheTableIsRead(): void
    {
        $router = new Router();
        $ran    = false;

        $router->cached($this->tmpSource(), function (Router $r) use (&$ran): void {
            $ran = true;
            $r->get('/deferred', 'A::b');
        });

        $this->assertFalse($ran, 'declaring a unit is what opts in, so registration waits');

        $this->assertNotNull($router->match('/deferred', 'GET'));
        $this->assertTrue($ran);
    }

    public function testDisableCacheMakesRegistrationEagerAgain(): void
    {
        $router = new Router();
        $router->disableCache();

        $ran = false;
        $router->cached($this->tmpSource(), function (Router $r) use (&$ran): void {
            $ran = true;
            $r->get('/eager', 'A::b');
        });

        $this->assertTrue($ran, 'opted out: nothing to defer, so the block runs here');
        $this->assertNull($router->cachePath());
    }

    public function testDisableCacheDoesNotLoseUnitsDeclaredBeforeIt(): void
    {
        $router = new Router();
        $router->cached($this->tmpSource(), static fn(Router $r) => $r->get('/kept', 'A::b'));

        // Nothing was read yet, so disabling has to run the pending unit rather than
        // drop it: the routes are the application's, not the cache's.
        $router->disableCache();

        $this->assertNotNull($router->match('/kept', 'GET'));
    }

    public function testTheDefaultPathIsDerivedFromTheDeclaredSourcesAndNeverCollides(): void
    {
        $a = $this->tmpSource();
        $b = $this->tmpSource();

        $one = (new Router())->cached($a, static fn(Router $r) => $r->get('/a', 'A::b'));
        $two = (new Router())->cached($b, static fn(Router $r) => $r->get('/b', 'A::b'));

        $first  = (string) $one->cachePath();
        $second = (string) $two->cachePath();

        $this->assertStringStartsWith(sys_get_temp_dir(), $first);
        $this->assertStringStartsWith('routes-', basename($first));
        $this->assertNotSame($first, $second, 'two apps in the temp dir must not share a file');

        // Stable within a process and for the same declaration.
        $this->assertSame($first, $one->cachePath());
        $this->assertSame(1, $one->compileCache());
        $this->assertFileExists($first);
        $this->tmpFiles[] = $first;
    }

    public function testSetCachePathOverridesTheDerivedDefault(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static fn(Router $r) => $r->get('/x', 'A::b'));

        $this->assertSame($cache, $router->cachePath());

        // null goes back to the derived default rather than disabling the cache.
        $router->setCachePath(null);
        $this->assertStringStartsWith(sys_get_temp_dir(), (string) $router->cachePath());
        $this->assertNotSame($cache, $router->cachePath());
    }

    public function testCachedSourcesReportsEveryDeclaredFileInOrder(): void
    {
        $a = $this->tmpSource();
        $b = $this->tmpSource();

        $router = (new Router())->setCachePath($this->tmpPath('.php'));
        $router->cached($a, static fn(Router $r) => $r->get('/a', 'A::b'));
        $router->cached([$b, $a], static fn(Router $r) => $r->get('/b', 'A::b'));

        $this->assertSame([$a, $b], $router->cachedSources());
    }

    public function testCachedThrowsWhenANestedUnitIsExecuted(): void
    {
        $router = (new Router())->setCachePath($this->tmpPath('.php'));
        $file   = $this->tmpSource();

        $router->cached($file, function (Router $r) use ($file): void {
            $r->cached($file, static fn(Router $inner) => $inner->get('/a', 'A::b'));
        });

        // The outer body only runs at the first read, which is where the nested
        // cached() call is reached and refused.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nested');

        $router->match('/a', 'GET');
    }

    public function testReDeclaringAfterAFlushRebuildsInsteadOfAppending(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static fn(Router $r) => $r->get('/first', 'A::b'));
        $this->assertNotNull($router->match('/first', 'GET'));

        // A worker that re-runs its bootstrap reaches cached() again. The old table must
        // be dropped and rebuilt from the declarations, never appended to.
        $router->cached($source, static fn(Router $r) => $r->get('/second', 'A::b'));
        $this->assertNotNull($router->match('/second', 'GET'));
        $this->assertNull($router->match('/first', 'GET'), 'the old table is gone, not merged');

        $this->assertSame(1, count($router->allRoutes()));
    }

    public function testCachedThrowsInsideAGroupCallback(): void
    {
        $router = (new Router())->setCachePath($this->tmpPath('.php'));
        $file   = $this->tmpSource();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('top level');

        $router->prefix('/admin', function (Router $r) use ($file): void {
            $r->cached($file, static fn(Router $inner) => $inner->get('/dashboard', 'A::b'));
        });
    }

    public function testTypeThrowsInsideACachedUnit(): void
    {
        $router = (new Router())->setCachePath($this->tmpPath('.php'));
        $file   = $this->tmpSource();

        $router->cached($file, function (Router $r): void {
            $r->type('slug', static fn($v) => true);
        });

        // The block runs at the first read, which is where the guard fires.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('type() cannot be called inside a cached() block');

        $router->match('/anything', 'GET');
    }

    public function testSetCachePathAfterTheTableIsReadThrows(): void
    {
        $router = (new Router())->setCachePath($this->tmpPath('.php'));
        $router->cached($this->tmpSource(), static fn(Router $r) => $r->get('/a', 'A::b'));
        $router->match('/a', 'GET');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already read');

        $router->setCachePath($this->tmpPath('.php'));
    }

    public function testSettingTheSamePathTwiceIsANoOp(): void
    {
        $path   = $this->tmpPath('.php');
        $router = (new Router())->setCachePath($path);

        // A worker that re-runs its bootstrap must not fail here.
        $this->assertSame($path, $router->setCachePath($path)->cachePath());
    }

    public function testSetCachePathRejectsAnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Router())->setCachePath('');
    }

    // -------------------------------------------------------------------------
    //  Routes outside a unit
    // -------------------------------------------------------------------------

    /**
     * The order the eager check could never cover: a route registered before the
     * first cached() block. The table would be rebuilt from the units alone, so that
     * route would silently vanish -- a working cold boot that loses a route the
     * moment the cache is declared.
     */
    public function testARouteRegisteredBeforeTheFirstUnitThrowsAtFlushTime(): void
    {
        $router = new Router();
        $router->get('/before', 'A::b');
        $router->cached($this->tmpSource(), static fn(Router $r) => $r->get('/inside', 'A::b'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('before the first cached() block');

        $router->match('/inside', 'GET');
    }

    /**
     * The same guard for a dynamic route, which lands in `groups` rather than
     * `static` -- a different structure, so a separate path through the check.
     */
    public function testADynamicRouteRegisteredBeforeTheFirstUnitAlsoThrows(): void
    {
        $router = new Router();
        $router->get('/items/{id:int}', 'A::b');
        $router->cached($this->tmpSource(), static fn(Router $r) => $r->get('/inside', 'A::b'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('before the first cached() block');

        $router->match('/inside', 'GET');
    }

    public function testARouteRegisteredAfterTheTableIsReadThrows(): void
    {
        $router = new Router();
        $router->cached($this->tmpSource(), static fn(Router $r) => $r->get('/inside', 'A::b'));
        $router->match('/inside', 'GET');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('after the route table had already been read');

        $router->get('/late', 'A::b');
    }

    public function testARouteRegisteredAfterTheReadIsAllowedWhenTheCacheIsDisabled(): void
    {
        $router = new Router();
        $router->disableCache();
        $router->get('/late', 'A::b');

        $this->assertNotNull($router->match('/late', 'GET'), 'no cache, no table to invalidate');
    }

    /**
     * The reason mixed mode is forbidden, as a test rather than a comment: two routes
     * that tie on specificity produce different matches depending on registration
     * order, so a compiled table restored in a different order than it was built would
     * answer differently than a cold request.
     */
    public function testInsertionOrderChangesTheWinnerOnASpecificityTie(): void
    {
        $forward = new Router();
        $forward->get('/a/{x:alpha}', 'Alpha::handler');
        $forward->get('/a/{y:alnum}', 'Alnum::handler');

        $reverse = new Router();
        $reverse->get('/a/{y:alnum}', 'Alnum::handler');
        $reverse->get('/a/{x:alpha}', 'Alpha::handler');

        $f = $forward->match('/a/xyz', 'GET');
        $r = $reverse->match('/a/xyz', 'GET');

        $this->assertSame('Alpha', $f['override']['controller']);
        $this->assertSame('Alnum', $r['override']['controller']);
        $this->assertNotEquals(
            $f['override'],
            $r['override'],
            'if this ever becomes equal, the mixed-mode ban can be reconsidered',
        );
    }

    // -------------------------------------------------------------------------
    //  Write on miss, restore on hit, rebuild on staleness
    // -------------------------------------------------------------------------

    /** Build a router whose single unit registers one static route. */
    private function cacheRouter(string $cachePath, string $source, string $uri = '/cached'): Router
    {
        $router = (new Router())->setCachePath($cachePath);
        $router->cached($source, function (Router $r) use ($uri): void {
            $r->get($uri, 'A::b');
        });

        return $router;
    }

    /** The envelope the router writes, for tests that need to forge a file. */
    private function envelope(array $files, ?int $compiled = null): array
    {
        return ['version' => 1, 'compiled' => $compiled ?? time(), 'files' => $files];
    }

    private function writeState(string $path, array $state): void
    {
        file_put_contents($path, "<?php\n\nreturn " . var_export($state, true) . ";\n");
        clearstatcache(true, $path);
    }

    public function testAMissBuildsTheTableAndWritesTheFile(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = $this->cacheRouter($cache, $source);
        $this->assertFileDoesNotExist($cache, 'nothing is written until the router is read');

        $this->assertNotNull($router->match('/cached', 'GET'));
        $this->assertFileExists($cache, 'a miss writes the table, so the next process inherits it');

        $written = require $cache;
        $this->assertSame(1, $written['__cache']['version']);
        $this->assertArrayHasKey($source, $written['__cache']['files']);
    }

    public function testAHitNeverRunsTheUnit(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        // First process: builds and writes.
        $this->assertNotNull($this->cacheRouter($cache, $source)->match('/cached', 'GET'));

        // Second process: the unit would throw if it ran at all.
        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static function (Router $r): void {
            throw new \RuntimeException('the unit must not run on a cache hit');
        });

        $this->assertNotNull($router->match('/cached', 'GET'));
    }

    public function testTouchingASourceMakesTheTableStale(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $this->assertNotNull($this->cacheRouter($cache, $source, '/old')->match('/old', 'GET'));

        touch($source, time() + 5);
        clearstatcache(true, $source);

        $router = $this->cacheRouter($cache, $source, '/new');
        $this->assertNotNull($router->match('/new', 'GET'), 'a stale table is rebuilt');
    }

    public function testAddingARouteFileMakesTheTableStale(): void
    {
        $cache = $this->tmpPath('.php');
        $first = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->cached($first, static fn(Router $r) => $r->get('/one', 'A::b'));
        $this->assertNotNull($router->match('/one', 'GET'));

        // A second unit appears. Every recorded mtime is unchanged, so only comparing
        // the *set* of declared files can catch this.
        $second = $this->tmpSource();
        $router2 = (new Router())->setCachePath($cache);
        $router2->cached($first, static fn(Router $r) => $r->get('/one', 'A::b'));
        $router2->cached($second, static fn(Router $r) => $r->get('/two', 'A::b'));

        $this->assertNotNull($router2->match('/two', 'GET'), 'a new route file must invalidate');
    }

    public function testRemovingARouteFileMakesTheTableStale(): void
    {
        $cache = $this->tmpPath('.php');
        $kept  = $this->tmpSource();
        $gone  = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->cached($kept, static fn(Router $r) => $r->get('/kept', 'A::b'));
        $router->cached($gone, static fn(Router $r) => $r->get('/gone', 'A::b'));
        $this->assertNotNull($router->match('/gone', 'GET'));

        $router2 = (new Router())->setCachePath($cache);
        $router2->cached($kept, static fn(Router $r) => $r->get('/kept', 'A::b'));

        $this->assertNull($router2->match('/gone', 'GET'), 'a removed route file must invalidate');
    }

    public function testAnEqualMtimeIsTreatedAsStale(): void
    {
        $cache  = $this->tmpPath('.php');
        $now    = time();
        $source = $this->tmpSource();

        // Forge a table whose compile stamp equals the source's mtime: a deploy that
        // finishes inside one clock tick must not be mistaken for fresh.
        $built = $this->cacheRouter($cache, $source, '/x');
        $built->match('/x', 'GET');
        $written = require $cache;
        $written['__cache'] = $this->envelope([$source => $now], $now);
        $this->writeState($cache, $written);
        touch($source, $now);
        clearstatcache(true, $source);

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static function (Router $r): void {
            throw new \RuntimeException('an equal mtime must not be trusted');
        });

        $this->expectException(\RuntimeException::class);
        $router->match('/x', 'GET');
    }

    public function testAHandWrittenExportStateFileIsNeverFresh(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        // exportState() is pure table data. Without the envelope, freshness cannot be
        // computed, so the file must be ignored rather than trusted.
        $built = new Router();
        $built->get('/x', 'A::b');
        $this->writeState($cache, $built->exportState());

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static fn(Router $r) => $r->get('/fresh', 'A::b'));

        $this->assertNotNull($router->match('/fresh', 'GET'));
        $this->assertNull($router->match('/x', 'GET'), 'the hand-written table must be replaced');
    }

    public function testAMissingSourceMakesTheTableStale(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $built = $this->cacheRouter($cache, $source, '/x');
        $built->match('/x', 'GET');

        unlink($source);
        clearstatcache(true, $source);

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static function (Router $r): void {
            throw new \RuntimeException('a missing source cannot be judged fresh');
        });

        $this->expectException(\RuntimeException::class);
        $router->match('/x', 'GET');
    }

    // -------------------------------------------------------------------------
    //  revalidateFreq
    // -------------------------------------------------------------------------

    public function testAPositiveVerdictIsReusedInsideTheWindow(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->setRevalidateFreq(3600);
        $router->cached($source, static fn(Router $r) => $r->get('/x', 'A::b'));

        $this->assertNotNull($router->match('/x', 'GET'));

        // Break the source. Inside the window the verdict is still trusted, so the
        // table is not re-checked -- that is the whole point of the setting.
        touch($source, time() + 5);
        clearstatcache(true, $source);

        $this->assertNotNull($router->match('/x', 'GET'));
    }

    public function testAVerdictIsRecheckedAfterTheWindow(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        // Revalidation is about a table this process *restored*, not one it wrote: a
        // self-written table is authoritative and is never re-read. So build the file
        // with one router, then have a second one restore it.
        $this->cacheRouter($cache, $source, '/x')->compileCache();

        $builds = 0;
        $router = (new Router())->setCachePath($cache);
        $router->setRevalidateFreq(1);
        $router->cached($source, function (Router $r) use (&$builds): void {
            $builds++;
            $r->get('/x', 'A::b');
        });

        $this->assertNotNull($router->match('/x', 'GET'));
        $this->assertSame(0, $builds, 'the first read restored, so the unit did not run');

        // Still inside the window: the stored positive verdict is reused.
        $this->assertNotNull($router->match('/x', 'GET'));
        $this->assertSame(0, $builds);

        // Past the window and stale: re-checked, found stale, rebuilt through the unit.
        touch($source, time() + 5);
        clearstatcache(true, $source);
        sleep(2);

        $this->assertNotNull($router->match('/x', 'GET'));
        $this->assertSame(1, $builds, 'a stale restored table is rebuilt through the units');
    }

    public function testARevalidationRebuildsThroughTheUnits(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        // The file comes from a first router, so the second one restores rather than
        // owns it -- a self-written table is never re-read (see ownWriteAt).
        $this->cacheRouter($cache, $source, '/x')->compileCache();

        $builds = 0;
        $router = (new Router())->setCachePath($cache);
        $router->setRevalidateFreq(1);
        $router->cached($source, function (Router $r) use (&$builds): void {
            $builds++;
            $r->get('/x', 'A::b');
        });

        $router->match('/x', 'GET');
        $this->assertSame(0, $builds, 'the restored table did not need the unit');

        touch($source, time() + 5);
        clearstatcache(true, $source);
        sleep(2);

        $router->match('/x', 'GET');
        $this->assertSame(1, $builds, 'a stale table is rebuilt through the units');
    }

    public function testATableThisProcessWroteIsNeverReread(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $builds = 0;
        $router = (new Router())->setCachePath($cache);
        $router->setRevalidateFreq(1);
        $router->cached($source, function (Router $r) use (&$builds): void {
            $builds++;
            $r->get('/x', 'A::b');
        });

        $router->match('/x', 'GET');
        $this->assertSame(1, $builds, 'the miss built it');

        // Break the source and wait past the window. A self-written table is still
        // authoritative: re-reading it would pay a full OPcache compile (this process
        // cannot cache a file it wrote) to swap in an identical table.
        touch($source, time() + 5);
        clearstatcache(true, $source);
        sleep(2);

        $router->match('/x', 'GET');
        $this->assertSame(1, $builds, 'a self-written table is not re-checked');
    }

    public function testOwnershipOfTheFileIsLostWhenAnotherProcessReplacesIt(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $builds = 0;
        $router = (new Router())->setCachePath($cache);
        $router->setRevalidateFreq(1);
        $router->cached($source, function (Router $r) use (&$builds): void {
            $builds++;
            $r->get('/x', 'A::b');
        });
        $router->match('/x', 'GET');
        $this->assertSame(1, $builds);

        // Another process rebuilds and replaces the file (a deploy hook, or a sibling
        // worker). The new table must be adopted rather than ignored as "ours".
        $other = (new Router())->setCachePath($cache);
        $other->cached($source, static fn(Router $r) => $r->get('/x', 'A::b'));
        $other->compileCache();
        touch($cache, time() + 10);
        clearstatcache(true, $cache);

        sleep(2);
        $router->match('/x', 'GET');

        $this->assertSame(1, $builds, 'the replaced table was read, not rebuilt');
    }

    public function testRevalidateFreqIsClampedToZero(): void
    {
        $router = new Router();
        $router->setRevalidateFreq(-5);

        $this->assertSame(0, $router->getRevalidateFreq());
    }

    public function testAUnitCannotLeakAnOpenGroupIntoTheNextUnit(): void
    {
        $cache = $this->tmpPath('.php');
        $a     = $this->tmpSource();
        $b     = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        // A sticky group call: `prefix()` with no callback leaves the stack open.
        $router->cached($a, static function (Router $r): void {
            $r->prefix('/sticky');
        });
        $router->cached($b, static fn(Router $r) => $r->get('/x', 'A::b'));

        $this->assertNotNull($router->match('/x', 'GET'), 'the second unit starts from an empty stack');

        $state = $router->exportState();
        $this->assertSame([], $state['prefixGroupStack'], 'group state must not survive a unit');
        $this->assertArrayNotHasKey('sticky', $state['groups']['GET'] ?? []);
    }

    public function testAUnitCannotLeakMiddlewareIntoTheNextUnit(): void
    {
        $cache = $this->tmpPath('.php');
        $a     = $this->tmpSource();
        $b     = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->cached($a, static function (Router $r): void {
            $r->middleware('auth');
        });
        $router->cached($b, static fn(Router $r) => $r->get('/public', 'A::b'));
        $router->match('/public', 'GET');

        $state = $router->exportState();
        $this->assertSame(
            [],
            $state['static']['GET']['/public']['groups'],
            'a route in the second unit must not inherit the first unit\u2019s middleware',
        );
    }

    public function testASpecificityTieResolvesTheSameColdAndRestored(): void
    {
        $cache = $this->tmpPath('.php');
        $first = $this->tmpSource();
        $next  = $this->tmpSource();

        $cold = new Router();
        $cold->get('/a/{x:alpha}', 'Alpha::handler');
        $cold->get('/a/{y:alnum}', 'Alnum::handler');
        $expected = $cold->match('/a/xyz', 'GET')['override']['controller'];

        // The same two routes, split across two units in the same order.
        $built = (new Router())->setCachePath($cache);
        $built->cached($first, static fn(Router $r) => $r->get('/a/{x:alpha}', 'Alpha::handler'));
        $built->cached($next, static fn(Router $r) => $r->get('/a/{y:alnum}', 'Alnum::handler'));
        $built->compileCache();

        $restored = (new Router())->setCachePath($cache);
        $restored->cached($first, static fn(Router $r) => null);
        $restored->cached($next, static fn(Router $r) => null);

        $this->assertSame(
            $expected,
            $restored->match('/a/xyz', 'GET')['override']['controller'],
            'unit order must survive the round trip, or a cache hit changes which route wins',
        );
    }

    // -------------------------------------------------------------------------
    //  compileCache / clearCache / cacheStatus
    // -------------------------------------------------------------------------

    public function testCompileCacheWritesWithoutWaitingForARead(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = $this->cacheRouter($cache, $source);
        $this->assertSame(1, $router->compileCache());
        $this->assertFileExists($cache);
    }

    public function testCompileCacheWithoutAUnitHasNothingToCompile(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cached()');

        (new Router())->compileCache();
    }

    public function testCompileCacheNeedsUnits(): void
    {
        $router = (new Router())->setCachePath($this->tmpPath('.php'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cached()');

        $router->compileCache();
    }

    public function testClearCacheDeletesTheFile(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = $this->cacheRouter($cache, $source);
        $router->compileCache();
        $this->assertFileExists($cache);

        $this->assertTrue($router->clearCache());
        $this->assertFileDoesNotExist($cache);
        $this->assertFalse($router->clearCache(), 'clearing twice is not an error, just a no-op');
    }

    public function testCacheStatusReportsFreshAndStale(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        $router = $this->cacheRouter($cache, $source);
        $router->compileCache();

        $status = $router->cacheStatus();
        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['exists']);
        $this->assertTrue($status['fresh']);
        $this->assertSame(1, $status['routes']);
        $this->assertSame([$source], $status['files']);

        touch($source, time() + 5);
        clearstatcache(true, $source);

        $this->assertFalse($router->cacheStatus()['fresh']);
    }

    public function testCacheStatusOnADisabledCache(): void
    {
        $status = (new Router())->disableCache()->cacheStatus();

        $this->assertFalse($status['enabled']);
        $this->assertNull($status['path']);
        $this->assertFalse($status['exists']);
        $this->assertFalse($status['fresh']);
    }

    // -------------------------------------------------------------------------
    //  Failure paths
    // -------------------------------------------------------------------------

    public function testACorruptFileIsRebuiltRatherThanFatal(): void
    {
        $cache  = $this->tmpPath('.php');
        $source = $this->tmpSource();

        // A half-copied or hand-mangled file must not take a request down: the units
        // can always rebuild, and a cache is an optimisation.
        file_put_contents($cache, "<?php\n\nreturn 'not a table';\n");
        clearstatcache(true, $cache);

        $router = $this->cacheRouter($cache, $source, '/fresh');
        $this->assertNotNull($router->match('/fresh', 'GET'));
    }

    public function testAnUnwritableDirectoryDoesNotFailTheRequest(): void
    {
        $cache  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-no-such-dir-'
            . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'routes.php';
        $source = $this->tmpSource();

        $router = $this->cacheRouter($cache, $source, '/x');

        // The directory does not exist yet, so this exercises mkdir; on a read-only
        // mount the same guard applies. Either way the table is served.
        $this->assertNotNull($router->match('/x', 'GET'));
    }

    public function testTheCacheDirectoryIsCreatedWithRestrictivePermissions(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX mode bits only');
        }

        $cache  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-perms-' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR . 'routes.php';
        $source = $this->tmpSource();

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static fn(Router $r) => $r->get('/x', 'A::b'));
        $this->assertSame(1, $router->compileCache());

        $dir = dirname($cache);
        $this->tmpFiles[] = $cache;

        $this->assertSame(0, fileperms($dir) & 0o022, 'the directory must not be group/world writable');
        $this->assertSame(0, fileperms($cache) & 0o077, 'the table must not be group/world readable');

        @unlink($cache);
        @rmdir($dir);
    }

    public function testATableInAGroupWritableDirectoryIsNotLoaded(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX mode bits only');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-unsafe-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $cache  = $dir . DIRECTORY_SEPARATOR . 'routes.php';
        $source = $this->tmpSource();

        // A table that would be served if the directory check did not exist.
        $planted = (new Router())->setCachePath($cache);
        $planted->cached($source, static fn(Router $r) => $r->get('/planted', 'A::b'));
        $this->assertSame(1, $planted->compileCache());

        $router = (new Router())->setCachePath($cache);
        $router->cached($source, static fn(Router $r) => $r->get('/built', 'A::b'));

        $this->assertNotNull($router->match('/built', 'GET'), 'the units rebuild instead');
        $this->assertNull($router->match('/planted', 'GET'), 'the file must not be executed');

        @unlink($cache);
        @rmdir($dir);
    }

    public function testAWarningIsRaisedWhenAsked(): void
    {
        $source = $this->tmpSource();
        $cache  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera-readonly-'
            . bin2hex(random_bytes(6)) . '.php';

        // A path whose parent cannot be created: a file used as a directory.
        $blocker = $this->tmpPath();
        file_put_contents($blocker, '');
        $cache = $blocker . DIRECTORY_SEPARATOR . 'routes.php';

        $router = (new Router())->setCachePath($cache);
        $router->warnOnWriteFailure(true);
        $router->cached($source, static fn(Router $r) => $r->get('/x', 'A::b'));

        $warned = null;
        set_error_handler(static function (int $no, string $msg) use (&$warned): bool {
            $warned = $msg;

            return true;
        });

        try {
            $this->assertNotNull($router->match('/x', 'GET'), 'the table is served regardless');
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($warned);
        $this->assertStringContainsString('Route cache not written', $warned);
    }

    public function testAWriteFailureIsSilentByDefault(): void
    {
        $source  = $this->tmpSource();
        $blocker = $this->tmpPath();
        file_put_contents($blocker, '');

        $router = (new Router())->setCachePath($blocker . DIRECTORY_SEPARATOR . 'routes.php');
        $router->cached($source, static fn(Router $r) => $r->get('/x', 'A::b'));

        $warned = false;
        set_error_handler(static function (int $no) use (&$warned): bool {
            if ($no === E_USER_WARNING) {
                $warned = true;
            }

            return true;
        });

        try {
            $this->assertNotNull($router->match('/x', 'GET'));
        } finally {
            restore_error_handler();
        }

        // Suppressed filesystem notices are still routed through the handler; what
        // matters is that no E_USER_WARNING is raised.
        $this->assertFalse($warned, 'a read-only cache directory is a configuration, not an error');
    }
}
