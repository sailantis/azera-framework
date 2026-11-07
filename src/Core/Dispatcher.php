<?php

namespace Azera\Core;

use Azera\AppContext;
use Azera\Core\ResolvedRoute;
use Azera\Http\Response;
use InvalidArgumentException;
use Azera\Core\Exceptions\ActionNotFoundException;
use Azera\Core\Exceptions\InvalidControllerException;
use Azera\Core\Exceptions\ControllerNotFoundException;

/**
 * Dispatcher is responsible for handling the execution of controller actions based on the current routing information. It builds a middleware pipeline, and invokes the action, returning a Response object. It supports global and group-based middleware, as well as controller and action-specific middleware.
 */
class Dispatcher
{
    protected AppContext $context;

    /**
     * Create a new Dispatcher and bind it to the current {@see AppContext} singleton.
     */
    public function __construct()
    {
        $this->context       = AppContext::instance();
        $this->baseNamespace = '\\App\\Controllers';
    }

    protected array $globalMiddleware = [];

    /**
     * Register a middleware that runs on every dispatched request.
     *
     * Global middleware is prepended to the pipeline before any group,
     * controller, or action middleware.
     *
     * @param MiddlewareInterface $mw Middleware instance to add.
     */
    public function addMiddleware(MiddlewareInterface $mw): void
    {
        $this->globalMiddleware[] = $mw;
    }

    protected array $middlewareGroups = [];

    /**
     * Define a named middleware group that can be referenced from route definitions.
     *
     * Groups are applied after global middleware and before controller/action
     * middleware. If several middleware groups are active for a route, they are
     * applied in the order they are listed on the route.
     *
     * @param string $name       Unique group name (e.g. "auth", "admin").
     * @param array  $middleware Array of middleware definitions accepted by the pipeline normalizer.
     */
    public function defineMiddlewareGroup(string $name, array $middleware): void
    {
        $this->middlewareGroups[$name] = $middleware;
    }

    protected string $baseNamespace;
    protected string $defaultController = "IndexController";
    protected string $defaultAction = "indexAction";

    /**
     * `ReflectionMethod` per "class::action", resolved once.
     *
     * {@see resolveActionArguments()} used to construct one per request. That
     * constructor costs 97 ns, more than the whole argument loop it feeds when
     * the action takes no parameters (which is the common case — a controller
     * action receives route parameters or nothing).
     *
     * @var array<string, \ReflectionMethod>
     */
    private array $actionReflection = [];

    /**
     * The `ResolvedRoute` for each distinct route identity, reused across
     * requests.
     *
     * The value is immutable by construction (`ResolvedRoute` is all readonly),
     * so one instance can be handed to `AppContext::setRoute()` for every
     * request that matches the same route. Constructing it cost 194 ns and
     * setting it another 100 ns of the 1.69 µs dispatch, and nothing ever
     * mutated it.
     *
     * Static routes are keyed on the three names alone and are therefore
     * bounded by the route table. Dynamic routes are keyed on their variables
     * as well, and those are attacker-controlled — `/items/{id}` for a million
     * ids would otherwise pin a million objects in a persistent worker. Above
     * {@see DYNAMIC_ROUTE_CACHE_MAX} the oldest entries are dropped, which is
     * a cache miss and not a correctness problem: the object is a pure
     * function of the key.
     *
     * @var array<string, ResolvedRoute>
     */
    private array $resolvedRoutes = [];

    /** Entries allowed in the dynamic-route half of {@see $resolvedRoutes}. */
    private const DYNAMIC_ROUTE_CACHE_MAX = 256;

    /**
     * Get the base namespace for controllers.
     *
     * @return string The base namespace for controllers.
     */
    public function getBaseNamespace(): string
    {
        return $this->baseNamespace;
    }

    /**
     * Set the base namespace for controllers. This namespace will be prefixed to all controller class names when dispatching.
     *
     * @param string $baseNamespace The base namespace for controllers (e.g. "App\\Controllers")
     * @return $this
     */
    public function setBaseNamespace(string $baseNamespace): static
    {
        $this->baseNamespace = rtrim($baseNamespace, '\\');
        return $this;
    }

    /**
     * Get the default controller name used when a route doesn't provide one.
     *
     * @return string Default controller class name (without namespace)
     */
    public function getDefaultController(): string
    {
        return $this->defaultController;
    }

    /**
     * Set the default controller name.
     *
     * @param string $defaultController Controller class name to use as default
     * @throws InvalidArgumentException If given name is empty
     */
    public function setDefaultController(string $defaultController): static
    {
        if (empty($defaultController)) {
            throw new InvalidArgumentException("Default controller cannot be empty");
        }
        $this->defaultController = $defaultController;
        return $this;
    }

