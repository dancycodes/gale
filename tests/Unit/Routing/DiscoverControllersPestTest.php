<?php

/**
 * F-113 — PHP Unit: Route Discovery — DiscoverControllers Tests
 *
 * Tests controller discovery, PendingRoute/PendingRouteAction creation,
 * PendingRouteFactory, and the Discover facade.
 *
 * @see packages/dancycodes/gale/src/Routing/Discovery/
 * @see packages/dancycodes/gale/src/Routing/PendingRoutes/
 */

use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Where;
use Dancycodes\Gale\Routing\Discovery\Discover;
use Dancycodes\Gale\Routing\Discovery\DiscoverControllers;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteFactory;
use Dancycodes\Gale\Tests\Fixtures\Controllers\BasicController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\DoNotDiscoverClassController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\DoNotDiscoverController;
use Dancycodes\Gale\Tests\Fixtures\Controllers\WhereConstraintController;

// ---------------------------------------------------------------------------
// Helper: Build PendingRoute directly from class reflection (bypasses factory
// filesystem resolution that requires full Laravel app context)
// ---------------------------------------------------------------------------

function buildPendingRoute(string $className): PendingRoute
{
    $reflection = new ReflectionClass($className);
    $filePath = $reflection->getFileName();
    $fileInfo = new SplFileInfo($filePath);

    $actions = collect($reflection->getMethods())
        ->filter(fn (ReflectionMethod $method) => $method->isPublic())
        ->map(fn (ReflectionMethod $method) => new PendingRouteAction($method, $className));

    // Derive a simple URI from class name (kebab-case without Controller suffix)
    $shortName = str_replace('Controller', '', $reflection->getShortName());
    $uri = \Illuminate\Support\Str::kebab($shortName);

    return new PendingRoute($fileInfo, $reflection, $uri, $className, $actions);
}

// ---------------------------------------------------------------------------
// Discover Facade
// ---------------------------------------------------------------------------

describe('Discover facade', function () {
    it('returns DiscoverControllers builder via controllers()', function () {
        expect(Discover::controllers())->toBeInstanceOf(DiscoverControllers::class);
    });

    it('returns DiscoverViews builder via views()', function () {
        expect(Discover::views())->toBeInstanceOf(
            \Dancycodes\Gale\Routing\Discovery\DiscoverViews::class
        );
    });
});

// ---------------------------------------------------------------------------
// DiscoverControllers Builder
// ---------------------------------------------------------------------------

describe('DiscoverControllers builder', function () {
    it('provides fluent useRootNamespace method', function () {
        $discover = new DiscoverControllers;
        $result = $discover->useRootNamespace('App\\Http\\Controllers');

        expect($result)->toBe($discover);
    });

    it('provides fluent useBasePath method', function () {
        $discover = new DiscoverControllers;
        $result = $discover->useBasePath('/custom/path');

        expect($result)->toBe($discover);
    });
});

// ---------------------------------------------------------------------------
// PendingRouteFactory (via Orchestra Testbench context)
// ---------------------------------------------------------------------------

