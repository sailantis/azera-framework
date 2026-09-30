# Class: ResolvedRoute

**Full name:** [Azera\Core\ResolvedRoute](../../src/Core/ResolvedRoute.php)

ResolvedRoute represents the fully resolved route and execution context
used by the dispatcher to invoke the matched controller and action.

## Public Properties

- `public readonly` string|null `$namespace` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>
- `public readonly` string `$controller` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>
- `public readonly` string `$action` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>
- `public readonly` array `$params` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>
- `public readonly` array `$vars` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>
- `public readonly` array `$groups` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>
- `public readonly` array `$override` · <small>[🗎](../../src/Core/ResolvedRoute.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Core/ResolvedRoute.php#L22)</small>

`public function __construct(string|null $namespace, string $controller, string $action, array $params, array $vars, array $groups, array $override): mixed`

Create a new ResolvedRoute instance with the given parameters.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string\|null | - | Effective namespace for the controller, after applying route group namespaces. Null if no namespace is used. |
| `$controller` | string | - | Resolved controller class name. |
| `$action` | string | - | Resolved action method name. |
| `$params` | array | - | Resolved action method parameters. |
| `$vars` | array | - | Associative array of route variables extracted from the URL (e.g. ['id' => '123']). |
| `$groups` | array | - | List of middleware groups to apply for this route. |
| `$override` | array | - | Associative array of route overrides (e.g. ['controller' => 'OtherController', 'action' => 'otherAction']). |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
