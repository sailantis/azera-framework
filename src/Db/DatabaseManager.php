<?php
namespace Azera\Db;

use RuntimeException;

/**
 * Manages multiple SQL connections (roles) and their factories.
 *
 * This class allows the definition of multiple SQL connections (e.g. "default", "analytics", "logging") and retrieval of them by role. The first role defined will be used as the default when requesting the default connection, but it can be changed by calling setDefault(). Each role can be defined with either a Database instance or a factory callable that returns a Database instance. The factory will only be called once per role, and the resulting Database instance will be cached for future use.
 */
class DatabaseManager
{
    protected array $factories = [];
    /** @var array<string, Database> Cached Database instances for each role */
    protected array $instances = [];

    protected ?string $defaultRole = null;

    /**
     * Define a SQL connection for a specific role.
     *
     * @param string $role The name of the role (e.g. "default", "analytics")
     * @param callable|Database $factory A factory callable that returns a Database instance, or a Database instance directly
     * @return $this
     */
    public function set(string $role, callable|Database $factory): static
    {
        $this->factories[$role] = $factory;
        if ($factory instanceof Database) {
            $this->instances[$role] = $factory;
        }
        if ($this->defaultRole === null) {
            $this->defaultRole = $role;
        }
        return $this;
    }

    /**
     * Set the default SQL role to use when requesting the default connection. By default, the first defined role will be used as the default.
     *
     * @param string $role The name of the role to set as default
     * @return $this
     * @throws RuntimeException If the specified role is not defined
     */
    public function setDefault(string $role): static
    {
        if (!isset($this->factories[$role])) {
            throw new RuntimeException("Cannot set default role: role '$role' is not configured");
        }

        $this->defaultRole = $role;
        return $this;
    }

    /**
     * Check if a SQL role is defined.
     *
     * @param string $role The name of the role to check
     * @return bool True if the role is defined, false otherwise
     */
    public function has(string $role): bool
    {
        return isset($this->factories[$role]);
    }

    /**
     * Get the Database instance for a specific role.
     *
     * @param string $role The name of the role to retrieve
     * @return Database The Database instance for the specified role
     * @throws RuntimeException If the role is not defined or if the factory does not return a Database instance
     */
    public function get(string $role): Database
    {
        if (isset($this->instances[$role])) {
            return $this->instances[$role];
        }

        if (!isset($this->factories[$role])) {
            throw new RuntimeException("SQL role not configured: $role");
        }

        $factory = $this->factories[$role];
        if ($factory instanceof Database) {
            return $this->instances[$role] = $factory;
        }

        $db = $factory();
        if (!$db instanceof Database) {
            throw new RuntimeException("Factory for role $role did not return a Database instance");
        }

        $this->instances[$role] = $db;

        return $db;
    }

    /**
     * Get the Database instance for a specific role, or the default if the role is not defined.
     *
     * @param string $role The name of the role to retrieve
     * @return Database The Database instance for the specified role, or the default if not defined
     * @throws RuntimeException If no default Database is configured
     */
    public function getOrDefault(string $role): Database
    {
        if (isset($this->factories[$role])) {
            return $this->get($role);
        }

        return $this->getDefault();
    }

    /**
     * Get the Database instance for a role **if it is already instantiated**,
     * without invoking its factory.
     *
     * Lets diagnostics inspect live connection state (for example to find
     * connections left inside a transaction) without opening every configured
     * connection as a side effect.
     *
     * @param string $role The role to inspect.
     * @return Database|null The instance, or null when the role is unknown or its
     *   factory has not run yet.
     */
    public function peek(string $role): ?Database
    {
        return $this->instances[$role] ?? null;
    }

    /**
     * Get the default Database instance.
     *
     * @return Database The default Database instance
     * @throws RuntimeException If no default Database is configured
     */
    public function getDefault(): Database
    {
        if ($this->defaultRole === null) {
            throw new RuntimeException("No Database configured");
        }
        return $this->get($this->defaultRole);
    }

    /**
     * Return the names of all registered SQL roles.
     *
     * @return string[] List of role names (e.g. ["default", "read", "write"]).
     */
    public function roles(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Return the name of the default SQL role, or null if none is configured.
     *
     * @return string|null The default role name.
     */
    public function defaultRole(): ?string
    {
        return $this->defaultRole;
    }
}