    /**
     * Get the default action name used when a route doesn't provide one.
     *
     * @return string Default action method name
     */
    public function getDefaultAction(): string
    {
        return $this->defaultAction;
    }

    /**
     * Set the default action name.
     *
     * @param string $defaultAction Action method name to use as default
     * @throws InvalidArgumentException If given name is empty
     */
    public function setDefaultAction(string $defaultAction): static
    {
        if (empty($defaultAction)) {
            throw new InvalidArgumentException("Default action cannot be empty");
        }
        $this->defaultAction = $defaultAction;
        return $this;
    }

    /**
     * Dispatch a request to the appropriate controller and action based on the provided routing information. This method will determine the controller class and action method to invoke, build the middleware pipeline, and execute the controller action, returning the resulting Response.
     * @param array $routeInfo An associative array containing routing information, including 'namespace', 'controller', 'action', and any route parameters.
     * @throws ControllerNotFoundException
     * @throws InvalidControllerException
     * @throws ActionNotFoundException
     * @return Response
     */
    public function dispatch(array $routeInfo): Response
    {
        // Auto-OPTIONS: short-circuit before any controller resolution
        if (!empty($routeInfo['__auto_options'])) {
            $methods = implode(', ', $routeInfo['__allowed_methods']);
            return Response::status(204)
                ->setHeader('Allow', $methods)
                ->setHeader('Access-Control-Allow-Methods', $methods)
                ->setHeader('Access-Control-Allow-Headers', '*');
        }

        $groups   = $routeInfo['groups'] ?? [];
        $vars     = $routeInfo['vars'] ?? [];
        $override = $routeInfo['override'] ?? [];

        if (!empty($override['namespace'])) {
            $namespace = rtrim((string) $override['namespace'], '\\');
            if (empty($namespace)) {
                $namespace = $this->baseNamespace;
            } elseif (!empty($this->baseNamespace)) {
                if (!str_contains($namespace, '\\')) {
                    $namespace = $this->baseNamespace . '\\' . $namespace;
                }
            }
        } else {
            $namespace = $this->baseNamespace;
            if (!empty($vars['namespace'])) {
                if ($namespace !== '') {
                    $namespace .= '\\';
                }
                $namespace .= $this->camelize((string) $vars['namespace']);
            }
        }

        if (!empty($override['controller'])) {
            $controllerName = (string) $override['controller'];
        } elseif (!empty($vars['controller'])) {
            $controllerName = $this->camelize((string) $vars['controller']) . 'Controller';
        } else {
            $controllerName = $this->defaultController;
        }

        if (!empty($override['action'])) {
            $actionName = (string) $override['action'];
        } elseif (!empty($vars['action'])) {
            $actionName = $this->camelize((string) $vars['action'], false) . 'Action';
        } else {
            $actionName = $this->defaultAction;
        }

        $controllerClass = $namespace !== ''
            ? $namespace . '\\' . $controllerName
            : $controllerName;

        /**
         * @var Controller $controller
         */
        $controller = $this->context->get($controllerClass);

        if (!$controller instanceof Controller) {
            throw new InvalidControllerException("{$controllerClass} is not a Controller");
        }

        $params = $this->resolveActionArguments(
            $controller,
            $actionName,
            $vars,
            $this->context
        );

        $this->context->setRoute(
            $this->resolvedRoute(
                $namespace,
                $controllerClass,
                $actionName,
                $params,
                $vars,
                $groups,
                $override
            )
        );

        $pipeline = $this->buildPipeline(
            $controller,
            $actionName,
            $params,
            $groups,
            $this->context
        );

        $response = $pipeline();

        return $response ?? Response::status(204);
    }

