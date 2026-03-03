<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\Attributes\Group;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;

/**
 * URI Attribute Handler Transformer
 *
 * Applies custom URI segments from Route attributes to replace automatically
 * generated method-based URI segments during discovery. The uri property replaces
 * only the method-specific portion of the URI while preserving the controller
 * prefix (either declared via #[Prefix] or derived from the filesystem path).
 *
 * Unlike fullUri which replaces the entire URI, this property replaces only the
 * method segment, enabling surgical customization while maintaining the controller's
 * path structure.
 *
 * @see \Dancycodes\Gale\Routing\Attributes\Route
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class HandleUriAttribute implements PendingRouteTransformer
{
    /**
     * Transform pending routes by applying custom URI segments from Route attributes
     *
     * Checks each action for Route attribute with uri property. When present, determines
     * the controller base prefix (from #[Prefix] attribute or filesystem-derived URI),
     * then replaces the method-specific portion with the custom URI from the attribute.
     * Strips leading slashes from the custom URI to prevent double-slash paths.
     *
     * @param Collection<int, PendingRoute> $pendingRoutes Pending routes to transform
     *
     * @return Collection<int, PendingRoute> Transformed pending routes with custom URI segments
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            // Determine the controller-level base URI.
            // After HandlePrefixAttribute / HandleGroupAttribute runs, the action URIs
            // start with the declared prefix. We resolve the effective controller base
            // to know what to keep when applying a custom method URI segment.
            $prefixAttribute = $pendingRoute->getAttribute(Prefix::class);
            $groupAttribute = $pendingRoute->getAttribute(Group::class);

            if ($prefixAttribute instanceof Prefix) {
                $controllerBase = trim($prefixAttribute->prefix, '/');
            } elseif ($groupAttribute instanceof Group && $groupAttribute->prefix !== null) {
                $controllerBase = trim($groupAttribute->prefix, '/');
            } else {
                $controllerBase = $pendingRoute->uri;
            }

            $pendingRoute->actions->each(function (PendingRouteAction $action) use ($controllerBase) {
                $routeAttribute = $action->getRouteAttribute();

                if (!$routeAttribute instanceof Route) {
                    return;
                }

                if (!$routeAttribute->uri) {
                    return;
                }

                $customSegment = ltrim($routeAttribute->uri, '/');

                // Build the new URI: controller base + custom method segment
                if ($controllerBase === '') {
                    $action->uri = $customSegment;
                } else {
                    $action->uri = $controllerBase . '/' . $customSegment;
                }
            });
        });

        return $pendingRoutes;
    }
}
