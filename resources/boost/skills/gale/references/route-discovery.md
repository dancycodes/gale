# Route Discovery Reference

Complete reference for PHP 8 attribute-based routing with automatic route registration.

## Overview

Route discovery scans controller classes for PHP 8 attributes and automatically registers routes. Disabled by default.

```php
// config/gale.php
'route_discovery' => [
    'enabled' => false,      // Set to true to enable
    'conventions' => true,   // Auto-register CRUD method names
],
```

## Attributes

### #[Route]

Configure route registration for a controller method or class.

**Target**: Class or Method

```php
use Dancycodes\Gale\Routing\Attributes\Route;

class ProductController extends Controller
{
    // Explicit method and URI
    #[Route(method: 'GET', uri: '/products')]
    public function index() { ... }

    // Multiple HTTP methods
    #[Route(method: ['GET', 'POST'], uri: '/products/search')]
    public function search() { ... }

    // Named route
    #[Route(method: 'GET', uri: '/products/{product}', name: 'products.show')]
    public function show(Product $product) { ... }

    // With middleware
    #[Route(method: 'POST', uri: '/products', middleware: ['auth'])]
    public function store() { ... }

    // Full URI override (bypasses all transformers)
    #[Route(method: 'GET', fullUri: '/api/v2/products')]
    public function apiIndex() { ... }

    // Domain constraint
    #[Route(method: 'GET', uri: '/dashboard', domain: 'admin.example.com')]
    public function dashboard() { ... }

    // Soft-deleted model binding
    #[Route(method: 'GET', uri: '/products/{product}', withTrashed: true)]
    public function showTrashed(Product $product) { ... }
}
```

**Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `method` | string\|array | `[]` | HTTP methods: GET, POST, PUT, PATCH, DELETE, etc. |
| `uri` | ?string | `null` | Custom URI pattern (auto-generated if null) |
| `fullUri` | ?string | `null` | Complete URI override (bypasses all transformers) |
| `name` | ?string | `null` | Route name for `route()` helper |
| `middleware` | string\|array | `[]` | Middleware to apply |
| `domain` | ?string | `null` | Domain constraint |
| `withTrashed` | bool | `false` | Include soft-deleted models in route model binding |

HTTP methods are normalized to uppercase and validated against Laravel's Router verb list.

### #[Group]

Apply shared settings to all routes in a controller.

**Target**: Class only

```php
use Dancycodes\Gale\Routing\Attributes\Group;

#[Group(prefix: '/admin', middleware: ['auth'], as: 'admin.', domain: 'admin.example.com')]
class AdminController extends Controller
{
    // Route: GET /admin/dashboard, name: admin.dashboard
    #[Route(method: 'GET', name: 'dashboard')]
    public function dashboard() { ... }
}
```

**Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `prefix` | ?string | `null` | URI prefix for all routes |
| `middleware` | string\|array | `[]` | Middleware for all routes |
| `as` | ?string | `null` | Name prefix for all routes |
| `domain` | ?string | `null` | Domain constraint for all routes |

Cannot be used on the same class as `#[Prefix]`.

### #[Prefix]

Simple URI prefix for all controller routes.

**Target**: Class only

```php
use Dancycodes\Gale\Routing\Attributes\Prefix;

#[Prefix('/api/v1')]
class ApiController extends Controller
{
    // Route: GET /api/v1/users
    #[Route(method: 'GET', uri: '/users')]
    public function index() { ... }
}
```

### #[Middleware]

Apply middleware to controller routes. Repeatable.

**Target**: Class or Method

```php
use Dancycodes\Gale\Routing\Attributes\Middleware;

#[Middleware('auth')]
class DashboardController extends Controller
{
    // Inherits class-level 'auth' middleware
    #[Route(method: 'GET')]
    public function index() { ... }

    // Stacks: 'auth' + 'can:manage-users'
    #[Middleware('can:manage-users')]
    #[Route(method: 'GET', uri: '/users')]
    public function users() { ... }
}
```

Method-level middleware stacks on top of class-level (does not replace).

### #[RateLimit]

Apply throttle middleware via attributes.

**Target**: Class or Method

```php
use Dancycodes\Gale\Routing\Attributes\RateLimit;

// 60 requests per minute (default)
#[RateLimit(60)]
public function index() { ... }

// 10 requests per 5 minutes
#[RateLimit(10, decayMinutes: 5)]
public function store() { ... }

// Named rate limiter (defined in AppServiceProvider)
#[RateLimit(limiter: 'api')]
public function apiIndex() { ... }
```

