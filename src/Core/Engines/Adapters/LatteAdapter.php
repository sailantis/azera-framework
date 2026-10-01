<?php
namespace Azera\Core\Engines\Adapters;

use Azera\Core\ViewEngine;

/**
 * Latte template engine adapter.
 *
 * Wraps Nette's Latte so Azera applications can use `.latte` templates.
 * Requires `latte/latte` to be installed:
 *
 * ```sh
 * composer require latte/latte
 * ```
 *
 * Latte filters are registered natively and are available in templates using
 * the pipe syntax: `{$value|filterName}`.
 *
 * The sandbox is NOT enabled. Latte ships a `SandboxExtension` and a
 * `Latte\Sandbox\SecurityPolicy`, but both are inert until a policy is set
 * (`Engine::setPolicy()`), and enabling the sandbox changes the compiled
 * template — so an adapter that silently turned it on would measure a different
 * engine than `latte` names. Use {@see getDriver()} to opt in explicitly:
 *
 * ```php
 * $adapter->getDriver()
 *     ->setPolicy(\Latte\Sandbox\SecurityPolicy::createSafePolicy())
 *     ->setSandboxMode(true);
 * ```
 *
 * NOTE the name collision: this is *Latte's* `setPolicy()`/`setSandboxMode()`,
 * not Clarity's. Clarity replaced its own `setSandboxMode()` with a
 * `Clarity\Engine\Policy` object (see {@see \Azera\Core\Engines\ClarityEngine}),
 * and the two engines' policies are unrelated types.
 *
 * Cache location: `sys_get_temp_dir()/latte_cache` (override with
 * {@see setCachePath()}). Pass an empty string to disable caching.
 */
class LatteAdapter extends ViewEngine
{
    protected string $extension = '.latte';
    protected string $cachePath;

    /** Filters registered before Latte is initialised. */
    protected array $pendingFilters = [];

    /** Functions registered before Latte is initialised. */
    protected array $pendingFunctions = [];

    protected \Latte\Engine $latte;

    public function __construct(array $vars = [])
    {
        parent::__construct($vars);
        $this->cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'latte_cache';
    }

    // -------------------------------------------------------------------------
    // Cache configuration
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Pass an empty string to disable caching entirely.
     * Changes take effect immediately even if Latte is already initialised.
     */
    public function setCachePath(string $path): static
    {
        $this->cachePath = $path;
        if (isset($this->latte)) {
            $this->latte->setCacheDirectory($path !== '' ? $path : null);
        }
        return $this;
    }

