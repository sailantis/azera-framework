<?php

namespace Azera\Aop;

use ReflectionClass;
use ReflectionMethod;

/**
 * Creates proxy classes that wrap advised methods with interceptor chains.
 *
 * The proxy is an anonymous class that extends the target class. It IS
 * the instance — no separate target object, no state mismatch. Overridden
 * methods call `parent::method()` to reach the original implementation.
 *
 * Methods without advice attributes are NOT overridden — they call the
 * parent implementation directly with zero overhead.
 *
 * In-process caching: `buildProxyClass()` memoises per target class, and the
 * proxy class is looked up with `class_exists(..., false)` before any file I/O.
 *
 * On-disk caching (when a cache dir is set): the proxy is written once per
 * (target class, generated body, generator version) and `require`d thereafter.
 * The class name is DETERMINISTIC, which is what lets OPcache serve the file
 * instead of recompiling it on every cold request — see `loadProxyClass()` for
 * why a stable name is safe here and how a planted file is rejected.
 *
 * Cost when no interceptors are registered: zero (AppContext::build() never
 * calls ProxyFactory).
 * Cost when interceptors are registered but class has no #[Advised]: zero
 * (AppContext::build() checks the class-level attribute first).
 * Cost when class has #[Advised] but no advised methods: one-time
 * ReflectionMethod scan, then the raw target is returned — no proxy.
 */
class ProxyFactory
{
    /**
     * Bumped whenever the generated code changes shape.
     *
     * It is folded into the proxy class name, so an upgrade that alters the
     * emitted overrides produces a DIFFERENT class name. Long-running processes
     * that already loaded the old proxy therefore cannot collide with the new
     * one, and stale files on disk are simply never matched again.
     *
     * v2: the class name became deterministic (it used to be randomised).
     * v3: the emitted body is straight-line explicit AOP — interceptors are
     *     resolved once and invoked directly, with no per-call chain building
     *     and no per-call ReflectionMethod. Proxy argument arrays now carry
     *     references, so by-reference parameters are no longer silently copied.
     * v4: the emitted body no longer resolves interceptors at all. Each advised
     *     method builds one Invocation and runs the precomposed Pipeline that
     *     AopRuntime memoizes per (class, method); advice attributes are read
     *     once per process by AopRuntime, not per call.
     * v5: per-instance terminal closures were replaced by a runtime-held, per-instance
     *     terminal store. A closure cached on the proxy was bound to the instance that
     *     first called the method, so a `clone` inherited a terminal bound to the
     *     original (and the proxy stopped being serializable). Proxies now bake an
     *     interceptor NAMESPACE into the emitted source as well, so two factories in
     *     one process no longer overwrite each other's registry.
     */
    private const GENERATOR_VERSION = 5;

    /** @var array<class-string<Advice>, InterceptorInterface> */
    private array $interceptors = [];

    /**
     * Namespace this factory publishes its interceptors under.
     *
     * Baked into every generated proxy so the proxy resolves the registry entry that
     * belongs to THIS factory. Derived from the cache directory, so a cached proxy
     * file keeps working in the process that loads it later (the factory there
     * publishes under the same directory), and two factories with different cache
     * directories never share an entry.
     */
    private ?string $namespace = null;

    /** @var array<string, class-string|null> */
    private array $proxyClassCache = [];

    /** @var array<string, class-string> In-process registry: target class => loaded proxy class */
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
        AopRuntime::setInterceptor($this->namespace(), $adviceClass, $interceptor);
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
        return $this->namespace ??= 'factory:' . ($this->cacheDir ?? 'eval');
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

