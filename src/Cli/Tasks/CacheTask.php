<?php

namespace Azera\Cli\Tasks;

use Azera\AppContext;
use Azera\Cli\Task;

/**
 * Clear or inspect framework caches.
 *
 * Usage:
 *   cache:clear                   Clear all known caches
 *   cache:prune                   Delete superseded generated files
 *   cache:status                  Show cache file information
 *
 * Three caches are managed here:
 *
 *  - the bootstrap discovery cache (`vendor/azera-bootstrap.php`);
 *  - the Clarity compiled template cache (`sys_get_temp_dir()/clarity`);
 *  - the AOP proxy cache (`sys_get_temp_dir()/azera_aop`, or whatever
 *    `AppContext::setAopCacheDir()` selected).
 *
 * Both Clarity and the AOP proxy generator write CONTENT-ADDRESSED files: a
 * changed body lands at a new digest path instead of rewriting the old one. That
 * is deliberate — rewriting a cached file and invalidating it discards the
 * `op_array` every worker shares, and OPcache books that as
 * `wasted_shared_memory` (~1.7 KB per invalidation, measured). The cost of the
 * design is that superseded digests accumulate, which is what `cache:prune`
 * exists to reclaim.
 *
 * Options:
 *   --only=<target>    Only act on one cache: "bootstrap", "clarity" or "aop"
 *   --path=<dir>       Custom cache directory for the selected target
 *   --older-than=<s>   Skip files modified more recently than <s> seconds
 *
 * Examples:
 *   cache:clear
 *   cache:clear --only=bootstrap
 *   cache:clear --only=clarity --path=/tmp/my-cache
 *   cache:clear --only=aop
 *   cache:prune --older-than=3600
 *   cache:status
 */
class CacheTask extends Task
{
    private const BOOTSTRAP_CACHE_NAME = 'azera-bootstrap.php';

    /**
     * Clear all known caches.
     */
    public function clearAction(): void
    {
        $projectRoot = $this->console->findComposerRoot();
        $only        = $this->option('only');
        $cleared     = ['bootstrap' => 0, 'clarity' => 0, 'aop' => 0];

        if ($only === null || $only === 'bootstrap') {
            $cleared['bootstrap'] = $this->clearBootstrapCache($projectRoot);
        }

        if ($only === null || $only === 'clarity') {
            $cleared['clarity'] = $this->clearClarityCache();
        }

        if ($only === null || $only === 'aop') {
            $cleared['aop'] = $this->clearAopCache();
        }

        $total = array_sum($cleared);
        if ($total === 0) {
            $this->muted('No cache files found to clear.');
            return;
        }

        $parts = [];
        if ($cleared['bootstrap'] > 0) {
            $parts[] = $cleared['bootstrap'] . ' bootstrap';
        }
        if ($cleared['clarity'] > 0) {
            $parts[] = $cleared['clarity'] . ' template';
        }
        if ($cleared['aop'] > 0) {
            $parts[] = $cleared['aop'] . ' AOP proxy';
        }
        $this->success('Cleared ' . implode(' + ', $parts) . ' cache file(s).');
    }

    /**
     * Show information about existing cache files.
     */
    public function statusAction(): void
    {
        $projectRoot = $this->console->findComposerRoot();

        $this->line($this->style('Cache Status', 'bold', 'bgreen'));
        $this->line('');

        // Bootstrap cache
        $this->printBootstrapStatus($projectRoot);

        // Clarity template cache
        $this->printClarityStatus();

        // AOP proxy cache
        $this->printAopStatus();
    }

    // -------------------------------------------------------------------------
    //  Bootstrap cache
    // -------------------------------------------------------------------------

    private function clearBootstrapCache(?string $projectRoot): int
    {
        if ($projectRoot === null) {
            $this->muted('No project root found — skipping bootstrap cache.');
            return 0;
        }

        $cacheFile = $projectRoot . '/vendor/' . self::BOOTSTRAP_CACHE_NAME;
        if (!is_file($cacheFile)) {
            return 0;
        }

        unlink($cacheFile);
        $this->muted("Deleted {$cacheFile}");
        return 1;
    }

