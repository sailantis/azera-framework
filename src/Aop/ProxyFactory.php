<?php

namespace Azera\Aop;

use ReflectionClass;
use ReflectionMethod;
use Swoole\Server\Task;

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
 * Proxy class names are cached in an array keyed by class name, so repeated
 * `build()` calls for the same class reuse the same proxy class.
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
    /** @var array<class-string<Advice>, InterceptorInterface> */
    private array $interceptors = [];

    /** @var array<string, class-string|null> */
    private array $proxyClassCache = [];

    /** @var array<string, class-string> In-process registry: target class => loaded proxy class */
    private static array $loadedProxies = [];

    /** Cache directory for file-based proxy generation. Null = use eval(). */
    private ?string $cacheDir = null;

    public function register(string $adviceClass, InterceptorInterface $interceptor): void
    {
        $this->interceptors[$adviceClass] = $interceptor;
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

        // In-process cache: already loaded in this PHP process?
        if (isset(self::$loadedProxies[$className])) {
            return $this->proxyClassCache[$className] = self::$loadedProxies[$className];
        }

        $methodsCode = $this->generateProxyCode($ref, $advisedMethods);

        if ($this->cacheDir !== null) {
            // File-based: write to disk, require, OPcache caches it.
            $proxyClass = $this->writeAndLoadProxyFile($className, $methodsCode);
        } else {
            // Eval-based: development mode, anonymous class, no cache dir.
            $evalCode   = $this->buildEvalCode($className, $methodsCode);
            $instance   = eval($evalCode);
            $proxyClass = get_class($instance);
        }

        self::$loadedProxies[$className] = $proxyClass;
        return $this->proxyClassCache[$className] = $proxyClass;
    }

    /**
     * Write a generated proxy class to the cache directory and load it.
     *
     * Follows the Clarity Engine pattern: write to a temp file, rename
     * atomically, clearstatcache + opcache_invalidate, then require.
     * The file returns the class name as its last statement.
     *
     * @param string $className   The target class FQCN.
     * @param string $methodsCode Generated method overrides (class body only).
     * @return class-string The loaded proxy class name.
     */
    private function writeAndLoadProxyFile(string $className, string $methodsCode): string
    {
        $cacheFile = $this->proxyFilePath($className);
        $dir       = dirname($cacheFile);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create AOP proxy cache directory: {$dir}");
        }

        // Generate a versioned class name so recompilations don't collide
        // with previously loaded classes in long-running processes.
        $shortName      = str_replace('\\', '_', $className);
        $proxyClassName = "AzeraAopProxy_{$shortName}_" . bin2hex(random_bytes(8));

        // Build the full PHP file: named class extending the target.
        $fileContents = $this->buildFileCode($proxyClassName, $className, $methodsCode);

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
        return require $cacheFile;
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
     * Get the cache file path for a proxy class.
     */
    private function proxyFilePath(string $className): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR
            . 'Proxy_' . md5($className) . '.php';
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
                $advised[$method->getName()] = [
                    'method'  => $method,
                    'advices' => $matchingAdvices,
                ];
            }
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
     * The proxy IS the instance, so the innermost handler calls
     * parent::method() directly — no separate target object.
     */
    private function generateMethodOverride(ReflectionMethod $method, array $adviceClasses): string
    {
        $methodName      = $method->getName();
        $params          = $this->generateParameterList($method);
        $argsArray       = $this->generateArgsArray($method);
        $returnType      = $this->generateReturnType($method);
        $isVoid          = $this->isVoidMethod($method);
        $returnStatement = $isVoid ? "\$chain(\$args);" : "return \$chain(\$args);";

        $chainArray = implode(', ', array_map(fn($c) => "'{$c}'", $adviceClasses));

        return <<<PHP
    public function {$methodName}({$params}){$returnType}
    {
        \$factory = \\Azera\\Aop\\ProxyFactory::current();
        \$ref = new \\ReflectionMethod(parent::class, '{$methodName}');
        \$args = {$argsArray};

        \$adviceClasses = [{$chainArray}];
        \$interceptors = [];
        foreach (\$adviceClasses as \$adviceClass) {
            \$interceptors[] = \$factory->getInterceptor(\$adviceClass);
        }

        // Innermost handler: call parent::method()
        \$chain = function (array \$a) {
            return parent::{$methodName}(...\$a);
        };

        // Wrap interceptors in reverse order (first registered = outermost)
        for (\$i = count(\$interceptors) - 1; \$i >= 0; \$i--) {
            \$interceptor = \$interceptors[\$i];
            \$chain = function (array \$a) use (\$interceptor, \$ref, \$chain) {
                return \$interceptor->intercept(\$this, \$ref, \$a, \$chain);
            };
        }

        {$returnStatement}
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
                $args[] = "...\\\${$name}";
            } else {
                $args[] = "\${$name}";
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

    // --- Static registry ---

    private static ?ProxyFactory $current = null;

    public static function setCurrent(?ProxyFactory $factory): void
    {
        self::$current = $factory;
    }

    public static function current(): ?ProxyFactory
    {
        return self::$current;
    }

    public function getInterceptor(string $adviceClass): InterceptorInterface
    {
        return $this->interceptors[$adviceClass];
    }
}