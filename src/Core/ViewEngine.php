<?php
namespace Azera\Core;

use LogicException;
use RuntimeException;

/**
 * Abstract base for all view engine implementations.
 *
 * Holds shared configuration state (path, layout, extension, namespaces,
 * global variables, render depth) and the path-resolution logic.
 * Concrete engines (NativeEngine, ClarityEngine, …) extend this class and
 * implement the three abstract rendering methods.
 */
abstract class ViewEngine
{
    protected string $extension; // set by concrete engines
    protected array $namespaces = [];
    protected string $viewPath = __DIR__ . '/../../../../../views'; // relative to vendor/sailantis/azera-framework/src/Core/
    protected int $renderDepth = 0;
    protected ?string $layout = null;
    protected array $vars = [];

    /**
     * Create a new ViewEngine instance.
     *
     * @param array $vars Initial variables available to all views.
     */
    public function __construct(array $vars = [])
    {
        $this->vars = $vars;
    }

    /**
     * Set the view file extension for this instance.
     *
     * @param string $ext Extension with or without a leading dot.
     * @return $this
     */
    public function setExtension(string $ext): static
    {
        if ($ext !== '' && $ext[0] !== '.') {
            $ext = ".{$ext}";
        }
        $this->extension = $ext;
        return $this;
    }

    /**
     * Get the effective file extension used when resolving templates.
     *
     * @return string Extension including leading dot or empty string.
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Add a namespace for view resolution.
     *
     * Views can be referenced using the syntax "namespace::view.name".
     *
     * @param string $name Namespace name to register.
     * @param string $path Filesystem path corresponding to the namespace.
     * @return $this
     */
    public function addNamespace(string $name, string $path): static
    {
        $this->namespaces[$name] = rtrim($path, '/');
        return $this;
    }

    /**
     * Get the currently registered view namespaces.
     *
     * @return array Associative array of namespace => path mappings.
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Set the base path for resolving relative view names.
     *
     * @param string $path Base directory for views.
     * @return $this
     */
    public function setViewPath(string $path): static
    {
        $this->viewPath = rtrim($path, '/');
        return $this;
    }

    /**
     * Get the currently configured base path for view resolution.
     *
     * @return string Base directory for views.
     */
    public function getViewPath(): string
    {
        return $this->viewPath;
    }

    /**
     * Set the layout template name to be used when calling `render()`.
     *
     * The layout will receive a `content` variable containing the
     * rendered view output.
     *
     * @param string|null $layout Layout view name or null to disable.
     * @return $this
     */
    public function setLayout(?string $layout): static
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Get the currently configured layout view name.
     *
     * @return string|null Layout name or null when none set.
     */
    public function getLayout(): ?string
    {
        return $this->layout;
    }

    /**
     * Set a single view variable.
     *
     * @param string $name Variable name available inside templates.
     * @param mixed $value Value assigned to the variable.
     * @return $this
     */
    public function setVar(string $name, mixed $value): static
    {
        $this->vars[$name] = $value;
        return $this;
    }

    /**
     * Merge multiple variables into the view's variable set.
     *
     * Later values override earlier ones for the same keys.
     *
     * @param array $vars Associative array of variables.
     * @return $this
     */
    public function setVars(array $vars): static
    {
        $this->vars = [...$this->vars, ...$vars];
        return $this;
    }

    /**
     * Render a view (and optional layout) and return the result.
     *
     * @param string $view View name to render.
     * @param array $vars Additional variables for this render call.
     * @return string Rendered content.
     */
    abstract public function render(string $view, array $vars = []): string;

    /**
     * Render a partial view (without applying a layout) and return the output.
     *
     * @param string $view View name to resolve and render.
     * @param array $vars Variables for this render call.
     * @return string Rendered HTML/output.
     */
    abstract public function renderPartial(string $view, array $vars = []): string;

    /**
     * Render a layout template wrapping provided content.
     *
     * The layout receives the rendered view in the `content` variable.
     *
     * @param string $layout Layout view name.
     * @param string $content Previously rendered content.
     * @param array $vars Additional variables to pass to the layout.
     * @return string Rendered layout output.
     */
    abstract public function renderLayout(string $layout, string $content, array $vars = []): string;

    /**
     * Get current render nesting depth. Useful to detect top-level renders
     * (depth 0) when deciding whether to apply a layout.
     *
     * @return int Current render depth (0 = top-level).
     */
    public function getRenderDepth(): int
    {
        return $this->renderDepth;
    }

