<?php

/**
 * F-113 — PHP Unit: Route Discovery — Route Attribute Tests
 *
 * Tests each route attribute class for construction, value extraction,
 * default values, and edge cases.
 *
 * @see packages/dancycodes/gale/src/Routing/Attributes/
 */

use Dancycodes\Gale\Routing\Attributes\DiscoveryAttribute;
use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Routing\Attributes\Group;
use Dancycodes\Gale\Routing\Attributes\Middleware;
use Dancycodes\Gale\Routing\Attributes\NoAutoDiscovery;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\RateLimit;
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Where;
use Dancycodes\Gale\Routing\Attributes\WithTrashed;

// ---------------------------------------------------------------------------
// Route Attribute
// ---------------------------------------------------------------------------

describe('Route attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        $route = new Route;

        expect($route)->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('has correct default values', function () {
        $route = new Route;

        expect($route->methods)->toBe([])
            ->and($route->uri)->toBeNull()
            ->and($route->fullUri)->toBeNull()
            ->and($route->name)->toBeNull()
            ->and($route->middleware)->toBe([])
            ->and($route->domain)->toBeNull()
            ->and($route->withTrashed)->toBeFalse();
    });

    it('accepts a single HTTP method as string', function () {
        $route = new Route('GET');

        expect($route->methods)->toBe(['GET']);
    });

    it('accepts multiple HTTP methods as array', function () {
        $route = new Route(['GET', 'POST']);

        expect($route->methods)->toBe(['GET', 'POST']);
    });

    it('normalizes HTTP methods to uppercase', function () {
        $route = new Route(['get', 'post', 'Put']);

        expect($route->methods)->toBe(['GET', 'POST', 'PUT']);
    });

    it('filters out invalid HTTP methods', function () {
        $route = new Route(['GET', 'INVALID', 'POST', 'FAKE']);

        expect($route->methods)->toContain('GET')
            ->toContain('POST')
            ->not->toContain('INVALID')
            ->not->toContain('FAKE');
    });

    it('stores URI when provided', function () {
        $route = new Route('GET', '/users');

        expect($route->uri)->toBe('/users');
    });

    it('stores fullUri when provided', function () {
        $route = new Route('GET', fullUri: '/api/users');

        expect($route->fullUri)->toBe('/api/users');
    });

    it('stores name when provided', function () {
        $route = new Route('GET', name: 'users.index');

        expect($route->name)->toBe('users.index');
    });

    it('stores single middleware as array', function () {
        $route = new Route('GET', middleware: 'auth');

        expect($route->middleware)->toBe(['auth']);
    });

    it('stores multiple middleware as array', function () {
        $route = new Route('GET', middleware: ['auth', 'verified']);

        expect($route->middleware)->toBe(['auth', 'verified']);
    });

    it('stores domain when provided', function () {
        $route = new Route('GET', domain: 'api.example.com');

        expect($route->domain)->toBe('api.example.com');
    });

    it('stores withTrashed flag', function () {
        $route = new Route('GET', withTrashed: true);

        expect($route->withTrashed)->toBeTrue();
    });

    it('can set all properties at once', function () {
        $route = new Route(
            method: ['GET', 'POST'],
            uri: '/users',
            fullUri: '/api/users',
            name: 'users.list',
            middleware: ['auth', 'throttle'],
            domain: 'api.example.com',
            withTrashed: true,
        );

        expect($route->methods)->toBe(['GET', 'POST'])
            ->and($route->uri)->toBe('/users')
            ->and($route->fullUri)->toBe('/api/users')
            ->and($route->name)->toBe('users.list')
            ->and($route->middleware)->toBe(['auth', 'throttle'])
            ->and($route->domain)->toBe('api.example.com')
            ->and($route->withTrashed)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Prefix Attribute
// ---------------------------------------------------------------------------

describe('Prefix attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new Prefix('/api'))->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('stores the prefix string', function () {
        $prefix = new Prefix('/api/v1');

        expect($prefix->prefix)->toBe('/api/v1');
    });

    it('stores prefix without leading slash', function () {
        $prefix = new Prefix('admin');

        expect($prefix->prefix)->toBe('admin');
    });
});

// ---------------------------------------------------------------------------
// Where Attribute
// ---------------------------------------------------------------------------

