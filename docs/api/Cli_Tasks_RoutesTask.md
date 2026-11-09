# Class: RoutesTask

**Full name:** [Azera\Cli\Tasks\RoutesTask](../../src/Cli/Tasks/RoutesTask.php)

Inspect and manage the compiled route table.

Usage:
  routes:list              List every registered route
  routes:cache             Rebuild the table now and write it
  routes:clear             Delete the compiled table
  routes:status            Report whether it exists and whether it is fresh
  routes:status --strict   Same, but exit 1 unless it exists and is fresh

None of these are required. The router writes the table itself the first time a
request reads it after the files change (see Router::cached()), so a project that
never runs routes:cache still gets a warm table -- just at the first request after
a deploy instead of before it. These commands exist for ops: warming during a
deploy, clearing by hand, and inspecting what is on disk.

Why the table is worth compiling
--------------------------------
A cold SAPI (php-fpm, php-cgi, volucer's spawn_cgi) builds the whole route table
inside the timed request, because nothing survives between requests. Measured on
a 119-route table: 148 us to build from source against 3 us to require a compiled
one, which took nginx+php-fpm's /echo from 24,657 to 38,706 rps at c=96 on 12
cores.

The compiled file is PHP source on purpose. OPcache compiles it once and every
later request includes it in ~0.1 us; a serialized blob would rebuild the arrays
in C on every request and measured 163 us against 3 us. That is also why Symfony
and Laravel generate PHP rather than data files.

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php)</small>

## Public methods

### cacheAction() · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php#L47)</small>

`public function cacheAction(): void`

Rebuild the table from the cached() units and write it.

Ignores the file on disk: this is the "I want it now" command. A normal deploy
does not need it, because a missing or stale file is written by the first
request that reads the router.

**Return value**

- Type: `void`


---

### clearAction() · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php#L112)</small>

`public function clearAction(): void`

Delete the compiled table.

**Return value**

- Type: `void`


---

### statusAction() · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php#L139)</small>

`public function statusAction(): void`

Report whether a compiled table exists, and whether it still matches the code.

Pass --strict to exit 1 unless the table exists and is fresh. That is the check
worth putting in CI: a stale table is not an error at runtime -- the next request
rebuilds it -- but it does mean a deploy dashboard is describing a table that no
longer exists.

**Return value**

- Type: `void`


---

### listAction() · <small>[🗎](../../src/Cli/Tasks/RoutesTask.php#L192)</small>

`public function listAction(): void`

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
