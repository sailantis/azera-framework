<?php
namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

class RouterModernContractTest extends TestCase
{
    public function testNamedWildcardIsCapturedWithoutSpecialName(): void
    {
        $router = new Router();
        $router->add('GET', '/{controller}/{action}/{args:*}', null);

        $result = $router->match('/user/view/foo/bar');

        $this->assertNotNull($result);
        $this->assertEquals('user', $result['vars']['controller']);
        $this->assertEquals('view', $result['vars']['action']);
        $this->assertEquals(['foo', 'bar'], $result['vars']['args']);

        $this->assertArrayNotHasKey('namespace', $result);
        $this->assertArrayNotHasKey('controller', $result);
        $this->assertArrayNotHasKey('action', $result);
        $this->assertArrayNotHasKey('params', $result);
    }

    public function testInlinePrefixAndMiddlewareApplyToSubsequentRoutesAndScopedGroupsRestoreState(): void
    {
        $router = (new Router())
            ->middleware('auth')
            ->prefix('/admin');

        $router->prefix('/reports', function (Router $router): void {
            $router->middleware('audit');
            $router->add('GET', '/dashboard', 'Admin::dashboard');
        });

        $router->add('GET', '/profile', 'Account::profile');

        $dashboard = $router->match('/admin/reports/dashboard');
        $profile = $router->match('/admin/profile');

        $this->assertNotNull($dashboard);
        $this->assertSame(['auth', 'audit'], $dashboard['groups']);

        $this->assertNotNull($profile);
        $this->assertSame(['auth'], $profile['groups']);
    }

    public function testInlineNamespaceAppliesAndScopedNamespaceRestoresState(): void
    {
        $router = (new Router())->namespace('Admin');

        $router->namespace('Api', function (Router $router): void {
            $router->add('GET', '/users', 'UserController::list');
        });

        $router->add('GET', '/dashboard', 'DashboardController::view');

        $users = $router->match('/users');
        $dashboard = $router->match('/dashboard');

        $this->assertNotNull($users);
        $this->assertSame(
            ['namespace' => 'Admin\\Api', 'controller' => 'UserController', 'action' => 'list'],
            $users['override']
        );

        $this->assertNotNull($dashboard);
        $this->assertSame(
            ['namespace' => 'Admin', 'controller' => 'DashboardController', 'action' => 'view'],
            $dashboard['override']
        );
    }

    public function testNamespaceOnlyRouteKeepsRelativeNamespaceOverride(): void
    {
        $router = new Router();

        $router->prefix('/phpthunder', function (Router $router): void {
            $router->namespace('PhpThunder');
            $router->add('GET', '/');
        });

        $result = $router->match('/phpthunder');

        $this->assertNotNull($result);
        $this->assertSame(['namespace' => 'PhpThunder'], $result['override']);
    }

    public function testInlineControllerAppliesToSubsequentRoutes(): void
    {
        $router = (new Router())->controller('UserController');

        $router->add('GET', '/profile', '::show');

        $result = $router->match('/profile');

        $this->assertNotNull($result);
        $this->assertSame(
            ['controller' => 'UserController', 'action' => 'show'],
            $result['override']
        );
    }

    public function testOptionalTypedSegmentMatchesWithAndWithoutValue(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id?:int}', null);

        $withoutId = $router->match('/users');
        $withId = $router->match('/users/42');

        $this->assertNotNull($withoutId);
        $this->assertArrayNotHasKey('id', $withoutId['vars']);

        $this->assertNotNull($withId);
        $this->assertSame('42', $withId['vars']['id']);
    }

    public function testOptionalTypedSegmentRejectsInvalidValue(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id?:int}', null);

        $result = $router->match('/users/abc');

        $this->assertNull($result);
    }

    /**
     * The array handler form must split a controller the way the string form
     * does: on the *last* backslash.
     *
     * Splitting on the first turns `App\Controllers\IndexController` into the
     * namespace `App` and the class `Controllers\IndexController`, which
     * resolves to nothing — and does so at dispatch time, not at match time, so
     * the route looks healthy until a request arrives. This is how a
     * `[Controller::class, 'action']` route could be registered, match, and then
     * raise "Service not found".
     */
    public function testAFullyQualifiedControllerInTheArrayFormSplitsOnTheLastSeparator(): void
    {
        $router = new Router();
        $router->add('GET', '/', ['App\\Controllers\\IndexController', 'indexAction']);

        $result = $router->match('/');

        $this->assertNotNull($result);
        $this->assertSame('App\\Controllers', $result['override']['namespace']);
        $this->assertSame('IndexController', $result['override']['controller']);
        $this->assertSame('indexAction', $result['override']['action']);
    }

    /**
     * A controller name with no separator is a class, not a namespace, and is
     * left alone for the dispatcher's base namespace to complete.
     */
    public function testAnUnqualifiedControllerInTheArrayFormIsNotSplit(): void
    {
        $router = new Router();
        $router->add('GET', '/', ['IndexController', 'indexAction']);

        $result = $router->match('/');

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('namespace', $result['override']);
        $this->assertSame('IndexController', $result['override']['controller']);
        $this->assertSame('indexAction', $result['override']['action']);
    }
}