    /**
     * Resolve a view name to an actual file path on the filesystem.
     *
     * View names are confined to the configured view path (or to the
     * registered namespace root). Absolute names — POSIX, Windows and UNC —
     * are refused, and any name that canonicalises outside its root (via
     * `..` or otherwise) is refused too. Without this boundary a
     * user-influenced view name would reach `include`, giving arbitrary
     * local file inclusion and code execution for `.php` targets.
     *
     * @param string $view View name to resolve.
     * @throws \RuntimeException If the view cannot be resolved.
     * @return string Resolved file path.
     */
    protected function resolveView(string $view): string
    {
        if ($view === '') {
            throw new RuntimeException("Empty view name");
        }

        $ns = \strstr($view, '::', true);
        if ($ns !== false) {
            // namespaced view
            $name = \substr($view, \strlen($ns) + 2);

            if (!isset($this->namespaces[$ns])) {
                throw new RuntimeException("Unknown view namespace: $ns");
            }

            $root = $this->namespaces[$ns];
            $path = $root . '/' . \str_replace('.', '/', $name) . $this->extension;

            return $this->withinRoot($path, $root, $view);
        }

        if ($this->isAbsolutePath($view)) {
            throw new RuntimeException(
                "View name must be relative to the view path: {$view}"
            );
        }

        $addExtension = !\str_ends_with($view, $this->extension);

        if ($view[0] === '.') {
            // literal relative path: ./partials/header or ../shared/footer
            $path = $this->viewPath . '/' . $view;
        } else {
            // relative view name, resolve to path using dot-notation
            $path = $this->viewPath . '/' . \str_replace('.', '/', $view);
        }

        if ($addExtension) {
            $path .= $this->extension;
        }

        return $this->withinRoot($path, $this->viewPath, $view);
    }

    /**
     * Detect absolute paths in every spelling PHP accepts: POSIX ("/x"),
     * Windows drive ("C:/x", "C:\x"), and UNC ("\\server\share").
     */
    protected function isAbsolutePath(string $path): bool
    {
        $len = \strlen($path);

        if ($path[0] === '/') {
            return true;
        }

        if ($path[0] === '\\' && $len >= 2 && $path[1] === '\\') {
            return true;
        }

        if (
            $len >= 3
            && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\')
            && \ctype_alpha($path[0])
        ) {
            return true;
        }

        return false;
    }

    /**
     * Ensure the resolved path stays inside the given root.
     *
     * @param string $path Resolved (possibly not yet canonical) file path.
     * @param string $root Root directory the path must stay within.
     * @param string $view Original view name, for error messages.
     * @throws \RuntimeException When the path escapes the root.
     * @return string The resolved path (unchanged when it is still missing on disk).
     */
    protected function withinRoot(string $path, string $root, string $view): string
    {
        $normalized = $this->normalizePath($path);
        $rootPath   = $this->normalizePath($root);

        if ($rootPath === '') {
            return $path;
        }

        $rootPath = \rtrim($rootPath, '/') . '/';

        if ($normalized !== $rootPath && !\str_starts_with($normalized, $rootPath)) {
            throw new RuntimeException(
                "View '{$view}' resolves outside the view path"
            );
        }

        return $path;
    }

    /**
     * Collapse `.` and `..` segments without touching the filesystem.
     *
     * `realpath()` cannot be used because a resolved view may legitimately
     * not exist yet (the caller reports "View not found"), so the boundary
     * check is performed lexically on the normalised path.
     */
    protected function normalizePath(string $path): string
    {
        $path = \str_replace('\\', '/', $path);

        // Preserve a leading slash (absolute) or drive prefix.
        $prefix = '';
        if (\preg_match('#^([A-Za-z]:)?/#', $path, $m)) {
            $prefix = $m[1] ?? '';
            $path   = \substr($path, \strlen($m[0]));
        }

        $segments = [];

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== []) {
                    \array_pop($segments);
                }
                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . '/' . \implode('/', $segments);
    }

    // -- Clarity-specific helper methods for IDE completion --

    /**
     * Register a custom filter callable.
     *
     * Filters transform a piped value and are invoked with pipe syntax,
     * e.g. `{{ value|name }}` or `{{ value|name(arg) }}`.
     *
     * @param string   $name Filter name used in templates (e.g. 'currency').
     * @param callable $fn   fn($value, ...$args): mixed
     * @return static
     */
    public function addFilter(string $name, callable $fn): static
    {
        throw new LogicException("Filters are not supported by this ViewEngine.");
    }

    /**
     * Register a custom function callable.
     *
     * Functions are called directly in templates, e.g. `{{ name(arg) }}`.
     * This is distinct from filters, which transform a piped value.
     *
     * @param string   $name Function name used in templates (e.g. 'formatDate').
     * @param callable $fn   fn(...$args): mixed
     * @return static
     */
    public function addFunction(string $name, callable $fn): static
    {
        throw new LogicException("Functions are not supported by this ViewEngine.");
    }

    /**
     * Return the underlying engine/driver object for advanced configuration.
     *
     * Returns the raw engine instance (e.g. `\Twig\Environment`,
     * `\League\Plates\Engine`, `\Illuminate\View\Factory`) for cases not
     * covered by the adapter API.  Returns `null` for engines without a
     * separate driver object (Clarity, Native).
     *
     * @return mixed
     */
    public function getDriver(): mixed
    {
        return null;
    }

    /**
     * Set the directory where compiled templates should be cached.
     * @param string $path
     */
    public function setCachePath(string $path): static
    {
        throw new LogicException("Caching is not supported by this ViewEngine.");
    }

    /**
     * Get the currently configured cache directory.
     */
    public function getCachePath(): string
    {
        throw new LogicException("Caching is not supported by this ViewEngine.");
    }

    /**
     * Flush all cached compiled templates.
     */
    public function flushCache(): static
    {
        throw new LogicException("Caching is not supported by this ViewEngine.");
    }

}