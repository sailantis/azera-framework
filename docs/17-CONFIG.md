# Config

A lightweight configuration service with dot-notation access for hierarchical configuration arrays.

## The `config()` accessor

When none is registered, `AppContext::config()` lazily creates an empty one:

```php
$ctx->config()->get('db.dsn', 'sqlite::memory:');
$ctx->config()->set('app.debug', true);
```

Register a pre-loaded config:

```php
use Azera\Config\Config;

$configArray = require CONFIG_PATH;
$ctx->set(Config::class, fn() => new Config($configArray));
```

## Config API

### `get(string $key, mixed $default = null): mixed`

```php
$dsn = $ctx->config()->get('db.dsn');
$debug = $ctx->config()->get('app.debug', false); // default if missing
```

### `set(string $key, mixed $value): void`

```php
$ctx->config()->set('cache.driver', 'redis');
```

### `has(string $key): bool`

Check if a key exists:

```php
if ($ctx->config()->has('stripe.secret_key')) {
    // ...
}
```

### `setArray(array $config): void`

Bulk-load a configuration array (overwrites existing keys):

```php
$ctx->config()->setArray([
    'db' => ['dsn' => 'sqlite:app.db'],
    'app' => ['debug' => true],
]);
```

### `merge(array $config): void`

Deep-merge a configuration array into the existing config:

```php
$ctx->config()->merge([
    'db' => ['user' => 'admin'], // merges with existing 'db' keys
]);
```

### `scope(string $prefix): Config`

A scoped Config automatically prefixes all keys:

```php
$stripeConfig = $ctx->config()->scope('stripe');
$stripeConfig->get('secret_key'); // reads 'stripe.secret_key'
$stripeConfig->set('webhook_secret', 'whsec_...'); // sets 'stripe.webhook_secret'
```

## Usage patterns

### Loading from a config file

```php
// config.php
return [
    'db' => [
        'dsn' => DB_DSN,
        'user' => DB_USER,
        'auth' => DB_PASSWORD,
    ],
    'stripe' => [
        'secret_key' => STRIPE_SK,
        'public_key' => STRIPE_PK,
    ],
];

// Bootstrap.php
$config = require CONFIG_PATH;
$ctx->set(Config::class, fn() => new Config($config));
```

### Environment overlays

Merge environment-specific values on top of the base config:

```php
$ctx->config()->merge([
    'app' => ['debug' => DEBUG_MODE],
    'site_protection' => [
        'enabled' => SITE_PROTECTION_ENABLED,
        'username' => SITE_PROTECTION_USERNAME,
    ],
]);
```
