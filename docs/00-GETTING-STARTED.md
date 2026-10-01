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
├── console.php
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
use Azera\Core\Dispatcher;

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

// Configure dispatcher with namespace and defaults (no constructor args – uses AppContext internally)
$dispatcher = new Dispatcher();
$dispatcher->setBaseNamespace('\\App\\Controllers');
$dispatcher->setDefaultController('IndexController');
$dispatcher->setDefaultAction('indexAction');
$dispatcher->addMiddleware(new SessionMiddleware()); // starts PHP session; makes $ctx->session() available

// Get the current request URI and method
$path   = $ctx->request()->getPath();
$method = $ctx->request()->getMethod();

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

## CLI Bootstrap

Create [console.php](../console.php):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Azera\Cli\Console;

$console = new Console();
$console->process($argv[1] ?? null, $argv[2] ?? null, array_slice($argv, 3));
```

`Console` auto-discovers every class whose name ends in `Task` under the registered namespace and registers it under a lowercase task name (`HelloTask` → `hello`). The built-in `Azera\Cli\Tasks` namespace (containing `ModelSyncTask`) and `App\Tasks` are included automatically.

Run:

```bash
php console.php hello world Azera    # positional args
php console.php hello world --shout   # boolean flag
php console.php help                  # overview of all tasks
php console.php help hello            # detailed help for one task
```

## About composer.json

The simplest way to handle dependencies is through a `composer.json` file. This file manages dependencies, autoloading, and project metadata. Composer will automatically generate this file when you run `composer require sailantis/azera-framework`, but you can customize it as needed.

A minimal `composer.json` for your app might look like:

```json
{
  "require": {
    "sailantis/azera-framework": "^0.1"
  },
  "autoload": {
    "psr-4": {
      "App\\": "app/"
    }
  }
}
```

- The `require` section lists your dependencies, in our case we use Azera.
- The `autoload` section tells Composer to autoload your app classes from the `app/` directory using PSR-4.
- You can add scripts, dev dependencies, and other metadata as your project grows.

After editing `composer.json`, run:

```bash
composer dump-autoload
```

to update the autoloader.

## Web Server Configuration

When deploying your application, the web server should forward requests to `public/index.php`. Here is an example how Nginx configuration could look:

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

The directive `try_files $uri $uri/ /index.php?$query_string;` ensures that all non-file requests are forwarded to your `public/index.php` bootstrap file.

## Next Steps

- [Architecture](01-ARCHITECTURE.md)
- [MVC Routing](02-CORE-ROUTING.md)
- [Controllers & Views](03-CONTROLLERS-VIEWS.md)
- [Models & ORM](04-MODELS-ORM.md)
- [Database Queries](05-DATABASE-QUERIES.md)
- [Validation](07-VALIDATION.md)
- [CLI Tasks](08-CLI-TASKS.md)
- [Security (Crypt)](09-SECURITY.md)
