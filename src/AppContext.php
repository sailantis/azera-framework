<?php
namespace Azera;

use Azera\Aop\Advice;
use Azera\Aop\Advised;
use Azera\Aop\InterceptorInterface;
use Azera\Aop\ProxyFactory;
use Azera\Cache\NullCache;
use Azera\Config\Config;
use Azera\Core\Dispatcher;
use Azera\Core\Engines\ClarityEngine;
use Azera\Core\ResolvedRoute;
use Azera\Core\Router;
use Azera\Core\ViewEngine;
use Azera\Db\DatabaseManager;
use Azera\Db\Resolver\ModelResolver;
use Azera\Db\Resolver\TableResolver;
use Azera\Event\NullEventDispatcher;
use Azera\Http\Cookies;
use Azera\Http\Request as HttpRequest;
use Azera\Http\Session;
use Azera\Lifecycle\RequestScoped;
use Azera\Log\NullLogger;
use Azera\Orm\EntityManager;
use Azera\Orm\Heap;
use Azera\Orm\Storage\Stores;
use Azera\Queue\QueueInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

class AppContext
{
    public function __construct()
    {
        $this->serviceDefinitions = [
            AppContext::class               => fn() => $this,
            CacheInterface::class           => fn() => new NullCache(),
            Config::class                   => fn() => new Config(),
            Cookies::class                  => fn() => new Cookies(),
            DatabaseManager::class          => fn() => new DatabaseManager(),
            Dispatcher::class               => fn() => new Dispatcher(),
            EventDispatcherInterface::class => fn() => new NullEventDispatcher(),
            EntityManager::class            => fn() => new EntityManager($this->heap()),
            Heap::class                     => fn() => new Heap(),
            HttpRequest::class              => fn() => new HttpRequest(),
            LoggerInterface::class          => fn() => new NullLogger(),
            Router::class                   => fn() => new Router(),
            // Opt-in per request: resolves to null until setSession() binds one.
            Session::class       => fn() => null,
            Stores::class        => fn() => new Stores(),
            TableResolver::class => fn() => new ModelResolver(),
            ViewEngine::class    => fn() => new ClarityEngine(),
        ];
    }

    protected array $serviceDefinitions;

    protected array $serviceInstances = [];

    /** @var array<class-string<Advice>, InterceptorInterface|callable> Map of advice class => interceptor (or lazy factory returning one) */
    protected array $interceptors = [];

    protected ?ProxyFactory $proxyFactory = null;

    /** @var array<string, bool> Cache of whether a class has #[Advised] */
    private array $advisedCache = [];

    // --- Singleton ---

    /** @var AppContext|null Shared singleton instance */
    private static ?AppContext $instance = null;

    /**
     * Get/create shared singleton instance
     */
    public static function instance(): static
    {
        return self::$instance ??= new static();
    }

    /**
     * Set the shared singleton instance (e.g. for testing or multi-context scenarios).
     * @param self $instance
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Drop the shared singleton instance.
     *
     * Call in test setUp()/tearDown() (or between multi-context scenarios) to
     * guarantee each test starts from a pristine context, instead of relying on
     * a previous test having called {@see setInstance()}. After this, the next
     * {@see instance()} call lazily builds a fresh context.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // --- Lazy Services ---

    /**
     * Get the HttpRequest instance, resolved through the DI container.
     *
     * @return HttpRequest The HttpRequest instance.
     */
    public function request(): HttpRequest
    {
        return $this->serviceInstances[HttpRequest::class]
            ?? $this->get(HttpRequest::class);
    }

    /**
     * Get the active view engine instance.
     *
     * Resolution is fully delegated to the DI container: the default definition
     * builds a ClarityEngine, an app may register its own factory via
     * `set(ViewEngine::class, $factory)` (deferred build, resolved on first
     * use) or an instance via {@see setView()} (e.g. test doubles). view() and
     * get(ViewEngine::class) always return the same instance — one resolution
     * path, memoized by the container.
     *
     * Boot code must NOT call this method (or get(ViewEngine::class)) — that
     * is what triggers the engine build and its class autoload cost.
     *
     * @return ViewEngine The active view engine instance.
     */
    public function view(): ViewEngine
    {
        return $this->serviceInstances[ViewEngine::class]
            ?? $this->get(ViewEngine::class);
    }

