<?php
// tests/Feature/Security/RouteIntegrityTest.php
//
// Every API route leads somewhere (Oct 2026).
//
// A check of every endpoint on the live system found six routes pointing at
// controller methods that were never written -- an apiResource('patients')
// and two approval routes. Each answered 500. Nothing called them, which is
// exactly why nobody noticed; a route like that is only ever found by a
// scanner, or by the panel.

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteIntegrityTest extends TestCase
{
    public function test_every_api_route_points_at_a_method_that_exists(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (!str_starts_with($route->uri(), 'api/') || !str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);

            if (!class_exists($class) || !method_exists($class, $method)) {
                $missing[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' -> ' . class_basename($class) . '@' . $method;
            }
        }

        $this->assertSame([], $missing);
    }
}