describe('Where attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new Where('id', '[0-9]+'))->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('stores parameter name and constraint', function () {
        $where = new Where('id', '[0-9]+');

        expect($where->param)->toBe('id')
            ->and($where->constraint)->toBe('[0-9]+');
    });

    it('provides alpha constant', function () {
        expect(Where::alpha)->toBe('[a-zA-Z]+');
    });

    it('provides numeric constant', function () {
        expect(Where::numeric)->toBe('[0-9]+');
    });

    it('provides alphanumeric constant', function () {
        expect(Where::alphanumeric)->toBe('[a-zA-Z0-9]+');
    });

    it('provides uuid constant', function () {
        expect(Where::uuid)->toBe('[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}');
    });

    it('can use predefined constants as constraints', function () {
        $where = new Where('slug', Where::alpha);

        expect($where->constraint)->toBe('[a-zA-Z]+');
    });
});

// ---------------------------------------------------------------------------
// DoNotDiscover Attribute
// ---------------------------------------------------------------------------

describe('DoNotDiscover attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new DoNotDiscover)->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('is a marker attribute with no properties', function () {
        $attribute = new DoNotDiscover;

        expect($attribute)->toBeInstanceOf(DoNotDiscover::class);
    });
});

// ---------------------------------------------------------------------------
// WithTrashed Attribute
// ---------------------------------------------------------------------------

describe('WithTrashed attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new WithTrashed)->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('is a marker attribute with no properties', function () {
        $attribute = new WithTrashed;

        expect($attribute)->toBeInstanceOf(WithTrashed::class);
    });
});

// ---------------------------------------------------------------------------
// NoAutoDiscovery Attribute
// ---------------------------------------------------------------------------

describe('NoAutoDiscovery attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new NoAutoDiscovery)->toBeInstanceOf(DiscoveryAttribute::class);
    });
});

// ---------------------------------------------------------------------------
// Middleware Attribute
// ---------------------------------------------------------------------------

describe('Middleware attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new Middleware('auth'))->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('stores a single middleware', function () {
        $middleware = new Middleware('auth');

        expect($middleware->middleware)->toBe(['auth']);
    });

    it('stores multiple middleware via variadic constructor', function () {
        $middleware = new Middleware('auth', 'verified', 'admin');

        expect($middleware->middleware)->toBe(['auth', 'verified', 'admin']);
    });
});

// ---------------------------------------------------------------------------
// RateLimit Attribute
// ---------------------------------------------------------------------------

describe('RateLimit attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new RateLimit)->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('has sensible defaults', function () {
        $rateLimit = new RateLimit;

        expect($rateLimit->maxAttempts)->toBe(60)
            ->and($rateLimit->decayMinutes)->toBe(1)
            ->and($rateLimit->limiter)->toBeNull();
    });

    it('generates inline throttle middleware string', function () {
        $rateLimit = new RateLimit(10, decayMinutes: 5);

        expect($rateLimit->toMiddlewareString())->toBe('throttle:10,5');
    });

    it('generates named limiter throttle middleware string', function () {
        $rateLimit = new RateLimit(limiter: 'api');

        expect($rateLimit->toMiddlewareString())->toBe('throttle:api');
    });

    it('prefers named limiter over inline values', function () {
        $rateLimit = new RateLimit(100, decayMinutes: 10, limiter: 'custom');

        expect($rateLimit->toMiddlewareString())->toBe('throttle:custom');
    });
});

// ---------------------------------------------------------------------------
// Group Attribute
// ---------------------------------------------------------------------------

describe('Group attribute', function () {
    it('implements DiscoveryAttribute interface', function () {
        expect(new Group)->toBeInstanceOf(DiscoveryAttribute::class);
    });

    it('has all-null defaults except middleware', function () {
        $group = new Group;

        expect($group->prefix)->toBeNull()
            ->and($group->middleware)->toBe([])
            ->and($group->as)->toBeNull()
            ->and($group->domain)->toBeNull();
    });

    it('stores prefix, middleware, as, and domain', function () {
        $group = new Group(
            prefix: '/admin',
            middleware: ['auth', 'admin'],
            as: 'admin.',
            domain: 'admin.example.com',
        );

        expect($group->prefix)->toBe('/admin')
            ->and($group->middleware)->toBe(['auth', 'admin'])
            ->and($group->as)->toBe('admin.')
            ->and($group->domain)->toBe('admin.example.com');
    });

    it('wraps single middleware string to array', function () {
        $group = new Group(middleware: 'auth');

        expect($group->middleware)->toBe(['auth']);
    });
});