    /**
     * Replace the active view engine instance (e.g. swap in a specific engine
     * or a test double at bootstrap). Sugar over set(): the instance is bound
     * as both the container definition and the memoized instance.
     *
     * To register a deferred factory instead, use
     * `set(ViewEngine::class, $factory)` — registering a callable unsets the
     * cached instance, so the factory applies on the next resolution.
     *
     * @param ViewEngine $engine The engine to use from this point on.
     * @return static
     */
    public function setView(ViewEngine $engine): static
    {
        $this->set(ViewEngine::class, $engine);
        return $this;
    }

    /**
     * Get the Cookies instance, resolved through the DI container.
     *
     * @return Cookies The Cookies instance.
     */
    public function cookies(): Cookies
    {
        return $this->serviceInstances[Cookies::class]
            ?? $this->get(Cookies::class);
    }

    /**
     * Get the request-scoped ORM Heap (identity map).
     *
     * One heap per request: the same DB row read twice yields the same
     * entity object, and the EntityManager diffs against the node
     * snapshot instead of scanning every constructed instance. The
     * heap is created lazily, registered in the container, and wiped by
     * {@see clearRequestScope()} via its RequestScoped hook (non-negotiable
     * in persistent workers — a leaking heap would serve stale entities
     * across requests/tenants).
     */
    public function heap(): Heap
    {
        return $this->serviceInstances[Heap::class]
            ?? $this->get(Heap::class);
    }

    /**
     * Get the request-scoped EntityManager (identity map + write pipeline).
     *
     * Shares the request's Heap with everything else — FastHydrator reads,
     * Model::save() and direct EM calls all see the same identity space.
     * persist() schedules, flush() executes in one transaction. Wiped by
     * {@see clearRequestScope()} like the heap.
     */
    public function entityManager(): EntityManager
    {
        return $this->serviceInstances[EntityManager::class]
            ?? $this->get(EntityManager::class);
    }

    /**
     * Get the DatabaseManager instance, resolved through the DI container.
     */
    public function dbManager(): DatabaseManager
    {
        return $this->serviceInstances[DatabaseManager::class]
            ?? $this->get(DatabaseManager::class);
    }

    /**
     * Get the Router instance, resolved through the DI container.
     *
     * @return Router The Router instance.
     */
    public function router(): Router
    {
        return $this->serviceInstances[Router::class]
            ?? $this->get(Router::class);
    }

    /**
     * Get the Dispatcher instance, resolved through the DI container.
     *
     * @return Dispatcher The Dispatcher instance.
     */
    public function dispatcher(): Dispatcher
    {
        return $this->serviceInstances[Dispatcher::class]
            ?? $this->get(Dispatcher::class);
    }

    /**
     * Get the logger instance. Returns a {@see NullLogger} if no logger
     * has been registered, so calling code can safely log without
     * null-checks. Register a real logger via `set(LoggerInterface::class, ...)`.
     *
     * @return LoggerInterface
     */
    public function logger(): LoggerInterface
    {
        return $this->serviceInstances[LoggerInterface::class]
            ?? $this->get(LoggerInterface::class);
    }

    /**
     * Get the event dispatcher. Returns a {@see NullEventDispatcher} if
     * none has been registered, so `dispatch()` is always safe. Register
     * a real dispatcher via `set(EventDispatcherInterface::class, ...)`.
     *
     * @return EventDispatcherInterface
     */
    public function events(): EventDispatcherInterface
    {
        return $this->serviceInstances[EventDispatcherInterface::class]
            ?? $this->get(EventDispatcherInterface::class);
    }

