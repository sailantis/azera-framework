# Class: RoutesTask

**Full name:** [Azera\Cli\Tasks\RoutesTask](../../src/Cli/Tasks/RoutesTask.php)

List all registered routes.

Usage:
  routes:list

Displays a table of all routes registered via the Router, showing
HTTP method, path pattern, handler (controller::action), route name
(if assigned), and middleware groups.

Routes are registered during application bootstrap. If your Bootstrap
class implements a `registerRoutes(Router $router)` method, it will
be called automatically by the `azera` binary before any task runs.

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php)</small>

## Public methods

### listAction() · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php#L27)</small>

`public function listAction(): void`

List all registered routes.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
