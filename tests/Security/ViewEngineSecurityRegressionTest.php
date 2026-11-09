<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Engines\NativeEngine;
use Azera\Core\ViewEngine;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the view-engine LFI findings of the 2026 security
 * review:
 *  - `extract()` let a view variable shadow the resolved template path
 *  - `resolveView()` accepted absolute and `..` paths, escaping the view root
 */
class ViewEngineSecurityRegressionTest extends TestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $this->root    = sys_get_temp_dir() . '/azera_view_root_' . bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir() . '/azera_view_outside_' . bin2hex(random_bytes(6));

        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);

        file_put_contents($this->root . '/home.php', 'HOME');
        file_put_contents($this->outside . '/evil.php', 'PWNED');
        mkdir($this->root . '/partials', 0777, true);
        file_put_contents($this->root . '/partials/header.php', 'HEADER');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
        $this->rmTree($this->outside);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function engine(): NativeEngine
    {
        $engine = new NativeEngine();
        $engine->setViewPath($this->root);
        return $engine;
    }

    /** Expose the protected resolver for direct testing. */
    private function resolve(NativeEngine $engine, string $view): string
    {
        $ref = new \ReflectionMethod(ViewEngine::class, 'resolveView');
        return $ref->invoke($engine, $view);
    }

    /* -------------------------------------------- extract() shadowing (LFI) */

    /**
     * A view variable named `sourcePath` must not redirect the include.
     */
    public function testViewVariableCannotShadowTemplatePath(): void
    {
        $engine = $this->engine();

        $output = $engine->render('home', [
            'sourcePath' => $this->outside . '/evil.php',
            'output'     => 'x',
            'view'       => 'y',
        ]);

        $this->assertSame('HOME', $output);
        $this->assertStringNotContainsString('PWNED', $output);
    }

    /**
     * The same for `renderPartial` called directly.
     */
    public function testRenderPartialCannotBeHijackedByVariable(): void
    {
        $engine = $this->engine();

        $output = $engine->renderPartial('home', [
            'sourcePath'     => $this->outside . '/evil.php',
            '__azeraViewPath' => $this->outside . '/evil.php',
            'vars'           => [],
        ]);

        $this->assertSame('HOME', $output);
    }

    /**
     * Template variables still reach the template scope.
     */
    public function testVariablesAreStillAvailableInTemplate(): void
    {
        file_put_contents($this->root . '/greet.php', '<?= $name ?>');

        $this->assertSame('World', $this->engine()->render('greet', ['name' => 'World']));
    }

    /**
     * `$this` remains usable inside native templates.
     */
    public function testThisIsStillBoundInsideTemplate(): void
    {
        file_put_contents(
            $this->root . '/fn.php',
            '<?= $this->shout("hi") ?>'
        );

        $engine = $this->engine();
        $engine->addFunction('shout', fn(string $s) => strtoupper($s) . '!');

        $this->assertSame('HI!', $engine->render('fn'));
    }

    /* -------------------------------------------- path traversal in resolve */

    public function testAbsolutePosixPathIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolve($this->engine(), '/etc/passwd');
    }

    public function testAbsoluteWindowsPathIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolve($this->engine(), 'C:/Windows/win.ini');
    }

    public function testAbsoluteUncPathIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolve($this->engine(), '\\\\server\\share\\evil');
    }

    public function testDotDotEscapeIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolve($this->engine(), '../outside/evil');
    }

    /**
     * Dot-notation expansion turns the `..` into separators, so the name
     * collapses back inside the root. Assert containment either way.
     */
    public function testNestedDotDotCannotEscapeRoot(): void
    {
        $path = $this->resolve($this->engine(), 'partials/../../outside/evil');

        $this->assertStringStartsWith(
            str_replace('\\', '/', $this->root) . '/',
            str_replace('\\', '/', $path)
        );
    }

    public function testRelativeViewInsideRootResolves(): void
    {
        $path = $this->resolve($this->engine(), 'partials.header');

        $this->assertSame($this->root . '/partials/header.php', $path);
    }

    public function testDotPrefixedViewInsideRootResolves(): void
    {
        $path = $this->resolve($this->engine(), './partials/header.php');

        $this->assertSame($this->root . '/./partials/header.php', $path);
    }

    public function testNamespaceViewInsideRootResolves(): void
    {
        $engine = $this->engine();
        $engine->addNamespace('admin', $this->root);

        $this->assertSame(
            $this->root . '/partials/header.php',
            $this->resolve($engine, 'admin::partials.header')
        );
    }

    /**
     * A namespaced name carrying `..` must not escape the namespace root.
     * The dot-expansion collapses it back inside, so assert containment.
     */
    public function testNamespaceTraversalCannotEscapeRoot(): void
    {
        $engine = $this->engine();
        $engine->addNamespace('admin', $this->root);

        $path = $this->resolve($engine, 'admin::../outside/evil');

        $this->assertStringStartsWith(
            str_replace('\\', '/', $this->root) . '/',
            str_replace('\\', '/', $path)
        );
    }

    /**
     * Rendering an escaped path must fail rather than execute the file.
     */
    public function testRenderOfTraversalPathDoesNotExecuteOutsideFile(): void
    {
        $engine = $this->engine();

        try {
            $output = $engine->render('../' . basename($this->outside) . '/evil');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('outside the view path', $e->getMessage());
            return;
        }

        $this->fail('Traversal render unexpectedly succeeded: ' . $output);
    }
}