describe('PendingRouteFactory', function () {
    it('creates PendingRoute from a valid controller file', function () {
        $fixtureDir = realpath(dirname(__DIR__, 2) . '/Fixtures/Controllers');

        $factory = new PendingRouteFactory(
            basePath: $fixtureDir,
            rootNamespace: 'Dancycodes\\Gale\\Tests\\Fixtures\\Controllers\\',
            registeringDirectory: $fixtureDir,
        );

        $file = new SplFileInfo($fixtureDir . DIRECTORY_SEPARATOR . 'BasicController.php');
        $pendingRoute = $factory->make($file);

        expect($pendingRoute)->toBeInstanceOf(PendingRoute::class)
            ->and($pendingRoute->fullyQualifiedClassName)->toBe(BasicController::class)
            ->and($pendingRoute->actions)->not->toBeEmpty();
    });

    it('returns null for non-existent class files', function () {
        $fixtureDir = realpath(dirname(__DIR__, 2) . '/Fixtures/Controllers');

        $factory = new PendingRouteFactory(
            basePath: $fixtureDir,
            rootNamespace: 'Nonexistent\\Namespace\\',
            registeringDirectory: $fixtureDir,
        );

        $file = new SplFileInfo($fixtureDir . DIRECTORY_SEPARATOR . 'BasicController.php');
        $pendingRoute = $factory->make($file);

        expect($pendingRoute)->toBeNull();
    });

    it('only includes public methods as actions', function () {
        $fixtureDir = realpath(dirname(__DIR__, 2) . '/Fixtures/Controllers');

        $factory = new PendingRouteFactory(
            basePath: $fixtureDir,
            rootNamespace: 'Dancycodes\\Gale\\Tests\\Fixtures\\Controllers\\',
            registeringDirectory: $fixtureDir,
        );

        $file = new SplFileInfo($fixtureDir . DIRECTORY_SEPARATOR . 'NoAttributeController.php');
        $pendingRoute = $factory->make($file);

        $methodNames = $pendingRoute->actions->map(fn (PendingRouteAction $a) => $a->method->name)->all();

        expect($methodNames)->toContain('index')
            ->toContain('store')
            ->not->toContain('protectedMethod')
            ->not->toContain('privateMethod');
    });
});

// ---------------------------------------------------------------------------
// PendingRoute
// ---------------------------------------------------------------------------

describe('PendingRoute', function () {
    beforeEach(function () {
        $this->pendingRoute = buildPendingRoute(BasicController::class);
    });

    it('extracts namespace from fully-qualified class name', function () {
        expect($this->pendingRoute->namespace())->toBe('Dancycodes\\Gale\\Tests\\Fixtures\\Controllers');
    });

    it('extracts short controller name without Controller suffix', function () {
        expect($this->pendingRoute->shortControllerName())->toBe('Basic');
    });

    it('computes child namespace correctly', function () {
        expect($this->pendingRoute->childNamespace())->toBe(
            'Dancycodes\\Gale\\Tests\\Fixtures\\Controllers\\Basic'
        );
    });

    it('retrieves Route attribute from class when present', function () {
        // BasicController does not have class-level Route attribute
        expect($this->pendingRoute->getRouteAttribute())->toBeNull();
    });

    it('retrieves attribute by class name', function () {
        // BasicController has no class-level DoNotDiscover
        expect($this->pendingRoute->getAttribute(DoNotDiscover::class))->toBeNull();
    });

    it('detects class-level DoNotDiscover attribute', function () {
        $route = buildPendingRoute(DoNotDiscoverClassController::class);

        expect($route->getAttribute(DoNotDiscover::class))->toBeInstanceOf(DoNotDiscover::class);
    });

    it('has a fileInfo SplFileInfo instance', function () {
        expect($this->pendingRoute->fileInfo)->toBeInstanceOf(SplFileInfo::class);
    });

    it('has a ReflectionClass instance', function () {
        expect($this->pendingRoute->class)->toBeInstanceOf(ReflectionClass::class)
            ->and($this->pendingRoute->class->getName())->toBe(BasicController::class);
    });
});

// ---------------------------------------------------------------------------
// PendingRouteAction
// ---------------------------------------------------------------------------

