<?php

namespace Azera\Core;

use LogicException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Maps HTTP methods and URI patterns to handlers. Supports static routes,
 * typed and optional parameters, wildcards, and route groups with shared
 * middleware, namespaces, or controllers. Routes are matched by specificity,
 * with static routes before dynamic ones. Named routes support URL generation.
 *
 * The compiled route index, chunked combined-regex matching,
 * and method-bucketed candidate lists are inspired by Phalcon's MVC router:
 *
 * @link https://github.com/phalcon/phalcon
 * @link https://github.com/phalcon/phalcon/blob/v6.0.x/src/Mvc/Router.php
 * @see  self::buildSegmentsFor() for the Azera adaptation.
 *
 */
class Router
{
    protected const KIND_STATIC = 1;
    protected const KIND_WILDCARD = 2;
    protected const KIND_PARAM = 3;
    protected const KIND_PARAM_OPT = 4;
    protected const KIND_REGEX = 5;
    protected const KIND_REGEX_OPT = 6;

    /**
     * Alternatives per combined-regex chunk.
     *
     * Measured on this pattern shape: bigger chunks mean fewer `preg_match` calls, and
     * PCRE's per-call cost barely moves until roughly a thousand alternatives in one
     * pattern. 128 measured better than 10 at every bucket size above twenty (a
     * 200-route bucket: 6.1 us against 10.2 us) and keeps each pattern around 4 KB,
     * far inside PCRE's 64 KB limit — which this shape hits at about 2000
     * alternatives. Phalcon uses 10; the difference is that its patterns carry more
     * per-route state.
     */
    private const REGEX_CHUNK_SIZE = 128;

    protected array $static = []; // [method][path] => ['handler'=>..., 'namespace'=>...]
    protected array $groups = []; // [method][firstSegment] => [route, ...]
    protected array $types = [];  // type validators

    /**
     * HTTP methods reported by {@see self::getAllowedMethods()}, in the order the
     * resulting `Allow` header lists them.
     */
    private const KNOWN_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

    /**
     * Bucket key for routes whose first segment is dynamic, and therefore cannot be
     * looked up by a literal. Chosen from the control range so no real first segment
     * can collide with it.
     */
    private const DYNAMIC_BUCKET = "\0dyn";

    /**
     * Compiled match segments, built on demand from $groups.
     *
     * Shape: compiled[method][firstSegment] = list of segments, where a segment is
     * either `['route' => candidate]` (a single scan step) or
     * `['routes' => list<candidate>, 'chunk' => string]` (a run of candidates
     * combined into one `preg_match`).
     *
     * Derived state only: cleared whenever a route is added. Deliberately not
     * persisted — rebuilding costs microseconds and a hand-editable cache should not
     * be able to inject a regex into the request path.
     *
     * @var array<string, array<string, list<array<string, mixed>>>>
     */
    private array $compiled = [];
    /**
     * Union of every path-bearing key in the table, for the cheap "could any route
     * match this URI?" check in {@see self::allowedMethods()}.
     *
     * Derived state, invalidated exactly like {@see self::$compiled}: a view of
     * `$static` and `$groups`, never persisted, so a hand-edited cache cannot
     * influence it.
     *
     * @var array{static: array<string, true>, first: array<string, true>, dynamic: bool}|null
     */
    private ?array $pathIndex = null;

    /**
     * Memoised {@see self::methodBuckets()}; invalidated with {@see self::$pathIndex}.
     *
     * @var list<string>|null
     */
    private ?array $methodList = null;
    protected array $middlewareGroupStack = [];
    protected array $prefixGroupStack = [];
    protected array $namespaceGroupStack = [];
    protected array $controllerGroupStack = [];
    protected array $namedRoutes = []; // [name] => ['tokens'=>...]
    protected ?array $lastAddedTokens = null;
    protected bool $autoOptions = false;

    /**
     * Buckets whose specificity order is still stale, i.e. routes were added
     * after the last sort. Keyed "[method]\0[firstSegment]".
     *
     * @var array<string, true>
     */
    protected array $unsortedBuckets = [];