    /**
     * Get the cache instance. Returns a {@see NullCache} if none has been
     * registered (always reports a miss). Register a real cache via
     * `set(CacheInterface::class, ...)`.
     *
     * @return CacheInterface
     */
    public function cache(): CacheInterface
    {
        return $this->serviceInstances[CacheInterface::class]
            ?? $this->get(CacheInterface::class);
    }

    /**
     * Get the queue instance.
     *
     * Unlike the other subsystems, the queue has no null implementation
     * because silently dropping jobs would be dangerous. If no queue is
     * registered, this throws a LogicException with an install hint.
     * Register a queue via `set(QueueInterface::class, ...)`.
     *
     * @return QueueInterface
     * @throws \LogicException If no queue is registered.
     */
    public function queue(): QueueInterface
    {
        return $this->getOrNull(QueueInterface::class)
            ?? throw new \LogicException(
                'No queue registered. Set one via '
                    . 'AppContext::set(QueueInterface::class, $queue). '
                    . 'For synchronous processing, use Azera\\Queue\\SyncQueue.'
            );
    }

    /**
     * Get the configuration service. Lazily creates a {@see Config}
     * if none has been registered.
     *
     * @return Config
     */
    public function config(): Config
    {
        return $this->serviceInstances[Config::class]
            ?? $this->get(Config::class);
    }

    /**
     * Create a pipeline for explicit interceptor composition.
     *
     * This is the no-proxy alternative to AOP attributes. It lets you
     * wrap any callable with interceptors without generating proxy
     * classes. The same interceptors that work with the proxy AOP
     * also work here.
     *
     * Example:
     * <code>
     * $result = $ctx->pipeline()
     *     ->through([new RetryInterceptor(3), new LogInterceptor($logger)])
     *     ->call(fn() => $service->chargeCard(100));
     * </code>
     *
     * @param InterceptorInterface[] $interceptors
     * @return \Azera\Aop\Pipeline
     */
    public function pipeline(array $interceptors = []): \Azera\Aop\Pipeline
    {
        return new \Azera\Aop\Pipeline($interceptors);
    }

    /**
     * Register an interceptor for a specific advice type.
     *
     * Once at least one interceptor is registered, the DI container
     * will proxy classes marked with {@see Advised} that have methods
     * carrying the corresponding advice attribute.
     *
     * The interceptor may also be passed as a zero-argument factory
     * (closure/invokable) returning an InterceptorInterface. Factories are
     * resolved exactly once — when the ProxyFactory is first built — so boot
     * can register lazy wiring (e.g. an interceptor depending on dbManager())
     * without constructing anything during bootstrap.
     *
     * @param class-string<Advice>                          $adviceClass The advice attribute class.
     * @param InterceptorInterface|callable                 $interceptor The interceptor to handle it (or a factory returning one).
     * @return void
     */
    public function registerInterceptor(string $adviceClass, InterceptorInterface|callable $interceptor): void
    {
        $this->interceptors[$adviceClass] = $interceptor;
    }

    /**
     * Get the ProxyFactory, lazily created and configured with
     * all registered interceptors.
     */
    protected function proxyFactory(): ProxyFactory
    {
        if ($this->proxyFactory === null) {
            $this->proxyFactory = new ProxyFactory();
            // Default cache dir: sys_get_temp_dir()/azera_aop (OPcache-friendly).
            // Set to null via setAopCacheDir(null) to use eval (development).
            $this->proxyFactory->setCacheDir(
                $this->aopCacheDir
                    ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_aop'
            );
            foreach ($this->interceptors as $adviceClass => $interceptor) {
                // Resolve lazy factories exactly once, at ProxyFactory build
                // time (first advised-class instantiation) — not at boot.
                $this->proxyFactory->register(
                    $adviceClass,
                    $interceptor instanceof InterceptorInterface ? $interceptor : $interceptor()
                );
            }
            ProxyFactory::setCurrent($this->proxyFactory);
        }
        return $this->proxyFactory;
    }

