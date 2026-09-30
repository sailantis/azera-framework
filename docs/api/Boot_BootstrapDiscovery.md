# Class: BootstrapDiscovery

**Full name:** [Azera\Boot\BootstrapDiscovery](../../src/Boot/BootstrapDiscovery.php)

Discovers BootstrapProvider implementations by scanning PSR-4 autoload
directories declared in the project's composer.json.

No dependency on Composer's runtime API — just reads the JSON, walks
the directories, and token-scans each .php file for a class that
implements BootstrapProvider.

The result is cached to vendor/azera-bootstrap.php so the resolver
does not repeat the scan on every invocation.

## Public methods

### scanAndCache() · <small>[🗎](../../src/Boot/BootstrapDiscovery.php#L25)</small>

`public static function scanAndCache(string $projectRoot): void`

Scan the project for a BootstrapProvider implementation and write
the result to vendor/azera-bootstrap.php.

If a cache file already exists and is fresh, the scan is skipped.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$projectRoot` | string | - |  |

**Return value**

- Type: `void`


---

### scanForProvider() · <small>[🗎](../../src/Boot/BootstrapDiscovery.php#L53)</small>

`public static function scanForProvider(string $projectRoot): string|null`

Scan the project's PSR-4 autoload directories for classes
implementing BootstrapProvider.

Returns the FQCN of the discovered provider, or null if none found.
If multiple candidates exist, prefers one named "…\Bootstrap".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$projectRoot` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### writeCache() · <small>[🗎](../../src/Boot/BootstrapDiscovery.php#L336)</small>

`public static function writeCache(string $vendorDir, string|null $provider): void`

Write the generated cache file to vendor/azera-bootstrap.php.

Made public so BootstrapResolver can persist a --save choice.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vendorDir` | string | - |  |
| `$provider` | string\|null | - |  |

**Return value**

- Type: `void`


---

### saveProvider() · <small>[🗎](../../src/Boot/BootstrapDiscovery.php#L362)</small>

`public static function saveProvider(string $projectRoot, string $provider): void`

Persist a provider FQCN to the discovery cache.

Called by the resolver when --save is passed alongside --provider=.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$projectRoot` | string | - |  |
| `$provider` | string | - |  |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
