<?php

namespace Azera\Aop;

use ReflectionClass;
use ReflectionMethod;

/**
 * Builds proxies that run advised methods through interceptor pipelines.
 *
 * A proxy extends the target and is the service instance. Overridden methods
 * delegate to `parent::method()`; unadvised methods remain inherited.
 *
 * `buildProxyClass()` memoizes by namespace and target class before reflection,
 * then checks for a loaded class before file I/O. Disk caching uses content-addressed
 * bodies and per-class pointers; a matching source signature skips generation.
 * Deterministic class names allow OPcache reuse (see `loadCachedProxyClass()`).
 *
 * With no interceptors registered, no proxy is built. An unadvised class
 * costs one attribute check; an advised class with no advised methods costs one
 * reflection scan and returns the raw target.
 */
class ProxyFactory
{
    /**
     * Increment when generated code or cache layout changes. The version is part
     * of proxy identity, so new versions use distinct class names and ignore stale files.
     *
     * v2: deterministic class names.
     * v3: direct interceptor calls, reference-preserving arguments, no per-call chain
     *     building or reflection.
     * v4: one Invocation through a precomposed, per-method memoized Pipeline.
     * v5: runtime-managed terminal closures for clone/serialization safety; per-factory
     *     interceptor namespaces.
     * v6: advice arguments embedded in source, so changes produce a new digest.
     * v7: pre-reflection memoization, per-class pointers and source signatures, and
     *     existence-only body reuse.
     */
    private const GENERATOR_VERSION = 7;

    /** @var array<class-string<Advice>, InterceptorInterface> */
    private array $interceptors = [];

    /**
     * Interceptor namespace embedded in generated proxies.
     *
     * Derived from the cache directory for stable, factory-specific lookup.
     */
    private ?string $namespace = null;

    /** @var array<string, class-string|null> Keyed by `<namespace>\0<class>` — never by body digest. */
    private array $proxyClassCache = [];

    /** @var array<string, class-string> In-process registry: `<namespace>\0<class>` => loaded proxy class */
    private static array $loadedProxies = [];

    /** Cache directory for file-based proxy generation. Null = use eval(). */
    private ?string $cacheDir = null;

    /**
     * Register the interceptor for one advice class, replacing any previous one.
     *
     * The resolved map is published to {@see AopRuntime} so generated proxies can
     * resolve their precomposed chains without holding a factory reference.
     */
    public function register(string $adviceClass, InterceptorInterface $interceptor): void
    {
        $this->interceptors[$adviceClass] = $interceptor;

        // Advice changes affect generated bodies, so clear the pre-reflection memo.
        // AppContext replaces the factory too; direct users need this reset.
        $this->proxyClassCache = [];

        AopRuntime::setInterceptor($this->namespace(), $adviceClass, $interceptor);
    }

    /**
     * The namespace a factory publishing from `$dir` will use.
     *
     * Static and single-sourced: the namespace is baked into generated source and
     * keys the pointer files, so a caller reasoning about a directory it has no
     * factory for (a maintenance command) must derive exactly the same string.
     * Normalisation lives here, not in each caller.
     *
     * @param string|null $dir Cache directory, or null for `eval()`.
     */
    public static function namespaceFor(?string $dir): string
    {
        return 'factory:' . ($dir !== null ? rtrim($dir, '/\\') : 'eval');
    }

    /**
     * The namespace this factory publishes under.
     *
     * Lazily derived so `register()` before `setCacheDir()` still lands somewhere
     * sane, and so `setCacheDir()` can move a factory to its final namespace.
     * `AppContext::proxyFactory()` sets the directory first, then registers.
     */
    public function namespace(): string
    {
        return $this->namespace ??= self::namespaceFor($this->cacheDir);
    }

    /**
     * Set the cache directory for file-based proxy generation.
     *
     * When set, proxy classes are written to disk as PHP files and `require`d,
     * allowing OPcache to cache them. When null (default), `eval()` is used.
     *
     * @param string|null $dir Cache directory, or null to use eval().
     */
    public function setCacheDir(?string $dir): void
    {
        $this->cacheDir = $dir !== null ? rtrim($dir, '/\\') : null;

        // The namespace is embedded in source, so update it and reset the memo.
        $this->namespace = self::namespaceFor($this->cacheDir);
        $this->proxyClassCache = [];

        if ($this->interceptors !== []) {
            AopRuntime::setInterceptors($this->namespace, $this->interceptors);
        }
    }

