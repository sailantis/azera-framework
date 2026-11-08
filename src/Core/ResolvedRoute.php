<?php

namespace Azera\Core;

/**
 * Resolved route metadata shared across matching requests.
 *
 * `$params` is not cached because it may contain closures or request-scoped
 * services; the dispatcher rebuilds it for each request. Read URL parameters
 * from `$vars`; `$params` is empty on cached routes.
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