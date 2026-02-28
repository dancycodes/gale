<?php

namespace Dancycodes\Gale\Routing\Attributes;

use Attribute;
use Illuminate\Support\Arr;

/**
 * Middleware Route Attribute
 *
 * Applies middleware to controller routes during route discovery. When placed on a
 * controller class, the specified middleware applies to all routes in that controller.
 * When placed on a method, the middleware applies only to that method's route.
 *
 * Method-level middleware stacks on top of class-level middleware rather than replacing
 * it, enabling the pattern of protecting all routes with `auth` at class level while
 * adding role-specific middleware on individual methods.
 *
 * Supports middleware with parameters (e.g., 'can:edit,post') by passing the full
 * middleware string including parameters. Laravel's middleware system handles
 * parameter parsing transparently.
 *
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleMiddlewareAttribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware implements DiscoveryAttribute
{
    /** @var array<int, string> Middleware class names or aliases to apply */
    public array $middleware;

    /**
     * Initialize middleware attribute with one or more middleware names
     *
     * @param string ...$middleware Middleware aliases or class names to apply to the route
     */
    public function __construct(string ...$middleware)
    {
        $this->middleware = Arr::wrap($middleware);
    }
}
