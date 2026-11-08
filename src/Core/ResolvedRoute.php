<?php

namespace Azera\Core;

/**
 * ResolvedRoute represents the fully resolved route and execution context
 * used by the dispatcher to invoke the matched controller and action.
 *
 * It is an immutable VALUE shared across every request that resolves to the same
 * route, so it carries the route's identity only. The action's actual arguments
 * are not part of it: they are the list handed to the invocation, they contain
 * whatever the container injected, and a container can return a request-scoped
 * service that is reset between requests. Caching those would either fail to
 * `serialize()` (services hold closures) or silently carry one request's instance
 * into the next. The dispatcher therefore rebuilds the argument list per request
 * and exposes it through `$params` for inspection only — `vars` is the field to
 * read route parameters from.
 */
class ResolvedRoute
{
    /**
     * Create a new ResolvedRoute instance with the given parameters.
     *
     * @param string|null $namespace Effective namespace for the controller, after applying route group namespaces. Null if no namespace is used.
     * @param string      $controller Resolved controller class name.
     * @param string      $action Resolved action method name.
     * @param array       $params Resolved action method parameters. Empty for a cached route; see the class docblock.
     * @param array       $vars Associative array of route variables extracted from the URL (e.g. ['id' => '123']).
     * @param array       $groups List of middleware groups to apply for this route.
     * @param array       $override Associative array of route overrides (e.g. ['controller' => 'OtherController', 'action' => 'otherAction']).
     */
    public function __construct(
        public readonly ?string $namespace,
        public readonly string $controller,
        public readonly string $action,
        public readonly array $params,
        public readonly array $vars,
        public readonly array $groups,
        public readonly array $override
    ) {}
}