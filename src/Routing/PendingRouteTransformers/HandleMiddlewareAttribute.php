<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\Attributes\Middleware;
use Dancycodes\Gale\Routing\Attributes\RateLimit;
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;
use ReflectionAttribute;

/**
 * Middleware Attribute Handler Transformer
 *
 * Applies middleware configuration from Route, Middleware, and RateLimit attributes
 * to pending route actions during discovery. Supports middleware cascading where
 * controller-level middleware is inherited by all methods and merged with method-level
 * middleware declarations.
 *
 * Processing order:
 *   1. Class-level #[Route(middleware: ...)] attributes
 *   2. Class-level #[Middleware(...)] attributes (all instances, IS_REPEATABLE)
 *   3. Class-level #[RateLimit(...)] attributes
 *   4. Method-level #[Route(middleware: ...)] attributes
 *   5. Method-level #[Middleware(...)] attributes (all instances, IS_REPEATABLE)
 *   6. Method-level #[RateLimit(...)] attributes
 *
 * Middleware from controller attributes applies first, followed by method attributes,
 * with duplicates removed to prevent double-execution of the same middleware.
 *
 * @see \Dancycodes\Gale\Routing\Attributes\Middleware
 * @see \Dancycodes\Gale\Routing\Attributes\RateLimit
 * @see \Dancycodes\Gale\Routing\Attributes\Route
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class HandleMiddlewareAttribute implements PendingRouteTransformer
{
    /**
     * Transform pending routes by applying middleware from all middleware-related attributes
     *
     * Iterates each pending route and its actions, collecting middleware from class-level
     * and method-level Route, Middleware, and RateLimit attributes. Class-level middleware
     * applies first to every action; method-level middleware stacks on top. Duplicates
     * are removed during merge to ensure each middleware executes only once per request.
     *
     * @param Collection<int, PendingRoute> $pendingRoutes Pending routes to transform
     *
     * @return Collection<int, PendingRoute> Transformed pending routes with merged middleware
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            $classMiddleware = $this->collectClassMiddleware($pendingRoute);

            $pendingRoute->actions->each(function (PendingRouteAction $action) use ($classMiddleware) {
                // Apply class-level middleware first
                if ($classMiddleware) {
                    $action->addMiddleware($classMiddleware);
                }

                // Apply method-level middleware on top
                $methodMiddleware = $this->collectMethodMiddleware($action);

                if ($methodMiddleware) {
                    $action->addMiddleware($methodMiddleware);
                }
            });
        });

        return $pendingRoutes;
    }

    /**
     * Collect all middleware strings from class-level attributes
     *
     * Reads #[Route(middleware: ...)], all #[Middleware(...)] instances (repeatable),
     * and #[RateLimit(...)] from the controller class.
     *
     * @param PendingRoute $pendingRoute The pending route to inspect
     *
     * @return array<int, string> Middleware strings from class-level attributes
     */
    protected function collectClassMiddleware(PendingRoute $pendingRoute): array
    {
        $middleware = [];

        // From #[Route(middleware: ...)] on class
        $routeAttribute = $pendingRoute->getRouteAttribute();

        if ($routeAttribute instanceof Route && $routeAttribute->middleware) {
            $middleware = array_merge($middleware, $routeAttribute->middleware);
        }

        // From #[Middleware(...)] on class — IS_REPEATABLE so collect all instances
        $middlewareAttributes = $pendingRoute->class->getAttributes(
            Middleware::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        foreach ($middlewareAttributes as $reflectionAttribute) {
            /** @var Middleware $instance */
            $instance = $reflectionAttribute->newInstance();
            $middleware = array_merge($middleware, $instance->middleware);
        }

        // From #[RateLimit(...)] on class
        $rateLimitAttributes = $pendingRoute->class->getAttributes(
            RateLimit::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        foreach ($rateLimitAttributes as $reflectionAttribute) {
            /** @var RateLimit $instance */
            $instance = $reflectionAttribute->newInstance();
            $middleware[] = $instance->toMiddlewareString();
        }

        return $middleware;
    }

    /**
     * Collect all middleware strings from method-level attributes
     *
     * Reads #[Route(middleware: ...)], all #[Middleware(...)] instances (repeatable),
     * and #[RateLimit(...)] from the controller method.
     *
     * @param PendingRouteAction $action The pending route action to inspect
     *
     * @return array<int, string> Middleware strings from method-level attributes
     */
    protected function collectMethodMiddleware(PendingRouteAction $action): array
    {
        $middleware = [];

        // From #[Route(middleware: ...)] on method
        $routeAttribute = $action->getRouteAttribute();

        if ($routeAttribute instanceof Route && $routeAttribute->middleware) {
            $middleware = array_merge($middleware, $routeAttribute->middleware);
        }

        // From #[Middleware(...)] on method — IS_REPEATABLE so collect all instances
        $middlewareAttributes = $action->method->getAttributes(
            Middleware::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        foreach ($middlewareAttributes as $reflectionAttribute) {
            /** @var Middleware $instance */
            $instance = $reflectionAttribute->newInstance();
            $middleware = array_merge($middleware, $instance->middleware);
        }

        // From #[RateLimit(...)] on method
        $rateLimitAttributes = $action->method->getAttributes(
            RateLimit::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        foreach ($rateLimitAttributes as $reflectionAttribute) {
            /** @var RateLimit $instance */
            $instance = $reflectionAttribute->newInstance();
            $middleware[] = $instance->toMiddlewareString();
        }

        return $middleware;
    }
}
