<?php

/**
 * F-113 — PHP Unit: Route Discovery — PendingRouteTransformer Tests
 *
 * Tests each PendingRouteTransformer for correct route transformation behavior.
 * Uses fixture controllers to test real attribute extraction via reflection.
 *
 * @see packages/dancycodes/gale/src/Routing/PendingRouteTransformers/
 */

use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Dancycodes\Gale\Routing\PendingRouteTransformers\AddControllerUriToActions;
use Dancycodes\Gale\Routing\PendingRouteTransformers\AddDefaultRouteName;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDomainAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDoNotDiscoverAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleFullUriAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleGroupAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleGroupNamePrefix;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleHttpMethodsAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleMiddlewareAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandlePrefixAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleRouteNameAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleUriAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleWheresAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleWithTrashedAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\MoveRoutesStartingWithParametersLast;
use Dancycodes\Gale\Routing\PendingRouteTransformers\PendingRouteTransformer;
use Dancycodes\Gale\Routing\PendingRouteTransformers\RejectDefaultControllerMethodRoutes;
use Dancycodes\Gale\Routing\PendingRouteTransformers\ValidateOptionalParameters;
use Dancycodes\Gale\Tests\Fixtures\Controllers\BasicController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\DomainController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\DoNotDiscoverClassController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\DoNotDiscoverController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\FullUriController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\GroupController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\MiddlewareController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\NoAttributeController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\PrefixedController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\WhereConstraintController;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helper: Build PendingRoute from class reflection
// ---------------------------------------------------------------------------

function makePendingRouteFromClass(string $className): PendingRoute
{
    $reflection = new ReflectionClass($className);
    $filePath = $reflection->getFileName();
    $fileInfo = new SplFileInfo($filePath);

    $actions = collect($reflection->getMethods())
        ->filter(fn (ReflectionMethod $method) => $method->isPublic()
            && $method->getDeclaringClass()->getName() === $className)
        ->map(fn (ReflectionMethod $method) => new PendingRouteAction($method, $className));

    $shortName = str_replace('Controller', '', $reflection->getShortName());
    $uri = Str::kebab($shortName);

    return new PendingRoute($fileInfo, $reflection, $uri, $className, $actions);
}

function findActionByName(PendingRoute $route, string $methodName): ?PendingRouteAction
{
    return $route->actions->first(fn (PendingRouteAction $a) => $a->method->name === $methodName);
}

// ---------------------------------------------------------------------------
// HandleDoNotDiscoverAttribute
// ---------------------------------------------------------------------------

describe('HandleDoNotDiscoverAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleDoNotDiscoverAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('returns empty collection for empty input', function () {
        $transformer = new HandleDoNotDiscoverAttribute;
        $result = $transformer->transform(collect([]));

        expect($result)->toBeEmpty();
    });

    it('removes class-level DoNotDiscover controllers', function () {
        $transformer = new HandleDoNotDiscoverAttribute;
        $route = makePendingRouteFromClass(DoNotDiscoverClassController::class);

        $result = $transformer->transform(collect([$route]));

        expect($result)->toBeEmpty();
    });

    it('removes method-level DoNotDiscover actions while preserving others', function () {
        $transformer = new HandleDoNotDiscoverAttribute;
        $route = makePendingRouteFromClass(DoNotDiscoverController::class);

        $transformer->transform(collect([$route]));

        $methodNames = $route->actions->map(fn (PendingRouteAction $a) => $a->method->name)->all();

        expect($methodNames)->toContain('visible')
            ->not->toContain('hidden');
    });

    it('preserves routes without DoNotDiscover', function () {
        $transformer = new HandleDoNotDiscoverAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $result = $transformer->transform(collect([$route]));

        expect($result)->toHaveCount(1);
    });
});

// ---------------------------------------------------------------------------
// AddControllerUriToActions
// ---------------------------------------------------------------------------

describe('AddControllerUriToActions transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new AddControllerUriToActions)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('prepends controller URI to action URIs', function () {
        $transformer = new AddControllerUriToActions;
        $route = makePendingRouteFromClass(BasicController::class);
        $route->uri = 'basic';

        $transformer->transform(collect([$route]));

        $searchAction = findActionByName($route, 'search');

        expect($searchAction->uri)->toBe('basic/search');
    });

    it('uses only controller URI for common methods with empty relative URI', function () {
        $transformer = new AddControllerUriToActions;
        $route = makePendingRouteFromClass(BasicController::class);
        $route->uri = 'basic';

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->uri)->toBe('basic');
    });
});

