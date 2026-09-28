<?php
namespace Azera\Core\Engines\Adapters;

use Azera\Core\ViewEngine;
use Spiral\Core\Container;
use Spiral\Stempler\Builder;
use Spiral\Stempler\Config\StemplerConfig;
use Spiral\Stempler\Directive;
use Spiral\Stempler\StemplerCache;
use Spiral\Stempler\StemplerEngine;
use Spiral\Stempler\Transform\Finalizer;
use Spiral\Stempler\Transform\Visitor;
use Spiral\Views\ContextInterface;
use Spiral\Views\ViewContext;
use Spiral\Views\ViewLoader;

/**
 * Stempler template engine adapter (Spiral).
 *
 * Wraps Spiral's Stempler so Azera applications can use `.dark.php` templates.
 * Requires `spiral/stempler-bridge` to be installed:
 *
 * ```sh
 * composer require spiral/stempler-bridge
 * ```
 *
 * Not `spiral/stempler`: that package provides the parser, directives and
 * visitors, but `Spiral\Stempler\StemplerEngine` — the class instantiated below —
 * lives in the bridge, which also pulls in `spiral/views` for `ViewLoader`. The
 * bridge depends on `spiral/stempler`, so requiring it alone is enough; naming
 * both risks pinning the two out of step.
 *
 * Stempler mixes HTML and PHP directly: `{{ $var }}` prints, `@foreach(...)`
 * and `@if(...)` are directives, and inheritance uses `<extends path="..."/>`
 * plus `<block:name>`. Templates are compiled to PHP classes and, when a cache
 * directory is configured, persisted there.
 *
 * The `path` attribute is not a style preference: Spiral's documented
 * `<extends:layouts/main/>` form is mangled in transit and silently DROPS the
 * inheritance. The HTML grammar does not treat `:` as a name character, so the
 * tag's name becomes the text before the colon plus the first character after it
 * — `extends:layouts/main/` is parsed as a tag named `extendsl`. `ExtendsParent`
 * then never sees an `extends:` name, no parent is merged, and the raw tag is
 * printed into the output as `<<mextends:layouts/main/>`. It is not an error, so
 * the only symptom is a page missing its layout. `ExtendsParent::getPath()` has
 * a second branch that reads a `path` attribute, and that form parses cleanly
 * (verified by rendering both against a layout with its own marker).
 *
 * Unlike Twig/Blade/Plates, `Spiral\Stempler\StemplerEngine` is `final` and has
 * no bare mode: it takes a container, a config, an optional cache and a loader,
 * and the directives (`@foreach`, `@if`, …) come from an explicit list rather
 * than being built in. Spiral normally assembles all of that through its
 * bootloaders. This adapter performs the same wiring directly — a minimal
 * container and the directive/visitor set the framework's own
 * StemplerBootloader registers — so the engine can be measured on its own,
 * exactly as the other three adapters wrap their engines without their
 * frameworks.
 *
 * Cache location: `sys_get_temp_dir()/stempler_cache` (override with
 * {@see setCachePath()}). Pass an empty string to disable caching.
 */
class StemplerAdapter extends ViewEngine
{
    protected string $extension = '.dark.php';
    protected string $cachePath;

    /**
     * The Spiral container the engine compiles through.
     *
     * Stempler resolves directives and visitors through it, and the compiled
     * view classes run their rendering inside a container scope. Nothing is
     * bound to it: the directive and visitor types this adapter needs are
     * registered explicitly on the config, so a bare container is sufficient.
     */
    protected Container $container;

    protected StemplerEngine $engine;
    protected ContextInterface $context;
    protected ?StemplerCache $cache = null;

    public function __construct(array $vars = [])
    {
        parent::__construct($vars);

        // The guard belongs HERE, not in buildEngine(): the constructor already
        // instantiates Spiral\Core\Container and Spiral\Views\ViewContext, so a
        // consumer who installed the wrong package would hit "Class not found"
        // before any later check could run. StemplerEngine is named because it
        // lives in spiral/stempler-bridge, the package that brings the rest.
        if (!class_exists(StemplerEngine::class)) {
            throw new \RuntimeException(
                'Stempler not installed. Run: composer require spiral/stempler-bridge'
            );
        }

        $this->cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stempler_cache';
        $this->container = new Container();
        $this->context   = new ViewContext();
    }

    /**
     * {@inheritdoc}
     *
     * Pass an empty string to disable caching entirely.
     *
     * The engine and its cache are built lazily on first render, so calling this
     * afterwards rebuilds them. Without that, a cache path set after the first
     * render would be silently ignored — the same trap the Twig adapter
     * documents.
     */
    public function setCachePath(string $path): static
    {
        $this->cachePath = $path;
        if (isset($this->engine)) {
            $this->cache = $path !== '' ? new StemplerCache($path) : null;
            $this->buildEngine();
        }
        return $this;
    }