    private function printBootstrapStatus(?string $projectRoot): void
    {
        $label = $this->style('bootstrap', 'cyan');
        if ($projectRoot === null) {
            $this->writeln("  {$label}  " . $this->style('project root not found', 'yellow'));
            return;
        }

        $cacheFile = $projectRoot . '/vendor/' . self::BOOTSTRAP_CACHE_NAME;
        if (!is_file($cacheFile)) {
            $this->writeln("  {$label}  " . $this->style('not found (will regenerate on next boot)', 'gray'));
            return;
        }

        $size = filesize($cacheFile);
        $time = date('Y-m-d H:i:s', filemtime($cacheFile));
        $this->writeln("  {$label}  {$size} bytes, modified {$time}");
    }

    // -------------------------------------------------------------------------
    //  Clarity template cache
    // -------------------------------------------------------------------------

    private function getClarityCachePath(): string
    {
        $custom = $this->option('path');
        if ($custom !== null && is_dir($custom)) {
            return rtrim($custom, '/\\');
        }
        try {
            return AppContext::instance()->view()->getCachePath();
        } catch (\Throwable) {
            return '';
        }
    }

    private function clearClarityCache(): int
    {
        $dir = $this->getClarityCachePath();
        if (!is_dir($dir)) {
            return 0;
        }

        $count    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                unlink($file->getRealPath());
                $count++;
            }
        }

        if ($count > 0) {
            $this->muted("Deleted {$count} compiled template file(s) from {$dir}");
        }

        return $count;
    }

    /**
     * Delete compiled template bodies that no pointer references.
     *
     * Superseded digests are inert — nothing loads them — but they occupy the
     * cache directory and, if OPcache cached them, its shared memory until a
     * master restart. This is a maintenance task rather than request-path
     * work because an unreferenced file may still be about to be required by a
     * worker that read the pointer a moment earlier.
     */
    public function pruneAction(): void
    {
        $only      = $this->option('only');
        $olderThan = (int) ($this->option('older-than') ?? 0);
        $pruned    = [];

        if ($only === null || $only === 'clarity') {
            $dir = $this->getClarityCachePath();

            if (is_dir($dir) && method_exists(\Clarity\Engine\Cache::class, 'prune')) {
                $cache  = new \Clarity\Engine\Cache($dir);
                $result = $cache->prune($olderThan);

                $pruned[] = sprintf(
                    'Pruned %d unreferenced compiled template file(s), %d from the previous layout; kept %d still referenced.',
                    $result['removed'],
                    $result['legacy'] ?? 0,
                    $result['kept'],
                );
            } elseif (!is_dir($dir)) {
                if ($only === 'clarity') {
                    $pruned[] = 'No Clarity cache directory found at ' . ($dir === '' ? '(unknown)' : $dir) . '.';
                }
            } else {
                // The installed clarity-engine predates content-addressed bodies, so
                // there are no superseded digests to collect — but saying so beats a
                // fatal `undefined method` in a maintenance command.
                $pruned[] = 'Clarity cache pruning needs a clarity-engine version with Cache::prune().';
            }
        }

        if ($only === null || $only === 'aop') {
            $pruned[] = $this->pruneAopCache($olderThan);
        }

        foreach ($pruned as $line) {
            $this->success($line);
        }
    }

    // -------------------------------------------------------------------------
    //  AOP proxy cache
    // -------------------------------------------------------------------------

    /**
     * The directory the AOP proxy generator writes to.
     *
     * Read from the shared context so the CLI prunes exactly what the application
     * writes — including a custom `setAopCacheDir()`. Returns null when the
     * context is configured for `eval()` (no files to manage).
     */
    private function getAopCacheDir(): ?string
    {
        $custom = $this->option('path');

        if ($custom !== null) {
            return is_dir($custom) ? rtrim($custom, '/\\') : null;
        }

        try {
            return AppContext::instance()->aopCacheDir();
        } catch (\Throwable) {
            // A context that predates the accessor, or one that cannot boot: fall
            // back to the documented default so `status` still reports something
            // useful rather than failing the whole command.
            $default = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_aop';

            return is_dir($default) ? $default : null;
        }
    }

    /**
     * Delete every generated proxy file in the directory.
     *
     * Deliberately unconditional: this is the escape hatch for a directory whose
     * contents cannot be trusted (a hand-built directory, a cache written by a
     * generator this version does not recognise, a pointer set so corrupt that
     * pruning refuses to judge it). It is safe only because the CALLER decided to
     * wipe the cache — proxies are regenerated on the next instantiation, and this
     * command names the directory it acts on.
     *
     * Prune is the narrow, pointer-aware counterpart; this is the blunt one.
     */
    private function clearAopCache(): int
    {
        $dir = $this->getAopCacheDir();

        if ($dir === null || !is_dir($dir)) {
            return 0;
        }

        $count = 0;

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'Proxy_*.php') ?: [] as $file) {
            // Invalidate first so OPcache releases the shared page with the file
            // still resolvable; a plain unlink leaves it until the next restart.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }

            if (@unlink($file)) {
                $count++;
            }
        }

        // Pointers, manifests from older versions, and stray temp files from an
        // interrupted write are all dead once the proxies are gone.
        foreach (glob($dir . DIRECTORY_SEPARATOR . '{Proxy_*.ptr,manifest_*,azera_aop_*}', GLOB_BRACE) ?: [] as $tmp) {
            @unlink($tmp);
        }

        if ($count > 0) {
            $this->muted("Deleted {$count} generated proxy file(s) from {$dir}");
        }

        return $count;
    }

    /**
     * Remove generated bodies that no pointer in the directory names.
     *
     * Every generator-version bump and every change to a class's advice set leaves
     * the previous body behind, and once a worker has loaded one it also holds a
     * permanent OPcache entry. Deleting it (with invalidation) is the only way to
     * reclaim that shared memory short of restarting the pool.
     *
     * "Live" is read from the pointer files on disk, so this works from a cold CLI
     * that booted no services and registered no interceptors — the previous
     * implementation needed the caller to supply every proxied class, which a
     * maintenance command cannot do.
     */
    private function pruneAopCache(int $olderThan): string
    {
        $dir = $this->getAopCacheDir();

        if ($dir === null || !is_dir($dir)) {
            return 'No AOP proxy cache directory found.';
        }

        $factory = $this->aopFactory($dir);

        if ($factory === null) {
            return 'AOP is not installed; nothing to prune.';
        }

        $result = $factory->prune($olderThan);

        if (isset($result['reason'])) {
            return "Skipped AOP proxy pruning: {$result['reason']}.";
        }

        return sprintf(
            'Pruned %d superseded AOP proxy file(s); kept %d still in use.',
            $result['removed'],
            $result['kept'],
        );
    }

    /**
     * A factory pointed at the directory.
     *
     * Pruning and staleness reporting need no interceptor map: "live" is read from
     * the pointer files on disk, and a pointer's path is derived from the directory
     * alone, so a cold CLI that registered nothing prunes exactly what a warm worker
     * wrote. Registering the map here would buy nothing and would invoke the
     * application's lazy interceptor factories — a maintenance command must not open
     * a database connection to delete a file.
     */
    private function aopFactory(string $dir): ?\Azera\Aop\ProxyFactory
    {
        if (!class_exists(\Azera\Aop\ProxyFactory::class)) {
            return null;
        }

        $factory = new \Azera\Aop\ProxyFactory();
        $factory->setCacheDir($dir);

        return $factory;
    }

    /**
     * Report the AOP proxy cache: how many generated bodies exist and how many are
     * unreferenced.
     *
     * "Unreferenced" is counted the way the loader decides it — a body no pointer in
     * the directory names. The pointer set is read from disk, so this is correct even
     * from a cold CLI that registered no services.
     */
    private function printAopStatus(): void
    {
        $label = $this->style('aop', 'cyan');
        $dir   = $this->getAopCacheDir();

        if ($dir === null) {
            $this->writeln("  {$label}  " . $this->style('eval() — no proxy files are written', 'gray'));
            return;
        }

        if (!is_dir($dir)) {
            $this->writeln("  {$label}  " . $this->style('cache directory not found', 'gray'));
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'Proxy_*.php') ?: [];
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += (int) @filesize($file);
        }

        if ($files === []) {
            $this->writeln("  {$label}  " . $this->style('empty', 'gray'));
            return;
        }

        $sizeStr = $bytes > 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . ' MB'
            : ($bytes > 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' bytes');

        $this->writeln("  {$label}  " . count($files) . " proxy file(s), {$sizeStr}, in {$dir}");

        $stale = $this->countStaleProxies($dir, $files);

        if ($stale > 0) {
            $this->writeln("  {$label}  " . $this->style(
                "{$stale} unreferenced — run `cache:prune --only=aop`",
                'yellow',
            ));
        } else {
            $this->writeln("  {$label}  " . $this->style('all current', 'gray'));
        }
    }

    /**
     * Count bodies no pointer in the directory names.
     *
     * Same rule as {@see \Azera\Aop\ProxyFactory::prune()}: the pointer files ARE the
     * answer, so a body is unreferenced when nothing on disk points at it. No class
     * list and no reflection — which is what makes the count trustworthy from a CLI
     * that booted nothing.
     *
     * @param list<string> $files
     */
    private function countStaleProxies(string $dir, array $files): int
    {
        $referenced = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'Proxy_*.ptr') ?: [] as $pointerPath) {
            $raw  = @file_get_contents($pointerPath);
            $data = is_string($raw) && $raw !== '' ? @json_decode($raw, true) : null;

            if (is_array($data) && is_string($data['body'] ?? null)) {
                $referenced[$data['body']] = true;
            }
        }

        $stale = 0;

        foreach ($files as $file) {
            if (!isset($referenced[basename($file)])) {
                $stale++;
            }
        }

        return $stale;
    }

    private function printClarityStatus(): void
    {
        $dir   = $this->getClarityCachePath();
        $label = $this->style('clarity', 'cyan');

        if (!is_dir($dir)) {
            $this->writeln("  {$label}  " . $this->style('cache directory not found', 'gray'));
            return;
        }

        $count  = 0;
        $bytes  = 0;
        $bodies = 0;
        $ptrs   = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();

            if (str_ends_with($name, '.ptr')) {
                $ptrs++;
                $count++;
                $bytes += $file->getSize();
                continue;
            }

            if (str_ends_with($name, '.php')) {
                $count++;
                $bytes += $file->getSize();
                if (preg_match('/^[0-9a-f]{32}-[0-9a-f]+\.php$/', $name) === 1) {
                    $bodies++;
                }
            }
        }

        // Unreferenced bodies, resolved through the pointer API rather than by
        // reading files: the pointer format is the store's business, not the CLI's.
        $stale = $ptrs > 0 ? $this->countStaleBodies($dir) : 0;

        if ($count === 0) {
            $this->writeln("  {$label}  " . $this->style('empty', 'gray'));
            return;
        }

        $sizeStr = $bytes > 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . ' MB'
            : ($bytes > 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' bytes');
        $this->writeln("  {$label}  {$count} file(s), {$sizeStr}");

        if ($bodies > 0) {
            $hint = $stale > 0
                ? $stale . ' unreferenced — run `cache:prune`'
                : 'all referenced';
            $this->writeln("  {$label}  {$bodies} compiled body/bodies, {$ptrs} pointer(s), {$hint}");
        }
    }

    /**
     * Count compiled bodies no pointer refers to.
     *
     * Reads the pointer FILES directly: a pointer is `<hash>.ptr` holding a
     * serialized record whose class name ends in the body's digest, so
     * `<hash>-<digest>.php` is exactly the set still in use.
     */
    private function countStaleBodies(string $dir): int
    {
        $referenced = [];

        foreach (new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS) as $bucket) {
            if ($bucket->isDot() || !$bucket->isDir()) {
                continue;
            }

            foreach (new \DirectoryIterator($bucket->getPathname()) as $entry) {
                if ($entry->isDot() || !$entry->isFile() || !str_ends_with($entry->getFilename(), '.ptr')) {
                    continue;
                }

                $raw = @file_get_contents($entry->getPathname());
                if (!is_string($raw) || $raw === '') {
                    continue;
                }

                try {
                    $data = @unserialize($raw, ['allowed_classes' => false]);
                } catch (\Throwable) {
                    continue;
                }

                if (!is_array($data) || !is_string($data['class'] ?? null)) {
                    continue;
                }

                $hash   = substr($entry->getFilename(), 0, -4);
                $digest = substr($data['class'], strrpos($data['class'], '_') + 1);
                $referenced[$hash . '-' . $digest . '.php'] = true;
            }
        }

        $stale = 0;
        foreach (new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS) as $bucket) {
            if ($bucket->isDot() || !$bucket->isDir()) {
                continue;
            }
            foreach (new \DirectoryIterator($bucket->getPathname()) as $entry) {
                if ($entry->isFile()
                    && preg_match('/^[0-9a-f]{32}-[0-9a-f]+\.php$/', $entry->getFilename()) === 1
                    && !isset($referenced[$entry->getFilename()])
                ) {
                    $stale++;
                }
            }
        }

        return $stale;
    }
}