// ---------------------------------------------------------------------------
// HandlePrefixAttribute
// ---------------------------------------------------------------------------

describe('HandlePrefixAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandlePrefixAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('replaces controller URI with prefix for prefixed controllers', function () {
        $transformer = new HandlePrefixAttribute;
        $route = makePendingRouteFromClass(PrefixedController::class);

        // Simulate AddControllerUriToActions
        $controllerUri = $route->uri;
        $route->actions->each(function (PendingRouteAction $action) use ($controllerUri) {
            $originalUri = $action->uri;
            $action->uri = $controllerUri;
            if ($originalUri) {
                $action->uri .= '/' . $originalUri;
            }
        });

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');
        $createAction = findActionByName($route, 'create');

        expect($indexAction->uri)->toBe('api/v1')
            ->and($createAction->uri)->toContain('api/v1');
    });

    it('does nothing to controllers without Prefix attribute', function () {
        $transformer = new HandlePrefixAttribute;
        $route = makePendingRouteFromClass(BasicController::class);
        $route->uri = 'basic';

        $indexAction = findActionByName($route, 'index');
        $indexAction->uri = 'basic';

        $transformer->transform(collect([$route]));

        expect($indexAction->uri)->toBe('basic');
    });
});

// ---------------------------------------------------------------------------
// HandleHttpMethodsAttribute
// ---------------------------------------------------------------------------

describe('HandleHttpMethodsAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleHttpMethodsAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('overrides inferred HTTP methods with Route attribute methods', function () {
        $transformer = new HandleHttpMethodsAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');
        $searchAction = findActionByName($route, 'search');

        expect($indexAction->methods)->toBe(['GET'])
            ->and($searchAction->methods)->toBe(['GET', 'POST']);
    });

    it('does not modify actions without Route attribute', function () {
        $transformer = new HandleHttpMethodsAttribute;
        $route = makePendingRouteFromClass(NoAttributeController::class);

        $indexAction = findActionByName($route, 'index');
        $methodsBefore = $indexAction->methods;

        $transformer->transform(collect([$route]));

        expect($indexAction->methods)->toBe($methodsBefore);
    });
});

// ---------------------------------------------------------------------------
// HandleRouteNameAttribute
// ---------------------------------------------------------------------------

describe('HandleRouteNameAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleRouteNameAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('sets route name from Route attribute', function () {
        $transformer = new HandleRouteNameAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->name)->toBe('users.index');
    });

    it('does not set name for actions without Route name', function () {
        $transformer = new HandleRouteNameAttribute;
        $route = makePendingRouteFromClass(NoAttributeController::class);

        $indexAction = findActionByName($route, 'index');

        $transformer->transform(collect([$route]));

        expect($indexAction->name)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// HandleFullUriAttribute
// ---------------------------------------------------------------------------

describe('HandleFullUriAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleFullUriAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('replaces entire action URI with fullUri', function () {
        $transformer = new HandleFullUriAttribute;
        $route = makePendingRouteFromClass(FullUriController::class);

        $listAction = findActionByName($route, 'list');
        $listAction->uri = 'some/initial/uri';

        $transformer->transform(collect([$route]));

        expect($listAction->uri)->toBe('/api/v2/users');
    });

    it('does not modify actions without fullUri', function () {
        $transformer = new HandleFullUriAttribute;
        $route = makePendingRouteFromClass(FullUriController::class);

        $normalAction = findActionByName($route, 'normal');
        $normalAction->uri = 'original/path';

        $transformer->transform(collect([$route]));

        expect($normalAction->uri)->toBe('original/path');
    });
});

// ---------------------------------------------------------------------------
// HandleWheresAttribute
// ---------------------------------------------------------------------------

