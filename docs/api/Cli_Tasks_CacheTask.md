# Class: CacheTask

**Full name:** [Azera\Cli\Tasks\CacheTask](../../src/Cli/Tasks/CacheTask.php)

Clear or inspect framework caches.

Usage:
  cache:clear                   Clear all known caches
  cache:status                  Show cache file information

The bootstrap discovery cache (vendor/azera-bootstrap.php) and
the Clarity compiled template cache (sys_get_temp_dir()/clarity)
are managed by this task. No AppContext boot is required — the
cache files are deleted directly.

Options:
  --only=<target>    Only clear a specific cache: "bootstrap" or "clarity"
  --path=<dir>       Custom Clarity cache directory (overrides default)

Examples:
  cache:clear
  cache:clear --only=bootstrap
  cache:clear --only=clarity --path=/tmp/my-cache
  cache:status

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/CacheTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/CacheTask.php)</small>

## Public methods

### clearAction() · <small>[🗎](../../src/Cli/Tasks/CacheTask.php#L37)</small>

`public function clearAction(): void`

Clear all known caches.

**Return value**

- Type: `void`


---

### statusAction() · <small>[🗎](../../src/Cli/Tasks/CacheTask.php#L70)</small>

`public function statusAction(): void`

Show information about existing cache files.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