    /**
     * Set the AOP proxy cache directory.
     *
     * Pass a path for file-based proxy generation (OPcache-cached, production).
     * Pass null to use eval() (development, no cache files).
     *
     * @param string|null $dir
     * @return void
     */
    public function setAopCacheDir(?string $dir): void
    {
        if ($this->proxyFactory !== null) {
            $this->proxyFactory->setCacheDir($dir);
        }
        $this->aopCacheDir = $dir;
    }

    /** @var string|null AOP cache directory (stored before proxyFactory is created). */
    private ?string $aopCacheDir = null;

    /**
     * Check if a class is marked with #[Advised] and has at least one
     * method with a registered advice attribute. Cached per class.
     */
    private function hasAdvisedMethods(\ReflectionClass $ref): bool
    {
        $className = $ref->getName();

        if (isset($this->advisedCache[$className])) {
            return $this->advisedCache[$className];
        }

        // Fast check: class (or any parent) must have #[Advised] attribute.
        // Class-level attributes are NOT inherited by PHP, so we walk
        // up the parent chain ourselves.
        $hasAdvised = false;
        $class      = $ref;
        do {
            if ($class->getAttributes(Advised::class) !== []) {
                $hasAdvised = true;
                break;
            }
        } while ($class = $class->getParentClass());

        if (!$hasAdvised) {
            return $this->advisedCache[$className] = false;
        }

        // Check if any method has a registered advice attribute.
        // Method-level attributes ARE inherited in PHP — getAttributes()
        // on an inherited method returns the declaring class's attributes.
        foreach ($ref->getMethods() as $method) {
            foreach ($method->getAttributes() as $attr) {
                $attrClass = $attr->getName();
                if (isset($this->interceptors[$attrClass])) {
                    return $this->advisedCache[$className] = true;
                }
            }
        }

        return $this->advisedCache[$className] = false;
    }

    // --- Critical Services ---

    /**
     * Get the Session instance, or null until {@see setSession()} binds one.
     */
    public function session(): ?Session
    {
        return $this->serviceInstances[Session::class]
            ?? $this->getOrNull(Session::class);
    }

    /**
     * Set the Session instance.
     *
     * @param Session $session The Session instance to set in the context.
     */
    public function setSession(Session $session): void
    {
        $this->set(Session::class, $session);
    }

    /**
     * Get the current resolved route information.
     */
    public function route(): ?ResolvedRoute
    {
        return $this->serviceInstances[ResolvedRoute::class] ?? null;
    }

    /**
     * Set the current resolved route information.
     *
     * @param ResolvedRoute $route The resolved route to set in the context.
     */
    public function setRoute(ResolvedRoute $route): void
    {
        $this->serviceInstances[ResolvedRoute::class] = $route;
    }

    /**
     * Clear all request-scoped state on this context.
     *
     * Under a persistent application server (RoadRunner, Swoole, FrankenPHP,
     * Octane, …) the AppContext survives across many requests. This method
     * resets the per-request services so the next request starts clean:
     *
     *  - the request-scoped container entries (Request, Session, Cookies)
     *    are removed so their accessors resolve fresh instances on demand;
     *  - the current {@see ResolvedRoute} value is cleared;
     *  - every service registered on the container that implements
     *    {@see RequestScoped} has its {@see RequestScoped::resetState()} hook
     *    called — this covers the ORM Heap and EntityManager, whose identity
     *    state is wiped in place while their instances stay registered
     *    (persistent-worker contract: handles survive, state dies).
     *
     * Persistent infrastructure is deliberately left untouched — database
     * manager, cache/Redis backends, queue, logger and event dispatcher keep
     * their handles and connections alive across requests.
     *
     * Safe to call repeatedly; a no-op when no request has been processed yet.
     */
    public function clearRequestScope(): void
    {
        // Drop the request-scoped container entries so the accessors resolve
        // fresh instances on the next request. The RequestScoped loop below
        // also covers the ORM Heap and EntityManager: both implement
        // RequestScoped and live in serviceInstances like every other service.
        unset($this->serviceInstances[HttpRequest::class]);
        unset($this->serviceInstances[Session::class]);
        unset($this->serviceInstances[Cookies::class]);
        unset($this->serviceInstances[ResolvedRoute::class]);

        // Drop any service that must be re-instantiated per request by calling
        // its resetState() hook. Services that hold persistent handles keep
        // them, but clear their per-request state.
        foreach ($this->serviceInstances as $service) {
            if ($service instanceof RequestScoped) {
                $service->resetState();
            }
        }
    }

