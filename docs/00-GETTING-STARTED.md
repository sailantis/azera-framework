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

For a complete starter project with a `BootstrapProvider`, routing, views, and a
console, create one with Composer:

```bash
composer create-project sailantis/azera-app my-app
cd my-app
composer serve
```

The [Azera App skeleton](https://github.com/sailantis/azera-app) is also
cloneable directly if you prefer to manage the repository yourself.

## Recommended Structure

```text
your-project/
├── app/
│   ├── Bootstrap.php
│   ├── Controllers/
│   ├── Models/
│   ├── Tasks/
├── public/
│   ├── index.php
├── views/
└── composer.json
```

## Bootstrap Provider

Implement `Azera\Boot\BootstrapProvider` in an autoloadable application class.
Its `boot()` method configures the application context, including routes,
middleware, and services. The Azera CLI discovers the provider from the
project's Composer autoload mappings.

Create [app/Bootstrap.php](../app/Bootstrap.php):

```php
<?php

namespace App;

use Azera\AppContext;
use Azera\Boot\BootstrapProvider;
use Azera\Db\Database;
use Azera\Http\SessionMiddleware;

class Bootstrap implements BootstrapProvider
{
    public function boot(): void
    {
        $ctx = AppContext::instance();

        $ctx->view()->setViewPath(\dirname(__DIR__) . '/views');
        $ctx->dbManager()->set(
            'default',
            fn() => new Database('mysql:host=localhost;dbname=myapp', 'user', 'pass'),
        );

        $dispatcher = $ctx->dispatcher();
        $dispatcher->setBaseNamespace('\\App\\Controllers');
        $dispatcher->setDefaultController('IndexController');
        $dispatcher->setDefaultAction('indexAction');
        $dispatcher->addMiddleware(new SessionMiddleware());

        $router = $ctx->router();
        $router->add('GET', '/', 'IndexController::indexAction');
        $router->add('GET', '/users/{id:int}', 'UserController::viewAction')
            ->setName('user.view');
    }
}
```

Call the provider from [public/index.php](../public/index.php), then match and
dispatch the request:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

(new \App\Bootstrap())->boot();

$ctx = \Azera\AppContext::instance();
$request = $ctx->request();
$route = $ctx->router()->match($request->path(), $request->method());

$response = $route === null
    ? \Azera\Http\Response::text("404 Not Found\n", 404)
    : $ctx->dispatcher()->dispatch($route);

$response->send();
```

The CLI can discover the provider when its class is included in Composer's
autoload mappings. See [About composer.json](#about-composerjson) below.

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

Returning an array from a controller action produces a JSON response; it does
not select a view. To render a template, pass its logical name and variables
to the view engine:

```php
public function indexAction(): string
{
    return $this->view()->render('index/index', ['title' => 'Welcome']);
}
```

## Minimal View

Clarity is Azera's default view engine. Templates live under the view path
configured in the bootstrap and use the `.clarity.html` extension. The route's
controller and action do not determine the template name: `render('index/index')`
resolves to `views/index/index.clarity.html`. Clarity automatically escapes
values printed with `{{ ... }}`.

Create [views/index/index.clarity.html](../views/index/index.clarity.html):

```twig
{% extends "layouts/main" %}
{% block title %}{{ title ?? 'Welcome' }}{% endblock %}
{% block body %}
    <h1>{{ title ?? 'Welcome' }}</h1>
    <p>Application is running.</p>
{% endblock %}
```

Use Clarity template inheritance for page layouts. Create the parent template
[views/layouts/main.clarity.html](../views/layouts/main.clarity.html) with the
blocks child templates can override:

```twig
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}App{% endblock %}</title>
</head>
<body>
    {% block body %}{% endblock %}
</body>
</html>
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

For the `/users/{id:int}` route, create
[app/Controllers/UserController.php](../app/Controllers/UserController.php):

```php
<?php
namespace App\Controllers;

use App\Models\User;
use Azera\Core\Controller;
use Azera\Http\Response;

class UserController extends Controller
{
    public function viewAction(int $id): string|Response
    {
        $user = User::find($id);
        if ($user === null) {
            return Response::text("User not found\n", 404);
        }

        return $this->view()->render('users/view', ['user' => $user]);
    }
}
```

The `{id:int}` route parameter is passed to the matching `int $id` action
argument. Configure the database connection for your environment before
querying models.

Create [views/users/view.clarity.html](../views/users/view.clarity.html):

```twig
{% extends "layouts/main" %}
{% block title %}{{ user.username }}{% endblock %}
{% block body %}
    <h1>{{ user.username }}</h1>
    <p>User ID: {{ user.id }}</p>
    <p>Email: {{ user.email }}</p>
{% endblock %}
```

See the [Clarity Engine guide](03b-CLARITY-ENGINE.md) for template inheritance
and other features.

## Middleware

Middleware runs before (and after) every controller action. Register global middleware on the dispatcher:

```php
$dispatcher->addMiddleware(new SessionMiddleware());  // built-in: starts PHP session
$dispatcher->addMiddleware(new MyAuthMiddleware());   // custom
```

To apply middleware only to selected routes, use the router's
`middleware()` groups; see [Middleware Groups in MVC Routing](02-CORE-ROUTING.md#prefix-namespace-controller-and-middleware-groups).
You can also restrict middleware to specific controllers or actions using the
`$middlewares` and `$actionMiddlewares` properties on a controller class. See
[Controllers & Views](03-CONTROLLERS-VIEWS.md) for details.

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