describe('HandleWheresAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleWheresAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('applies Where constraints from method attributes', function () {
        $transformer = new HandleWheresAttribute;
        $route = makePendingRouteFromClass(WhereConstraintController::class);

        $transformer->transform(collect([$route]));

        $showAction = findActionByName($route, 'show');
        $bySlugAction = findActionByName($route, 'bySlug');

        expect($showAction->wheres)->toBe(['id' => '[0-9]+'])
            ->and($bySlugAction->wheres)->toBe(['slug' => '[a-z\-]+']);
    });

    it('preserves existing wheres when no attribute present', function () {
        $transformer = new HandleWheresAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->wheres)->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// HandleMiddlewareAttribute
// ---------------------------------------------------------------------------

describe('HandleMiddlewareAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleMiddlewareAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('applies class-level Middleware attribute to all actions', function () {
        $transformer = new HandleMiddlewareAttribute;
        $route = makePendingRouteFromClass(MiddlewareController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->middleware)->toContain('auth');
    });

    it('stacks method-level middleware on top of class-level', function () {
        $transformer = new HandleMiddlewareAttribute;
        $route = makePendingRouteFromClass(MiddlewareController::class);

        $transformer->transform(collect([$route]));

        $adminAction = findActionByName($route, 'admin');

        expect($adminAction->middleware)->toContain('auth')
            ->toContain('admin')
            ->toContain('verified');
    });

    it('applies RateLimit as throttle middleware', function () {
        $transformer = new HandleMiddlewareAttribute;
        $route = makePendingRouteFromClass(MiddlewareController::class);

        $transformer->transform(collect([$route]));

        $apiAction = findActionByName($route, 'api');

        expect($apiAction->middleware)->toContain('throttle:60,1');
    });

    it('applies no middleware to controllers without middleware attributes', function () {
        $transformer = new HandleMiddlewareAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->middleware)->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// HandleDomainAttribute
// ---------------------------------------------------------------------------

describe('HandleDomainAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleDomainAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('applies class-level domain to actions without method-level Route attribute', function () {
        // DomainController has class-level #[Route(domain: 'api.example.com')]
        // Both methods also have #[Route] — method-level Route always overrides domain
        // The status method's Route has domain:null which overrides class-level
        $transformer = new HandleDomainAttribute;
        $route = makePendingRouteFromClass(DomainController::class);

        $transformer->transform(collect([$route]));

        // The health method's Route explicitly sets domain: 'health.example.com'
        $healthAction = findActionByName($route, 'health');
        expect($healthAction->domain)->toBe('health.example.com');
    });

    it('method-level Route domain overrides class-level even when null', function () {
        // status method has #[Route('GET', '/status')] with domain:null
        // This overrides the class-level domain to null
        $transformer = new HandleDomainAttribute;
        $route = makePendingRouteFromClass(DomainController::class);

        $transformer->transform(collect([$route]));

        $statusAction = findActionByName($route, 'status');

        // Method-level Route(domain: null) overrides class-level domain
        expect($statusAction->domain)->toBeNull();
    });

    it('applies class-level domain when no method-level Route exists', function () {
        // Controllers where methods don't have Route attribute: domain cascades from class
        $transformer = new HandleDomainAttribute;
        $route = makePendingRouteFromClass(NoAttributeController::class);

        // Manually set class-level domain via Route attribute on the PendingRoute
        // NoAttributeController has no attributes, so domain stays null
        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');
        expect($indexAction->domain)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// HandleWithTrashedAttribute
// ---------------------------------------------------------------------------

describe('HandleWithTrashedAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleWithTrashedAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('does not set withTrashed for actions without the attribute', function () {
        $transformer = new HandleWithTrashedAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->withTrashed)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// HandleGroupAttribute
// ---------------------------------------------------------------------------

describe('HandleGroupAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleGroupAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('applies prefix from Group attribute', function () {
        $transformer = new HandleGroupAttribute;
        $route = makePendingRouteFromClass(GroupController::class);

        // Simulate AddControllerUriToActions
        $controllerUri = $route->uri;
        $route->actions->each(function (PendingRouteAction $a) use ($controllerUri) {
            $orig = $a->uri;
            $a->uri = $controllerUri;
            if ($orig) {
                $a->uri .= '/' . $orig;
            }
        });

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->uri)->toBe('admin');
    });

    it('applies middleware from Group attribute', function () {
        $transformer = new HandleGroupAttribute;
        $route = makePendingRouteFromClass(GroupController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->middleware)->toContain('auth')
            ->toContain('admin');
    });

    it('applies domain from Group attribute', function () {
        $transformer = new HandleGroupAttribute;
        $route = makePendingRouteFromClass(GroupController::class);

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        expect($indexAction->domain)->toBe('admin.example.com');
    });

    it('does nothing to controllers without Group attribute', function () {
        $transformer = new HandleGroupAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        $indexAction = findActionByName($route, 'index');
        $uriBefore = $indexAction->uri;

        $transformer->transform(collect([$route]));

        expect($indexAction->uri)->toBe($uriBefore)
            ->and($indexAction->domain)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// HandleGroupNamePrefix
// ---------------------------------------------------------------------------

describe('HandleGroupNamePrefix transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleGroupNamePrefix)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('prepends group name prefix to action names', function () {
        $transformer = new HandleGroupNamePrefix;
        $route = makePendingRouteFromClass(GroupController::class);

        $indexAction = findActionByName($route, 'index');
        $indexAction->name = 'dashboard';

        $settingsAction = findActionByName($route, 'settings');
        $settingsAction->name = 'settings';

        $transformer->transform(collect([$route]));

        expect($indexAction->name)->toBe('admin.dashboard')
            ->and($settingsAction->name)->toBe('admin.settings');
    });

    it('skips actions with null name', function () {
        $transformer = new HandleGroupNamePrefix;
        $route = makePendingRouteFromClass(GroupController::class);

        $indexAction = findActionByName($route, 'index');
        $indexAction->name = null;

        $transformer->transform(collect([$route]));

        expect($indexAction->name)->toBeNull();
    });

    it('does nothing to controllers without Group attribute', function () {
        $transformer = new HandleGroupNamePrefix;
        $route = makePendingRouteFromClass(BasicController::class);

        $indexAction = findActionByName($route, 'index');
        $indexAction->name = 'original';

        $transformer->transform(collect([$route]));

        expect($indexAction->name)->toBe('original');
    });
});