    // --- Service Container ---

    /**
     * Register a service instance or lazy factory in the context.
     *
     * Registered callables are treated as zero-argument factories. They are invoked on
     * first resolution and their returned object is cached for subsequent lookups.
     *
     * @param string          $id      The identifier for the service (usually the class name).
     * @param callable|object|null $service Optional service instance or zero-argument factory to register.
     */
    public function set(string $id, callable|object|null $service = null): void
    {
        $service ??= $id;
        $this->serviceDefinitions[$id] = $service;

        if (is_object($service) && !is_callable($service)) {
            $this->serviceInstances[$id] = $service;
        } else {
            unset($this->serviceInstances[$id]);
        }
    }

    /**
     * Check if a service is registered in the context.
     *
     * @param string $id The identifier of the service to check.
     * @return bool True if the service is registered, false otherwise.
     */
    public function has(string $id): bool
    {
        return isset($this->serviceDefinitions[$id]);
    }

    /**
     * Get a service instance from the context.
     *
     * If the service is registered as a callable, it will be invoked lazily
     * once and the returned object will be cached. If the service is not
     * registered but the identifier is a class name, it will attempt to
     * auto-wire and instantiate it.
     *
     * @template T of object
     * @param class-string<T> $id The identifier of the service to retrieve.
     * @return T The service instance associated with the given identifier.
     * @throws RuntimeException If the service is not found and cannot be auto-wired.
     */
    public function get(string $id): object
    {
        if (isset($this->serviceDefinitions[$id])) {
            return $this->resolveRegisteredService($id, allowNull: false);
        }

        if (class_exists($id)) {
            $service = $this->build($id);
            $this->serviceDefinitions[$id] = $service;
            $this->serviceInstances[$id]   = $service;
            return $service;
        }

        throw new RuntimeException("Service not found: $id");
    }

    /**
     * Try to get a service instance from the context.
     *
     * If the service is registered as a callable, it will be invoked lazily
     * once and the returned object will be cached. If the service is not
     * registered but the identifier is a class name, it will attempt to
     * auto-wire and instantiate it. Returns null if the service is not found,
     * or if a registered factory currently resolves to null.
     *
     * @template T of object
     * @param class-string<T> $id The identifier of the service to retrieve.
     * @return T|null The service instance associated with the given identifier, or null if not found.
     */
    public function tryGet(string $id): ?object
    {
        // A registered factory that currently resolves to null must stay null.
        if (isset($this->serviceDefinitions[$id])) {
            return $this->resolveRegisteredService($id, allowNull: true);
        }

        if (class_exists($id)) {
            $service = $this->build($id);
            $this->serviceDefinitions[$id] = $service;
            $this->serviceInstances[$id]   = $service;
            return $service;
        }

        return null;
    }

    /**
     * Get a registered service instance if it exists, or null if it does not.
     *
     * Registered factories are resolved lazily. This method does not attempt
     * to auto-wire or instantiate classes that have not been registered
     * explicitly.
     *
     * @template T of object
     * @param class-string<T> $id The identifier of the service to retrieve.
     * @return T|null The service instance associated with the given identifier, or null if not found.
     */
    public function getOrNull(string $id): ?object
    {
        return $this->resolveRegisteredService($id, allowNull: true);
    }

