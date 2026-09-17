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
     * @param string|array|null $handler Optional handler definition to override controller/action. Can be a string like 'Admin::dashboard' or an array with keys 'namespace', 'controller', 'action'.
     * @return static For method chaining
     */
    public function add(
        string|array|null $method,
        string $pattern,
        string|array|null $handler = null
    ): static {

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

    protected function resolveHandler(
        string|array|null $handler,
        array $params,
        array $groups,
    ): array {

        if ($handler === null) {
            $override = [];
        } elseif (\is_array($handler)) {
            $override = $handler;
            if (!empty($override['controller'])) {
                $namespacePart = strstr($override['controller'], '\\', true);
                if ($namespacePart !== false) {
                    if (!empty($override['namespace'])) {
                        $override['namespace'] .= '\\' . $namespacePart;
                    } else {
                        $override['namespace'] = $namespacePart;
                    }
                    $override['controller'] = substr($override['controller'], strlen($namespacePart) + 1);
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
                        $override['namespace']  = substr($handler, 0, $namespaceSeparator);
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

        return [
            'vars'     => $params,
            'override' => $override,
            'groups'   => $groups,
        ];
    }

}