    public function getCacheDir(): ?string
    {
        return $this->cacheDir;
    }

    public function hasInterceptors(): bool
    {
        return $this->interceptors !== [];
    }

    /**
     * Return the proxy file path, or null when disk caching is disabled or no
     * methods are advised. Used by tooling to locate or warm the exact cache file.
     *
     * @return string|null Absolute path, or null.
     */
    public function proxyPath(ReflectionClass $ref): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }

        $advisedMethods = $this->collectAdvisedMethods($ref);

        if ($advisedMethods === []) {
            return null;
        }

        return $this->cacheDir . DIRECTORY_SEPARATOR
            . 'Proxy_' . $this->proxyDigest($ref->getName(), $this->generateProxyCode($ref, $advisedMethods)) . '.php';
    }

    /**
     * Return the target class's pointer file, or null without a cache directory.
     *
     * Each class keeps one pointer until {@see self::prune()} removes it.
     */
    public function pointerFileFor(ReflectionClass $ref): ?string
    {
        return $this->cacheDir === null ? null : $this->pointerPath($this->cacheDir, $ref->getName());
    }

    /**
     * Build a proxy class, or return null if no methods need interception.
     *
     * Disk-backed proxies are required from file for OPcache; eval mode creates
     * an anonymous class.
     *
     * @return class-string|null
     */
    public function buildProxyClass(ReflectionClass $ref): ?string
    {
        $className = $ref->getName();

        // Check the per-factory memo before reflection or generation. PHP does not
        // refresh attributes for loaded classes; register() and setCacheDir() clear
        // this cache when advice or namespace changes, so (namespace, class) suffices.
        // The process-wide registry below uses a separate key.
        $cacheKey = $this->namespace() . "\0" . $className;

        if (array_key_exists($cacheKey, $this->proxyClassCache)) {
            return $this->proxyClassCache[$cacheKey];
        }

        // Reuse a proxy already loaded in this process without reflection or I/O.
        $registryKey = $this->registryKey($className);

        if (isset(self::$loadedProxies[$registryKey])) {
            return $this->proxyClassCache[$cacheKey] = self::$loadedProxies[$registryKey];
        }

        if ($this->cacheDir !== null) {
            $proxyClass = $this->loadCachedProxyClass($ref, $cacheKey);
        } else {
            $advisedMethods = $this->collectAdvisedMethods($ref);

            if ($advisedMethods === []) {
                return $this->proxyClassCache[$cacheKey] = null;
            }

            // Without a cache directory, create an anonymous proxy with eval().
            $evalCode   = $this->buildEvalCode($className, $this->generateProxyCode($ref, $advisedMethods));
            $instance   = eval($evalCode);
            $proxyClass = get_class($instance);
        }

        if ($proxyClass === null) {
            return $this->proxyClassCache[$cacheKey] = null;
        }

        self::$loadedProxies[$registryKey] = $proxyClass;

        return $this->proxyClassCache[$cacheKey] = $proxyClass;
    }

    /**
     * Load the cached proxy when its pointer matches the source and advice map.
     *
     * Each class has a `Proxy_<key>.ptr` file keyed by generator version, namespace,
     * and class. It records the current body, advice map, source `mtime:size`, and
     * proxy class. Missing or invalid pointers and missing bodies trigger generation.
     *
     * A matching signature and advice map skip generation. The signature is a hint:
     * source edits preserving both mtime and size may reuse the previous body.
     * Bodies are content-addressed and reused with `is_file()`; the cache directory
     * is the trust boundary (`0700`, checked by {@see self::assertSafeCacheDir()}).
     *
     * @param ReflectionClass<object> $ref
     * @param string $cacheKey `<namespace>\0<class>` memo key.
     * @return class-string|null
     */
    private function loadCachedProxyClass(ReflectionClass $ref, string $cacheKey): ?string
    {
        $className = $ref->getName();
        $dir       = $this->cacheDir;
        \assert($dir !== null);

        $signature = $this->sourceSignature($ref);

        // Reuse the body when the source signature and interceptor map match.
        if ($signature !== null) {
            $current = $this->readPointer($dir, $className);

            if ($current !== null
                && $current['sig'] === $signature
                && $current['map'] === $this->interceptorKey()
            ) {
                $path = $dir . DIRECTORY_SEPARATOR . $current['body'];

                // A missing body requires regeneration. Clear cached file metadata
                // so long-lived workers see the current path.
                clearstatcache(true, $path);

                if (is_file($path)) {
                    // The filename contains the digest used to derive the class name.
                    $digest     = substr($current['body'], 6, -4);
                    $proxyClass = $this->proxyClassNameFor($className, $digest);

                    // Avoid requiring a class already declared in this process.
                    if (class_exists($proxyClass, false)) {
                        return $proxyClass;
                    }

                    /** @var class-string $loaded */
                    $loaded = require $path;

                    if ($loaded === $proxyClass) {
                        return $loaded;
                    }
                }
            }
        }

        $advisedMethods = $this->collectAdvisedMethods($ref);

        if ($advisedMethods === []) {
            return null;
        }

        $methodsCode = $this->generateProxyCode($ref, $advisedMethods);

        return $this->emitProxyClass($ref, $methodsCode, $signature);
    }

    /**
     * Publish and load a proxy from its generated method body.
     *
     * @param ReflectionClass<object> $ref
     * @return class-string
     */
    private function emitProxyClass(ReflectionClass $ref, string $methodsCode, ?string $signature): string
    {
        $className    = $ref->getName();
        $dir          = $this->cacheDir;
        \assert($dir !== null);

        $digest         = $this->proxyDigest($className, $methodsCode);
        $proxyClassName = $this->proxyClassNameFor($className, $digest);
        $fileContents   = $this->buildFileCode($proxyClassName, $className, $methodsCode);
        $cacheFile      = $dir . DIRECTORY_SEPARATOR . "Proxy_{$digest}.php";

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create AOP proxy cache directory: {$dir}");
        }

        $this->assertSafeCacheDir($dir);

        clearstatcache(true, $cacheFile);

        if (!is_file($cacheFile)) {
            $this->writeProxyFile($cacheFile, $dir, $fileContents);
        }

        // Another factory may already have declared this identical proxy.
        if (class_exists($proxyClassName, false)) {
            $this->writePointer($dir, $className, "Proxy_{$digest}.php", $signature, $proxyClassName);

            return $proxyClassName;
        }

        // The digest in the filename identifies the expected content; avoid rereading it.
        /** @var class-string $loaded */
        $loaded = require $cacheFile;

        if ($loaded !== $proxyClassName) {
            // Replace a mismatched body and invalidate its possibly shared OPcache entry.
            $this->writeProxyFile($cacheFile, $dir, $fileContents, invalidate: true);

            /** @var class-string $loaded */
            $loaded = require $cacheFile;
        }

        // Publish only after loading succeeds.
        $this->writePointer($dir, $className, "Proxy_{$digest}.php", $signature, $loaded);

        return $loaded;
    }

    /**
     * Return the source `mtime:size` signature, or null if it is incomplete.
     *
     * This hint skips generation but is not a cache key. Classes without a source
     * file or with methods from multiple files (such as traits) have no usable signature.
     */
    private function sourceSignature(ReflectionClass $ref): ?string
    {
        $file = $ref->getFileName();

        if ($file === false || $file === '') {
            return null;
        }

        // Trait methods may come from a file this class signature does not cover.
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->getFileName() !== $file) {
                return null;
            }
        }

        clearstatcache(true, $file);

        $mtime = @filemtime($file);
        $size  = @filesize($file);

        if ($mtime === false || $size === false) {
            return null;
        }

        return $mtime . ':' . $size;
    }

    /**
     * Return the pointer path for a target class.
     *
     * Keep generator version in the record, not the filename, so a version bump
     * replaces the pointer and makes its previous body eligible for pruning.
     */
    private function pointerPath(string $dir, string $className): string
    {
        $key = hash('xxh3', $this->namespace() . "\0" . $className);

        return $dir . DIRECTORY_SEPARATOR . "Proxy_{$key}.ptr";
    }

    /**
     * Read a compatible pointer, or return null to trigger regeneration.
     *
     * @return array{class: string, body: string, map: string, sig: string|null}|null
     */
    private function readPointer(string $dir, string $className): ?array
    {
        $path = $this->pointerPath($dir, $className);

        clearstatcache(true, $path);
        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = @json_decode($raw, true);

        if (!is_array($data)
            || ($data['v'] ?? null) !== self::GENERATOR_VERSION
            || ($data['class'] ?? null) !== $className
            || !is_string($data['body'] ?? null)
            || $data['body'] === ''
        ) {
            return null;
        }

        $sig = $data['sig'] ?? null;
        $map = $data['map'] ?? null;

        return [
            'class' => $className,
            'body'  => $data['body'],
            'map'   => is_string($map) ? $map : '',
            'sig'   => is_string($sig) ? $sig : null,
        ];
    }

    /**
     * Atomically update a target class's pointer when its contents change.
     *
     * Pointers are data, not executable code. Every successful load updates the
     * pointer to the body actually loaded.
     *
     * @param class-string $proxyClass The class the body declares.
     */
    private function writePointer(
        string $dir,
        string $className,
        string $body,
        ?string $signature,
        string $proxyClass,
    ): void {
        $path = $this->pointerPath($dir, $className);

        $payload = (string) json_encode([
            'v'     => self::GENERATOR_VERSION,
            'class' => $className,
            'body'  => $body,
            'map'   => $this->interceptorKey(),
            'sig'   => $signature,
            'proxy' => $proxyClass,
        ]);

        clearstatcache(true, $path);

        if (@file_get_contents($path) === $payload) {
            return;
        }

        $tmp = @tempnam($dir, 'azera_aop_ptr_');

        if ($tmp === false || @file_put_contents($tmp, $payload, LOCK_EX) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }

            // A failed pointer write only causes repeated regeneration; keep the proxy usable.
            return;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return;
        }

        clearstatcache(true, $path);
    }

    /**
     * Atomically write a proxy and backdate it for immediate OPcache caching.
     *
     * Content-addressed paths are immutable; invalidate only when replacing a
     * mismatched existing file.
     */
    private function writeProxyFile(string $cacheFile, string $dir, string $contents, bool $invalidate = false): void
    {
        if ($invalidate && function_exists('opcache_invalidate')) {
            // Invalidate before replacing the bytes at this path.
            @opcache_invalidate($cacheFile, true);
        }

        $tmp = @tempnam($dir, 'azera_aop_');

        if ($tmp === false) {
            throw new \RuntimeException("Unable to create a temporary file in {$dir}");
        }

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to write AOP proxy file: {$cacheFile}");
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $cacheFile)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to move AOP proxy file into place: {$cacheFile}");
        }

        clearstatcache(true, $cacheFile);
        $this->backdateForOpcache($cacheFile);
    }

    /**
     * Remove unreferenced proxy bodies and dangling pointers.
     *
     * All pointers in the directory define the live set, so pruning works from a
     * cold CLI and across applications. Only `Proxy_<hex>.php` files are candidates;
     * invalidate OPcache before deleting bodies. Keep uncertain files.
     *
     * @param int $olderThanSeconds Skip files modified more recently than this.
     * @return array{removed: int, kept: int, reason?: string}
     */
    public function prune(int $olderThanSeconds = 0): array
    {
        $dir = $this->cacheDir;

        if ($dir === null || !is_dir($dir)) {
            return ['removed' => 0, 'kept' => 0, 'reason' => 'no AOP proxy cache directory'];
        }

        // Collect bodies referenced by pointers in this directory.
        $referenced = [];
        $pointers   = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'Proxy_*.ptr') ?: [] as $pointerPath) {
            $raw  = @file_get_contents($pointerPath);
            $data = is_string($raw) && $raw !== '' ? @json_decode($raw, true) : null;

            if (!is_array($data) || !is_string($data['body'] ?? null) || $data['body'] === '') {
                // Discard malformed pointers; they cannot identify a live body.
                $pointers[$pointerPath] = null;
                continue;
            }

            $referenced[$data['body']] = true;
            $pointers[$pointerPath]     = $data['body'];
        }

        $cutoff  = $olderThanSeconds > 0 ? time() - $olderThanSeconds : null;
        $removed = 0;
        $kept    = 0;

        // Bodies nothing points at.
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'Proxy_*.php') ?: [] as $path) {
            $file = basename($path);

            if (!preg_match('/^Proxy_[0-9a-f]{8,64}\.php$/', $file)) {
                continue; // Not a body filename generated here.
            }

            if (isset($referenced[$file])) {
                $kept++;
                continue;
            }

            if ($cutoff !== null) {
                $mtime = @filemtime($path);

                if ($mtime !== false && $mtime > $cutoff) {
                    $kept++;
                    continue;
                }
            }

            // Invalidate before deletion so OPcache can release the shared page.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }

            if (@unlink($path)) {
                $removed++;
            } else {
                $kept++;
            }
        }

        // Remove dangling pointers; they can only trigger regeneration.
        foreach ($pointers as $pointerPath => $body) {
            if ($body !== null && is_file($dir . DIRECTORY_SEPARATOR . $body)) {
                continue;
            }

            @unlink($pointerPath);
        }

        if ($removed === 0 && $kept === 0 && $referenced === []) {
            return ['removed' => 0, 'kept' => 0, 'reason' => 'no proxies recorded in ' . $dir];
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * Return a stable identity for the registered advice classes.
     *
     * Sorting makes the key independent of registration order; generated method
     * bodies preserve advice declaration order.
     */
    private function interceptorKey(): string
    {
        $advice = array_keys($this->interceptors);
        sort($advice);

        return implode(',', $advice);
    }

    /**
     * Return the process-wide key, including the advice map.
     *
     * Different maps can generate proxies with different advised methods.
     */
    private function registryKey(string $className): string
    {
        return $this->namespace() . "\0" . $className . "\0" . $this->interceptorKey();
    }

    /**
     * Hash the generator version, target class, and emitted method body.
     */
    private function proxyDigest(string $className, string $methodsCode): string
    {
        return hash('xxh3', self::GENERATOR_VERSION . "\0" . $className . "\0" . $methodsCode);
    }

    /**
     * Derive the declared class name so the loader can check it without reading the file.
     */
    private function proxyClassNameFor(string $className, string $digest): string
    {
        return 'AzeraAopProxy_' . str_replace('\\', '_', $className) . '_' . $digest;
    }

    /**
     * Backdate new files past OPcache's protection window for immediate caching.
     */
    private function backdateForOpcache(string $file): void
    {
        $window = @ini_get('opcache.file_update_protection');

        // OPcache is unavailable; no backdating is needed.
        if ($window === false) {
            return;
        }

        @touch($file, time() - max(0, (int) $window) - 1);
        clearstatcache(true, $file);
    }

    /**
     * Reject cache directories writable by other users.
     *
     * @todo Move this to a security review task
     */
    private function assertSafeCacheDir(string $dir): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            // POSIX mode bits are unavailable on Windows; tempnam provides the control.
            return;
        }

        $perms = @fileperms($dir);
        if ($perms === false) {
            throw new \RuntimeException("Unable to stat AOP proxy cache directory: {$dir}");
        }

        if (($perms & 0x0022) !== 0) {
            throw new \RuntimeException(
                "AOP proxy cache directory is group/world writable, refusing to load executable code from it: {$dir}"
            );
        }
    }

    /**
     * Collect advised public and protected methods declared by the target class.
     *
     * Advice arguments are read at build time and embedded in generated source.
     *
     * @return array<string, array{method: ReflectionMethod, advices: array<class-string<Advice>, array<string, mixed>>}>
     */
    private function collectAdvisedMethods(ReflectionClass $ref): array
    {
        $advised = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if ($method->isStatic()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            /** @var array<class-string<Advice>, array<string, mixed>> $matchingAdvices */
            $matchingAdvices = [];

            foreach ($method->getAttributes() as $attr) {
                $attrClass = $attr->getName();

                if (!is_subclass_of($attrClass, Advice::class) && $attrClass !== Advice::class) {
                    continue;
                }

                if (isset($this->interceptors[$attrClass])) {
                    $matchingAdvices[$attrClass] = $this->adviceArguments(
                        $attr,
                        $attrClass,
                        $ref->getName(),
                        $method->getName(),
                    );
                }
            }

            if ($matchingAdvices !== []) {
                if ($method->isFinal() && $ref->isFinal()) {
                    // Report this before the generated proxy fails to load.
                    throw new \RuntimeException(
                        "Cannot apply AOP advice to {$ref->getName()}::{$method->getName()}:"
                        . ' the class and the method are both final, so no proxy can override it.'
                    );
                }
                if ($method->isFinal()) {
                    // Report the attributed final method before proxy generation.
                    throw new \RuntimeException(
                        "Cannot apply AOP advice to {$ref->getName()}::{$method->getName()}:"
                        . ' the method is final and cannot be overridden by a proxy.'
                        . ' Remove `final`, or call the logic directly instead of via an advice attribute.'
                    );
                }

                $advised[$method->getName()] = [
                    'method'  => $method,
                    'advices' => $matchingAdvices,
                ];
            }
        }

        if ($advised !== [] && $ref->isFinal()) {
            throw new \RuntimeException(
                "Cannot apply AOP advice to {$ref->getName()}: the class is final, so no proxy"
                . ' can extend it. Remove `final`, or call the logic directly instead of via an advice attribute.'
            );
        }

        return $advised;
    }

    /**
     * Read and validate advice arguments for generated source.
     *
     * Preserve named and positional forms. Values must round-trip as stable PHP
     * literals because they affect the proxy digest; reject objects whose
     * `var_export()` output may require an unavailable `__set_state()`.
     *
     * @param \ReflectionAttribute<object> $attribute The advice attribute.
     * @param class-string<Advice>         $adviceClass The advice class it instantiates.
     * @param string                       $class  Target class, for error messages.
     * @param string                       $method Target method, for error messages.
     * @return array<string|int, mixed> Constructor arguments, ready to spread.
     */
    private function adviceArguments(
        \ReflectionAttribute $attribute,
        string $adviceClass,
        string $class,
        string $method,
    ): array {
        $arguments = $attribute->getArguments();

        foreach ($arguments as $name => $value) {
            if (self::isExportable($value)) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Cannot bake the arguments of advice [%s] on %s::%s() into a proxy: $%s holds %s, which '
                . 'cannot be expressed as a stable PHP literal in the generated proxy. Attribute arguments '
                . 'must be scalars, arrays, enum cases, class constants or null.',
                $adviceClass,
                $class,
                $method,
                (string) $name,
                get_debug_type($value),
            ));
        }

        return $arguments;
    }

    /**
     * Return whether a value is a stable PHP literal supported by the proxy.
     *
     * Scalars, null, enums, and arrays of supported values are accepted; other
     * objects are rejected.
     */
    private static function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof \UnitEnum) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isExportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Generate method overrides for eval or file-based proxies.
     *
     * Embedded advice arguments make attribute changes alter the body digest.
     */
    private function generateProxyCode(ReflectionClass $ref, array $advisedMethods): string
    {
        $methods = [];
        foreach ($advisedMethods as $info) {
            $methods[] = $this->generateMethodOverride($info['method'], $info['advices']);
        }

        return implode("\n\n", $methods);
    }

    /** Build the source for an anonymous eval-based proxy. */
    private function buildEvalCode(string $parentClass, string $methodsCode): string
    {
        return "return new class extends \\{$parentClass} {\n{$methodsCode}\n};";
    }

    /**
     * Build a file-ready PHP source string for a named proxy class.
     *
     * @param string $proxyClassName  The named class to declare.
     * @param string $parentClass     The target class to extend.
     * @param string $methodsCode     Generated method overrides.
     * @return string Complete PHP file contents (with <?php).
     */
    private function buildFileCode(string $proxyClassName, string $parentClass, string $methodsCode): string
    {
        // Prefix the fully qualified parent name for global namespace resolution.
        return "<?php\n"
            . "class {$proxyClassName} extends \\{$parentClass}\n"
            . "{\n{$methodsCode}\n}\n"
            . "return '{$proxyClassName}';\n";
    }

    /**
     * Generate an advised override that uses the memoized per-method plan.
     *
     * Create the parent-call terminal per invocation and declare no proxy
     * properties, preserving clone, serialization, and property-collision safety.
     * Preserve argument references; invoke void pipelines without `return`.
     */
    private function generateMethodOverride(ReflectionMethod $method, array $advices): string
    {
        $methodName    = $method->getName();
        $targetClass   = $method->getDeclaringClass()->getName();
        $params        = $this->generateParameterList($method);
        $argsArray     = $this->generateArgsArray($method);
        $returnType    = $this->generateReturnType($method);
        $isVoid        = $this->isVoidMethod($method);
        $namespace     = var_export($this->namespace(), true);
        $adviceMap     = $this->generateAdviceMap($advices);
        $parameterList = $this->generateParameterNameList($method);

        $handle = "        \$__plan = \\Azera\\Aop\\AopRuntime::plan(\n"
            . "            namespace: {$namespace},\n"
            . "            class: " . var_export($targetClass, true) . ",\n"
            . "            method: " . var_export($methodName, true) . ",\n"
            . "            advice: {$adviceMap},\n"
            . "            parameterNames: {$parameterList},\n"
            . "        );\n"
            . "        \$__invocation = new \\Azera\\Aop\\Invocation(\n"
            . "            target: \$this,\n"
            . "            method: " . var_export($methodName, true) . ",\n"
            . "            identity: " . var_export($targetClass . '::' . $methodName, true) . ",\n"
            . "            args: {$argsArray},\n"
            . "            advice: \$__plan->advice,\n"
            . "            parameterNames: \$__plan->parameterNames,\n"
            . "        );\n"
            . "        \$__invocation->attributes[\\Azera\\Aop\\Pipeline::ATTR_CALLABLE] = fn(&...\$__a) => parent::{$methodName}(...\$__a);\n";

        $body = $isVoid
            ? $handle . "        \$__plan->pipeline->handle(\$__invocation);"
            : $handle . "        return \$__plan->pipeline->handle(\$__invocation);";

        return <<<PHP
    public function {$methodName}({$params}){$returnType}
    {
{$body}
    }
PHP;
    }

    /**
     * Emit advice classes and arguments in declaration order.
     *
     * Order determines pipeline nesting (the first advice is outermost).
     *
     * @param array<class-string<Advice>, array<string|int, mixed>> $advices
     */
    private function generateAdviceMap(array $advices): string
    {
        $entries = [];

        foreach ($advices as $adviceClass => $arguments) {
            $entries[] = var_export($adviceClass, true) . ' => ' . $this->exportArguments($arguments);
        }

        return '[' . implode(', ', $entries) . ']';
    }

    /**
     * Render constructor arguments, preserving named keys and positional order.
     *
     * @param array<string|int, mixed> $arguments
     */
    private function exportArguments(array $arguments): string
    {
        $parts = [];

        foreach ($arguments as $name => $value) {
            $parts[] = is_int($name)
                ? $this->exportValue($value)
                : var_export((string) $name, true) . ' => ' . $this->exportValue($value);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Render a stable, single-line PHP literal.
     *
     * Compose arrays directly and use `var_export()` for scalar escaping; do not
     * normalize its output, which could alter whitespace inside strings.
     * Values must pass {@see self::isExportable()}.
     */
    private function exportValue(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map($this->exportValue(...), $value)) . ']';
            }

            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = (is_int($key) ? (string) $key : var_export((string) $key, true))
                    . ' => ' . $this->exportValue($item);
            }

            return '[' . implode(', ', $parts) . ']';
        }

        // var_export() emits scalars, null, and enum cases as stable literals.
        return var_export($value, true);
    }

    /** Return declared parameter names for cache-key placeholders. */
    private function generateParameterNameList(ReflectionMethod $method): string
    {
        $names = [];

        foreach ($method->getParameters() as $parameter) {
            $names[] = var_export($parameter->getName(), true);
        }

        return '[' . implode(', ', $names) . ']';
    }

    private function isVoidMethod(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();
        return $type instanceof \ReflectionNamedType && $type->getName() === 'void';
    }

    private function generateParameterList(ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $parts = [];

            $type = $param->getType();
            if ($type !== null) {
                $parts[] = $this->renderType($type);
            }
            if ($param->isPassedByReference()) {
                $parts[] = '&';
            }
            if ($param->isVariadic()) {
                $parts[] = '...';
            }
            $parts[] = '$' . $param->getName();

            if ($param->isDefaultValueAvailable()) {
                $parts[] = '= ' . var_export($param->getDefaultValue(), true);
            } elseif ($param->allowsNull() && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $parts[] = '= null';
            }

            $params[] = implode(' ', $parts);
        }
        return implode(', ', $params);
    }

    private function generateArgsArray(ReflectionMethod $method): string
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if ($param->isVariadic()) {
                // Spreading the variadic preserves references for the terminal call.
                $args[] = '...$' . $name;
            } elseif ($param->isPassedByReference()) {
                // Preserve the reference so mutations reach the caller.
                $args[] = '&$' . $name;
            } else {
                $args[] = '$' . $name;
            }
        }
        return '[' . implode(', ', $args) . ']';
    }

    private function generateReturnType(ReflectionMethod $method): string
    {
        $type = $method->getReturnType();
        if ($type === null) {
            return '';
        }
        return ': ' . $this->renderType($type);
    }

    private function renderType(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
                return '?' . $name;
            }
            return $name;
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map($this->renderType(...), $type->getTypes()));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map($this->renderType(...), $type->getTypes()));
        }
        return '';
    }

    /** Return the interceptor registered for an advice class. */
    public function getInterceptor(string $adviceClass): InterceptorInterface
    {
        return $this->interceptors[$adviceClass];
    }
}
