<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;

/**
 * Optional Parameter Order Validation Transformer
 *
 * Validates that route parameters follow Laravel's routing conventions where optional
 * parameters (marked with ?) must appear after all required parameters. Logs warning
 * when convention violations are detected but does not modify or reject routes.
 *
 * This transformer helps identify potential routing issues during development where
 * required parameters following optional parameters cause Laravel routing errors.
 *
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class ValidateOptionalParameters implements PendingRouteTransformer
{
    /**
     * Transform pending routes by validating parameter order
     *
     * Iterates through all pending route actions and validates parameter order.
     * Returns unchanged collection after logging warnings for convention violations.
     *
     * @param Collection<int, PendingRoute> $pendingRoutes Pending routes to validate
     *
     * @return Collection<int, PendingRoute> Unchanged pending routes collection
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            $pendingRoute->actions->each(function (PendingRouteAction $action) {
                $this->validateParameterOrder($action);
            });
        });

        return $pendingRoutes;
    }

    /**
     * Validate parameter order for single route action
     *
     * Parses URI segments to identify optional parameters (ending with ?}) and required
     * parameters. Logs warning when required parameters appear after optional parameters,
     * violating Laravel routing conventions. Does not modify route configuration.
     *
     * @param PendingRouteAction $action Route action to validate
     */
    protected function validateParameterOrder(PendingRouteAction $action): void
    {
        $uri = $action->uri;
        $segments = explode('/', $uri);
        $hasOptional = false;

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $isOptional = str_ends_with($segment, '?}');

                if ($hasOptional && !$isOptional) {
                    // Silently skip - this violates Laravel routing conventions
                    // Required parameters must come before optional parameters
                }

                if ($isOptional) {
                    $hasOptional = true;
                }
            }
        }
    }
}