// ---------------------------------------------------------------------------
// AddDefaultRouteName
// ---------------------------------------------------------------------------

describe('AddDefaultRouteName transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new AddDefaultRouteName)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('generates dot-notation name from URI segments', function () {
        $transformer = new AddDefaultRouteName;
        $route = makePendingRouteFromClass(BasicController::class);

        $indexAction = findActionByName($route, 'index');
        $indexAction->uri = 'users';
        $indexAction->name = null;

        $transformer->transform(collect([$route]));

        expect($indexAction->name)->toBe('users');
    });

    it('generates multi-segment name with dots', function () {
        $transformer = new AddDefaultRouteName;
        $route = makePendingRouteFromClass(BasicController::class);

        $searchAction = findActionByName($route, 'search');
        $searchAction->uri = 'users/search';
        $searchAction->name = null;

        $transformer->transform(collect([$route]));

        expect($searchAction->name)->toBe('users.search');
    });

    it('excludes parameter segments from route name', function () {
        $transformer = new AddDefaultRouteName;
        $route = makePendingRouteFromClass(WhereConstraintController::class);

        $showAction = findActionByName($route, 'show');
        $showAction->uri = 'where-constraint/{id}';
        $showAction->name = null;

        $transformer->transform(collect([$route]));

        expect($showAction->name)->not->toContain('{')
            ->and($showAction->name)->toContain('where-constraint');
    });

    it('does not override existing names', function () {
        $transformer = new AddDefaultRouteName;
        $route = makePendingRouteFromClass(BasicController::class);

        $indexAction = findActionByName($route, 'index');
        $indexAction->name = 'custom.name';

        $transformer->transform(collect([$route]));

        expect($indexAction->name)->toBe('custom.name');
    });

    it('appends method name for show/store/edit/update/destroy', function () {
        $transformer = new AddDefaultRouteName;
        $route = makePendingRouteFromClass(BasicController::class);

        $storeAction = findActionByName($route, 'store');
        $storeAction->uri = 'users';
        $storeAction->name = null;

        $transformer->transform(collect([$route]));

        expect($storeAction->name)->toBe('users.store');
    });
});

// ---------------------------------------------------------------------------
// HandleUriAttribute
// ---------------------------------------------------------------------------

