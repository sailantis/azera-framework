<?php
namespace Azera\Core;

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Http\Cookies;
use Azera\Http\Request;
use Azera\Http\Session;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Base class for controllers in Azera.
 */
abstract class Controller
{
	/**
	 * Controller-wide middleware
	 * Example:
	 * protected array $middlewares = [
	 *     AuthMiddleware::class,
	 *     [RoleMiddleware::class, ['admin']],
	 * ];
	 */
	protected array $middlewares = [];

	/**
	 * Action-specific middleware
	 * Example:
	 * protected array $actionMiddlewares = [
	 *     'editAction' => [
	 *         AuthMiddleware::class,
	 *         [RoleMiddleware::class, ['admin']],
	 *     ],
	 * ];
	 */
	protected array $actionMiddlewares = [];

	// --- Middleware getters ---

	/**
	 * Get the middleware for the controller. Usually used by the Dispatcher to build the middleware pipeline for the current request.
	 * @return array
	 */
	public function getMiddlewares(): array
	{
		return $this->middlewares;
	}

	/**
	 * Get the middleware for a specific action. Usually used by the Dispatcher to build the middleware pipeline for the current request.
	 * @param string $action The name of the action (e.g. "editAction")
	 * @return array
	 */
	public function getActionMiddlewares(string $action): array
	{
		return $this->actionMiddlewares[$action] ?? [];
	}

	// --- Helpers ---

	/**
	 * Get the current AppContext instance. Useful for accessing services or route info from the controller.
	 * @return AppContext
	 */
	public function context(): AppContext
	{
		return AppContext::instance();
	}

	/**
	 * Get the current Request object from the context.
	 * @return Request
	 */
	public function request(): Request
	{
		return $this->context()->request();
	}

	/**
	 * Get the ViewEngine from the context for rendering views.
	 * @return ViewEngine
	 */
	public function view(): ViewEngine
	{
		return $this->context()->view();
	}

	/**
	 * Get the Session from the context. May return null if no session is available.
	 * @return Session|null
	 */
	public function session(): ?Session
	{
		return $this->context()->session();
	}

	/**
	 * Get the one-request message bag.
	 *
	 * Sugar over `AppContext::flash()`:
	 *
	 * ```php
	 * // POST /account — queue for the page after the redirect
	 * $this->flash()->success('Account settings updated.');
	 * return Response::redirect('/account');
	 *
	 * // GET /account — show once
	 * $messages = $this->flash()->consume();
	 * ```
	 */
	public function flash(): \Azera\Http\Flash
	{
		return $this->context()->flash();
	}

	/**
	 * Get the application-wide translation service.
	 */
	public function translator(): \Azera\Localization\Translator
	{
		return $this->context()->translator();
	}

	/**
	 * Translate a key for this controller's output.
	 *
	 * Sugar over {@see translator()}; templates bound through
	 * `$ctx->translator()->bind($ctx->view())` resolve the same catalogue.
	 *
	 * @param string              $key    Message key (e.g. "nav.home").
	 * @param array<string,mixed> $vars   Placeholder values for `{name}` substitution.
	 * @param string|null         $domain Domain override for this call.
	 * @param string|null         $locale Locale override for this call.
	 */
	public function t(string $key, array $vars = [], ?string $domain = null, ?string $locale = null): string
	{
		return $this->translator()->get($key, $vars, $domain, $locale);
	}

	/**
	 * Get the application-wide locale-aware formatting service.
	 */
	public function intl(): \Azera\Localization\Intl
	{
		return $this->context()->intl();
	}

	/**
	 * Get the Cookies service from the context for managing cookies.
	 * @return Cookies
	 */
	public function cookies(): Cookies
	{
		return $this->context()->cookies();
	}

	/**
	 * Get a service from the context by class name.
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function resolve(string $id): object
	{
		return $this->context()->get($id);
	}

	/**
	 * Get the database manager for persistence access.
	 */
	public function db(): DatabaseManager
	{
		return $this->context()->dbManager();
	}

	/**
	 * Get the cache service.
	 */
	public function cache(): CacheInterface
	{
		return $this->context()->cache();
	}

	/**
	 * Get the logger.
	 */
	public function logger(): LoggerInterface
	{
		return $this->context()->logger();
	}

	/**
	 * Get the event dispatcher.
	 */
	public function events(): EventDispatcherInterface
	{
		return $this->context()->events();
	}
}
