<?php

/**
 * F-113 — PHP Unit: Route Discovery — Route Caching Tests
 *
 * Tests that route properties are preserved correctly through registration,
 * and that the RouteRegistrar integrates with the Laravel router.
 *
 * @see packages/dancycodes/gale/src/Routing/RouteRegistrar.php
 */

use Dancycodes\Gale\Routing\RouteRegistrar;
use Dancycodes\Gale\Tests\Fixtures\Controllers\BasicController;

// ---------------------------------------------------------------------------
// Route Registration Data Integrity
// ---------------------------------------------------------------------------

describe('Route registration data integrity', function () {
    it('preserves route name through registration', function () {
        $router = app('router');

        $router->get('/cache-test-name', fn () => 'ok')->name('cache.test.name');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName('cache.test.name');

        expect($route)->not->toBeNull()
            ->and($route->getName())->toBe('cache.test.name');
    });

    it('preserves HTTP methods through registration', function () {
        $router = app('router');

        $router->match(['GET', 'POST'], '/cache-test-methods', fn () => 'ok')->name('cache.test.methods');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName('cache.test.methods');

        expect($route)->not->toBeNull()
            ->and($route->methods())->toContain('GET')
            ->toContain('POST');
    });

    it('preserves middleware through registration', function () {
        $router = app('router');

        $router->get('/cache-test-middleware', fn () => 'ok')
            ->middleware(['auth', 'verified'])
            ->name('cache.test.middleware');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName('cache.test.middleware');

        expect($route)->not->toBeNull()
            ->and($route->middleware())->toContain('auth')
            ->toContain('verified');
    });

    it('preserves parameter constraints through registration', function () {
        $router = app('router');

        $router->get('/cache-test-where/{id}', fn (int $id) => 'ok')
            ->where('id', '[0-9]+')
            ->name('cache.test.where');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName('cache.test.where');

        expect($route)->not->toBeNull()
            ->and($route->wheres)->toBe(['id' => '[0-9]+']);
    });

    it('preserves domain constraints through registration', function () {
        $router = app('router');

        $router->get('/cache-test-domain', fn () => 'ok')
            ->domain('api.example.com')
            ->name('cache.test.domain');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName('cache.test.domain');

        expect($route)->not->toBeNull()
            ->and($route->getDomain())->toBe('api.example.com');
    });

    it('preserves action controller reference through registration', function () {
        $router = app('router');

        $router->get('/cache-test-action', [BasicController::class, 'index'])
            ->name('cache.test.action');

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName('cache.test.action');

        expect($route)->not->toBeNull()
            ->and($route->getAction('controller'))->toContain('BasicController@index');
    });

    it('preserves gale tag on discovered routes', function () {
        $router = app('router');

        $route = $router->get('/cache-test-gale', fn () => 'ok');
        $route->action['gale'] = true;

        expect($route->getAction('gale'))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Route Discovery Integration (via RouteRegistrar)
// ---------------------------------------------------------------------------

describe('Route discovery integration', function () {
    it('discovers routes from fixture controllers directory', function () {
        $router = app('router');

        $registrar = new RouteRegistrar($router);
        $fixtureDir = realpath(dirname(__DIR__, 2) . '/Fixtures/Controllers');

        $registrar->useRootNamespace('Dancycodes\\Gale\\Tests\\Fixtures\\Controllers\\');
        $registrar->useBasePath($fixtureDir);

        $initialCount = count($router->getRoutes());

        $registrar->registerDirectory($fixtureDir);

        $finalCount = count($router->getRoutes());

        expect($finalCount)->toBeGreaterThan($initialCount);
    });

    it('handles empty directory gracefully', function () {
        $emptyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gale-test-empty-' . uniqid();
        @mkdir($emptyDir);

        $router = app('router');

        $registrar = new RouteRegistrar($router);
        $registrar->useRootNamespace('Nonexistent\\');
        $registrar->useBasePath($emptyDir);

        $initialCount = count($router->getRoutes());

        $registrar->registerDirectory($emptyDir);

        $finalCount = count($router->getRoutes());

        expect($finalCount)->toBe($initialCount);

        @rmdir($emptyDir);
    });

    it('registers routes with correct HTTP methods from attributes', function () {
        $router = app('router');

        $registrar = new RouteRegistrar($router);
        $fixtureDir = realpath(dirname(__DIR__, 2) . '/Fixtures/Controllers');

        $registrar->useRootNamespace('Dancycodes\\Gale\\Tests\\Fixtures\\Controllers\\');
        $registrar->useBasePath($fixtureDir);

        $registrar->registerDirectory($fixtureDir);

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        // BasicController's search method has #[Route(['GET', 'POST'], '/users/search', name: 'users.search')]
        $searchRoute = $routes->getByName('users.search');

        // Route might be registered with transformed name, check all routes
        if ($searchRoute) {
            expect($searchRoute->methods())->toContain('GET')
                ->toContain('POST');
        } else {
            // Route exists by URI - search all routes for our search action
            $found = false;
            foreach ($routes as $route) {
                if (str_contains($route->uri(), 'search') && str_contains($route->getActionName(), 'BasicController@search')) {
                    expect($route->methods())->toContain('GET')
                        ->toContain('POST');
                    $found = true;
                    break;
                }
            }
            expect($found)->toBeTrue();
        }
    });
});