        // The namespace is derived from the directory and baked into generated
        // source, so a directory change must also move an already-registered map
        // and discard any proxy cached under the old namespace.
        $this->namespace = 'factory:' . ($this->cacheDir ?? 'eval');
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
     * The cache file this factory would use for $ref, or null when nothing would
     * be written (no cache dir, or the class has no advised methods).
     *
     * Public so deployment tooling can warm or prune the proxy cache without
     * re-deriving the naming scheme, and so tests can plant a file at the exact
     * path the loader will look at.
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
     * Build the proxy class for a target class.
     *
     * Returns the proxy class name (a class extending the target), or null
     * if no methods need interception. In file-based mode, the class is
     * written to disk and `require`d — OPcache caches it. In eval mode
     * (development), an anonymous class is created inline.
     *
     * @return class-string|null
     */
    public function buildProxyClass(ReflectionClass $ref): ?string
    {
        $className = $ref->getName();

        if (array_key_exists($className, $this->proxyClassCache)) {
            return $this->proxyClassCache[$className];
        }

        $advisedMethods = $this->collectAdvisedMethods($ref);

        if ($advisedMethods === []) {
            return $this->proxyClassCache[$className] = null;
        }

        // Both caches are keyed by namespace + class: the namespace is baked into
        // the emitted source, so two factories with different cache directories
        // produce DIFFERENT proxy classes for the same target. Keying on the class
        // name alone would hand the second factory the first factory's proxy, and
        // that proxy resolves plans under the first factory's namespace.
        $cacheKey = $this->namespace() . "\0" . $className;

        // In-process cache: already loaded in this PHP process?
        if (isset(self::$loadedProxies[$cacheKey])) {
            return $this->proxyClassCache[$className] = self::$loadedProxies[$cacheKey];
        }

        $methodsCode = $this->generateProxyCode($ref, $advisedMethods);

        if ($this->cacheDir !== null) {
            // File-based: reuse the cached file when it already describes this
            // proxy, otherwise write it atomically and load it.
            $proxyClass = $this->loadProxyClass($className, $methodsCode);
        } else {
            // Eval-based: development mode, anonymous class, no cache dir.
            $evalCode   = $this->buildEvalCode($className, $methodsCode);
            $instance   = eval($evalCode);
            $proxyClass = get_class($instance);
        }

        self::$loadedProxies[$cacheKey] = $proxyClass;
        return $this->proxyClassCache[$className] = $proxyClass;
    }

    /**
     * Load the generated proxy class for a target class, reusing the cache file
     * when it already describes exactly this proxy.
     *
     * WHY THE FILE IS KEYED ON THE GENERATED SOURCE, NOT THE CLASS NAME.
     * This used to write `Proxy_<md5(class)>.php` with a class named
     * `..._<random_bytes(8)>` and `require` it unconditionally, every process.
     * Because the declared class name changed on every write, OPcache could
     * never serve the file, so each cold process recompiled it instead of
     * loading it.
     *
     * The measured size depends on the platform, and it is easy to overstate:
     *
     *   NTFS (Windows dev box)   rewrite ~18-31 ms, reuse ~0.5-1.0 ms
     *   Linux (bench VM)         rewrite ~0.28-0.46 ms, reuse ~0.14-0.18 ms
     *
     * On Linux the write is a small fraction of a millisecond, so this change is
     * NOT what makes a request slow there — the ~12 ms first-touch class-autoload
     * floor dominates any per-request proxy work, and in a 1000-iteration mean
     * either variant is under 0.5 us per request. The fix removes a real defect
     * (an uncacheable file) without changing any published benchmark row.
     *
     * The class name is now derived from (target class, generated body,
     * generator version), so it is stable for a given proxy and changes
     * automatically when the generated code does. The filename carries the
     * same digest, which makes the reuse check cheap and exact.
     *
     * The class name is now derived from (target class, generated body,
     * generator version), so it is stable for a given proxy and changes
     * automatically when the generated code does. The filename carries the
     * same digest, which makes the reuse check cheap and exact.
     *
     * WHY THE CONTENT IS STILL VERIFIED. A stable, predictable filename means
     * another local user could plant a file there (`/tmp` is world-writable).
     * Trusting the name alone would turn that into arbitrary code execution, so
     * every reuse compares a digest of the stored body against the body we would
     * generate, and any mismatch falls through to the (atomic, overwriting)
     * write path. A planted file is therefore never required: it is replaced.
     *
     * @param string $className   The target class FQCN.
     * @param string $methodsCode Generated method overrides (class body only).
     * @return class-string The loaded proxy class name.
     */
    private function loadProxyClass(string $className, string $methodsCode): string
    {
        $shortName      = str_replace('\\', '_', $className);
        $proxyClassName = "AzeraAopProxy_{$shortName}_" . $this->proxyDigest($className, $methodsCode);

        $fileContents = $this->buildFileCode($proxyClassName, $className, $methodsCode);

        // Already declared in this process (another factory instance, or a
        // caller that required the file itself): nothing to read or write.
        if (class_exists($proxyClassName, false)) {
            return $proxyClassName;
        }

        $dir = $this->cacheDir;
        \assert($dir !== null);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create AOP proxy cache directory: {$dir}");
        }

        $this->assertSafeCacheDir($dir);