describe('HandleUriAttribute transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new HandleUriAttribute)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('replaces method URI segment with custom uri from Route attribute', function () {
        $transformer = new HandleUriAttribute;
        $route = makePendingRouteFromClass(BasicController::class);

        // Simulate post-AddControllerUriToActions state
        $route->actions->each(function (PendingRouteAction $a) {
            $orig = $a->uri;
            $a->uri = 'basic';
            if ($orig) {
                $a->uri .= '/' . $orig;
            }
        });

        $transformer->transform(collect([$route]));

        $indexAction = findActionByName($route, 'index');

        // Route(uri: '/users') on index -> controllerBase + '/users'
        expect($indexAction->uri)->toContain('/users');
    });

    it('does not modify actions without uri in Route attribute', function () {
        $transformer = new HandleUriAttribute;
        $route = makePendingRouteFromClass(NoAttributeController::class);

        $indexAction = findActionByName($route, 'index');
        $indexAction->uri = 'original';

        $transformer->transform(collect([$route]));

        expect($indexAction->uri)->toBe('original');
    });
});

// ---------------------------------------------------------------------------
// MoveRoutesStartingWithParametersLast
// ---------------------------------------------------------------------------

describe('MoveRoutesStartingWithParametersLast transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new MoveRoutesStartingWithParametersLast)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('returns empty collection for empty input', function () {
        $transformer = new MoveRoutesStartingWithParametersLast;
        $result = $transformer->transform(collect([]));

        expect($result)->toBeEmpty();
    });

    it('sorts static routes before parameter routes', function () {
        $transformer = new MoveRoutesStartingWithParametersLast;

        $routeWithParam = makePendingRouteFromClass(WhereConstraintController::class);
        $routeWithParam->actions->each(fn (PendingRouteAction $a) => $a->uri = '{id}/' . $a->uri);

        $staticRoute = makePendingRouteFromClass(BasicController::class);
        $staticRoute->actions->each(fn (PendingRouteAction $a) => $a->uri = 'users/' . $a->uri);

        $result = $transformer->transform(collect([$routeWithParam, $staticRoute]));

        $firstRouteUri = $result->values()->first()?->actions->first()?->uri;

        expect($firstRouteUri)->toStartWith('users');
    });

    it('preserves order of routes without parameters', function () {
        $transformer = new MoveRoutesStartingWithParametersLast;

        $route1 = makePendingRouteFromClass(BasicController::class);
        $route1->actions->each(fn (PendingRouteAction $a) => $a->uri = 'alpha/' . $a->uri);

        $route2 = makePendingRouteFromClass(NoAttributeController::class);
        $route2->actions->each(fn (PendingRouteAction $a) => $a->uri = 'beta/' . $a->uri);

        $result = $transformer->transform(collect([$route1, $route2]));

        $uris = $result->values()->map(fn (PendingRoute $r) => $r->actions->first()?->uri)->all();

        expect($uris[0])->toStartWith('alpha')
            ->and($uris[1])->toStartWith('beta');
    });
});

// ---------------------------------------------------------------------------
// RejectDefaultControllerMethodRoutes
// ---------------------------------------------------------------------------

describe('RejectDefaultControllerMethodRoutes transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new RejectDefaultControllerMethodRoutes)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('includes Illuminate Controller in rejection list', function () {
        $transformer = new RejectDefaultControllerMethodRoutes;

        expect($transformer->rejectMethodsInClasses)->toContain(\Illuminate\Routing\Controller::class);
    });

    it('returns empty collection for empty input', function () {
        $transformer = new RejectDefaultControllerMethodRoutes;
        $result = $transformer->transform(collect([]));

        expect($result)->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// ValidateOptionalParameters
// ---------------------------------------------------------------------------

describe('ValidateOptionalParameters transformer', function () {
    it('implements PendingRouteTransformer interface', function () {
        expect(new ValidateOptionalParameters)->toBeInstanceOf(PendingRouteTransformer::class);
    });

    it('returns routes unchanged — validation only, no mutation', function () {
        $transformer = new ValidateOptionalParameters;
        $route = makePendingRouteFromClass(BasicController::class);

        $countBefore = $route->actions->count();

        $result = $transformer->transform(collect([$route]));

        expect($result)->toHaveCount(1)
            ->and($route->actions->count())->toBe($countBefore);
    });
});
