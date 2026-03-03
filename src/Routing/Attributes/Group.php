<?php

namespace Dancycodes\Gale\Routing\Attributes;

use Attribute;
use Illuminate\Support\Arr;

/**
 * Route Group Attribute
 *
 * Applies a combination of prefix, middleware, name prefix, and domain settings
 * to all routes within a controller class during route discovery. Provides a
 * one-attribute alternative to combining #[Prefix], #[Middleware], and other
 * attributes individually.
 *
 * Only applicable to controller classes, not individual methods. Cannot be used
 * on the same class as #[Prefix] — only one grouping mechanism is allowed per class.
 *
 * Examples:
 *   #[Group(prefix: '/admin')]
 *   #[Group(prefix: '/api/v1', middleware: ['auth:sanctum'], as: 'api.v1.')]
 *   #[Group(domain: 'admin.{tenant}.example.com')]
 *   #[Group(prefix: '/admin', middleware: ['auth'], as: 'admin.', domain: 'admin.example.com')]
 *
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleGroupAttribute
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Group implements DiscoveryAttribute
{
    /** @var array<int, string> Middleware class names or aliases to apply to all routes */
    public array $middleware;

    /**
     * Initialize route group attribute
     *
     * @param string|null $prefix URI segment to prepend to all controller routes
     * @param array<int, string>|string $middleware Middleware to apply to all routes
     * @param string|null $as Name prefix prepended to all route names
     * @param string|null $domain Domain constraint applied to all routes
     */
    public function __construct(
        public ?string $prefix = null,
        array|string $middleware = [],
        public ?string $as = null,
        public ?string $domain = null,
    ) {
        $this->middleware = Arr::wrap($middleware);
    }
}
