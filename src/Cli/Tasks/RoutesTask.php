<?php

namespace Azera\Cli\Tasks;

use Azera\AppContext;
use Azera\Cli\Task;
use RuntimeException;

/**
 * Inspect and manage the compiled route table.
 *
 * Usage:
 *   routes:list              List every registered route
 *   routes:cache             Rebuild the table now and write it
 *   routes:clear             Delete the compiled table
 *   routes:status            Report whether it exists and whether it is fresh
 *   routes:status --strict   Same, but exit 1 unless it exists and is fresh
 *
 * None of these are required. The router writes the table itself the first time a
 * request reads it after the files change (see Router::cached()), so a project that
 * never runs routes:cache still gets a warm table -- just at the first request after
 * a deploy instead of before it. These commands exist for ops: warming during a
 * deploy, clearing by hand, and inspecting what is on disk.
 *
 * Why the table is worth compiling
 * --------------------------------
 * A cold SAPI (php-fpm, php-cgi, volucer's spawn_cgi) builds the whole route table
 * inside the timed request, because nothing survives between requests. Measured on
 * a 119-route table: 148 us to build from source against 3 us to require a compiled
 * one, which took nginx+php-fpm's /echo from 24,657 to 38,706 rps at c=96 on 12
 * cores.
 *
 * The compiled file is PHP source on purpose. OPcache compiles it once and every
 * later request includes it in ~0.1 us; a serialized blob would rebuild the arrays
 * in C on every request and measured 163 us against 3 us. That is also why Symfony
 * and Laravel generate PHP rather than data files.
 */
class RoutesTask extends Task
{
    /**
     * Rebuild the table from the cached() units and write it.
     *
     * Ignores the file on disk: this is the "I want it now" command. A normal deploy
     * does not need it, because a missing or stale file is written by the first
     * request that reads the router.
     */
    public function cacheAction(): void
    {
        $router = AppContext::instance()->router();

        if ($router->cachePath() === null) {
            $this->error('The route cache is not enabled.');
            $this->muted('');
            $this->muted('  Enable it in your bootstrap, before any route is registered:');
            $this->muted('');
            $this->muted("    \$router->enableCache(BASE_DIR . '/temp/routes.php');");
            $this->muted('');
            $this->muted('  and declare every route inside a cached() block:');
            $this->muted('');
            $this->muted('    $router->cached(__FILE__, function (Router $r): void {');
            $this->muted("        \$r->get('/', 'IndexController::indexAction');");
            $this->muted('        // ... the rest');
            $this->muted('    });');
            exit(1);
        }

        if ($router->cachedSources() === []) {
            $this->error('No cached() units are declared, so there is nothing to compile.');
            $this->muted('');
            $this->muted('  Routes must be declared inside cached(), which is also what tells the');
            $this->muted('  cache which files to watch for staleness:');
            $this->muted('');
            $this->muted('    $router->cached(__FILE__, function (Router $r): void {');
            $this->muted("        \$r->get('/', 'IndexController::indexAction');");
            $this->muted('    });');
            exit(1);
        }

        try {
            $count = $router->compileCache();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            exit(1);
        }

        $path = (string) $router->cachePath();

        if ($count === 0) {
            $this->warn('No routes registered - nothing to compile.');
            return;
        }

        $this->success(sprintf(
            'Compiled %d route(s) to %s (%.1f KB)',
            $count,
            $path,
            filesize($path) / 1024,
        ));

        $this->muted('Watching for staleness:');
        foreach ($router->cachedSources() as $file) {
            $this->muted('  ' . $file);
        }
        $this->muted('');
        $this->muted('Nothing else is required: a stale or missing table is rewritten by the');
        $this->muted('first request that reads the router.');
    }

    /**
     * Delete the compiled table.
     */
    public function clearAction(): void
    {
        $router = AppContext::instance()->router();

        if ($router->cachePath() === null) {
            $this->muted('The route cache is not enabled; there is nothing to clear.');
            return;
        }

        $path = (string) $router->cachePath();

        if (!$router->clearCache()) {
            $this->muted("No compiled route table found at {$path}.");
            return;
        }

        $this->success("Deleted {$path}");
    }

