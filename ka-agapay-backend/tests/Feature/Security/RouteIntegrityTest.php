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

    /**
     * A controller argument typed as a model is looked up from the URL only
     * when the URL has a parameter of the same name. Otherwise Laravel hands
     * over an empty model and the action fails in confusing ways: "Start
     * screening" and "Endorse to doctor" on the Telemedicine page always
     * did ("Current status: []", Oct 2026).
     */
    public function test_every_model_argument_is_bound_from_the_url(): void
    {
        $unbound = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (!str_starts_with($route->uri(), 'api/') || !str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);

            if (!method_exists($class, $method)) {
                continue;
            }

            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $param) {
                $type = $param->getType();

                if (
                    $type instanceof \ReflectionNamedType
                    && !$type->isBuiltin()
                    && is_subclass_of($type->getName(), \Illuminate\Database\Eloquent\Model::class)
                    && !in_array($param->getName(), $route->parameterNames(), true)
                ) {
                    $unbound[] = $route->uri() . ' -> ' . class_basename($class) . '@' . $method . '($' . $param->getName() . ')';
                }
            }
        }

        $this->assertSame([], $unbound);
    }
}