    /**
     * Resolve a registered service by its identifier.
     *
     * If the service is registered as a callable, it will be invoked lazily once and the
     * returned object will be cached. If the service is not registered, this method
     * returns null or throws an exception based on the $allowNull parameter.
     *
     * @param string $id The identifier of the service to resolve.
     * @param bool $allowNull Whether to allow null return if the service is not found.
     * @return object|null The resolved service instance, or null if not found and $allowNull is true.
     * @throws RuntimeException If the service is not found and $allowNull is false.
     */
    protected function resolveRegisteredService(string $id, bool $allowNull): ?object
    {
        if (isset($this->serviceInstances[$id])) {
            return $this->serviceInstances[$id];
        }

        $definition = $this->serviceDefinitions[$id] ?? null;

        if ($definition === null) {
            return null;
        }

        if (is_string($definition) && class_exists($definition)) {
            $service = $this->build($definition);
            $this->serviceDefinitions[$id] = $service;
            $this->serviceInstances[$id]   = $service;
            return $service;
        }

        if (!is_callable($definition)) {
            return $this->serviceInstances[$id] = $definition;
        }

        $service = $definition();

        if ($service === null) {
            if ($allowNull) {
                return null;
            }

            throw new RuntimeException("Service factory for $id did not return an object");
        }

        if (!is_object($service)) {
            throw new RuntimeException("Service factory for $id did not return an object");
        }

        return $this->serviceInstances[$id] = $service;
    }

    protected function build(string $class): object
    {
        $ref = new \ReflectionClass($class);

        // AOP fast path: if no interceptors registered, instantiate directly.
        if ($this->interceptors === []) {
            return $this->instantiate($ref, $class);
        }

        // AOP fast path: if class is not #[Advised] with matching methods,
        // instantiate directly — zero proxy overhead.
        if (!$this->hasAdvisedMethods($ref)) {
            return $this->instantiate($ref, $class);
        }

        // Build the proxy class and instantiate it directly.
        // The proxy extends the target class, so constructor DI works
        // the same way — the proxy IS the instance.
        $proxyClass = $this->proxyFactory()->buildProxyClass($ref);

        if ($proxyClass === null) {
            return $this->instantiate($ref, $class);
        }

        return $this->instantiate(new \ReflectionClass($proxyClass), $proxyClass);
    }

    /**
     * Instantiate a class, resolving constructor dependencies via DI.
     */
    private function instantiate(\ReflectionClass $ref, string $class): object
    {
        if (!$ref->getConstructor()) {
            return new $class();
        }
        return $this->instantiateWithDeps($ref, $class);
    }

    /**
     * Instantiate a class with constructor dependencies resolved via DI.
     */
    private function instantiateWithDeps(\ReflectionClass $ref, string $class): object
    {

        $args = [];

        foreach ($ref->getConstructor()->getParameters() as $param) {

            $typeObj = $param->getType();
            $types   = [];

            // Extract all possible types (Named, Union, Intersection)
            if ($typeObj instanceof \ReflectionNamedType) {
                $types[] = $typeObj->getName();
            } elseif ($typeObj instanceof \ReflectionUnionType) {
                foreach ($typeObj->getTypes() as $t) {
                    if ($t instanceof \ReflectionNamedType) {
                        $types[] = $t->getName();
                    }
                }
            } else {
                throw new RuntimeException(
                    "Unsupported parameter type for \${$param->getName()} in $class constructor"
                );
            }

            // Try to resolve via DI (AppContext)
            foreach ($types as $t) {

                // If service is registered
                if ($this->has($t)) {
                    $service = $this->getOrNull($t);
                    if ($service !== null) {
                        $args[] = $service;
                        continue 2; // next parameter
                    }

                    continue;
                }

                // If class exists -> auto-wire
                if (class_exists($t)) {
                    $args[] = $this->get($t);
                    continue 2;
                }

                // Built-in types (int, string, etc.) are not supported here
            }

            // Default value
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Nullable
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new Exception(
                "Cannot resolve constructor parameter \${$param->getName()} for $class"
            );
        }

        return new $class(...$args);
    }
}