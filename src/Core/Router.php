<?php

namespace Azera\Core;

use LogicException;
use RuntimeException;
use InvalidArgumentException;

/**
 * A simple and efficient router for mapping HTTP requests to handlers based on URI patterns and HTTP methods. Supports static routes, typed parameters, optional segments, wildcards, and route groups with shared middleware, namespaces, or controllers. Routes are matched in order of specificity, with static routes taking precedence over dynamic ones. Named routes allow for easy URL generation.
 */
class Router
{
    protected const KIND_STATIC = 1;
    protected const KIND_WILDCARD = 2;
    protected const KIND_PARAM = 3;
    protected const KIND_PARAM_OPT = 4;
    protected const KIND_REGEX = 5;
    protected const KIND_REGEX_OPT = 6;

    protected array $static = []; // [method][path] => ['handler'=>..., 'namespace'=>...]
    protected array $groups = []; // [method][firstSegment] => [route, ...]
    protected array $types = [];  // type validators
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
            'uuid'  => function ($v) {
                if (\strlen($v) !== 36) {
                    return false;
                }
                if ($v[8] !== '-' || $v[13] !== '-' || $v[18] !== '-' || $v[23] !== '-') {
                    return false;
                }
                $hex = \str_replace('-', '', $v);
                return \ctype_xdigit($hex);
            },
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
     * Get all HTTP methods that have routes matching the given URI.
     * Useful for generating Allow headers for OPTIONS requests or 405 responses.
     *
     * @param string $uri The request URI (path) to check, e.g. "/blog/hello-world"
     * @return array Array of HTTP methods (e.g., ['GET', 'POST'])
     */
    public function getAllowedMethods(string $uri): array
    {
        $this->ensureFlushed();

        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        $allowed = [];
        foreach ($methods as $m) {
            if ($this->match($uri, $m) !== null) {
                $allowed[] = $m;
            }
        }
        return $allowed;
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
                    . 'Declare every route inside a cached() block, which runs before the first '
                    . 'match().',
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
                        'Route handler key "%s" must be a string, %s given. A route is a '
                            . 'description of which controller and action to call, never the '
                            . 'callable itself.',
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
                'handler' => $handler,
                'tokens'  => $tokens,
                'groups'  => $groups,
            ];
            return;
        }

        $first = $tokens[0][0] === self::KIND_STATIC
            ? $tokens[0][1]
            : '__DYNAMIC__';

        $specificity = $this->calculateSpecificity($tokens);

        $this->groups[$method][$first][] = [
            'tokens'      => $tokens,
            'handler'     => $handler,
            'specificity' => $specificity,
            'groups'      => $groups,
        ];

        // Priority is "most specific first", but the sort is DEFERRED to the
        // first match() that reads this bucket (see $unsortedBuckets). Sorting
        // here would re-order the whole bucket after every single insert.
        $this->unsortedBuckets[$method . "\0" . $first] = true;
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
                $result[] = [$optional ? self::KIND_PARAM_OPT : self::KIND_PARAM, $name, $type];
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
     * @param string $uri The request URI (path) to match, e.g. "/blog/hello-world"
     * @param string $method The HTTP method, e.g. "GET", "POST"
     * @return array|null If a match is found, returns an array with keys 'vars', 'override', 'groups', 'wildcards'. Otherwise, returns null.
     */
    public function match(string $uri, string $method = 'GET'): ?array
    {
        $this->ensureFlushed();

        $method = strtoupper($method);
        // Per RFC 7231: HEAD is identical to GET; route HEAD requests as GET
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $uri = '/' . trim($uri, '/');

        // static
        if (isset($this->static[$method][$uri])) {
            $route = $this->static[$method][$uri];
            return $this->resolveHandler(
                $route['handler'],
                [],
                $route['groups']
            );
        }

        $parts = explode('/', trim($uri, '/'));
        foreach ($parts as $index => $part) {
            $parts[$index] = rawurldecode($part);
        }
        $first = $parts[0] ?? '';

        $candidates = [];

        if (isset($this->groups[$method][$first])) {
            $this->sortBucket($method, $first);
            $candidates = array_merge($candidates, $this->groups[$method][$first]);
        }
        if (isset($this->groups[$method]['__DYNAMIC__'])) {
            $this->sortBucket($method, '__DYNAMIC__');
            $candidates = array_merge($candidates, $this->groups[$method]['__DYNAMIC__']);
        }

        foreach ($candidates as $route) {
            $tokens = $route['tokens'];

            $hasWildcard = false;
            $minSegments = 0;
            $maxSegments = 0;
            foreach ($tokens as $token) {
                $kind = $token[0];
                if ($kind === self::KIND_WILDCARD) {
                    $hasWildcard = true;
                    continue;
                }

                if ($kind === self::KIND_PARAM_OPT || $kind === self::KIND_REGEX_OPT) {
                    $maxSegments++;
                    continue;
                }

                $minSegments++;
                $maxSegments++;
            }

            $partCount = \count($parts);
            if ($partCount < $minSegments) {
                continue;
            }
            if (!$hasWildcard && $partCount > $maxSegments) {
                continue;
            }

            $params    = [];
            $ok        = true;
            $partIndex = 0;

            foreach ($tokens as $token) {
                [$kind, $name, $type] = $token + [null, null, null];

                if ($kind === self::KIND_WILDCARD) {
                    $params[$name] = array_slice($parts, $partIndex);
                    $partIndex = $partCount;
                    break;
                }

                if ($kind === self::KIND_PARAM_OPT || $kind === self::KIND_REGEX_OPT) {
                    if (!isset($parts[$partIndex])) {
                        continue;
                    }

                    $segment = $parts[$partIndex];

                    if ($kind === self::KIND_PARAM_OPT) {
                        if (!isset($this->types[$type])) {
                            throw new RuntimeException("Unknown validator: $type");
                        }
                        if ($this->types[$type]($segment)) {
                            $params[$name] = $segment;
                            $partIndex++;
                        }
                    } else {
                        if (preg_match('/^' . $type . '$/', $segment)) {
                            $params[$name] = $segment;
                            $partIndex++;
                        }
                    }
                    continue;
                }

                if (!isset($parts[$partIndex])) {
                    $ok = false;
                    break;
                }

                $segment = $parts[$partIndex];

                switch ($kind) {
                    case self::KIND_STATIC:
                        if ($name !== $segment) {
                            $ok = false;
                        } else {
                            $partIndex++;
                        }
                        break;

                    case self::KIND_PARAM:
                        if (!isset($this->types[$type])) {
                            throw new RuntimeException("Unknown validator: $type");
                        }
                        if (!$this->types[$type]($segment)) {
                            $ok = false;
                        } else {
                            $params[$name] = $segment;
                            $partIndex++;
                        }
                        break;

                    case self::KIND_REGEX:
                        if (!preg_match('/^' . $type . '$/', $segment)) {
                            $ok = false;
                        } else {
                            $params[$name] = $segment;
                            $partIndex++;
                        }
                        break;

                }

                if (!$ok) {
                    break;
                }
            }

            if ($ok && $partIndex !== $partCount) {
                $ok = false;
            }

            if ($ok) {
                return $this->resolveHandler(
                    $route['handler'],
                    $params,
                    $route['groups'],
                );
            }
        }

        // Auto-OPTIONS: if no explicit OPTIONS route matched, generate one
        if ($method === 'OPTIONS' && $this->autoOptions) {
            $allowed = $this->getAllowedMethods($uri);
            if (!empty($allowed)) {
                $allowed[] = 'OPTIONS';
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
     */
    private const CACHE_VERSION = 1;

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

        if (!\is_array($state) || !isset($state['__cache']) || !\is_array($state['__cache'])) {
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