    /** {@inheritdoc} */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * {@inheritdoc}
     *
     * Compiled templates are PHP CLASSES loaded into the current process
     * (`class_exists` / `eval`), so deleting the cached files is not enough on
     * its own: a class already declared cannot be redeclared, which is what
     * makes this a no-op for a process that has already rendered.
     */
    public function flushCache(): static
    {
        if ($this->cachePath !== '' && is_dir($this->cachePath)) {
            foreach (glob($this->cachePath . '/*') ?: [] as $file) {
                @unlink($file);
            }
        }
        return $this;
    }

    /** {@inheritdoc} */
    public function render(string $view, array $vars = []): string
    {
        $renderVars = [...$this->vars, ...$vars];
        $content    = $this->renderPartial($view, $renderVars);

        if ($this->layout !== null) {
            return $this->renderLayout($this->layout, $content, $renderVars);
        }

        return $content;
    }

    /** {@inheritdoc} */
    public function renderPartial(string $view, array $vars = []): string
    {
        return $this->engine()->get($this->stemplerViewName($view), $this->context)
            ->render([...$this->vars, ...$vars]);
    }

    /**
     * {@inheritdoc}
     *
     * Stempler has its own inheritance (`<extends path="..."/>`), so an
     * Azera-level layout is rendered as an ordinary template with the content
     * handed to it.
     */
    public function renderLayout(string $layout, string $content, array $vars = []): string
    {
        return $this->engine()->get($this->stemplerViewName($layout), $this->context)->render([
            ...$this->vars,
            ...$vars,
            'content' => $content,
        ]);
    }

    // -------------------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------------------

    /**
     * Azera's view-name syntax translated to Stempler's.
     *
     * Azera resolves `namespace::view.name` (Twig's separator); Stempler's
     * loader uses a SINGLE colon (`namespace:view.name`, see
     * LoaderInterface::NS_SEPARATOR). Passing Azera's form straight through does
     * not fail loudly: the loader parses no namespace, looks for a file literally
     * named `benchmarks::sample.dark.php`, and reports "Unable to read file"
     * against the DIRECTORY — a message that points at the path rather than the
     * separator.
     */
    private function stemplerViewName(string $view): string
    {
        return str_replace('::', ':', $view);
    }

    private function engine(): StemplerEngine
    {
        if (!isset($this->engine)) {
            $this->buildEngine();
        }

        return $this->engine;
    }

    /**
     * Build the engine and its loader from the current paths and cache path.
     *
     * Rebuilt (rather than configured once) because the loader is immutable: it
     * reads its namespace map at construction, so view paths registered after
     * the first render would otherwise be ignored.
     */
    private function buildEngine(): void
    {
        // The DEFAULT namespace points at the view path, so an unprefixed name
        // like `layouts/main` resolves relative to it — which is how Stempler
        // templates themselves reference each other
        // (`<extends path="layouts/main"/>`).
        $namespaces = ['default' => $this->viewPath] + $this->namespaces;

        $loader = (new ViewLoader($namespaces))->withExtension('dark.php');

        $this->cache  = $this->cachePath !== '' ? new StemplerCache($this->cachePath) : null;
        $this->engine = new StemplerEngine($this->container, $this->config(), $this->cache);
        $this->engine = $this->engine->withLoader($loader);
    }

    /**
     * The directive, processor and visitor set Stempler needs to be useful.
     *
     * These are the defaults Spiral's own StemplerBootloader registers. They are
     * stated here rather than inherited because this adapter drives the engine
     * WITHOUT Spiral's bootloaders — and the directives are not built into the
     * engine, so omitting them would fail on the first `@foreach` with an error
     * about an unknown directive rather than anything about configuration.
     */
    private function config(): StemplerConfig
    {
        return new StemplerConfig([
            'directives' => [
                Directive\PHPDirective::class,
                Directive\LoopDirective::class,
                Directive\JsonDirective::class,
                Directive\ConditionalDirective::class,
            ],
            'processors' => [],
            'visitors'   => [
                Builder::STAGE_PREPARE => [
                    Visitor\DefineBlocks::class,
                    Visitor\DefineAttributes::class,
                ],
                Builder::STAGE_TRANSFORM => [],
                Builder::STAGE_FINALIZE  => [
                    Visitor\DefineStacks::class,
                    Finalizer\StackCollector::class,
                ],
                Builder::STAGE_COMPILE => [],
            ],
        ]);
    }
}
