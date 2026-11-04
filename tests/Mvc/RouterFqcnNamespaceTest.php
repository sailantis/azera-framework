<?php

namespace Azera\Tests\Mvc;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * A fully qualified controller must not inherit the group's namespace.
 *
 * `$r->controller(LicenseController::class, ...)` inside
 * `$r->namespace('PhpThunder')` produced the namespace
 * `PhpThunder\App\Controllers\PhpThunder`, because resolveHandler appended the
 * controller's own namespace to the group's instead of replacing it. The
 * resulting class name exists nowhere, so the route died at dispatch with
 * "Service not found: PhpThunder\App\Controllers\PhpThunder\LicenseController".
 *
 * It only bites routes registered WITHOUT an explicit action
 * (`$r->add('GET', '/license')`). With `'::indexAction'` the handler becomes the
 * string "Ns\Controller::action" and takes the string branch of resolveHandler,
 * which never appended. That is why `GET /license` broke while
 * `POST /license/seats` kept working, and why a test using only `::action`
 * routes passes against the broken code.
 *
 * The relative form must keep inheriting the group namespace, which is the whole
 * point of grouping.
 */
class RouterFqcnNamespaceTest extends TestCase
{
    public function testFqcnControllerWithoutActionReplacesTheGroupNamespace(): void
    {
        $router = new Router();

        $router->prefix('/phpthunder', function (Router $router): void {
            $router->namespace('PhpThunder');
            $router->controller('App\\Controllers\\PhpThunder\\LicenseController', function (Router $router): void {
                // No action: the controller group supplies the controller, so the
                // stored handler stays an array and resolveHandler uses its
                // array branch.
                $router->add('GET', '/license');
            });
        });

        $result = $router->match('/phpthunder/license');

        $this->assertNotNull($result);
        $this->assertSame('App\\Controllers\\PhpThunder', $result['override']['namespace']);
        $this->assertSame('LicenseController', $result['override']['controller']);
    }

    public function testFqcnControllerWithActionAlsoResolves(): void
    {
        $router = new Router();

        $router->prefix('/phpthunder', function (Router $router): void {
            $router->namespace('PhpThunder');
            $router->controller('App\\Controllers\\PhpThunder\\LicenseController', function (Router $router): void {
                $router->add('POST', '/license/seats', '::buySeatsAction');
            });
        });

        $result = $router->match('/phpthunder/license/seats', 'POST');

        $this->assertNotNull($result);
        $this->assertSame(
            [
                'namespace'  => 'App\\Controllers\\PhpThunder',
                'controller' => 'LicenseController',
                'action'     => 'buySeatsAction',
            ],
            $result['override'],
        );
    }

    public function testLeadingBackslashOnAnFqcnIsTolerated(): void
    {
        $router = new Router();

        $router->namespace('PhpThunder', function (Router $router): void {
            $router->controller('\\App\\Controllers\\PhpThunder\\AccountController', function (Router $router): void {
                $router->add('GET', '/account');
            });
        });

        $result = $router->match('/account');

        $this->assertNotNull($result);
        $this->assertSame('AccountController', $result['override']['controller']);
        $this->assertSame('App\\Controllers\\PhpThunder', $result['override']['namespace']);
    }

    public function testRelativeControllerStillInheritsTheGroupNamespace(): void
    {
        $router = new Router();

        $router->prefix('/phpthunder', function (Router $router): void {
            $router->namespace('PhpThunder');
            $router->add('GET', '/', 'DashboardController');
            $router->add('GET', '/invoices', 'InvoicesController::indexAction');
        });

        $dashboard = $router->match('/phpthunder');
        $invoices  = $router->match('/phpthunder/invoices');

        $this->assertSame('PhpThunder', $dashboard['override']['namespace']);
        $this->assertSame(
            [
                'namespace'  => 'PhpThunder',
                'controller' => 'InvoicesController',
                'action'     => 'indexAction',
            ],
            $invoices['override'],
        );
    }

    public function testSiblingControllersInTheSameGroupResolveIndependently(): void
    {
        $router = new Router();

        $router->namespace('PhpThunder', function (Router $router): void {
            $router->controller('App\\Controllers\\PhpThunder\\WorkspaceController', function (Router $router): void {
                $router->add('GET', '/workspaces');
            });
            $router->add('GET', '/plain', 'PlainController');
        });

        $fqcn  = $router->match('/workspaces');
        $plain = $router->match('/plain');

        $this->assertSame('App\\Controllers\\PhpThunder', $fqcn['override']['namespace']);
        // The FQCN registration must not leak into later relative routes.
        $this->assertSame('PhpThunder', $plain['override']['namespace']);
    }
}
