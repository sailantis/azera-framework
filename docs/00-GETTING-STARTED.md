# Getting Started

**First steps** — set up a minimal Azera project with MVC routing, models, and CLI tasks.

## Requirements

- PHP >= 8.2
- Composer
- `ext-pdo`
- `ext-mbstring`

## Installation

```bash
composer require sailantis/azera-framework
```

## Recommended Structure

```text
your-project/
├── app/
│   ├── Controllers/
│   ├── Models/
│   ├── Tasks/
├── public/
│   ├── index.php
├── views/
└── composer.json
```

## Minimal Web Bootstrap

Create [public/index.php](../public/index.php):

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Azera\AppContext;
use Azera\Db\Database;
use Azera\Http\Response;
use Azera\Http\SessionMiddleware;
// Initialize application context
$ctx = AppContext::instance();

// Register database connection(s)
$ctx->dbManager()->set('default', new Database('mysql:host=localhost;dbname=myapp', 'user', 'pass'));

// Configure view engine
$ctx->view()->setViewPath(__DIR__ . '/../views');
$ctx->view()->setLayout('layouts/main');   // default wrapping layout for all views

// Set up routing
$router = $ctx->router();
$router->add('GET', '/', 'IndexController::indexAction');
$router->add('GET', '/users/{id:int}', 'UserController::viewAction')->setName('user.view');

// Configure the context-managed dispatcher
$dispatcher = $ctx->dispatcher();
$dispatcher->setBaseNamespace('\\App\\Controllers');
$dispatcher->setDefaultController('IndexController');
$dispatcher->setDefaultAction('indexAction');
$dispatcher->addMiddleware(new SessionMiddleware()); // starts PHP session; makes $ctx->session() available

// Get the current request URI and method
$path   = $ctx->request()->path();
$method = $ctx->request()->method();

// Match the route and dispatch
$route = $router->match($path, $method);
if ($route === null) {
    // No route matched, return 404 response
    $response = Response::status(404);
} else {
    // Dispatcher will invoke the controller action and store route info in AppContext
    $response = $dispatcher->dispatch($route);
}
// Send the response to the client
$response->send();
```

## Minimal Controller

Create [app/Controllers/IndexController.php](../app/Controllers/IndexController.php):

```php
<?php
namespace App\Controllers;

use Azera\Core\Controller;

class IndexController extends Controller
{
    public function indexAction(): string
    {
        return 'Azera is running.';
    }
}
```

To render a view, return an array of variables — the framework passes them to the matching template automatically:

```php
public function indexAction(): array
{
    return ['title' => 'Welcome', 'version' => '1.0'];
}
```

You can also render explicitly using the view engine:

```php
public function indexAction(): string
{
    return $this->view()->render('home/index', ['title' => 'Welcome']);
}
```

## Minimal View

Templates live under the view path configured in the bootstrap. By default, Azera resolves them as `{controller}/{action}` relative to that path.

Create [views/home/index.php](../views/home/index.php):

```php
<h1><?= htmlspecialchars($title) ?></h1>
<p>Application is running.</p>
```

If you configured a layout (`$ctx->view()->setLayout('layouts/main')`), create the layout file and output the rendered view body via `$content`:

Create [views/layouts/main.php](../views/layouts/main.php):

```php
<!DOCTYPE html>
<html>
<head><title><?= htmlspecialchars($title ?? 'App') ?></title></head>
<body>
<?= $content ?>
</body>
</html>
```

To render without the layout (e.g. for partials or JSON responses), use `renderPartial()`:

```php
return $this->view()->renderPartial('partials/flash', ['message' => 'Saved!']);
```

## Minimal Model

Models are Active Record classes: declare public properties that match your table columns.

Create [app/Models/User.php](../app/Models/User.php):

```php
<?php
namespace App\Models;

use Azera\Orm\Model;

class User extends Model
{
    public int $id;
    public string $username;
    public string $email;
}
```

Usage:

```php
$user = User::find(1);
$admins = User::findAll(['role' => 'admin']);

$newUser = User::create([
    'username' => 'alice',
    'email' => 'alice@example.com',
]);

$exists = User::exists(['email' => 'alice@example.com']);
```

## Middleware

Middleware runs before (and after) every controller action. Register global middleware on the dispatcher:

```php
$dispatcher->addMiddleware(new SessionMiddleware());  // built-in: starts PHP session
$dispatcher->addMiddleware(new MyAuthMiddleware());   // custom
```

You can also restrict middleware to specific controllers or actions using the `$middlewares` and `$actionMiddlewares` properties on a controller class. See [Controllers & Views](03-CONTROLLERS-VIEWS.md) for details.

## CLI Tasks

The framework provides an `azera` command through Composer. From your project root, run:

```bash
composer exec azera -- help
composer exec azera -- help model
```

Custom task classes in the `App\Tasks` namespace are auto-discovered when that
namespace is mapped in `composer.json`. Invoke a task with
`composer exec azera -- <task> <action> [arguments...]`. See the
[CLI Tasks guide](08-CLI-TASKS.md) for details. If you prefer, you can also
create your own `console.php` in the project root as a custom entry point.

## About composer.json

Composer manages dependencies and autoloading in `composer.json`. Running
`composer require sailantis/azera-framework` creates or updates the file.
Add a PSR-4 mapping to autoload your application classes:

A minimal `composer.json` for your app might look like:

```json
{
  "require": {
    "sailantis/azera-framework": "^0.4"
  },
  "autoload": {
    "psr-4": {
      "App\\": "app/"
    }
  }
}
```

After changing the autoload mapping, run:

```bash
composer dump-autoload
```

to update the autoloader.

## Web Server Configuration

Forward requests that do not match files to `public/index.php`. For example:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/your-app/public;

    # Forward all requests that don't match actual files to index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP handler
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock; # Adjust if using TCP
        # fastcgi_pass 127.0.0.1:9000; # Alternative if using TCP
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Hide dot files
    location ~ /\. {
        deny all;
    }
}
```

## Next Steps

- [Architecture](01-ARCHITECTURE.md)
- [MVC Routing](02-CORE-ROUTING.md)
- [Controllers & Views](03-CONTROLLERS-VIEWS.md)
- [Models & ORM](04-MODELS-ORM.md)
- [Database Queries](05-DATABASE-QUERIES.md)
- [Validation](07-VALIDATION.md)
- [CLI Tasks](08-CLI-TASKS.md)
- [Security (Crypt)](09-SECURITY.md)
- [All guides](README.md)
