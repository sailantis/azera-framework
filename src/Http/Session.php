<?php
namespace Azera\Http;

/**
 * Wrapper around a PHP session array that provides typed accessors and
 * a clean API for reading, writing, and clearing session data.
 */
class Session
{
    /**
     * Create a new Session backed by the given store reference.
     *
     * @param array $store Reference to the underlying session array (typically $_SESSION).
     */
    public function __construct(private array &$store)
    {
    }

    /**
     * Retrieve a value from the session.
     *
     * @param string $key     Session key.
     * @param mixed  $default Value to return when the key is not set.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    /**
     * Store a value in the session.
     *
     * @param string $key   Session key.
     * @param mixed  $value Value to store.
     */
    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    /**
     * Remove a key from the session.
     *
     * @param string $key Session key to unset.
     */
    public function remove(string $key): void
    {
        unset($this->store[$key]);
    }

    /**
     * Check whether a key exists in the session.
     *
     * @param string $key Session key.
     * @return bool True if the key is set and not null.
     */
    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    /**
     * Remove all data from the session.
     */
    public function clear(): void
    {
        $this->store = [];
    }

    /**
     * Whether a PHP session is active.
     *
     * The lifecycle helpers use this status to determine their behavior in
     * controllers, CLI tasks, and tests, with or without {@see SessionMiddleware}.
     */
    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Return the current session ID, or `''` when no session is active.
     *
     * This method reads the ID; use {@see regenerateId()} to change it. The ID
     * can be used in audit logs or to correlate a request with a stored session
     * without accessing the session superglobal.
     */
    public function id(): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $id = session_id();

        return $id === false ? '' : $id;
    }

    /**
     * Issue a new session ID while keeping the session data.
     *
     * Regenerate the ID after login or a privilege change, such as a role grant
     * or the start of impersonation, so the authenticated session uses a new ID.
     *
     * @param bool $deleteOldSession Whether to delete the previous session's storage.
     * @return bool True when a new ID was issued; false when no session is active
     *   or the SAPI cannot regenerate it (for example, after output has started).
     */
    public function regenerateId(bool $deleteOldSession = true): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        // Match SessionMiddleware's handling of session_start(): output may
        // already have started in a worker. The return value reports whether
        // regeneration succeeded.
        return @session_regenerate_id($deleteOldSession);
    }

    /**
     * Destroy the active session and clear this store.
     *
     * PHP's `session_destroy()` removes server-side data while leaving
     * `$_SESSION` populated for the rest of the request. Clearing the store
     * keeps subsequent reads in the request consistent with the destroyed
     * session.
     *
     * When a session is active, it is destroyed before the store is cleared.
     * The subsequent `session_write_close()` in {@see SessionMiddleware}
     * returns `false` when the session is inactive.
     */
    public function destroy(): void
    {
        if ($this->isActive()) {
            @session_destroy();
        }

        $this->store = [];
    }
}
