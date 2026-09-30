<?php

namespace Azera\Config;

/**
 * Dot-notation access to a configuration array.
 *
 * Provides `get('db.dsn')`, `set('db.dsn', ...)`, `has('db.dsn')` over a
 * nested array. Namespaces are separated by a dot.
 *
 * Example:
 * ```php
 * $config = new Config([
 *     'db' => ['dsn' => 'mysql:host=localhost', 'user' => 'root'],
 *     'app' => ['name' => 'Azera'],
 * ]);
 * $config->get('db.dsn');      // 'mysql:host=localhost'
 * $config->get('app.name');    // 'Azera'
 * $config->get('missing', 'fallback'); // 'fallback'
 * $config->set('app.env', 'prod');
 * ```
 */
class Config
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data Initial configuration. */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Get a configuration value by dot-notation key.
     *
     * @param string $key     Dot-separated key (e.g. 'db.dsn').
     * @param mixed  $default Default value if the key is not found.
     * @return mixed The value, or $default if not found.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set a configuration value by dot-notation key.
     *
     * @param string $key   Dot-separated key.
     * @param mixed  $value The value to set.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current  = &$this->data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    /**
     * Check if a configuration key exists.
     *
     * @param string $key Dot-separated key.
     * @return bool True if the key exists.
     */
    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $value    = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Return the entire configuration as a nested array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Replace the configuration data with a new array.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function setArray(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Merge another configuration array into this one (recursive).
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function merge(array $data): void
    {
        $this->data = $this->mergeRecursive($this->data, $data);
    }

    /**
     * Get a namespaced sub-configuration view.
     *
     * Returns a new Config rooted at the given namespace, so
     * `$config->scope('db')->get('dsn')` is equivalent to
     * `$config->get('db.dsn')`.
     *
     * @param string $namespace Dot-separated prefix.
     * @return self
     */
    public function scope(string $namespace): self
    {
        return new self(
            is_array($this->get($namespace, [])) ? $this->get($namespace, []) : []
        );
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                    && isset($base[$key])
                    && is_array($base[$key])
            ) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}