    protected function resolveActionArguments(
        object $controller,
        string $action,
        array $routeParams,
        AppContext $context
    ): array {

        $reflectionKey = $controller::class . '::' . $action;
        $ref = $this->actionReflection[$reflectionKey] ??= $this->reflectAction($controller, $action);
        $args = [];

        foreach ($ref->getParameters() as $index => $param) {

            $name    = $param->getName();
            $typeObj = $param->getType();

            // Extract type names (for union types)
            $types = [];

            if ($typeObj instanceof \ReflectionNamedType) {
                $types[] = $typeObj->getName();
            } elseif ($typeObj instanceof \ReflectionUnionType) {
                foreach ($typeObj->getTypes() as $t) {
                    if ($t instanceof \ReflectionNamedType) {
                        $types[] = $t->getName();
                    }
                }
            } elseif ($typeObj !== null) {
                throw new \RuntimeException(
                    "Unsupported parameter type for \${$name} for " . \get_class($controller) . "->{$action}()"
                );
            }

            // Route parameters (by name)
            if (isset($routeParams[$name])) {
                $value = $routeParams[$name];

                // Variadic wildcard support
                if ($param->isVariadic()) {
                    $array = (array) $value;
                    foreach ($array as $value) {
                        $args[] = $value;
                    }
                    continue;
                }

                // Type conversion for simple built-in types
                if (!empty($types)) {
                    $value = $this->castValueToType($value, $types);
                }

                $args[] = $value;
                continue;
            }

            // DI auto-resolve by type hint
            if (!empty($types)) {
                foreach ($types as $t) {

                    // If type is registered in AppContext, use it
                    if ($context->has($t)) {
                        $service = $context->getOrNull($t);
                        if ($service !== null) {
                            $args[] = $service;
                            continue 2; // next parameter
                        }

                        continue;
                    }

                    // If type is a class → Auto-Wiring
                    if (class_exists($t)) {
                        $args[] = $context->get($t);
                        continue 2;
                    }

                }
            }

            // Variadic parameter → empty array
            if ($param->isVariadic()) {
                continue;
            }

            // Default value from signature
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Nullable parameter → null
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            // Not resolved → Exception
            throw new \RuntimeException("Cannot resolve parameter \${$name} for action {$action}()");
        }

        return $args;
    }

    /**
     * Reflect one controller action, translating a missing action into the
     * dispatcher's own exception.
     */
    private function reflectAction(object $controller, string $action): \ReflectionMethod
    {
        try {
            return new \ReflectionMethod($controller, $action);
        } catch (\ReflectionException $e) {
            throw new ActionNotFoundException("Action " . \get_class($controller) . "->{$action} not found", previous: $e);
        }
    }

    /**
     * The `ResolvedRoute` for a matched route, shared by every request that
     * matches it.
     *
     * A route's identity is everything the object carries except the request
     * it served: namespace, controller class, action, argument values, route
     * variables and groups. Route variables are the only part that can differ
     * between two requests that matched the same pattern (`/items/{id}` for
     * two ids), so they lead the key and the rest follows as a constant.
     *
     * A static route has no route variables, and therefore no way for the
     * remaining fields to differ between two requests — the arguments are a
     * pure function of the action's signature — so it is keyed on the three
     * names alone. That is the hot path, and it must not pay for a serialise:
     * doing so costs more than the 194 ns object construction this replaces.
     * A dynamic route pays `serialize()`, which is still cheaper than
     * rebuilding the object whenever the same variables recur.
     */
    private function resolvedRoute(
        string $namespace,
        string $controllerClass,
        string $actionName,
        array $params,
        array $vars,
        array $groups,
        array $override
    ): ResolvedRoute {
        if ($vars === [] && $groups === []) {
            $key = $namespace . "\0" . $controllerClass . "\0" . $actionName;

            return $this->resolvedRoutes[$key] ??= new ResolvedRoute(
                $namespace,
                $controllerClass,
                $actionName,
                $params,
                $vars,
                $groups,
                $override
            );
        }

        $key = "\0v\0" . serialize([$vars, $namespace, $controllerClass, $actionName, $params, $groups, $override]);

        if (!isset($this->resolvedRoutes[$key]) && count($this->resolvedRoutes) >= self::DYNAMIC_ROUTE_CACHE_MAX) {
            // Drop half, so a steady stream of distinct ids amortises the
            // eviction instead of paying for it on every request.
            $this->resolvedRoutes = array_slice($this->resolvedRoutes, intdiv(count($this->resolvedRoutes), 2), null, true);
        }

        return $this->resolvedRoutes[$key] ??= new ResolvedRoute(
            $namespace,
            $controllerClass,
            $actionName,
            $params,
            $vars,
            $groups,
            $override
        );
    }