        $cacheFile = $dir . DIRECTORY_SEPARATOR . 'Proxy_' . $this->proxyDigest($className, $methodsCode) . '.php';

        if ($this->isReusableProxyFile($cacheFile, $fileContents)) {
            /** @var class-string $loaded */
            $loaded = require $cacheFile;

            if ($loaded === $proxyClassName) {
                return $proxyClassName;
            }

            // The file verified but returned something else (corrupt tail, or a
            // race with a concurrent rewrite). Fall through and write ours.
        }

        return $this->writeAndLoadProxyFile($cacheFile, $dir, $fileContents, $proxyClassName);
    }

    /**
     * True when $cacheFile already holds exactly $expected and can be required.
     *
     * Compares the WHOLE body rather than just its shape: the file declares the
     * class we are about to `require`, so anything else living at that path is
     * executable code we must not run.
     */
    private function isReusableProxyFile(string $cacheFile, string $expected): bool
    {
        if (!is_file($cacheFile)) {
            return false;
        }

        // clearstatcache first: a long-running process that already stat()ed a
        // path (including a failed is_file) would otherwise answer from cache.
        clearstatcache(true, $cacheFile);

        $stored = @file_get_contents($cacheFile);

        return $stored !== false && $stored === $expected;
    }

    /**
     * Write the proxy file atomically and load it.
     *
     * Follows the Clarity Engine pattern: write to a temp file, rename
     * atomically, clearstatcache + opcache_invalidate, then require.
     * The file returns the class name as its last statement.
     */
    private function writeAndLoadProxyFile(
        string $cacheFile,
        string $dir,
        string $fileContents,
        string $proxyClassName,
    ): string {
        // Atomic write: unique temp file in the same directory + rename, so a
        // partially written file is never required and concurrent writers do
        // not clobber each other.
        $tmp = @tempnam($dir, 'azera_aop_');
        if ($tmp === false) {
            throw new \RuntimeException("Unable to create a temporary file in {$dir}");
        }

        if (file_put_contents($tmp, $fileContents, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to write AOP proxy file: {$cacheFile}");
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $cacheFile)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to move AOP proxy file into place: {$cacheFile}");
        }

        clearstatcache(true, $cacheFile);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }

        // Only ever load the file we just wrote successfully.
        /** @var class-string $loaded */
        $loaded = require $cacheFile;

        return $loaded;
    }

    /**
     * Stable identity for one generated proxy.
     *
     * Folds in the generator version so a code-shape change produces a new name,
     * and the source digest so an edited attribute set does too. `sha256` rather
     * than `md5` because a collision here would make one proxy stand in for
     * another.
     */
    private function proxyDigest(string $className, string $methodsCode): string
    {
        return substr(
            hash('sha256', self::GENERATOR_VERSION . "\0" . $className . "\0" . $methodsCode),
            0,
            16,
        );
    }

    /**
     * Refuse to load proxies from a directory that other users can write to.
     * @todo Move this to a security review task
     */
    private function assertSafeCacheDir(string $dir): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            // Windows has no POSIX mode bits to inspect; ACLs are not
            // readable portably here. The unique-tempnam write below is the
            // control that matters.
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
     * Collect all public/protected methods declared in this class that have
     * at least one Advice attribute with a matching interceptor.
     *
     * @return array<string, array{method: ReflectionMethod, advices: array<class-string<Advice>>}>
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

            $matchingAdvices = [];

            foreach ($method->getAttributes() as $attr) {
                $attrClass = $attr->getName();

                if (!is_subclass_of($attrClass, Advice::class) && $attrClass !== Advice::class) {
                    continue;
                }

                if (isset($this->interceptors[$attrClass])) {
                    $matchingAdvices[] = $attrClass;
                }
            }

            if ($matchingAdvices !== []) {
                if ($method->isFinal() && $ref->isFinal()) {
                    // Both are final: nothing about this can be intercepted, and
                    // saying so here beats a fatal during require().
                    throw new \RuntimeException(
                        "Cannot apply AOP advice to {$ref->getName()}::{$method->getName()}:"
                        . ' the class and the method are both final, so no proxy can override it.'
                    );
                }
                if ($method->isFinal()) {
                    // A generated override of a final method is a fatal error in
                    // the emitted file, reported with no reference to the attribute
                    // that caused it.
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
     * Generate the proxy class body (method overrides only).
     * Used by both eval and file-based generation.
     */
    private function generateProxyCode(ReflectionClass $ref, array $advisedMethods): string
    {
        $methods = [];
        foreach ($advisedMethods as $info) {
            $methods[] = $this->generateMethodOverride($info['method'], $info['advices']);
        }

        // Per-(class, method) process-global plan: precomposed pipeline plus
        // the baked advice map. Built on first call by AopRuntime, memoized
        // thereafter — nothing is derived per call.
        return implode("\n\n", $methods);
    }

    /**
     * Build the eval-ready code string for an anonymous proxy class.
     */
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
        // $parentClass already contains single backslashes for namespaces
        // (e.g. "Azera\Tests\Aop\RetryService"). Prefix with \ for the
        // global namespace — no addslashes needed.
        return "<?php\n"
            . "class {$proxyClassName} extends \\{$parentClass}\n"
            . "{\n{$methodsCode}\n}\n"
            . "return '{$proxyClassName}';\n";
    }

    /**
     * Generate the override for a single advised method.
     *
     * The emitted body builds one {@see Invocation} per call and hands it to the
     * process-wide precomposed {@see AopPlan} for this (class, method) — the
     * interceptors, the advice map, the parameter names and the registry namespace
     * are resolved once per process by {@see AopRuntime::plan()}, never per call.
     *
     * The terminal (`parent::method(...)`) is allocated per call, deliberately.
     * Caching it on the proxy instance was a defect with three faces:
     *
     * - **`clone`.** The cached closure was bound to the instance that first called
     *   the method, so a clone inherited a terminal bound to the ORIGINAL. An advised
     *   method on the clone then ran `parent::method` against the original object.
     * - **Serialization.** After the first advised call the property held a Closure,
     *   so the proxy could no longer be serialized (session, queue, cache payloads) —
     *   a regression against the closure-free object it used to be.
     * - **Property collisions.** The generated property name was derived from the
     *   method name, so a target class that already declared it produced a fatal
     *   "access level must not be reduced" error in the emitted file.
     *
     * The proxy therefore declares no properties at all, which makes it clone-safe,
     * serializable, and immune to name collisions without generating `__clone` /
     * `__serialize` hooks — hooks that cannot be emitted correctly in general,
     * because a parent class may already define them with its own payload shape.
     *
     * Two details that are easy to get wrong and were both wrong before:
     *
     * - **By-reference parameters.** The old body collected arguments as values
     *   (`[$x, $arr]`), so a `&$array` parameter arrived as a copy and mutations
     *   were silently lost. References are now carried explicitly
     *   (`[$x, &$arr]`, `...$rest`), and PHP preserves reference elements when the
     *   array is spread into the by-reference variadic terminal.
     * - **`void` methods.** A `return expr;` in a void function is a fatal
     *   error, so void overrides invoke the chain as a statement.
     */
    private function generateMethodOverride(ReflectionMethod $method, array $adviceClasses): string
    {
        $methodName    = $method->getName();
        $targetClass   = $method->getDeclaringClass()->getName();
        $params        = $this->generateParameterList($method);
        $argsArray     = $this->generateArgsArray($method);
        $returnType    = $this->generateReturnType($method);
        $isVoid        = $this->isVoidMethod($method);
        $adviceList    = implode(', ', array_map(
            static fn(string $advice): string => var_export($advice, true),
            array_values($adviceClasses),
        ));
        $namespace     = var_export($this->namespace(), true);

        $handle = "        \$__plan = \\Azera\\Aop\\AopRuntime::plan({$namespace}, " . var_export($targetClass, true) . ", "
            . var_export($methodName, true) . ", [{$adviceList}]);\n"
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
                // A by-reference variadic (`&...$refs`) IS legal PHP, and each
                // element of the variadic array is a live reference to the caller's
                // variable. Spreading it into the args array literal keeps those
                // references, so mutations reach the caller exactly as for a single
                // `&$x` parameter. (The old comment claimed variadics could not be
                // by-reference and dropped the reference; spreading an empty
                // variadic is a no-op, so this is also the correct shape for a
                // by-value variadic.)
                $args[] = '...$' . $name;
            } elseif ($param->isPassedByReference()) {
                // Carry the reference, not a copy. The old generator emitted
                // "$arr", so a &$array parameter reached the parent method as a
                // value and every mutation was silently discarded.
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

    /**
     * The interceptor registered for one advice class.
     *
     * Exposed for diagnostics and tests; generated proxies resolve their plan
     * through {@see AopRuntime} instead.
     */
    public function getInterceptor(string $adviceClass): InterceptorInterface
    {
        return $this->interceptors[$adviceClass];
    }
}