    /**
     * Create a new Router instance.
     */
    public function __construct()
    {
        $this->types = [
            'int'   => fn($v) => \ctype_digit($v),
            'alpha' => fn($v) => \ctype_alpha($v),
            'alnum' => fn($v) => \ctype_alnum($v),
            'uuid'  => fn($v) => \preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                $v,
            ) === 1,
            '*' => fn() => true,
        ];
    }

    /**
     * Register a custom type validator for route parameters.
     * Predefined types include 'int', 'alpha', 'alnum', 'uuid', and '*' (matches anything). You can add your own types with custom validation logic. For example, you could add a 'slug' type that only allows lowercase letters, numbers, and hyphens. Once a type is registered, you can use it in your route patterns like /blog/{slug:slug}.
     *
     * @param string $name The type name (e.g., 'slug', 'email')
     * @param callable $validator Function that validates a string value, returns bool
     * @return static For method chaining
     *
     * @example
     * $router->type('slug', fn($v) => preg_match('/^[a-z0-9-]+$/', $v));
     * $router->add('GET', '/blog/{slug:slug}', 'Blog::view');
     */
    public function type(string $name, callable $validator): static
    {
        if ($this->flushing > 0) {
            // Validators are closures and are not exported, so a restored table never
            // runs this block. A type registered in here would be absent on every cache
            // hit, and the failure -- "Unknown validator" at dispatch time -- would
            // appear only in production. Register types before the units instead.
            throw new LogicException(
                'type() cannot be called inside a cached() block: validators are code and '
                    . 'are not stored in the compiled table, so a cache hit would never run this '
                    . 'registration and the type would be unknown at dispatch time. Register it '
                    . 'before the cached() units.',
            );
        }
        if ($this->flushed) {
            // A live table already holds the token type names; adding a validator
            // afterwards is fine, but doing it from inside a rebuild is not.
            $this->resolvedOverrides = [];
        }

        $this->types[$name] = $validator;

        return $this;
    }

    /**
     * Enable or disable automatic OPTIONS responses.
     * When enabled, if an OPTIONS request is received for a path that has
     * routes registered for other HTTP methods (GET, POST, etc.) but no
     * explicit OPTIONS route, the router will return a synthetic match
     * that the Dispatcher converts into a 204 response with Allow and
     * CORS headers.
     *
     * @param bool $enabled Whether to enable auto-OPTIONS handling
     * @return static For method chaining
     */
    public function autoOptions(bool $enabled = true): static
    {
        $this->autoOptions = $enabled;
        return $this;
    }

    /**
     * Add a new route to the router. The route can be defined for specific HTTP methods, a URI pattern, and an optional handler that overrides the default controller/action resolution. The pattern can include static segments, typed parameters, dynamic segments for namespace/controller/action, and wildcard segments for additional parameters. Validators can be applied to dynamic parameters using predefined or custom types. For example: /user/{id:int} or /blog/{slug:slug}
     *
     * @param string|array|null $method HTTP method(s) for the route (e.g., 'GET', ['GET', 'POST'], or '*' for all methods)
     * @param string $pattern Route pattern (e.g., '/blog/{slug}', '/{controller}/{action}/{params:*}')
     * @param string|array|null $handler Optional handler definition to override controller/action. Can be a string like 'Admin::dashboard' or an array of strings with keys 'namespace', 'controller', 'action'.
     * @return static For method chaining
     */
    public function add(
        string|array|null $method,
        string $pattern,
        string|array|null $handler = null
    ): static {

        if ($this->flushed && $this->caching && $this->units !== [] && $this->flushing === 0) {
            // The table was already read -- built from the units, or restored from the
            // compiled file -- so a route added now exists only in this process and
            // vanishes on the next request. That is the one inconsistency a cache must
            // not introduce. Routes registered *before* the first cached() call are
            // caught by assertNoRoutesOutsideUnits() at flush time instead, which costs
            // nothing per add().
            throw new LogicException(sprintf(
                'Route "%s %s" was registered after the route table had already been read. '
                    . 'Declare every route inside a cached() block, which runs before the first match().',
                \is_array($method) ? implode('|', $method) : (string) $method,
                $pattern,
            ));
        }

        // A handler array carries `controller`/`action`/`namespace`/`name`, and the
        // `array` half of the type accepts anything inside it. Objects and closures
        // there would be persisted into the route table and cannot mean anything at
        // dispatch time (the dispatcher reads these as class and method *names*).
        // Checked unconditionally rather than only while compiling: routes are a
        // description of how to find a handler, never the handler itself.
        if (\is_array($handler)) {
            foreach ($handler as $key => $value) {
                if (!\is_string($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'Route handler key "%s" must be a string, %s given. A route is a description of which controller and action to call, never the callable itself.',
                        (string) $key,
                        get_debug_type($value),
                    ));
                }
            }
        }

        $routeName = null;
        if (\is_array($handler)) {
            if (isset($handler[0])) {
                // If the handler is an indexed array,
                // treat it as [controller, action, name]
                $handler['controller'] = $handler[0];
                unset($handler[0]);
                if (isset($handler[1])) {
                    $handler['action'] = $handler[1];
                    unset($handler[1]);
                }
                if (isset($handler[2])) {
                    $routeName = (string) $handler[2];
                    unset($handler[2]);
                }
            } elseif (isset($handler['name'])) {
                $routeName = (string) $handler['name'];
                unset($handler['name']);
            }
        }

        if (!empty($this->prefixGroupStack)) {
            $prefix  = implode('/', $this->prefixGroupStack);
            $pattern = "$prefix/" . ltrim($pattern, '/');
        }

        if (!empty($this->controllerGroupStack)) {
            $controller = end($this->controllerGroupStack);
            if (\is_string($handler)) {
                $pos = strpos($handler, '::');
                if ($pos === false) {
                    $handler = "$controller::$handler";
                } elseif ($pos === 0) {
                    $handler = "$controller$handler";
                }
            } elseif (empty($handler['controller'])) {
                $handler['controller'] = $controller;
            }
        }

        if (!empty($this->namespaceGroupStack)) {
            $namespace = end($this->namespaceGroupStack);
            if (\is_string($handler)) {
                if (!str_contains($handler, '\\')) {
                    $handler = "$namespace\\$handler";
                }
            } elseif (empty($handler['namespace'])) {
                $handler['namespace'] = $namespace;
            }
        }

        $tokens = $this->parsePattern($pattern);
        $this->lastAddedTokens = $tokens;

        if ($method === null || $method === '*') {
            $method = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        }

        if (\is_string($method)) {
            $this->storeRoute(strtoupper($method), $tokens, $handler, $this->middlewareGroupStack);
        } else {
            foreach ($method as $m) {
                $this->storeRoute(strtoupper($m), $tokens, $handler, $this->middlewareGroupStack);
            }
        }

        if ($routeName !== null && $routeName !== '') {
            $this->namedRoutes[$routeName] = ['tokens' => $tokens];
        }

        return $this;
    }

    /**
     * Convenience method to add a GET route.
     *
     * @param string $pattern Route pattern (e.g., '/blog/{slug}')
     * @param string|array|null $handler Optional handler definition to override controller/action
     * @return static For method chaining
     */
    public function get(string $pattern, string|array|null $handler = null): static
    {
        return $this->add('GET', $pattern, $handler);
    }

    /**
     * Convenience method to add a POST route.
     *
     * @param string $pattern Route pattern (e.g., '/submit')
     * @param string|array|null $handler Optional handler definition to override controller/action
     * @return static For method chaining
     */
    public function post(string $pattern, string|array|null $handler = null): static
    {
        return $this->add('POST', $pattern, $handler);
    }

    /**
     * Convenience method to add a PUT route.
     *
     * @param string $pattern Route pattern (e.g., '/submit')
     * @param string|array|null $handler Optional handler definition to override controller/action
     * @return static For method chaining
     */
    public function put(string $pattern, string|array|null $handler = null): static
    {
        return $this->add('PUT', $pattern, $handler);
    }

    /**
     * Convenience method to add a DELETE route.
     *
     * @param string $pattern Route pattern (e.g., '/submit')
     * @param string|array|null $handler Optional handler definition to override controller/action
     * @return static For method chaining
     */
    public function delete(string $pattern, string|array|null $handler = null): static
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Convenience method to add a PATCH route.
     *
     * @param string $pattern Route pattern (e.g., '/submit')
     * @param string|array|null $handler Optional handler definition to override controller/action
     * @return static For method chaining
     */
    public function patch(string $pattern, string|array|null $handler = null): static
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    /**
     * Assign a name to the most recently added route. This allows you to generate URLs for this route using the `urlFor()` method.
     *
     * @param string $name The name to assign to the route
     * @return static For method chaining
     * @throws LogicException If no route has been added yet or if the last added route is invalid
     */
    public function setName(string $name): static
    {
        $this->ensureFlushed();

        if ($name === '') {
            throw new InvalidArgumentException('Route name cannot be empty');
        }
        if ($this->lastAddedTokens === null) {
            throw new LogicException('Cannot set route name before adding a route');
        }

        $this->namedRoutes[$name] = ['tokens' => $this->lastAddedTokens];
        return $this;
    }

    /**
     * Check if a named route exists.
     *
     * @param string $name The name of the route to check
     * @return bool True if a route with the given name exists, false otherwise
     */
    public function hasNamedRoute(string $name): bool
    {
        $this->ensureFlushed();

        return isset($this->namedRoutes[$name]);
    }

    /**
     * Generate a URL for a named route, substituting parameters as needed.
     *
     * @param string $name The name of the route to generate a URL for
     * @param array $params Associative array of parameter values to substitute into the route pattern
     * @param array $query Optional associative array of query parameters to append to the URL
     * @return string The generated URL path (e.g., "/blog/hello-world?ref=homepage")
     * @throws RuntimeException If no route with the given name exists or if required parameters are missing/invalid
     */
    public function urlFor(string $name, array $params = [], array $query = []): string
    {
        $this->ensureFlushed();

        if (!isset($this->namedRoutes[$name])) {
            throw new RuntimeException("Unknown route name: $name");
        }

        $path = $this->buildPathFromTokens($this->namedRoutes[$name]['tokens'], $params);

        if ($query) {
            $path .= '?' . http_build_query($query);
        }

        return $path;
    }

    /**
     * Return all registered routes as a flat array.
     *
     * Each entry is an associative array with keys:
     *   - 'method':    HTTP method (GET, POST, …)
     *   - 'pattern':   Reconstructed path pattern (e.g. "/users/{id:int}")
     *   - 'handler':   The handler value (string, array, or null)
     *   - 'groups':    Middleware group names applied to this route
     *   - 'name':      Route name if one was assigned, or null
     *
     * @return array<int, array{method:string, pattern:string, handler:string|array|null, groups:array, name:?string}>
     */
    public function allRoutes(): array
    {
        $this->ensureFlushed();

        $routes = [];

        // Build a reverse lookup: tokens → route name
        $nameLookup = [];
        foreach ($this->namedRoutes as $name => $data) {
            $key = serialize($data['tokens']);
            $nameLookup[$key] = $name;
        }

        // Static routes: [method][path] => ['handler', 'tokens', 'groups']
        foreach ($this->static as $method => $byPath) {
            foreach ($byPath as $path => $entry) {
                $tokens = $entry['tokens'];
                $key    = serialize($tokens);
                $routes[] = [
                    'method'  => $method,
                    'pattern' => $path,
                    // static paths are already canonical
                    'handler' => $entry['handler'],
                    'groups'  => $entry['groups'],
                    'name'    => $nameLookup[$key] ?? null,
                ];
            }
        }

        // Dynamic routes: [method][firstSegment][] => ['tokens', 'handler', 'specificity', 'groups']
        foreach ($this->groups as $method => $byFirst) {
            foreach ($byFirst as $first => $routesList) {
                // Materialise the deferred priority order, so the listing is
                // stable regardless of whether match() ran first.
                $this->sortBucket($method, $first);
                foreach ($this->groups[$method][$first] as $entry) {
                    $tokens = $entry['tokens'];
                    $key    = serialize($tokens);
                    $routes[] = [
                        'method'  => $method,
                        'pattern' => $this->tokensToPattern($tokens),
                        'handler' => $entry['handler'],
                        'groups'  => $entry['groups'],
                        'name'    => $nameLookup[$key] ?? null,
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * Reconstruct a path pattern string from parsed tokens.
     *
     * This is the inverse of {@see parsePattern()}.
     *
     * @param array $tokens Token array from parsePattern().
     * @return string The path pattern (e.g. "/users/{id:int}/posts/{slug?}").
     */
    protected function tokensToPattern(array $tokens): string
    {
        $segments = [];

        foreach ($tokens as $token) {
            [$kind, $name, $type] = $token + [null, null, null];

            if ($kind === self::KIND_STATIC) {
                if ($name !== '') {
                    $segments[] = $name;
                }
                continue;
            }

            if ($kind === self::KIND_WILDCARD) {
                $segments[] = "{{$name}:*}";
                continue;
            }

            if ($kind === self::KIND_PARAM) {
                $segments[] = "{{$name}:{$type}}";
                continue;
            }

            if ($kind === self::KIND_PARAM_OPT) {
                $segments[] = "{{$name}?:{$type}}";
                continue;
            }

            if ($kind === self::KIND_REGEX) {
                $segments[] = "{{$name}:regex({$type})}";
                continue;
            }

            if ($kind === self::KIND_REGEX_OPT) {
                $segments[] = "{{$name}?:regex({$type})}";
                continue;
            }
        }

        return '/' . implode('/', $segments);
    }

    protected function snapshotGroupStacks(): array
    {
        return [
            'middleware' => \count($this->middlewareGroupStack),
            'prefix'     => \count($this->prefixGroupStack),
            'namespace'  => \count($this->namespaceGroupStack),
            'controller' => \count($this->controllerGroupStack),
        ];
    }

    protected function restoreGroupStacks(array $snapshot): void
    {
        $this->middlewareGroupStack = \array_slice($this->middlewareGroupStack, 0, $snapshot['middleware']);
        $this->prefixGroupStack     = \array_slice($this->prefixGroupStack, 0, $snapshot['prefix']);
        $this->namespaceGroupStack  = \array_slice($this->namespaceGroupStack, 0, $snapshot['namespace']);
        $this->controllerGroupStack = \array_slice($this->controllerGroupStack, 0, $snapshot['controller']);
    }

    protected function pushGroupValues(array &$stack, string|array $values): void
    {
        if (\is_string($values)) {
            $stack[] = $values;
            return;
        }

        foreach ($values as $value) {
            if (!\is_string($value)) {
                // The `string|array` parameter type accepts an array of anything; only
                // this check stops an object (or a closure) reaching the route table
                // through it. Verified before the check existed: middleware arrays of
                // objects were accepted, and `serialize()` on the table then threw
                // "Serialization of 'class@anonymous' is not allowed".
                //
                // Routes and their middleware are a description of how to find a
                // handler, never the handler itself, so this is a contract rather than
                // a convenience -- see {@see self::exportState()}, which depends on it
                // to write a route cache without inspecting every value.
                throw new InvalidArgumentException(sprintf(
                    'Middleware group values must be strings, %s given. '
                        . 'Routes and route middleware are data; use a class name or a '
                        . 'registered middleware group name, never an instance or closure.',
                    get_debug_type($value),
                ));
            }
            $stack[] = $value;
        }
    }

    /**
     * Define a group of routes that share a common URI prefix. When a callback is supplied, the prefix is scoped to that callback and the router restores the previous group state afterward. When omitted, the prefix stays on the stack for subsequent routes.
     *
     * @param string $prefix URI prefix for the group (e.g., "/admin")
     * @param callable|null $callback Optional callback that receives the router instance to define routes within the group
     * @return static For method chaining
     *
     * @example
     * $router->prefix('/admin');
     * $router->add('GET', '/dashboard', 'Admin::dashboard');
     */
    public function prefix(string $prefix, ?callable $callback = null): static
    {
        if (empty($prefix)) {
            throw new InvalidArgumentException('Prefix cannot be empty');
        }

        if ($callback === null) {
            $this->prefixGroupStack[] = trim($prefix, '/');
            return $this;
        }

        $snapshot = $this->snapshotGroupStacks();
        $this->prefixGroupStack[] = trim($prefix, '/');

        try {
            $callback($this);
        } finally {
            $this->restoreGroupStacks($snapshot);
        }

        return $this;
    }

    /**
     * Add group of middleware to be applied to all routes defined within the group. When a callback is supplied, the middleware groups are scoped to that callback and the router restores the previous stack afterward. When omitted, the middleware stays on the active stack for subsequent routes.
      *
      * @param string|array $name Middleware group name (e.g., "auth")
      * @param callable|null $callback Optional callback that receives the router instance to define routes within the group
      * @return static For method chaining
      *
      * @example
      * $router->middleware('auth');
      * $router->add('GET', '/admin/dashboard', 'Admin::dashboard');
     */
    public function middleware(string|array $name, ?callable $callback = null): static
    {
        if (\is_string($name)) {
            if (empty($name)) {
                throw new InvalidArgumentException('Middleware group name cannot be empty');
            }
        }

        if ($callback === null) {
            $this->pushGroupValues($this->middlewareGroupStack, $name);
            return $this;
        }

        $snapshot = $this->snapshotGroupStacks();
        $this->pushGroupValues($this->middlewareGroupStack, $name);

        try {
            $callback($this);
        } finally {
            $this->restoreGroupStacks($snapshot);
        }

        return $this;
    }

    /**
     * Define a group of routes that share a common namespace for their handlers. When a callback is supplied, the namespace is scoped to that callback and the router restores the previous group state afterward. When omitted, the namespace stays on the stack for subsequent routes.
     *
     * @param string $namespace Namespace prefix for the group (e.g., "Admin")
     * @param callable|null $callback Optional callback that receives the router instance to define routes within the group
     * @return static For method chaining
     *
     * @example
     * $router->namespace('Admin');
     * $router->add('GET', '/dashboard', 'Dashboard::view');
     */
    public function namespace(string $namespace, ?callable $callback = null): static
    {
        $namespace = rtrim($namespace, '\\');
        if ($namespace === '') {
            throw new InvalidArgumentException('Namespace cannot be empty');
        }

        if ($namespace[0] !== '\\') {
            $parentNamespace = end($this->namespaceGroupStack);
            if ($parentNamespace !== false && $parentNamespace !== null && $parentNamespace !== '') {
                $namespace = trim((string) $parentNamespace, '\\') . '\\' . $namespace;
            }
        }

        if ($callback === null) {
            $this->namespaceGroupStack[] = $namespace;
            return $this;
        }

        $snapshot = $this->snapshotGroupStacks();
        $this->namespaceGroupStack[] = $namespace;

        try {
            $callback($this);
        } finally {
            $this->restoreGroupStacks($snapshot);
        }

        return $this;
    }

    /**
     * Define a group of routes that share a common controller. When a callback is supplied, the controller is scoped to that callback and the router restores the previous group state afterward. When omitted, the controller stays on the stack for subsequent routes.
     *
     * @param string $controller Controller name for the group (e.g., "Admin")
     * @param callable|null $callback Optional callback that receives the router instance to define routes within the group
     * @return static For method chaining
     *
     * @example
     * $router->controller('Admin');
     * $router->add('GET', '/dashboard', '::view');
     */
    public function controller(string $controller, ?callable $callback = null): static
    {
        if (empty($controller)) {
            throw new InvalidArgumentException('Controller name cannot be empty');
        }

        if (!empty($this->controllerGroupStack)) {
            if (!str_contains($controller, '\\')) {
                $controller = implode('\\', $this->controllerGroupStack) . '\\' . $controller;
            }
        }

        if ($callback === null) {
            $this->controllerGroupStack[] = $controller;
            return $this;
        }

        $snapshot = $this->snapshotGroupStacks();
        $this->controllerGroupStack[] = $controller;

        try {
            $callback($this);
        } finally {
            $this->restoreGroupStacks($snapshot);
        }

        return $this;
    }

    protected function storeRoute(string $method, array $tokens, string|array|null $handler, array $groups): void
    {
        if ($this->isStaticTokens($tokens)) {
            $path = '/' . implode('/', array_column($tokens, 1));
            $this->static[$method][$path] = [
                'handler'  => $handler,
                'tokens'   => $tokens,
                'groups'   => $groups,
                'override' => $this->cachedOverride($handler),
            ];
            $this->pathIndex  = null;
            $this->methodList = null;
            return;
        }

        [$min, $max, $wildcard, $captures] = $this->routeShape($tokens);

        $first = $tokens[0][0] === self::KIND_STATIC
            ? $tokens[0][1]
            : self::DYNAMIC_BUCKET;

        $this->groups[$method][$first][] = [
            'tokens'      => $tokens,
            'handler'     => $handler,
            'specificity' => $this->calculateSpecificity($tokens),
            'groups'      => $groups,
            'override'    => $this->cachedOverride($handler),
            'min'         => $min,
            'max'         => $max,
            'wildcard'    => $wildcard,
            'captures'    => $captures,
        ];

        // Priority is "most specific first", but the sort is DEFERRED to the
        // first match() that reads this bucket (see $unsortedBuckets). Sorting
        // here would re-order the whole bucket after every single insert.
        $this->unsortedBuckets[$method . "\0" . $first] = true;

        // The compiled index is derived from the buckets, so any insert invalidates
        // the method it belongs to. The path index is a union over every method, so
        // it is invalidated too.
        unset($this->compiled[$method]);
        $this->pathIndex  = null;
        $this->methodList = null;
    }

    /**
     * The per-candidate facts the match loop needs, derived once at registration
     * rather than per request.
     *
     * `min`/`max` bound the segment count a candidate can accept, `wildcard` says
     * whether the upper bound is unbounded, and `captures` lists the named groups a
     * compiled body produces, in group order.
     *
     * @param list<array<int, string>> $tokens
     * @return array{0: int, 1: int, 2: bool, 3: list<array{0: string, 1: int}>}
     */
    protected function routeShape(array $tokens): array
    {
        $min      = 0;
        $max      = 0;
        $wildcard = false;
        $captures = [];

        foreach ($tokens as $token) {
            $kind = $token[0];

            if ($kind === self::KIND_WILDCARD) {
                $wildcard = true;
                $captures[] = [$token[1], self::KIND_WILDCARD];
                continue;
            }

            if ($kind === self::KIND_PARAM_OPT || $kind === self::KIND_REGEX_OPT) {
                $max++;
                continue;
            }

            $min++;
            $max++;

            if ($kind === self::KIND_PARAM) {
                $captures[] = [$token[1], self::KIND_PARAM];
            }
        }

        return [$min, $max, $wildcard, $captures];
    }

    /**
     * Sort a bucket by specificity (highest first) if adds happened since the
     * last sort. Idempotent and cheap once clean.
     */
    protected function sortBucket(string $method, string $first): void
    {
        $key = $method . "\0" . $first;

        if (!isset($this->unsortedBuckets[$key]) || empty($this->groups[$method][$first])) {
            return;
        }

        usort(
            $this->groups[$method][$first],
            fn($a, $b) => $b['specificity'] <=> $a['specificity']
        );

        unset($this->unsortedBuckets[$key]);
    }

    /**
     * Calculate route specificity for automatic priority.
     * Higher score = more specific = checked first.
     *
     * Scoring:
     * - static segment = 3 points
     * - typed param (not '*' or wildcard) = 2 points
     * - wildcard/dynamic/'*' = 1 point
     */
    protected function calculateSpecificity(array $tokens): int
    {
        $score = 0;
        foreach ($tokens as $token) {
            $kind = $token[0];
            $type = $token[2] ?? null;

            if ($kind === self::KIND_STATIC) {
                $score += 3;
            } elseif ($kind === self::KIND_PARAM && $type !== '*') {
                $score += 2;
            } elseif ($kind === self::KIND_REGEX) {
                $score += 2;
            } else {
                // wildcard, dynamic, or param with type '*'
                $score += 1;
            }
        }
        return $score;
    }

    protected function isStaticTokens(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if ($t[0] !== self::KIND_STATIC) {
                return false;
            }
        }
        return true;
    }

    protected function parsePattern(string $pattern): array
    {
        $segments = explode('/', trim($pattern, '/'));
        $result   = [];

        foreach ($segments as $t) {
            if ($t === '') {
                $result[] = [self::KIND_STATIC, ''];
                continue;
            }

            // normal static
            if ($t[0] !== '{') {
                $result[] = [self::KIND_STATIC, $t];
                continue;
            }

            $inner = trim($t, '{}');

            $name             = strstr($inner, ':', true);
            $hasTypeSeparator = $name !== false;
            if (!$hasTypeSeparator) {
                $name = $inner;
                $type = '*';
            } else {
                $type = \substr($inner, \strlen($name) + 1);
            }

            $optional = false;
            if (str_ends_with($name, '?')) {
                $optional = true;
                $name     = substr($name, 0, -1);
            }

            if ($name === '') {
                throw new RuntimeException(
                    "Unnamed route parameters are not supported: {$t}"
                );
            }

            if ($hasTypeSeparator && $type === '*') {
                $result[] = [self::KIND_WILDCARD, $name];
                continue;
            }

            if (str_starts_with($type, 'regex(') && str_ends_with($type, ')')) {
                $regex = substr($type, 6, -1);
                $result[] = [$optional ? self::KIND_REGEX_OPT : self::KIND_REGEX, $name, $regex];
            } else {
                // Reject an unknown type here rather than at dispatch time. A typo
                // like "{id:int?}" parses to the type "int?" -- the `?` belongs on
                // the name -- and used to register cleanly, then throw from match()
                // inside a controller request. A custom type registered with type()
                // is accepted; that is the only legitimate reason the name is unknown.
                if (!isset($this->types[$type])) {
                    $suggestion = str_ends_with($type, '?')
                        ? sprintf('{%s?:%s}', $name, rtrim($type, '?'))
                        : sprintf('{%s?:%s}', $name, $type);

                    throw new InvalidArgumentException(sprintf(
                        'Unknown route parameter type "%s" in pattern "%s". Built-in types are %s; '
                            . 'register others with type(). To make the parameter optional, put the '
                            . '"?" on the name: "%s" rather than "{%s:%s}".',
                        $type,
                        $pattern,
                        implode(', ', array_keys($this->types)),
                        $suggestion,
                        $name,
                        $type,
                    ));
                }

                $result[] = [$optional ? self::KIND_PARAM_OPT : self::KIND_PARAM, $name, $type];
            }
        }

        // A wildcard may only be followed by static segments: those form its required
        // suffix. Two adjacent wildcards would be ambiguous, and a wildcard followed
        // by a parameter would make the split between them arbitrary.
        $seenWildcard = false;
        foreach ($result as $token) {
            if ($seenWildcard && $token[0] !== self::KIND_STATIC) {
                throw new InvalidArgumentException(sprintf(
                    'Only static segments may follow a wildcard parameter in pattern "%s"; found a dynamic one.',
                    $pattern,
                ));
            }

            if ($token[0] === self::KIND_WILDCARD) {
                $seenWildcard = true;
            }
        }

        return $result;
    }

    protected function buildPathFromTokens(array $tokens, array $params): string
    {
        $segments = [];

        foreach ($tokens as $token) {
            [$kind, $name, $type] = $token + [null, null, null];

            if ($kind === self::KIND_STATIC) {
                if ($name !== '') {
                    $segments[] = $name;
                }
                continue;
            }

            if ($kind === self::KIND_PARAM_OPT || $kind === self::KIND_REGEX_OPT) {
                if (!array_key_exists($name, $params)) {
                    continue;
                }

                $value = (string) $params[$name];
                if ($kind === self::KIND_PARAM_OPT) {
                    if (!isset($this->types[$type])) {
                        throw new RuntimeException("Unknown validator: $type");
                    }
                    if (!$this->types[$type]($value)) {
                        throw new RuntimeException("Route parameter '$name' does not match type '$type'");
                    }
                } else {
                    if (!preg_match('/^' . $type . '$/', $value)) {
                        throw new RuntimeException("Route parameter '$name' does not match regex '$type'");
                    }
                }

                $segments[] = rawurlencode($value);
                continue;
            }

            if ($kind === self::KIND_PARAM || $kind === self::KIND_REGEX) {
                if (!array_key_exists($name, $params)) {
                    throw new RuntimeException("Missing route parameter: $name");
                }

                $value = (string) $params[$name];
                if ($kind === self::KIND_PARAM) {
                    if (!isset($this->types[$type])) {
                        throw new RuntimeException("Unknown validator: $type");
                    }
                    if (!$this->types[$type]($value)) {
                        throw new RuntimeException("Route parameter '$name' does not match type '$type'");
                    }
                } else {
                    if (!preg_match('/^' . $type . '$/', $value)) {
                        throw new RuntimeException("Route parameter '$name' does not match regex '$type'");
                    }
                }

                $segments[] = rawurlencode($value);
                continue;
            }

            if ($kind === self::KIND_WILDCARD) {
                $wildcardValues = [];
                if (array_key_exists($name, $params)) {
                    $wildcardValues = $params[$name];
                } else {
                    foreach ($params as $key => $value) {
                        if (\is_int($key)) {
                            $wildcardValues[] = $value;
                        }
                    }
                }

                if (!\is_array($wildcardValues)) {
                    $wildcardValues = [$wildcardValues];
                }

                foreach ($wildcardValues as $wildcardValue) {
                    $segments[] = rawurlencode((string) $wildcardValue);
                }
            }
        }

        if (empty($segments)) {
            return '/';
        }

        return '/' . implode('/', $segments);
    }

    /**
     * Attempt to match the given URI and HTTP method against the registered routes.
     *
     * Matching runs over a compiled per-(method, first segment) index rather than
     * over routes one at a time. Candidates that can be expressed as PCRE are
     * combined into a few chunked patterns, so a bucket of N routes costs a handful
     * of `preg_match` calls instead of N token walks; candidates that cannot be
     * (`{x?:int}`, `regex(...)`, custom validators) stay as single scan steps and
     * the relative order of the two kinds is preserved exactly.
     *
     * @see self::buildSegmentsFor() for how the index is compiled.
     *
     * @param string $uri The request URI (path) to match, e.g. "/blog/hello-world"
     * @param string $method The HTTP method, e.g. "GET", "POST"
     * @return array|null If a match is found, returns an array with keys 'vars', 'override', 'groups', 'wildcards'. Otherwise, returns null.
     */
    public function match(string $uri, string $method = 'GET'): ?array
    {
        // Inlined common case: once the table is live and no revalidation window is
        // configured, there is nothing for ensureFlushed() to do. This keeps the call
        // off the request path, which matters more than it looks for a static route.
        if (!$this->flushed || ($this->caching && $this->revalidateFreq > 0)) {
            $this->ensureFlushed();
        }

        if ($method === 'GET' || $method === '') {
            $method = 'GET';
        } else {
            $method = strtoupper($method);
            // Per RFC 7231: HEAD is identical to GET; route HEAD requests as GET
            if ($method === 'HEAD') {
                $method = 'GET';
            }
        }

        // The common case, kept inline: no "%" means the raw path *is* the decoded
        // path, so the segments and the regex subject come straight from the URI.
        // {@see self::prepare()} handles the encoded case; measured against this
        // inline form it costs nothing extra for an ordinary URI, but it returns an
        // array that this hot path would then have to unpack, and the branch below
        // keeps the 405 path's array allocation off every request that never asks.
        if (!str_contains($uri, '%')) {
            $path = trim($uri, '/');
            $u    = null;

            // Static routes are consulted first and are unconditional: a literal
            // path is always more specific than any pattern that could also match
            // it, so this holds regardless of registration order.
            if (isset($this->static[$method]['/' . $path])) {
                return $this->matchStatic($this->static[$method]['/' . $path]);
            }

            $parts   = $path === '' ? [''] : explode('/', $path);
            $subject = $path;
        } else {
            $u = $this->prepare($uri);

            // `staticKey` is the decoded form, so a route registered as "/a b" is
            // reachable as "/a%20b". Null means a decoded segment contains a
            // separator: no literal can match it, and no combined pattern can
            // describe it.
            if ($u['staticKey'] !== null && isset($this->static[$method][$u['staticKey']])) {
                return $this->matchStatic($this->static[$method][$u['staticKey']]);
            }

            $parts   = $u['parts'];
            $subject = $u['subject'];
        }

        $first = $parts[0];
        $found = null;

        if (isset($this->groups[$method][$first])) {
            $found = $this->matchRun($this->bucketSegments($method, (string) $first), $parts, $subject);
        }

        if ($found === null && isset($this->groups[$method][self::DYNAMIC_BUCKET])) {
            $found = $this->matchRun(
                $this->bucketSegments($method, self::DYNAMIC_BUCKET),
                $parts,
                $subject,
            );
        }

        if ($found !== null) {
            return $found;
        }

        // Auto-OPTIONS: if no explicit OPTIONS route matched, generate one.
        // servedMethods() owns the OPTIONS synthesis, so this branch and
        // getAllowedMethods() cannot advertise different `Allow` lists.
        if ($method === 'OPTIONS' && $this->autoOptions) {
            $allowed = $this->servedMethods($u ?? $this->prepare($uri));

            if ($allowed !== []) {
                return [
                    'vars'              => [],
                    'override'          => [],
                    'groups'            => [],
                    '__auto_options'    => true,
                    '__allowed_methods' => $allowed,
                ];
            }
        }

        return null;
    }

    /**
     * The one place a URI is split, so every public method sees the same segments.
     *
     * Segments are decoded individually, never the whole path: decoding `/a%2Fb` as
     * one string would invent a segment boundary the client never sent. When a
     * decoded segment does contain a separator, `subject` is null and the caller
     * must fall back to walking the candidates, because no combined pattern can
     * describe a path whose segment count depends on decoding.
     *
     * @return array{path: string, staticKey: ?string, parts: list<string>, subject: ?string}
     */
    private function prepare(string $uri): array
    {
        $path = trim($uri, '/');

        // A URI with no "%" needs no decoding at all, which is the common case.
        if (!str_contains($path, '%')) {
            return [
                'path'      => $path,
                'staticKey' => '/' . $path,
                'parts'     => $path === '' ? [''] : explode('/', $path),
                'subject'   => $path,
            ];
        }

        $parts = $path === '' ? [''] : explode('/', $path);
        $lossy = false;

        foreach ($parts as $index => $part) {
            if ($part !== '' && str_contains($part, '%')) {
                $parts[$index] = rawurldecode($part);

                if (str_contains($parts[$index], '/')) {
                    $lossy = true;
                }
            }
        }

        $subject = implode('/', $parts);

        return [
            'path'      => $path,
            'staticKey' => $lossy ? null : '/' . $subject,
            'parts'     => $parts,
            'subject'   => $lossy ? null : $subject,
        ];
    }

    /**
     * Match one bucket's segment list, in order, and return the first hit.
     *
     * When `$subject` is null the URI could not be expressed without a segment
     * boundary appearing from nowhere (see {@see self::prepare()}), so a combined
     * pattern would answer a question about a different path. In that case each
     * chunk is expanded and its candidates are walked in the same order, which is
     * the pre-index behaviour and therefore equivalent by construction.
     *
     * @param list<array<string, mixed>> $run
     * @param list<string>               $parts
     * @param string|null                $subject The decoded URI without its leading slash, used as the regex subject.
     * @return array{vars: array, override: array<string, string>, groups: array}|null
     */
    private function matchRun(array $run, array $parts, ?string $subject): ?array
    {
        $partCount = \count($parts);

        foreach ($run as $segment) {
            if (isset($segment['route'])) {
                $route = $segment['route'];

                if ($partCount < $route['min']) {
                    continue;
                }
                if (!$route['wildcard'] && $partCount > $route['max']) {
                    continue;
                }

                $params = $this->matchTokens($route['tokens'], $parts, $partCount);
                if ($params === null) {
                    continue;
                }

                return [
                    'vars'     => $params,
                    'override' => $route['override'],
                    'groups'   => $route['groups'],
                ];
            }

            if ($subject === null) {
                foreach ($segment['routes'] as $route) {
                    if ($partCount < $route['min']) {
                        continue;
                    }
                    if (!$route['wildcard'] && $partCount > $route['max']) {
                        continue;
                    }

                    $params = $this->matchTokens($route['tokens'], $parts, $partCount);
                    if ($params === null) {
                        continue;
                    }

                    return [
                        'vars'     => $params,
                        'override' => $route['override'],
                        'groups'   => $route['groups'],
                    ];
                }

                continue;
            }

            if (!preg_match($segment['chunk'], $subject, $matches)) {
                continue;
            }

            $route  = $segment['routes'][(int) $matches['MARK']];
            $params = [];

            foreach ($route['captures'] as $captureIndex => $capture) {
                $value = (string) ($matches[$captureIndex + 1] ?? '');

                if ($capture[1] === self::KIND_WILDCARD) {
                    // A required suffix is matched by its own groups, so it never
                    // ends up inside the wildcard's capture.
                    $value = trim($value, '/');
                    $params[$capture[0]] = $value === '' ? [] : explode('/', $value);
                } else {
                    $params[$capture[0]] = $value;
                }
            }

            return [
                'vars'     => $params,
                'override' => $route['override'],
                'groups'   => $route['groups'],
            ];
        }

        return null;
    }

    /**
     * The compiled segments for one bucket, built on first use.
     *
     * Compilation is per bucket, not per method: a table of 300 buckets would
     * otherwise pay for all 300 on the first request that touches one of them. Only
     * the buckets a URI actually looks in are ever built, which keeps the cold cost
     * proportional to what the request needs rather than to the size of the table.
     *
     * @return list<array<string, mixed>>
     */
    private function bucketSegments(string $method, string $first): array
    {
        if (isset($this->compiled[$method][$first])) {
            return $this->compiled[$method][$first];
        }

        if (empty($this->groups[$method][$first])) {
            return $this->compiled[$method][$first] = [];
        }

        $this->sortBucket($method, $first);

        // Chunk validation below uses preg_match() to prove a pattern compiles. A
        // failing pattern emits a warning, and suppressing that per call with `@`
        // costs far more than the handler does for a whole bucket.
        set_error_handler(static fn(): bool => true);

        try {
            return $this->compiled[$method][$first] = $this->buildSegmentsFor($this->groups[$method][$first]);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Walk a candidate's tokens against the decoded URI segments.
     *
     * Used only for candidates that cannot be compiled into a combined regex:
     * optional parameters, `regex(...)` types and custom validators. A wildcard
     * absorbs every remaining segment, and any tokens after it form a required
     * suffix, so they are matched against the tail rather than being ignored.
     *
     * @param list<array<int, string>> $tokens
     * @param list<string>             $parts
     * @return array<string, mixed>|null Captured parameters, or null when it does not match.
     */
    private function matchTokens(array $tokens, array $parts, int $partCount): ?array
    {
        $params     = [];
        $partIndex  = 0;
        $tokenCount = \count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            [$kind, $name, $type] = $tokens[$i] + [null, null, null];

            if ($kind === self::KIND_WILDCARD) {
                $suffix    = \array_slice($tokens, $i + 1);
                $remaining = $partCount - $partIndex - \count($suffix);

                // The suffix needs its own segments, so it may not be eaten by the
                // wildcard. This is what makes "/files/{path:*}/x2" reject "/files/a/b".
                if ($remaining < 0) {
                    return null;
                }

                $params[$name] = $remaining === 0
                    ? []
                    : \array_slice($parts, $partIndex, $remaining);

                foreach ($suffix as $slot => $token) {
                    if ($token[0] !== self::KIND_STATIC) {
                        throw new RuntimeException(
                            'Only static segments may follow a wildcard parameter: '
                                . (string) ($token[1] ?? ''),
                        );
                    }

                    if ($parts[$partIndex + $remaining + $slot] !== $token[1]) {
                        return null;
                    }
                }

                return $params;
            }

            if ($kind === self::KIND_PARAM_OPT || $kind === self::KIND_REGEX_OPT) {
                if (!isset($parts[$partIndex])) {
                    continue;
                }

                $segment = $parts[$partIndex];

                if ($kind === self::KIND_PARAM_OPT) {
                    $validator = $this->types[$type] ?? null;
                    if ($validator === null) {
                        throw new RuntimeException("Unknown validator: $type");
                    }
                    if ($validator($segment)) {
                        $params[$name] = $segment;
                        $partIndex++;
                    }
                } elseif (preg_match('/^' . $type . '$/', $segment)) {
                    $params[$name] = $segment;
                    $partIndex++;
                }

                continue;
            }

            if (!isset($parts[$partIndex])) {
                return null;
            }

            $segment = $parts[$partIndex];

            if ($kind === self::KIND_STATIC) {
                if ($name !== $segment) {
                    return null;
                }
                $partIndex++;
                continue;
            }

            if ($kind === self::KIND_PARAM) {
                $validator = $this->types[$type] ?? null;
                if ($validator === null) {
                    throw new RuntimeException("Unknown validator: $type");
                }
                if (!$validator($segment)) {
                    return null;
                }
                $params[$name] = $segment;
                $partIndex++;
                continue;
            }

            if ($kind === self::KIND_REGEX) {
                if (!preg_match('/^' . $type . '$/', $segment)) {
                    return null;
                }
                $params[$name] = $segment;
                $partIndex++;
                continue;
            }

            return null;
        }

        return $partIndex === $partCount ? $params : null;
    }

    /**
     * Resolve a static route, whose handler is already parsed and stored.
     *
     * @param array<string, mixed> $route
     * @return array{vars: array, override: array<string, string>, groups: array}
     */
    private function matchStatic(array $route): array
    {
        return [
            'vars'     => [],
            'override' => $route['override'] ?? $this->cachedOverride($route['handler']),
            'groups'   => $route['groups'],
        ];
    }

    /**
     * Turn one specificity-ordered bucket into match segments.
     *
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private function buildSegmentsFor(array $routes): array
    {
        $segments = [];
        $run      = [];

        foreach ($routes as $route) {
            $route = $this->prepareRoute($route);
            $body  = $this->compileRouteBody($route['tokens']);

            if ($body === null) {
                if ($run !== []) {
                    $this->flushRun($segments, $run);
                    $run = [];
                }
                $segments[] = ['route' => $route];
                continue;
            }

            $run[] = [$route, $body];
        }

        if ($run !== []) {
            $this->flushRun($segments, $run);
        }

        return $segments;
    }

    /**
     * Ensure a candidate carries the metadata the match loop reads.
     *
     * Routes built by {@see self::storeRoute()} already have it. A table restored
     * from a hand-written state (or an older shape) is completed here, once, instead
     * of carrying optionals into the request path.
     *
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function prepareRoute(array $route): array
    {
        if (isset($route['captures'], $route['min'], $route['max'], $route['wildcard'], $route['override'])) {
            return $route;
        }

        [$min, $max, $wildcard, $captures] = $this->routeShape($route['tokens']);

        $route['min']      = $min;
        $route['max']      = $max;
        $route['wildcard'] = $wildcard;
        $route['captures'] = $captures;
        $route['override'] ??= $this->cachedOverride($route['handler'] ?? null);

        return $route;
    }

    /**
     * Chunk a contiguous run of combinable candidates and append it to `$segments`.
     *
     * Each chunk carries only its own routes so that the `(*:N)` mark the pattern
     * reports is a valid index into them.
     *
     * @param list<array<string, mixed>>                      $segments
     * @param list<array{0: array<string, mixed>, 1: string}> $run
     */
    private function flushRun(array &$segments, array $run): void
    {
        foreach (array_chunk($run, self::REGEX_CHUNK_SIZE) as $slice) {
            $routes = [];
            $bodies = [];

            foreach ($slice as $slot => [$route, $body]) {
                $routes[$slot] = $route;
                $bodies[] = $body . '(*:' . $slot . ')';
            }

            $pattern = '#^(?|' . implode('|', $bodies) . ')$#u';

            // A pattern that does not compile falls back to one scan step per route
            // rather than silently skipping routes at dispatch time. Checked here,
            // once, so the request path never pays for it. The error handler
            // installed by bucketSegments() already suppresses the warning.
            if (preg_match($pattern, '') === false) {
                foreach ($routes as $route) {
                    $segments[] = ['route' => $route];
                }
                continue;
            }

            $segments[] = [
                'routes' => $routes,
                'chunk'  => $pattern,
            ];
        }
    }

    /**
     * Compile one candidate's tokens into a PCRE body, or null when it cannot be
     * represented exactly.
     *
     * The body describes the path *without* its leading slash, because the combined
     * pattern anchors on `#^…$#u` and is matched against the stripped subject. It
     * goes into a `(?|…)` branch-reset group, so each alternative's captures start at
     * 1 regardless of what the other alternatives contain.
     *
     * @param list<array<int, string>> $tokens
     */
    private function compileRouteBody(array $tokens): ?string
    {
        $body       = '';
        $hasBody    = false;
        $tokenCount = \count($tokens);

        foreach ($tokens as $index => $token) {
            [$kind, $name, $type] = $token + [null, null, null];

            if ($kind === self::KIND_STATIC) {
                $literal = preg_quote((string) $name, '#');
                $body    = $hasBody ? $body . '/' . $literal : $literal;
                $hasBody = true;
                continue;
            }

            if ($kind === self::KIND_WILDCARD) {
                $isLast = $index === $tokenCount - 1;

                if ($isLast) {
                    // A bare trailing wildcard also matches zero segments, so the
                    // separator is part of the optional group rather than mandatory.
                    $body .= $hasBody ? '(?:/(.*))?' : '(.*)';
                    $hasBody = true;
                    continue;
                }

                // With a suffix the wildcard is greedy; the anchored suffix then takes
                // the last occurrence, so "/files/a/x2/x2" captures "a/x2".
                $body .= ($hasBody ? '/' : '') . '(.*)';
                $hasBody = true;
                continue;
            }

            // Optional segments, regex types and custom validators are left to the
            // scan: expressing them here would change which URLs match.
            if ($kind !== self::KIND_PARAM) {
                return null;
            }

            $capture = match ($type) {
                'int'   => '\d+',
                'alpha' => '[A-Za-z]+',
                'alnum' => '[A-Za-z0-9]+',
                'uuid'  => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
                '*'     => '[^/]+',
                default => null
            };

            if ($capture === null) {
                return null;
            }

            $body .= ($hasBody ? '/' : '') . '(' . $capture . ')';
            $hasBody = true;
        }

        return $hasBody ? $body : null;
    }

    /**
     * Every HTTP method whose routes match the given URI.
     *
     * One pass over the compiled segments per method, rather than a full `match()`
     * per method. It also cannot recurse: {@see self::match()} calls this only for
     * `OPTIONS`, and this never calls `match()`.
     *
     * @return list<string>
     */
    public function getAllowedMethods(string $uri): array
    {
        $this->ensureFlushed();

        return $this->servedMethods($this->prepare($uri));
    }

    /**
     * The methods that would match `$uri`, or null when `$uri` matches no route.
     *
     * Null is a 404. A non-empty list is a 405 whose `Allow` header is that list.
     * The result is never an empty array, so a caller needs one branch rather than
     * two: either the path is unknown, or exactly which methods it serves.
     *
     * Asking is opt-in. A caller that does not need to tell a 404 from a 405 never
     * pays for the scan -- the existence check below settles every unknown path in
     * about 0.15 µs, and the per-method walk only runs for a path that exists.
     *
     * @return list<string>|null
     */
    public function allowedMethods(string $uri): ?array
    {
        $this->ensureFlushed();

        $u = $this->prepare($uri);

        if (!$this->mayExist($u)) {
            return null;
        }

        $allowed = $this->servedMethods($u);

        return $allowed === [] ? null : $allowed;
    }

    /**
     * What this server would actually serve for a URI: the registered methods plus
     * any synthetic `OPTIONS` that {@see self::autoOptions()} implies.
     *
     * This is the only place `OPTIONS` is added, so `match()`'s auto-OPTIONS branch
     * and `getAllowedMethods()` cannot advertise different lists for one URI.
     *
     * @param array{path: string, staticKey: ?string, parts: list<string>, subject: ?string} $u
     * @return list<string>
     */
    private function servedMethods(array $u): array
    {
        $allowed = $this->registeredMethods($u);

        if ($allowed === [] || !$this->autoOptions) {
            return $allowed;
        }

        // An explicit OPTIONS route is already in the list, and a real OPTIONS bucket
        // is too. Appending unconditionally would emit the method twice.
        if (\in_array('OPTIONS', $allowed, true)) {
            return $allowed;
        }

        $allowed[] = 'OPTIONS';

        return $allowed;
    }

    /**
     * The methods registered for a URI, with no synthesis.
     *
     * Ordered: the documented methods first, then any other method a route was
     * registered under, alphabetically. Deriving the tail from the table rather than
     * from {@see self::KNOWN_METHODS} is what lets a `PROPFIND` route -- which
     * `match()` happily serves -- appear in `Allow` instead of being reported as 404.
     *
     * @param array{path: string, staticKey: ?string, parts: list<string>, subject: ?string} $u
     * @return list<string>
     */
    private function registeredMethods(array $u): array
    {
        $parts     = $u['parts'];
        $staticKey = $u['staticKey'];
        $subject   = $u['subject'];
        $first     = $parts[0];
        $count     = \count($parts);
        $allowed   = [];

        foreach ($this->methodBuckets() as $method) {
            // HEAD is servable exactly when GET is: match() routes a HEAD request as
            // GET, so reporting it from the GET bucket keeps the two in step.
            $bucket = $method === 'HEAD' ? 'GET' : $method;

            if ($staticKey !== null && isset($this->static[$bucket][$staticKey])) {
                $allowed[] = $method;
                continue;
            }

            if (!isset($this->groups[$bucket])) {
                continue;
            }

            if (
                isset($this->groups[$bucket][$first])
                    && $this->runMatches(
                        $this->bucketSegments($bucket, $first),
                        $parts,
                        $count,
                        $subject,
                    )
            ) {
                $allowed[] = $method;
                continue;
            }

            if (
                isset($this->groups[$bucket][self::DYNAMIC_BUCKET])
                    && $this->runMatches(
                        $this->bucketSegments($bucket, self::DYNAMIC_BUCKET),
                        $parts,
                        $count,
                        $subject,
                    )
            ) {
                $allowed[] = $method;
            }
        }

        return $allowed;
    }

    /**
     * Every method worth checking, in a stable order.
     *
     * The known methods keep their documented order so `Allow` is predictable; any
     * other method present in the table follows, sorted, so a non-standard method is
     * reported rather than silently omitted.
     *
     * Memoised because the 405 path asks for it on every call, and the answer only
     * changes when the table does.
     *
     * @return list<string>
     */
    private function methodBuckets(): array
    {
        if ($this->methodList !== null) {
            return $this->methodList;
        }

        $extra = [];

        foreach ([$this->static, $this->groups] as $table) {
            foreach (array_keys($table) as $method) {
                $method = (string) $method;

                if (!\in_array($method, self::KNOWN_METHODS, true)) {
                    $extra[$method] = true;
                }
            }
        }

        if ($extra === []) {
            return $this->methodList = self::KNOWN_METHODS;
        }

        $extra = array_keys($extra);
        sort($extra);

        return $this->methodList = [...self::KNOWN_METHODS, ...$extra];
    }

    /**
     * Whether any route could match this URI, for any method.
     *
     * A cheap negative answer only: `true` means "scan", not "exists". The `dynamic`
     * flag is the deliberate escape hatch -- a table with a leading-parameter route
     * (`/{controller}/{action}`) can match any first segment, so it must never be
     * short-circuited to a false 404.
     *
     * @param array{path: string, staticKey: ?string, parts: list<string>, subject: ?string} $u
     */
    private function mayExist(array $u): bool
    {
        $index = $this->pathIndex ??= $this->buildPathIndex();

        if ($index['dynamic']) {
            return true;
        }

        if ($u['staticKey'] !== null && isset($index['static'][$u['staticKey']])) {
            return true;
        }

        return isset($index['first'][$u['parts'][0]]);
    }

    /**
     * Build the union of every path-bearing key in the table.
     *
     * Derived state, invalidated exactly like {@see self::$compiled}: it is a view of
     * `$static` and `$groups`, never read from a persisted table.
     *
     * @return array{static: array<string, true>, first: array<string, true>, dynamic: bool}
     */
    private function buildPathIndex(): array
    {
        $static  = [];
        $first   = [];
        $dynamic = false;

        foreach ($this->static as $routes) {
            foreach (array_keys($routes) as $path) {
                $static[(string) $path] = true;
            }
        }

        foreach ($this->groups as $buckets) {
            foreach (array_keys($buckets) as $key) {
                if ($key === self::DYNAMIC_BUCKET) {
                    $dynamic = true;
                    continue;
                }

                $first[(string) $key] = true;
            }
        }

        return [
            'static'  => $static,
            'first'   => $first,
            'dynamic' => $dynamic,
        ];
    }

    /**
     * Whether any candidate in a segment list matches.
     *
     * @param list<array<string, mixed>> $run
     * @param list<string>               $parts
     * @param string|null                $subject Null when the URI contains a decoded
     *                                            separator, so chunks are walked instead.
     */
    private function runMatches(array $run, array $parts, int $partCount, ?string $subject): bool
    {
        foreach ($run as $segment) {
            if (isset($segment['route'])) {
                $route = $segment['route'];

                if ($partCount < $route['min']) {
                    continue;
                }
                if (!$route['wildcard'] && $partCount > $route['max']) {
                    continue;
                }

                if ($this->matchTokens($route['tokens'], $parts, $partCount) !== null) {
                    return true;
                }

                continue;
            }

            if ($subject === null) {
                foreach ($segment['routes'] as $route) {
                    if ($partCount < $route['min']) {
                        continue;
                    }
                    if (!$route['wildcard'] && $partCount > $route['max']) {
                        continue;
                    }

                    if ($this->matchTokens($route['tokens'], $parts, $partCount) !== null) {
                        return true;
                    }
                }

                continue;
            }

            if (preg_match($segment['chunk'], $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a registered handler into the `vars` / `override` / `groups`
     * triple the dispatcher consumes.
     *
     * The parse itself is cached — see {@see cachedOverride()} — because this
     * runs on every request of every static route.
     *
     * @return array{vars: array, override: array<string, string>, groups: array}
     */
    protected function resolveHandler(
        string|array|null $handler,
        array $params,
        array $groups,
    ): array {
        return [
            'vars'     => $params,
            'override' => $this->cachedOverride($handler),
            'groups'   => $groups,
        ];
    }

    /**
     * Cached parsed overrides, keyed by handler value.
     *
     * Parsing is pure, so results can be reused. String handlers use their exact
     * value, arrays use a serialized key, and `null` has a separate key from `''`.
     *
     * @var array<string, array<string, string>>
     */
    private array $resolvedOverrides = [];

    /**
     * Resolve a registered handler to its `override` triple, reusing the answer
     * from the first time this handler was seen.
     *
     * @return array<string, string>
     */
    protected function cachedOverride(string|array|null $handler): array
    {
        if ($handler === null) {
            return $this->resolvedOverrides["\0null"] ??= $this->parseHandler(null);
        }

        if (\is_string($handler)) {
            return $this->resolvedOverrides[$handler] ??= $this->parseHandler($handler);
        }

        $key = "\0array\0" . serialize($handler);

        return $this->resolvedOverrides[$key] ??= $this->parseHandler($handler);
    }

    /**
     * Parse a handler into the `override` array {@see resolveHandler()} returns.
     *
     * Pure: depends on the handler alone, which is why the result is cached by
     * {@see cachedOverride()}. The rules below are the whole contract, and
     * `RouterFqcnNamespaceTest` pins the fully-qualified cases.
     *
     * @return array<string, string>
     */
    private function parseHandler(string|array|null $handler): array
    {
        if ($handler === null) {
            $override = [];
        } elseif (\is_array($handler)) {
            $override = $handler;
            if (!empty($override['controller'])) {
                $controller = (string) $override['controller'];

                // Split at the last backslash: preceding segments form the
                // namespace, and the final segment is the controller class.
                $namespaceSeparator = strrpos($controller, '\\');
                if ($namespaceSeparator !== false) {
                    // Fully qualified names replace the group namespace; relative
                    // names inherit it. Strip a leading root separator to normalize
                    // fully qualified names with and without that prefix.
                    $override['namespace']  = ltrim(substr($controller, 0, $namespaceSeparator), '\\');
                    $override['controller'] = substr($controller, $namespaceSeparator + 1);
                }
            }
        } else {
            $override = [];
            $handler  = trim($handler);
            if ($handler !== '') {
                $action          = null;
                $actionSeparator = strrpos($handler, '::');
                if ($actionSeparator !== false) {
                    $action  = substr($handler, $actionSeparator + 2);
                    $handler = substr($handler, 0, $actionSeparator);
                }

                if ($handler !== '') {
                    $namespaceSeparator = strrpos($handler, '\\');
                    if ($namespaceSeparator !== false) {
                        // Same canonicalisation as the array branch above: a
                        // leading root backslash must not become part of the
                        // namespace string.
                        $override['namespace']  = ltrim(substr($handler, 0, $namespaceSeparator), '\\');
                        $override['controller'] = substr($handler, $namespaceSeparator + 1);
                    } else {
                        $override['controller'] = $handler;
                    }
                }

                if ($action !== null) {
                    $override['action'] = $action;
                }
            }
        }

        return $override;
    }

    // -------------------------------------------------------------------------
    //  Compiled route table (route:cache)
    // -------------------------------------------------------------------------

    /**
     * Return the serializable route table: scalars and arrays only. Handlers and
     * middleware values are validated by {@see self::add()} and
     * {@see self::pushGroupValues()}.
     *
     * `types` is omitted because validators are closures; built-ins are restored by
     * the constructor, and custom types must be re-registered (`customTypes()` lists
     * them). `resolvedOverrides` is derived from handlers and is not persisted.
     * `unsortedBuckets` is included to preserve deferred sorting behavior.
     *
     * @return array<string, mixed>
     * @see self::restoreState() for the inverse, and `azera routes:cache`.
     */
    public function exportState(): array
    {
        return [
            'static'               => $this->static,
            'groups'               => $this->groups,
            'namedRoutes'          => $this->namedRoutes,
            'middlewareGroupStack' => $this->middlewareGroupStack,
            'prefixGroupStack'     => $this->prefixGroupStack,
            'namespaceGroupStack'  => $this->namespaceGroupStack,
            'controllerGroupStack' => $this->controllerGroupStack,
            'lastAddedTokens'      => $this->lastAddedTokens,
            'autoOptions'          => $this->autoOptions,
            'unsortedBuckets'      => $this->unsortedBuckets,
        ];
    }

    /**
     * Replace this router's table with a previously exported one.
     *
     * Only the keys {@see self::exportState()} produces are applied, so a file
     * written by an older release cannot reintroduce a property that has since been
     * removed, and cannot overwrite `types`. Unknown keys are ignored rather than
     * assigned, which keeps the method safe against a hand-edited cache file.
     *
     * This does not clear the table first: it overwrites the keys it knows, so
     * restoring onto a router that already has routes replaces its routes for those
     * buckets. Call {@see self::forget()} first if that matters -- the CLI task and
     * every benchmark entry point do.
     *
     * @param array<string, mixed> $state
     * @return static
     */
    public function restoreState(array $state): static
    {
        $known = [
            'static',
            'groups',
            'namedRoutes',
            'middlewareGroupStack',
            'prefixGroupStack',
            'namespaceGroupStack',
            'controllerGroupStack',
            'lastAddedTokens',
            'autoOptions',
            'unsortedBuckets',
        ];

        foreach ($known as $property) {
            if (\array_key_exists($property, $state)) {
                $this->{$property} = $state[$property];
            }
        }

        // The memo is keyed by handler and derived from them; a restored table has
        // different handler strings than whatever was memoised here, so drop it
        // rather than serve answers for a table that no longer exists.
        $this->resolvedOverrides = [];

        // Likewise the compiled match index: it is keyed by the very buckets that
        // were just replaced. Dropping it means the first match() rebuilds from the
        // restored tokens, which is also what completes a state written by an older
        // release (its candidates lack the derived metadata, see prepareRoute()).
        $this->compiled = [];

        // And the path index, for the same reason: it is a union over the buckets.
        $this->pathIndex = null;

        return $this;
    }

    /**
     * The parameter type names this router knows that the constructor does not.
     *
     * These are the ones registered with {@see self::type()}. Their validators are
     * closures and therefore cannot be cached, so an application using them must
     * re-register them after restoring a compiled table -- exactly as it registered
     * them when building the table in the first place. The `routes:cache` command
     * writes this list into the generated file's header so the requirement is
     * visible where it matters.
     *
     * @return list<string>
     */
    public function customTypes(): array
    {
        $builtIn = ['int', 'alpha', 'alnum', 'uuid', '*'];
        return array_values(array_diff(array_keys($this->types), $builtIn));
    }

    // -------------------------------------------------------------------------
    //  Route cache
    // -------------------------------------------------------------------------

    /**
     * Envelope key written alongside the table. Not part of the table itself, so
     * {@see self::exportState()} stays pure data and {@see self::restoreState()}
     * ignores it through its known-keys list.
     *
     * 2: the dynamic bucket key changed from `__DYNAMIC__` to a control-character
     * key. It is part of the persisted `groups`, and a version-1 file would load
     * without error but silently stop matching every dynamic route. Bumping the
     * version makes such a file stale instead, so it is rebuilt on the next request.
     */
    private const CACHE_VERSION = 2;

    /**
     * The declared units, in the order cached() was called.
     *
     * Each entry is `['files' => list<string>, 'build' => callable]`. Nothing in
     * here has run yet: the point of deferral is that whether to run at all depends
     * on a file that has not been read.
     *
     * @var list<array{files: list<string>, build: callable}>
     */
    private array $units = [];

    /** True once the table has been built or restored. */
    private bool $flushed = false;

    /**
     * How many units are currently executing.
     *
     * Guards `add()`'s mixed-mode check, which must not fire for the routes a unit
     * legitimately registers. A counter rather than a boolean because one unit may
     * call another through a shared helper, and the state has to fall back correctly.
     */
    private int $flushing = 0;

    /** Seconds a positive freshness verdict is reused for. */
    private int $revalidateFreq = 0;

    /** When the last filesystem verdict was reached (microtime). */
    private float $checkedAt = 0.0;

    /** The last filesystem verdict; null when it was negative. */
    private ?bool $freshVerdict = null;

    /**
     * Set the compiled table path before the router is first read.
     *
     * {@see self::cached()} enables caching automatically. Pass null to use the
     * derived default path; use {@see self::disableCache()} to opt out.
     *
     * @param string|null $path Absolute file path, or null for the derived default.
     *                          The directory is created on write.
     */
    public function setCachePath(?string $path): static
    {
        if ($path !== null && $path === '') {
            throw new InvalidArgumentException(
                'setCachePath() expects a file path or null for the default location.',
            );
        }

        // A path is part of the table's identity: switching it after the table was
        // read would leave one table and two files. Re-declaring the same path is a
        // no-op because worker bootstraps run this line again.
        if ($this->flushed && $path !== $this->cachePath) {
            throw new LogicException(sprintf(
                'setCachePath() must be called before the route table is read, but it was '
                    . 'already read from %s.',
                $this->cachePath ?? 'the derived temp path',
            ));
        }

        $this->cachePath    = $path;
        $this->checkedAt    = 0.0;
        $this->freshVerdict = null;

        return $this;
    }

    /**
     * Disable compiled-table caching.
     *
     * Pending route blocks are registered immediately; an already-loaded table stays
     * in memory, but the compiled file is ignored for this process. Useful for
     * uncached benchmarks when bootstrap always declares `cached()` units.
     */
    public function disableCache(): static
    {
        $this->caching = false;

        if (!$this->flushed) {
            $this->flushed = true;

            // The pending units run in place, without emptying the table first: with the
            // cache off, a route registered before a unit is simply a route, which is
            // the behaviour a caller opting out is asking for.
            foreach ($this->units as $unit) {
                $snapshot = $this->snapshotGroupStacks();
                $this->flushing++;
                try {
                    ($unit['build'])($this);
                } finally {
                    $this->flushing--;
                    $this->restoreGroupStacks($snapshot);
                }
            }
            $this->units = [];
        }

        return $this;
    }

    /** Whether the compiled table is used at all. */
    private bool $caching = true;

    /** The location chosen by setCachePath(), or null for the derived default. */
    private ?string $cachePath = null;

    /** The path once resolved, so the derived name is not recomputed per read. */
    private ?string $resolvedCachePath = null;

    /**
     * Return the compiled table path, or null when caching is disabled or no sources
     * are declared. An explicit {@see self::setCachePath()} takes precedence;
     * otherwise the path is derived from the source files under
     * `sys_get_temp_dir()/azera_routes/`. The source hash isolates applications and
     * route-file sets; when available, the user ID isolates files in the 0700 directory.
     * The derived path is available only after route units are declared.
     */
    public function cachePath(): ?string
    {
        if (!$this->caching) {
            return null;
        }

        if ($this->cachePath !== null) {
            return $this->cachePath;
        }

        if ($this->resolvedCachePath !== null) {
            return $this->resolvedCachePath;
        }

        $sources = $this->cachedSources();
        if ($sources === []) {
            return null;
        }

        $identity = implode("\0", $sources);
        //if (\function_exists('posix_geteuid')) {
        //    $identity .= "\0" . posix_geteuid();
        //}

        return $this->resolvedCachePath =
            sys_get_temp_dir()
                . DIRECTORY_SEPARATOR . 'azera_routes'
                . DIRECTORY_SEPARATOR . 'routes-' . hash('xxh3', $identity) . '.php';
    }

    /**
     * The path the table would be written to, or null when no unit is declared.
     *
     * Kept separate from {@see self::cachePath()} so diagnostics never resolve and
     * cache a name that the application has not actually opted into.
     */
    public function resolvedCachePath(): ?string
    {
        return $this->cachePath();
    }

    /**
     * Seconds to reuse a positive route-table freshness verdict.
     *
     * Unlike template caching, the router checks once per request, not per render.
     * This matters only when a Router instance survives across requests; a positive
     * value can delay detecting source changes by that interval. Negative verdicts
     * are never reused, so the next rebuild checks the current files.
     *
     * @param int $seconds Seconds; negative values are treated as 0.
     */
    public function setRevalidateFreq(int $seconds): static
    {
        $this->revalidateFreq = max(0, $seconds);
        $this->checkedAt      = 0.0;
        $this->freshVerdict   = null;

        return $this;
    }

    /**
     * How many seconds a positive freshness verdict is reused for.
     */
    public function getRevalidateFreq(): int
    {
        return $this->revalidateFreq;
    }

    /**
     * Register a route block and its source file(s).
     *
     *     $router->cached(__FILE__, function (Router $r): void {
     *         $r->get('/', 'IndexController::indexAction');
     *     });
     *
     * The callback runs on the router's first read, and only if the compiled table is
     * missing or stale. Pass explicit source files (usually `__FILE__`) so changes to
     * the declaring file invalidate the cache; multiple files are supported.
     *
     * With caching enabled, blocks run in declaration order and must be top-level,
     * non-nested, and contain all routes; these restrictions are enforced. With caching
     * disabled, callbacks run immediately. Declaring a block enables caching;
     * {@see self::disableCache()} opts out.
     *
     * @param string|list<string> $files  Declared source files, conventionally `__FILE__`.
     * @param callable            $build  Receives this router.
     */
    public function cached(string|array $files, callable $build): static
    {
        if (!$this->caching) {
            // No cache: nothing to defer, so registration stays eager and an
            // application that opted out keeps its behaviour exactly.
            $this->flushing++;
            try {
                $build($this);
            } finally {
                $this->flushing--;
            }

            return $this;
        }

        if ($this->flushing > 0) {
            throw new LogicException(
                'cached() cannot be nested inside another cached() block.',
            );
        }

        if ($this->flushed) {
            // Bootstrap may run again after the table was built. Rebuild from the new declarations instead of appending; skip freshness checks because unchanged source files could otherwise restore the old table.
            $this->resetTable();
            $this->units        = [];
            $this->flushed      = false;
            $this->unitsStarted = false;
            $this->forceRebuild = true;
            $this->checkedAt    = 0.0;
            $this->freshVerdict = null;
        }

        $depth = count($this->middlewareGroupStack)
            + count($this->prefixGroupStack)
            + count($this->namespaceGroupStack)
            + count($this->controllerGroupStack);

        if ($depth > 0) {
            throw new LogicException(sprintf(
                'cached() must be called at the top level, but a route group is open (%s).',
                implode(', ', array_filter([
                    $this->prefixGroupStack !== [] ? 'prefix' : null,
                    $this->middlewareGroupStack !== [] ? 'middleware' : null,
                    $this->namespaceGroupStack !== [] ? 'namespace' : null,
                    $this->controllerGroupStack !== [] ? 'controller' : null,
                ])),
            ));
        }

        $list = [];
        foreach ((array) $files as $file) {
            if (!\is_string($file) || $file === '') {
                throw new InvalidArgumentException(sprintf(
                    'cached() expects file paths, %s given.',
                    get_debug_type($file),
                ));
            }
            $list[$file] = true;
        }

        $this->units[] = ['files' => array_keys($list), 'build' => $build];

        return $this;
    }

    /**
     * The declared source files of every unit, deduplicated, in declaration order.
     *
     * @return list<string>
     */
    public function cachedSources(): array
    {
        $files = [];
        foreach ($this->units as $unit) {
            foreach ($unit['files'] as $file) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    // -------------------------------------------------------------------------
    //  Flushing
    // -------------------------------------------------------------------------

    /**
     * Make sure the table exists, then apply the revalidation policy.
     *
     * Called from every way of reading the router, so a caller never has to know
     * whether the table is deferred, restored or live.
     */
    protected function ensureFlushed(): void
    {
        if (!$this->flushed) {
            $this->flushNow();
            return;
        }

        if (!$this->caching || $this->revalidateFreq <= 0) {
            return;
        }

        // Nothing to revalidate against: no units means no table to rebuild from, so
        // rebuilding here would empty a live table and refill it with nothing. Routes
        // registered directly (without cached()) are simply not cacheable.
        if ($this->units === []) {
            return;
        }

        // A verdict reached recently is still trusted.
        if (
            $this->freshVerdict === true
                && (microtime(true) - $this->checkedAt) < $this->revalidateFreq
        ) {
            return;
        }

        $state = $this->readIfFresh();
        if ($state === null) {
            // The files changed under a live table: rebuild it from the units rather
            // than serving routes that no longer match the code.
            $this->buildFromUnits();
            $this->tryWriteCache();
            return;
        }

        // Someone else rebuilt it (a deploy hook, another process). Adopt their table
        // so this process stops serving the previous one.
        $this->restoreState($state);
    }

    /**
     * First read: restore the compiled table, or build it and write it.
     */
    private function flushNow(): void
    {
        $this->flushed = true;

        if ($this->units === []) {
            return;
        }

        // The only point where a route registered outside a unit can still be observed:
        // every later rebuild empties the table and then runs nothing but units.
        $this->assertNoRoutesOutsideUnits();

        $force = $this->forceRebuild;
        $this->forceRebuild = false;

        if ($this->caching && !$force) {
            $state = $this->readIfFresh();
            if ($state !== null) {
                $this->restoreState($state);
                return;
            }
        }

        $this->buildFromUnits();

        if ($this->caching) {
            $this->tryWriteCache();
        }
    }

    /**
     * Reject routes registered outside cached units before the first flush.
     *
     * This one-time check stays out of `add()`'s hot path. It catches routes added
     * before the first `cached()` block, which {@see self::buildFromUnits()} would
     * discard. Later rebuilds start empty and run only cached units.
     */
    private function assertNoRoutesOutsideUnits(): void
    {
        if ($this->unitsStarted) {
            // A later rebuild emptied the table and then ran nothing but units, so
            // anything in it now came from a unit.
            return;
        }
        $this->unitsStarted = true;

        $outside = array_keys($this->static);
        foreach ($this->groups as $method => $buckets) {
            foreach ($buckets as $list) {
                if ($list !== []) {
                    $outside[] = (string) $method;
                    break 2;
                }
            }
        }

        if ($outside === []) {
            return;
        }

        throw new LogicException(sprintf(
            'A route was registered before the first cached() block (method "%s"), so it can '
                . 'never be part of the compiled table: the table is rebuilt from the cached() '
                . 'blocks alone, and that route would disappear on the next request. Declare '
                . 'every route inside a cached() block, or call disableCache() to opt out.',
            $outside[0],
        ));
    }

    /** Set once the first build has run, so the outside-a-unit check runs once. */
    private bool $unitsStarted = false;

    /** Set when the declarations changed after a flush, so the file must not be trusted. */
    private bool $forceRebuild = false;

    /**
     * Rebuild the table from units in declaration order. Restore group stacks after
     * each unit so unfinished group state cannot affect later declarations.
     */
    private function buildFromUnits(): void
    {
        $this->resetTable();

        foreach ($this->units as $unit) {
            $snapshot = $this->snapshotGroupStacks();
            $this->flushing++;
            try {
                ($unit['build'])($this);
            } finally {
                $this->flushing--;
                $this->restoreGroupStacks($snapshot);
            }
        }
    }

    /**
     * Read the compiled table if it exists and no declared source is newer.
     *
     * Records the verdict for {@see self::setRevalidateFreq()}: a positive one with
     * its timestamp, a negative one as "nothing to reuse".
     *
     * @return array<string, mixed>|null The table, or null when it must be rebuilt.
     */
    private function readIfFresh(): ?array
    {
        $this->checkedAt    = microtime(true);
        $this->freshVerdict = null;

        $path = $this->cachePath();
        if ($path === null || !is_file($path)) {
            return null;
        }

        // The file was written under a 0700 directory. If that is no longer true --
        // someone chmod'ed it, or the path was pointed at a shared directory -- this
        // process must not execute whatever happens to be there. A cache is optional,
        // so the safe answer is "rebuild in memory".
        try {
            $this->assertSafeCacheDir(dirname($path));
        } catch (RuntimeException) {
            return null;
        }

        // A corrupt or half-copied file must not take the application down: the units
        // can always rebuild, and a cache is an optimisation. A fatal parse error
        // cannot be caught, so the file is also checked for a plausible shape first --
        // but the guard below is what covers the file changing between the check and
        // the include.
        try {
            $state = @include $path;
        } catch (\Throwable) {
            return null;
        }

        if (!isset($state['__cache']) || !\is_array($state['__cache'])) {
            return null;
        }

        $meta = $state['__cache'];

        if (($meta['version'] ?? null) !== self::CACHE_VERSION) {
            return null;
        }

        $recorded = $meta['files'] ?? null;
        if (!\is_array($recorded)) {
            return null;
        }

        // The declared set must match, not just each recorded entry: that is what
        // catches a route file being added or removed, which leaves every surviving
        // mtime untouched.
        $declared = $this->cachedSources();
        $onDisk   = array_map('strval', array_keys($recorded));
        sort($declared);
        sort($onDisk);
        if ($declared !== $onDisk) {
            return null;
        }

        $compiled = (int) ($meta['compiled'] ?? 0);

        foreach ($recorded as $file => $mtime) {
            $file = (string) $file;

            // PHP's stat cache survives across requests in a long-lived worker, so
            // without this the check answers with the mtime it saw at boot and stops
            // noticing edits: measured, 0.055 us and lying, against 0.84 us and honest.
            clearstatcache(true, $file);

            $current = @filemtime($file);
            if ($current === false) {
                return null;
            }

            if ($current !== (int) $mtime) {
                return null;
            }

            // A source modified in the same second the table was compiled counts as
            // newer. A deploy that finishes inside one clock tick would otherwise
            // restore a table built from the previous code -- a bug that appears only
            // under a fast pipeline, which is the worst kind to debug.
            if ($current >= $compiled) {
                return null;
            }
        }

        $this->freshVerdict = true;

        return $state;
    }

    /**
     * Write the table, downgrading an I/O failure to a warning.
     *
     * A request path must not fail because the cache directory is read-only, full, or
     * owned by another user: the table in memory is already correct, and the only
     * consequence of not writing is that the next request rebuilds. `compileCache()`
     * uses the strict {@see self::writeCache()} instead, because there an operator
     * asked for the file and deserves the error.
     */
    private function tryWriteCache(): void
    {
        try {
            $this->writeCache();
        } catch (RuntimeException $e) {
            $this->checkedAt    = microtime(true);
            $this->freshVerdict = null; // nothing to trust; re-check next read
            if ($this->warnOnWriteFailure) {
                trigger_error(
                    'Route cache not written: ' . $e->getMessage()
                        . ' The table was built in memory and this request is unaffected.',
                    E_USER_WARNING,
                );
            }
        }
    }

    /** Whether a failed write should raise a warning. Off by default. */
    private bool $warnOnWriteFailure = false;

    /**
     * Reject group/world-writable cache directories because compiled tables are
     * loaded with `require` and could execute planted code. Skipped on Windows,
     * where POSIX permissions and portable ACL checks are unavailable.
     */
    private function assertSafeCacheDir(string $dir): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $perms = @fileperms($dir);
        if ($perms === false) {
            throw new RuntimeException("Cannot stat route cache directory: {$dir}");
        }

        if (($perms & 0o022) !== 0) {
            throw new RuntimeException(sprintf(
                'Route cache directory is group/world writable (mode %04o), refusing to load '
                    . 'executable code from it: %s',
                $perms & 0o7777,
                $dir,
            ));
        }
    }

    /**
     * Report a failed background write instead of failing silently.
     *
     * Off by default: a container whose cache directory is mounted read-only is a
     * legitimate configuration, and the only cost is rebuilding per request.
     */
    public function warnOnWriteFailure(bool $on = true): static
    {
        $this->warnOnWriteFailure = $on;

        return $this;
    }

    /**
     * Write the current table, with its freshness envelope, atomically.
     */
    private function writeCache(): void
    {
        $path = $this->cachePath();
        if ($path === null) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create route cache directory: {$dir}");
        }

        // The table is executable code that this process will `require`, so a directory
        // other users can write to is a remote code execution vector: they could drop a
        // file at the path we are about to load. The framework already refuses this for
        // AOP proxies; a cache directory is the same category. Refusing here means the
        // caller degrades to rebuilding in memory, not that the request fails.
        $this->assertSafeCacheDir($dir);

        $compiled = time();

        $files = [];
        foreach ($this->cachedSources() as $file) {
            clearstatcache(true, $file);
            $files[$file] = (int) (@filemtime($file) ?: 0);
        }

        $state = $this->exportState();
        $state['__cache'] = [
            'version'  => self::CACHE_VERSION,
            'compiled' => $compiled,
            'files'    => $files,
        ];

        $code = "<?php\n\n"
            . "// Generated by the Azera route cache. Do not edit.\n"
            . '// Routes: ' . $this->countRoutes() . "\n"
            . '// PHP: ' . PHP_VERSION . "\n"
            . "// Sources watched for staleness:\n"
            . implode('', array_map(static fn(string $f): string => "//   {$f}\n", array_keys($files)))
            . "\nreturn "
            . var_export($state, true)
            . ";\n";

        // Write then rename: a request that includes a half-written table during a
        // deploy is a fatal error, and rename() within one filesystem is atomic.
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $code, \LOCK_EX) === false) {
            throw new RuntimeException("Cannot write route cache: {$tmp}");
        }
        // 0600: the table is data about the application's routing, not a secret, but
        // there is no reason for another user on the host to read it, and it is the
        // same posture the AOP proxy cache takes.
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot move {$tmp} to {$path}");
        }

        clearstatcache(true, $path);
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        // OPcache ignores files newer than `opcache.file_update_protection` (2 s by
        // default). Despite the atomic rename, it would recompile this table on every
        // require (529 us vs 12.4 us). Backdate the mtime past the window; freshness
        // uses the envelope timestamp, though `routes:status` then reports an earlier
        // write time.
        $this->makeOpcacheAcceptable($path);

        // Populate OPcache now, in the process that did the write. This is the one
        // moment it is cheap for *this* process: every later request in this worker
        // would otherwise pay the same compile, and a fresh `Router` per request means
        // per-instance bookkeeping cannot help them. The result is discarded -- it is
        // identical to the table already in memory.
        if ($this->opcacheEnabled()) {
            clearstatcache(true, $path);
            require $path;
        }

        $this->freshVerdict = true;
        $this->checkedAt    = microtime(true);
    }

    /**
     * Backdate the table past OPcache's update-protection window.
     *
     * Apply this even when OPcache is off here: another SAPI may share the file.
     * Freshness uses the envelope timestamp, not the file mtime.
     */
    private function makeOpcacheAcceptable(string $path): void
    {
        $protection = (int) (ini_get('opcache.file_update_protection') ?: 2);
        $target     = time() - $protection - 1;

        // Only ever move the mtime backwards, and only when that is needed.
        clearstatcache(true, $path);
        $current = @filemtime($path);
        if ($current !== false && $current <= $target) {
            return;
        }

        @touch($path, $target);
        clearstatcache(true, $path);
    }

    private function opcacheEnabled(): bool
    {
        $enabled = (bool) ini_get('opcache.enable');
        if (!$enabled) {
            return false;
        }

        // Under CLI, OPcache is off unless enabled_cli is set, and without it the
        // require below would just recompile -- the cost we are trying to avoid.
        return PHP_SAPI !== 'cli' || (bool) ini_get('opcache.enable_cli');
    }

    /**
     * Empty the table, keeping the validators.
     *
     * Validators are code, they are the same either way, and clearing them would
     * silently break a rebuilt table that uses a custom type.
     */
    private function resetTable(): void
    {
        $this->static               = [];
        $this->groups               = [];
        $this->namedRoutes          = [];
        $this->middlewareGroupStack = [];
        $this->prefixGroupStack     = [];
        $this->namespaceGroupStack  = [];
        $this->controllerGroupStack = [];
        $this->lastAddedTokens      = null;
        $this->unsortedBuckets      = [];
        $this->resolvedOverrides    = [];
        $this->compiled             = [];
        $this->pathIndex            = null;
        $this->methodList           = null;
    }

    /**
     * Rebuild the table from declared units and write it, ignoring any existing file.
     * Used by `azera routes:cache` to prewarm or rebuild during deployment; otherwise
     * the first router read creates a missing table.
     *
     * @return int Number of routes written.
     */
    public function compileCache(): int
    {
        if ($this->units === []) {
            throw new LogicException(
                'compileCache() found no cached() units. Routes must be declared inside cached($files, function (Router $r) { ... }) for a table to be compiled.',
            );
        }
        if ($this->cachePath() === null) {
            throw new LogicException(
                'compileCache() needs a destination: declare a cached() unit, or call setCachePath().',
            );
        }

        $this->flushed = true;
        $this->assertNoRoutesOutsideUnits();
        $this->buildFromUnits();
        $this->writeCache();

        return $this->countRoutes();
    }

    /**
     * Delete the compiled table.
     *
     * @return bool True when a file was removed.
     */
    public function clearCache(): bool
    {
        $path = $this->cachePath();
        if ($path === null || !is_file($path)) {
            return false;
        }

        @unlink($path);
        clearstatcache(true, $path);
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $this->checkedAt    = 0.0;
        $this->freshVerdict = null;

        return true;
    }

    /**
     * Describe the compiled table without trusting it.
     *
     * @return array{enabled: bool, path: ?string, exists: bool, fresh: bool, routes: int, files: list<string>, compiled: ?int}
     */
    public function cacheStatus(): array
    {
        $path = $this->cachePath();

        $status = [
            'enabled'  => $this->caching,
            'path'     => $path,
            'exists'   => false,
            'fresh'    => false,
            'routes'   => 0,
            'files'    => [],
            'compiled' => null,
        ];

        if (!$this->caching) {
            return $status;
        }

        $status['files'] = $this->cachedSources();

        if ($path === null || !is_file($path)) {
            return $status;
        }

        $status['exists'] = true;

        $state = require $path;
        if (\is_array($state)) {
            $status['routes'] = $this->countRoutesIn($state);
        }

        // The envelope's stamp, not the file's mtime: the mtime is deliberately
        // backdated past OPcache's update-protection window (see writeCache()), so
        // reporting it would show a write time a few seconds in the past.
        $stamp = (int) ($state['__cache']['compiled'] ?? 0);
        $status['compiled'] = $stamp > 0 ? $stamp : null;

        // Freshness is a property of the files, not of the file's age, so reuse the
        // same check the runtime uses rather than approximating it here.
        $status['fresh'] = $this->readIfFresh() !== null;

        return $status;
    }

    /**
     * Number of routes currently in the table.
     */
    private function countRoutes(): int
    {
        return $this->countRoutesIn($this->exportState());
    }

    /**
     * @param array<string, mixed> $state
     */
    private function countRoutesIn(array $state): int
    {
        $n = 0;
        foreach ($state['static'] ?? [] as $byPath) {
            $n += count($byPath);
        }
        foreach ($state['groups'] ?? [] as $bucket) {
            foreach ($bucket as $list) {
                $n += count($list);
            }
        }

        return $n;
    }

}