    /**
     * Report whether a compiled table exists, and whether it still matches the code.
     *
     * Pass --strict to exit 1 unless the table exists and is fresh. That is the check
     * worth putting in CI: a stale table is not an error at runtime -- the next request
     * rebuilds it -- but it does mean a deploy dashboard is describing a table that no
     * longer exists.
     */
    public function statusAction(): void
    {
        $strict = (bool) $this->option('strict', false);

        $router = AppContext::instance()->router();
        $status = $router->cacheStatus();
        $label  = $this->style('routes', 'cyan');

        if (!$status['enabled']) {
            $this->writeln("  {$label}  "
                . $this->style('disabled - every request rebuilds the table', 'gray'));
            if ($strict) {
                exit(1);
            }
            return;
        }

        if (!$status['exists']) {
            $this->writeln("  {$label}  "
                . $this->style('not written yet - the next request will build it', 'gray'));
            if ($strict) {
                exit(1);
            }
            return;
        }

        $fresh = $status['fresh']
            ? $this->style('fresh', 'green')
            : $this->style('STALE - the next request will rebuild it', 'yellow');

        $this->writeln(sprintf(
            '  %s  %s, %d route(s), %.1f KB, %s',
            $label,
            $fresh,
            $status['routes'],
            filesize((string) $status['path']) / 1024,
            $status['compiled'] !== null
                ? 'written ' . date('Y-m-d H:i:s', $status['compiled'])
                : 'write time unknown',
        ));

        if ($status['files'] !== []) {
            $this->writeln('  watching:');
            foreach ($status['files'] as $file) {
                $this->writeln('    ' . $file);
            }
        }

        if ($strict && !$status['fresh']) {
            exit(1);
        }
    }

    public function listAction(): void
    {
        $ctx    = AppContext::instance();
        $router = $ctx->router();
        $routes = $router->allRoutes();

        if (empty($routes)) {
            $this->muted('No routes registered.');
            $this->line('');
            $this->muted('Implement registerRoutes(Router $router) on your Bootstrap class');
            $this->muted('to enable route registration for CLI tasks.');
            return;
        }

        // Resolve controller namespace from the dispatcher for display
        $namespace = '';
        try {
            $namespace = $ctx->dispatcher()->getBaseNamespace();
        } catch (\Throwable) {}

        $headers = ['Method', 'Path', 'Handler', 'Name', 'Middleware'];
        $rows    = [];

        foreach ($routes as $route) {
            $method  = $route['method'];
            $pattern = $route['pattern'];
            $handler = $this->formatHandler($route['handler'], $namespace);
            $name    = $route['name'] ?? '';
            $groups  = !empty($route['groups']) ? implode(', ', $route['groups']) : '';

            $rows[] = [
                $this->style($method, 'bmagenta'),
                $this->style($pattern, 'cyan'),
                $handler,
                $name !== '' ? $this->style($name, 'byellow') : '',
                $this->style($groups, 'gray'),
            ];
        }

        usort(
            $rows,
            function ($a, $b) {
                $diff = strnatcmp($a[1], $b[1]);
                if ($diff === 0) {
                    $diff = strcmp($a[0], $b[0]);
                }
                return $diff;
            }
        );

        $this->console->printTable($headers, $rows);

        $this->muted("\n" . count($routes) . ' ' . (count($routes) > 1 ? 'routes' : 'route') . '.');
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    /**
     * Format a route handler for display.
     */
    private function formatHandler(string|array|null $handler, string $namespace): string
    {
        if ($handler === null) {
            return $this->style('(auto)', 'gray');
        }

        if (is_array($handler)) {
            $dispatcher = AppContext::instance()->dispatcher();
            $ns         = $handler['namespace'] ?? '';
            $ctrl       = $handler['controller'] ?? $dispatcher->getDefaultController();
            $action     = $handler['action'] ?? $dispatcher->getDefaultAction();
            $fullCtrl   = $this->joinNamespace($namespace, $ns, $ctrl);
            return $fullCtrl . ($action !== '' ? '::' . $action : '');
        }

        // String handler: "Controller::action" or "::action" or "Controller"
        if (is_string($handler)) {
            if (str_starts_with($handler, '::')) {
                return $this->style('(controller)', 'gray') . $handler;
            }
            if (!str_contains($handler, '\\') && $namespace !== '') {
                $handler = ltrim($namespace, '\\') . '\\' . $handler;
            }
            return $handler;
        }

        return '';
    }

    private function joinNamespace(string $baseNs, string $groupNs, string $controller): string
    {
        $parts = [];
        if ($baseNs !== '') {
            $parts[] = trim($baseNs, '\\');
        }
        if ($groupNs !== '') {
            $parts[] = trim($groupNs, '\\');
        }
        $prefix = implode('\\', $parts);
        if ($controller === '') {
            return $prefix;
        }
        if (str_contains($controller, '\\')) {
            return ltrim($controller, '\\');
        }
        return $prefix !== '' ? $prefix . '\\' . $controller : $controller;
    }

}
