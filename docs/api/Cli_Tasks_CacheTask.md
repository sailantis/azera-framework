# Class: CacheTask

**Full name:** [Azera\Cli\Tasks\CacheTask](../../src/Cli/Tasks/CacheTask.php)

Clear or inspect framework caches.

Usage:
  cache:clear                   Clear all known caches
  cache:prune                   Delete superseded generated files
  cache:status                  Show cache file information

Three caches are managed here:

 - the bootstrap discovery cache (`vendor/azera-bootstrap.php`);
 - the Clarity compiled template cache (`sys_get_temp_dir()/clarity`);
 - the AOP proxy cache (`sys_get_temp_dir()/azera_aop`, or whatever
   `AppContext::setAopCacheDir()` selected).

Both Clarity and the AOP proxy generator write CONTENT-ADDRESSED files: a
changed body lands at a new digest path instead of rewriting the old one. That
is deliberate — rewriting a cached file and invalidating it discards the
`op_array` every worker shares, and OPcache books that as
`wasted_shared_memory` (~1.7 KB per invalidation, measured). The cost of the
design is that superseded digests accumulate, which is what `cache:prune`
exists to reclaim.

Options:
  --only=<target>    Only act on one cache: "bootstrap", "clarity" or "aop"
  --path=<dir>       Custom cache directory for the selected target
  --older-than=<s>   Skip files modified more recently than <s> seconds

Examples:
  cache:clear
  cache:clear --only=bootstrap
  cache:clear --only=clarity --path=/tmp/my-cache
  cache:clear --only=aop
  cache:prune --older-than=3600
  cache:status

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/CacheTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/CacheTask.php)</small>

## Public methods

### clearAction() · <small>[🗎](../../src/Cli/Tasks/CacheTask.php#L51)</small>

`public function clearAction(): void`

Clear all known caches.

**Return value**

- Type: `void`


---

### statusAction() · <small>[🗎](../../src/Cli/Tasks/CacheTask.php#L91)</small>

`public function statusAction(): void`

Show information about existing cache files.

**Return value**

- Type: `void`


---

### pruneAction() · <small>[🗎](../../src/Cli/Tasks/CacheTask.php#L201)</small>

`public function pruneAction(): void`

Delete compiled template bodies that no pointer references.

Superseded digests are inert — nothing loads them — but they occupy the
cache directory and, if OPcache cached them, its shared memory until a
master restart. This is a maintenance task rather than request-path
work because an unreferenced file may still be about to be required by a
worker that read the pointer a moment earlier.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