Translates to:
- `#[RateLimit(60)]` → `throttle:60,1`
- `#[RateLimit(10, decayMinutes: 5)]` → `throttle:10,5`
- `#[RateLimit(limiter: 'api')]` → `throttle:api`

### #[Where]

Route parameter constraints via regex patterns.

**Target**: Class or Method. Repeatable.

```php
use Dancycodes\Gale\Routing\Attributes\Where;

#[Where(param: 'id', constraint: Where::numeric)]
#[Route(method: 'GET', uri: '/products/{id}')]
public function show(int $id) { ... }

// Custom regex
#[Where(param: 'slug', constraint: '[a-z0-9-]+')]
#[Route(method: 'GET', uri: '/posts/{slug}')]
public function showBySlug(string $slug) { ... }
```

**Predefined constants**:

| Constant | Pattern | Description |
|----------|---------|-------------|
| `Where::alpha` | `[a-zA-Z]+` | Alphabetic only |
| `Where::numeric` | `[0-9]+` | Numeric only |
| `Where::alphanumeric` | `[a-zA-Z0-9]+` | Alphanumeric only |
| `Where::uuid` | UUID regex | Standard UUID format |

Class-level constraints cascade to all methods with matching parameters.

### #[WithTrashed]

Include soft-deleted models in route model binding.

**Target**: Class or Method

```php
use Dancycodes\Gale\Routing\Attributes\WithTrashed;

#[WithTrashed]
#[Route(method: 'GET', uri: '/products/{product}')]
public function showWithTrashed(Product $product) { ... }
```

Can also be set via `#[Route(withTrashed: true)]`.

### #[DoNotDiscover]

Exclude a controller or method from route discovery.

**Target**: Class or Method

```php
use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;

// Exclude entire controller
#[DoNotDiscover]
class InternalController extends Controller { ... }

// Exclude single method
class ProductController extends Controller
{
    #[DoNotDiscover]
    public function internalHelper() { ... }
}
```

### #[NoAutoDiscovery]

Disable convention-based auto-discovery for a controller. Explicit `#[Route]` attributes still work.

**Target**: Class only

```php
use Dancycodes\Gale\Routing\Attributes\NoAutoDiscovery;

#[NoAutoDiscovery]
class ProductController extends Controller
{
    // NOT auto-registered (no #[Route] attribute)
    public function index() { ... }

    // IS registered (has explicit #[Route])
    #[Route(method: 'GET', uri: '/products/featured')]
    public function featured() { ... }
}
```

**Difference from #[DoNotDiscover]**: `DoNotDiscover` prevents ALL discovery. `NoAutoDiscovery` only disables convention-based matching while allowing explicit `#[Route]` attributes.

## Convention-Based Discovery

When `route_discovery.conventions` is `true` (default), methods with standard CRUD names are auto-registered without needing `#[Route]` attributes.

| Method Name | HTTP Method | URI Pattern |
|-------------|-------------|-------------|
| `index` | GET | `/` |
| `show` | GET | `/{model}` |
| `create` | GET | `/create` |
| `store` | POST | `/` |
| `edit` | GET | `/{model}/edit` |
| `update` | PUT/PATCH | `/{model}` |
| `destroy` | DELETE | `/{model}` |

The model parameter name is derived from the controller name (e.g., `ProductController` → `{product}`).

## Complete Example

```php
use Dancycodes\Gale\Routing\Attributes\{Group, Route, Middleware, RateLimit, Where};

#[Group(prefix: '/products', middleware: ['auth'], as: 'products.')]
class ProductController extends Controller
{
    // GET /products — name: products.index
    // Convention-based: auto-registered
    public function index()
    {
        return gale()->view('products.index', ['products' => Product::all()], [], web: true);
    }

    // GET /products/{product} — name: products.show
    #[Where(param: 'product', constraint: Where::numeric)]
    public function show(Product $product)
    {
        return gale()->view('products.show', compact('product'), [], web: true);
    }

    // POST /products — name: products.store
    #[RateLimit(10, decayMinutes: 1)]
    public function store(Request $request)
    {
        $product = Product::create($request->validated());
        return gale()->redirect()->route('products.show', $product);
    }

    // DELETE /products/{product} — name: products.destroy
    #[Middleware('can:delete,product')]
    public function destroy(Product $product)
    {
        $product->delete();
        return gale()->redirect()->route('products.index');
    }
}
```