    protected function castValueToType(mixed $value, array $types): mixed
    {
        foreach ($types as $t) {

            switch ($t) {
                case 'int':
                    if (\ctype_digit((string) $value)) {
                        return (int) $value;
                    }
                    break;

                case 'float':
                    if (\is_numeric((string) $value)) {
                        return (float) $value;
                    }
                    break;

                case 'bool':
                    // true/false/1/0/yes/no/on/off
                    $filtered = \filter_var((string) $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                    if ($filtered !== null) {
                        return $filtered;
                    }
                    break;

                case 'string':
                    return (string) $value;

                case 'array':
                    if (\is_array($value)) {
                        return $value;
                    }
                    break;

                case 'mixed':
                    return $value;
            }
        }

        return $value;
    }

    protected function camelize(string $string, bool $beginUpper = true): string
    {
        $string = str_replace(['-', '_', '.'], ' ', $string);

        $result = '';
        foreach (explode(' ', $string) as $part) {
            if ($part === '') {
                continue;
            }

            if (!$beginUpper && $result === '') {
                $result .= strtolower($part);
                continue;
            }

            $result .= strtoupper($part[0]) . strtolower(substr($part, 1));
        }

        return $result;
    }

    /**
     * Build the middleware pipeline for the given controller action. The pipeline will include global middleware, controller-specific middleware, and action-specific middleware, in that order. Each middleware will be normalized to an instance of MiddlewareInterface, allowing for flexible definitions (class name, instance, closure, or array with arguments). The final callable returned by this method will execute the entire middleware stack and ultimately invoke the controller action.
     *
     * @param Controller $controller The controller instance to invoke
     * @param string $action The action method name to invoke on the controller
     * @param array $params Parameters to pass to the controller action
     * @param ?array $groups Middleware groups to apply to the pipeline
     * @param AppContext $context The application context to pass to middleware and controller
     * @return callable A callable that executes the middleware pipeline and invokes the controller action when called
     */
    protected function buildPipeline(Controller $controller, string $action, array $params, ?array $groups, AppContext $context): callable
    {
        /** @var MiddlewareInterface[] $middleware */
        $middleware = [];

        // 1) Global Middleware
        if (!empty($this->globalMiddleware)) {
            foreach ($this->globalMiddleware as $mw) {
                $middleware[] = $this->normalizeMiddleware($mw);
            }
        }

        // 2) Middleware from Route Groups
        if (!empty($groups)) {
            foreach ($groups as $groupName) {
                if (!empty($this->middlewareGroups[$groupName])) {
                    foreach ($this->middlewareGroups[$groupName] as $mw) {
                        $middleware[] = $this->normalizeMiddleware($mw);
                    }
                }
            }
        }

        // 2) Controller-based Middleware
        $controllerMiddleware = $controller->getMiddlewares();
        if (!empty($controllerMiddleware)) {
            foreach ($controllerMiddleware as $mw) {
                $middleware[] = $this->normalizeMiddleware($mw);
            }
        }

        // 3) Action-based Middleware
        $actionMiddleware = $controller->getActionMiddlewares($action);
        if (!empty($actionMiddleware)) {
            foreach ($actionMiddleware as $mw) {
                $middleware[] = $this->normalizeMiddleware($mw);
            }
        }

        // Core-Handler
        $core = fn() =>
            $this->invokeController(
                $controller,
                $action,
                $params
            );

        // No middleware is the common case, and building a chain of zero links
        // only to call it once is pure overhead: the pipeline is rebuilt on every
        // request, so a route without middleware was paying for the closures
        // that exist to wrap it. Return the core handler directly.
        if ($middleware === []) {
            return $core;
        }

        // Run the pipeline efficiently
        $next  = $core;
        $count = count($middleware);

        for ($i = $count - 1; $i >= 0; $i--) {
            /** @var MiddlewareInterface $mw */
            $mw   = $middleware[$i];
            $next = fn() => $mw->process($context, $next);
        }

        return $next;
    }

    private function normalizeMiddleware($mw): MiddlewareInterface
    {
        if ($mw instanceof MiddlewareInterface) {
            return $mw;
        }

        /** @var MiddlewareInterface $instance */

        if ($mw instanceof \Closure) {
            $instance = $mw();
        } else {
            if (\is_array($mw)) {
                $className = $mw[0] ?? null;
                $args      = $mw[1] ?? [];
            } else {
                $className = $mw;
                $args      = [];
            }
            if (!is_string($className)) {
                throw new InvalidArgumentException("Middleware class name must be a string");
            }
            if (!class_exists($className)) {
                throw new InvalidArgumentException("Middleware class {$className} does not exist");
            }
            $instance = new $className(...$args);
        }

        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException("Factory did not return MiddlewareInterface");
        }

        return $instance;
    }

    protected function invokeController(Controller $controller, string $action, array $params): Response
    {
        $result = $controller->$action(...$params);

        if ($result instanceof Response) {
            return $result;
        }

        if (\is_array($result)) {
            return Response::json($result);
        } elseif ($result instanceof \JsonSerializable) {
            return Response::json($result->jsonSerialize());
        } elseif (\is_string($result)) {
            return Response::html($result);
        } elseif (\is_int($result)) {
            return Response::status($result);
        } elseif ($result === null) {
            return Response::status(204);
        } else {
            throw new \UnexpectedValueException("Unsupported controller action return type: " . \get_debug_type($result));
        }
    }

}