    /** {@inheritdoc} */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /** {@inheritdoc} */
    public function flushCache(): static
    {
        if (!is_dir($this->cachePath)) {
            return $this;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Filters and functions
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Registers a Latte filter callable. Available in templates as
     * `{$value|name}` or `{$value|name: arg1, arg2}`.
     *
     * Can be called before or after the first render.
     */
    public function addFilter(string $name, callable $fn): static
    {
        if (isset($this->latte)) {
            $this->latte->addFilter($name, $fn);
        } else {
            $this->pendingFilters[$name] = $fn;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Registers a Latte function callable. Available in templates as
     * `{name(arg1, arg2)}`.
     *
     * Can be called before or after the first render.
     */
    public function addFunction(string $name, callable $fn): static
    {
        if (isset($this->latte)) {
            $this->latte->addFunction($name, $fn);
        } else {
            $this->pendingFunctions[$name] = $fn;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Returns the underlying `\Latte\Engine` instance for advanced
     * configuration (extensions, sandbox policy, syntax, etc.).
     * Initialises Latte on first call if not already done.
     */
    public function getDriver(): mixed
    {
        $this->ensureLatte();
        return $this->latte;
    }

    // -------------------------------------------------------------------------
    // Internal setup
    // -------------------------------------------------------------------------

    protected function ensureLatte(): void
    {
        if (isset($this->latte)) {
            return;
        }
        if (!class_exists('\Latte\Engine')) {
            throw new \RuntimeException(
                'Latte not installed. Run: composer require latte/latte'
            );
        }
        $this->latte = new \Latte\Engine();
        $this->latte->setLoader(new \Latte\Loaders\FileLoader($this->viewPath));
        $this->latte->setCacheDirectory($this->cachePath !== '' ? $this->cachePath : null);
        // Match the other adapters' development posture: a changed template is
        // recompiled rather than served from a stale class.
        $this->latte->setAutoRefresh(true);

        foreach ($this->pendingFilters as $name => $fn) {
            $this->latte->addFilter($name, $fn);
        }
        $this->pendingFilters = [];
        foreach ($this->pendingFunctions as $name => $fn) {
            $this->latte->addFunction($name, $fn);
        }
        $this->pendingFunctions = [];
    }

    // -------------------------------------------------------------------------
    // View name conversion
    // -------------------------------------------------------------------------

    /**
     * Convert a Azera view name to a Latte template name.
     *
     * Latte has one `FileLoader` with one base directory, so a namespace is
     * resolved to its REGISTERED PATH and then expressed relative to `viewPath`
     * rather than flattened blindly — the benchmark registers `benchmarks` at the
     * same directory as `viewPath`, so `benchmarks::sample` becomes
     * `sample.latte`. A namespace pointing OUTSIDE `viewPath` cannot be reached
     * by a single loader and is reported as such rather than silently resolved to
     * the wrong file.
     *
     * | Azera              | Latte                   |
     * |---------------------|-------------------------|
     * | `home/index`        | `home/index.latte`      |
     * | `home.index`        | `home/index.latte`      |
     * | `admin::dashboard`  | `admin/dashboard.latte` (admin registered at viewPath/admin) |
     *
     * @throws \RuntimeException for an unregistered namespace, or one that is not
     *                           inside `viewPath`.
     */
    protected function viewNameToLatte(string $view): string
    {
        $prefix = '';

        $sep = \strpos($view, '::');
        if ($sep !== false) {
            $ns   = \substr($view, 0, $sep);
            $view = \substr($view, $sep + 2);

            if (!isset($this->namespaces[$ns])) {
                throw new \RuntimeException("Unknown view namespace: {$ns}");
            }

            $prefix = $this->relativeToViewPath($this->namespaces[$ns], $ns);
        }

        $relative = \str_replace(['.', '\\'], '/', \trim($view, '/'));
        $full     = \trim($prefix . '/' . $relative, '/');
        $suffix   = \str_ends_with($full, $this->extension) ? '' : $this->extension;

        return $full . $suffix;
    }

    /**
     * Express a namespace path relative to `viewPath` for Latte's single loader.
     *
     * Returns an empty string when the namespace IS `viewPath`.
     *
     * @throws \RuntimeException when the namespace lies outside `viewPath`.
     */
    protected function relativeToViewPath(string $path, string $ns): string
    {
        $norm = static fn(string $p): string => \rtrim(\str_replace('\\', '/', $p), '/');
        $base = $norm($this->viewPath);
        $dir  = $norm($path);

        if ($dir === $base) {
            return '';
        }
        if (\str_starts_with($dir . '/', $base . '/')) {
            return \substr($dir, \strlen($base) + 1);
        }

        throw new \RuntimeException(
            "Namespace '{$ns}' resolves to '{$dir}', which is outside the Latte view path '{$base}'. "
                . "Latte uses a single loader directory, so such a namespace cannot be reached."
        );
    }

    // -------------------------------------------------------------------------
    // ViewEngine implementation
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function render(string $view, array $vars = []): string
    {
        $content = $this->renderPartial($view, $vars);
        if ($this->layout !== null && $this->renderDepth === 0) {
            $content = $this->renderLayout($this->layout, $content, $vars);
        }
        return $content;
    }

    /** {@inheritdoc} */
    public function renderPartial(string $view, array $vars = []): string
    {
        $this->ensureLatte();
        $name   = $this->viewNameToLatte($view);
        $merged = [...$this->vars, ...$vars];
        $this->renderDepth++;
        try {
            return $this->latte->renderToString($name, $merged);
        } finally {
            $this->renderDepth--;
        }
    }

    /** {@inheritdoc} */
    public function renderLayout(string $layout, string $content, array $vars = []): string
    {
        $vars['content'] = $content;
        return $this->renderPartial($layout, $vars);
    }
}
