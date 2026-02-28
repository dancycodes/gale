<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;

/**
 * Prefix Attribute Handler Transformer
 *
 * Applies URI prefixes from Prefix attributes on controller classes to all
 * method-level route actions during discovery. When a controller is decorated
 * with #[Prefix('/admin')], all routes in that controller are registered under
 * /admin/{method-uri} rather than the filesystem-derived URI.
 *
 * This transformer replaces the auto-generated controller URI segment (derived
 * from the filesystem path) with the explicitly declared prefix, giving developers
 * full control over route path structure independent of directory layout.
 *
 * Executes after AddControllerUriToActions so the prefix can replace the
 * filesystem-derived segment already applied to action URIs.
 *
 * @see \Dancycodes\Gale\Routing\Attributes\Prefix
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class HandlePrefixAttribute implements PendingRouteTransformer
{
    /**
     * Transform pending routes by applying Prefix attribute to controller action URIs
     *
     * Checks each controller for a Prefix attribute. When found, replaces the
     * filesystem-derived controller URI segment in every action URI with the
     * declared prefix. The replacement is applied to the beginning of each
     * action URI, stripping leading/trailing slashes for consistent formatting.
     *
     * @param  Collection<int, PendingRoute>  $pendingRoutes  Pending routes to transform
     * @return Collection<int, PendingRoute> Transformed pending routes with applied prefixes
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            $prefixAttribute = $pendingRoute->getAttribute(Prefix::class);

            if (! $prefixAttribute instanceof Prefix) {
                return;
            }

            $prefix = trim($prefixAttribute->prefix, '/');
            $controllerUri = $pendingRoute->uri;

            $pendingRoute->actions->each(function (PendingRouteAction $action) use ($prefix, $controllerUri) {
                $actionUri = $action->uri;

                // Replace the filesystem-derived controller segment at the start of the URI
                // with the declared prefix. Handles both 'controller-name/method' and
                // 'controller-name' (index/invoke with no trailing method segment).
                if ($controllerUri === '' || $actionUri === $controllerUri) {
                    // Index/invoke method: action URI is just the controller URI
                    $action->uri = $prefix;
                } elseif (str_starts_with($actionUri, $controllerUri.'/')) {
                    // Method URI: replace the controller segment prefix
                    $methodSegment = substr($actionUri, strlen($controllerUri) + 1);
                    $action->uri = $prefix.'/'.$methodSegment;
                } else {
                    // Fallback: prepend prefix to whatever URI the action has
                    $action->uri = $prefix.'/'.ltrim($actionUri, '/');
                }
            });
        });

        return $pendingRoutes;
    }
}
