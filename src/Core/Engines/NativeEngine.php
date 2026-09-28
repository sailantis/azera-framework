<?php
namespace Azera\Core\Engines
{

    use Azera\Core\ViewEngine;

    /**
     * Native PHP template engine.
     *
     * Templates are plain `.php` files. Variables are extracted into the local
     * scope and the file is included directly, making this engine as fast as
     * hand-written PHP includes.
     */
    class NativeEngine extends ViewEngine
    {
        protected string $extension = '.php';

        /** @var array<string, callable> Custom functions available in templates. */
        protected array $functions = [];

        protected array $renderStack = [];

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
            $this->functions[$name] = $fn;
            return $this;
        }

        /**
         * Dispatch calls to registered functions from within templates.
         *
         * Templates are included inside a method scope where `$this` is the
         * NativeEngine instance, so `$this->myFunc($arg)` naturally routes here
         * for any name that is not an actual engine method.
         *
         * @throws \LogicException When the function is not registered.
         */
        public function __call(string $name, array $args): mixed
        {
            if (isset($this->functions[$name])) {
                return ($this->functions[$name])(...$args);
            }
            throw new \LogicException(
                "Template function '{$name}' is not registered. Use addFunction() to register it before rendering."
            );
        }

        /**
         * Render a view (and optional layout) and return the result.
         *
         * @param string $view View name to render.
         * @param array $vars Additional variables for this render call.
         * @return string Rendered content.
         */
        public function render(string $view, array $vars = []): string
        {
            $content = $this->renderPartial($view, $vars);

            if ($this->layout !== null && $this->renderDepth === 0) {
                $content = $this->renderLayout($this->layout, $content, $vars);
            }

            return $content;
        }

        /**
         * Render a partial view template and return the generated output.
         *
         * Variables are merged with global view variables and extracted into the
         * template scope. Per-call variables override globals.
         *
         * @param string $view View name to resolve and render.
         * @param array $vars Variables for this render call.
         * @return string Rendered HTML/output.
         * @throws \RuntimeException If the view file cannot be resolved.
         */
        public function renderPartial(string $view, array $vars = []): string
        {
            $sourcePath = $this->resolveView($view);
            if (!is_file($sourcePath)) {
                throw new \RuntimeException("View not found: {$sourcePath}");
            }

            if (isset($this->renderStack[$sourcePath])) {
                $chain = [...array_keys($this->renderStack), $sourcePath];
                throw new \RuntimeException(
                    'Recursive template rendering detected: ' . \implode(' -> ', $chain)
                );
            }

            $this->renderStack[$sourcePath] = true;

            $this->renderDepth++;
            // Call-site vars override globals (unlike EXTR_SKIP which silently drops them)
            extract([...$this->vars, ...$vars]);

            ob_start();
            include $sourcePath;
            $output = ob_get_clean();

            unset($this->renderStack[$sourcePath]);

            $this->renderDepth--;
            return $output;
        }

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
        public function renderLayout(string $layout, string $content, array $vars = []): string
        {
            $vars['content'] = $content;
            return $this->renderPartial($layout, $vars);
        }
    }
}

namespace
{

    /** Escape for HTML body context (e.g. inside <p> or <div>)
     */
    function esc_html($str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

}