describe('PendingRouteAction', function () {
    it('infers GET HTTP method for index methods', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'index');
        $action = new PendingRouteAction($reflection, BasicController::class);

        expect($action->methods)->toBe(['GET']);
    });

    it('infers POST HTTP method for store methods', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'store');
        $action = new PendingRouteAction($reflection, BasicController::class);

        expect($action->methods)->toBe(['POST']);
    });

    it('infers PUT/PATCH for update methods', function () {
        $class = new class
        {
            public function update(int $id): void {}
        };
        $reflection = new ReflectionMethod($class, 'update');
        $action = new PendingRouteAction($reflection, get_class($class));

        expect($action->methods)->toBe(['PUT', 'PATCH']);
    });

    it('infers DELETE for destroy methods', function () {
        $class = new class
        {
            public function destroy(int $id): void {}
        };
        $reflection = new ReflectionMethod($class, 'destroy');
        $action = new PendingRouteAction($reflection, get_class($class));

        expect($action->methods)->toBe(['DELETE']);
    });

    it('defaults to POST for non-standard method names', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'search');
        $action = new PendingRouteAction($reflection, BasicController::class);

        // Before HTTP methods attribute transformer, default is POST for non-standard names
        expect($action->methods)->toBe(['POST']);
    });

    it('retrieves Route attribute from method', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'index');
        $action = new PendingRouteAction($reflection, BasicController::class);

        $routeAttr = $action->getRouteAttribute();

        expect($routeAttr)->toBeInstanceOf(Route::class)
            ->and($routeAttr->name)->toBe('users.index');
    });

    it('retrieves DoNotDiscover attribute from method', function () {
        $reflection = new ReflectionMethod(DoNotDiscoverController::class, 'hidden');
        $action = new PendingRouteAction($reflection, DoNotDiscoverController::class);

        expect($action->getAttribute(DoNotDiscover::class))->toBeInstanceOf(DoNotDiscover::class);
    });

    it('returns null when method has no matching attribute', function () {
        $reflection = new ReflectionMethod(DoNotDiscoverController::class, 'visible');
        $action = new PendingRouteAction($reflection, DoNotDiscoverController::class);

        expect($action->getAttribute(DoNotDiscover::class))->toBeNull();
    });

    it('generates relative URI from method name in kebab-case', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'search');
        $action = new PendingRouteAction($reflection, BasicController::class);

        expect($action->uri)->toBe('search');
    });

    it('generates empty URI for common controller methods', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'index');
        $action = new PendingRouteAction($reflection, BasicController::class);

        expect($action->uri)->toBe('');
    });

    it('adds Where constraint via addWhere', function () {
        $reflection = new ReflectionMethod(WhereConstraintController::class, 'show');
        $action = new PendingRouteAction($reflection, WhereConstraintController::class);

        $where = new Where('id', '[0-9]+');
        $action->addWhere($where);

        expect($action->wheres)->toBe(['id' => '[0-9]+']);
    });

    it('overwrites Where constraint for same parameter', function () {
        $reflection = new ReflectionMethod(WhereConstraintController::class, 'show');
        $action = new PendingRouteAction($reflection, WhereConstraintController::class);

        $action->addWhere(new Where('id', '[0-9]+'));
        $action->addWhere(new Where('id', '[a-z]+'));

        expect($action->wheres)->toBe(['id' => '[a-z]+']);
    });

    it('adds middleware via addMiddleware without duplicates', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'index');
        $action = new PendingRouteAction($reflection, BasicController::class);

        $action->addMiddleware('auth');
        $action->addMiddleware(['auth', 'verified']);

        expect($action->middleware)->toContain('auth')
            ->toContain('verified')
            ->and(count(array_filter($action->middleware, fn ($m) => $m === 'auth')))->toBe(1);
    });

    it('returns invokable controller format for __invoke', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'index');
        $action = new PendingRouteAction($reflection, BasicController::class);
        $action->action = [BasicController::class, '__invoke'];

        expect($action->action())->toBe(BasicController::class);
    });

    it('returns array format for non-invoke methods', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'index');
        $action = new PendingRouteAction($reflection, BasicController::class);

        expect($action->action())->toBe([BasicController::class, 'index']);
    });

    it('generates URI with parameters for typed method arguments', function () {
        $reflection = new ReflectionMethod(WhereConstraintController::class, 'show');
        $action = new PendingRouteAction($reflection, WhereConstraintController::class);

        // 'show' is common method name -> empty base; int $id -> {id}
        expect($action->uri)->toBe('{id}');
    });

    it('handles optional parameters with ? syntax', function () {
        $class = new class
        {
            public function search(string $query, string $filter = 'all'): void {}
        };

        $reflection = new ReflectionMethod($class, 'search');
        $action = new PendingRouteAction($reflection, get_class($class));

        expect($action->uri)->toContain('{query}')
            ->toContain('{filter?}');
    });

    it('sets action array correctly', function () {
        $reflection = new ReflectionMethod(BasicController::class, 'store');
        $action = new PendingRouteAction($reflection, BasicController::class);

        expect($action->action)->toBe([BasicController::class, 'store']);
    });
});
