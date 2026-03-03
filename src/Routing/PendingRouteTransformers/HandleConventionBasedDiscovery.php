<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\Attributes\NoAutoDiscovery;
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Convention-Based Discovery Transformer
 *
 * Implements convention-over-configuration route registration for CRUD controller methods.
 * Filters out non-conventional public methods that do not have explicit #[Route] attributes,
 * and fixes URI patterns to match Laravel's RESTful resource route conventions.
 *
 * Also pluralizes the controller's base URI (e.g., ContactController -> contacts).
 *
 * Controllers annotated with #[NoAutoDiscovery] are completely skipped by convention-based
 * logic, preserving any explicitly-attributed methods they may have.
 *
 * Convention table (relative URIs before controller prefix is prepended):
 * - index()          -> GET    ''          (resolves to /{resource})
 * - create()         -> GET    'create'    (resolves to /{resource}/create)
 * - store()          -> POST   ''          (resolves to /{resource})
 * - show($model)     -> GET    '{model}'   (resolves to /{resource}/{model})
 * - edit($model)     -> GET    '{model}/edit' (resolves to /{resource}/{model}/edit)
 * - update($model)   -> PUT|PATCH '{model}' (resolves to /{resource}/{model})
 * - destroy($model)  -> DELETE '{model}'   (resolves to /{resource}/{model})
 *
 * @see \Dancycodes\Gale\Routing\Attributes\NoAutoDiscovery
 * @see \Dancycodes\Gale\Routing\Attributes\Route
 */
class HandleConventionBasedDiscovery implements PendingRouteTransformer
{
    /**
     * CRUD method names that are eligible for convention-based auto-discovery
     *
     * @var array<int, string>
     */
    protected array $conventionalMethods = [
        'index',
        'create',
        'store',
        'show',
        'edit',
        'update',
        'destroy',
    ];

    /**
     * Canonical relative URI segments for each CRUD method.
     * Values are null when the URI needs to be derived from method parameters.
     * For show/edit/update/destroy: the first model parameter becomes the route segment.
     *
     * @var array<string, string|null>
     */
    protected array $conventionalUris = [
        'index' => '',
        'create' => 'create',
        'store' => '',
        'show' => null,   // {model}
        'edit' => null,   // {model}/edit
        'update' => null,   // {model}
        'destroy' => null,   // {model}
    ];

    /**
     * Transform pending routes by filtering non-conventional methods and correcting URIs
     *
     * When config('gale.route_discovery.conventions') is false, this transformer is a no-op
     * and returns routes unmodified.
     *
     * For controllers without #[NoAutoDiscovery]:
     * - Removes actions whose method names are not in the conventional CRUD set AND
     *   have no explicit #[Route] attribute.
     * - Fixes URIs for CRUD methods to match Laravel resource conventions.
     * - Pluralizes the controller's base URI resource name.
     *
     * For controllers with #[NoAutoDiscovery]:
     * - Removes all actions that do NOT have an explicit #[Route] attribute.
     *   Convention-based discovery is entirely disabled for that controller.
     *
     * @param Collection<int, PendingRoute> $pendingRoutes Pending routes to transform
     *
     * @return Collection<int, PendingRoute> Filtered, URI-corrected pending routes
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        if (!config('gale.route_discovery.conventions', true)) {
            return $pendingRoutes;
        }

        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            $hasNoAutoDiscovery = (bool) $pendingRoute->getAttribute(NoAutoDiscovery::class);

            /** @var Collection<int, PendingRouteAction> $filteredActions */
            $filteredActions = $pendingRoute->actions->filter(function (PendingRouteAction $action) use ($hasNoAutoDiscovery): bool {
                $hasExplicitRoute = $action->getAttribute(Route::class) !== null;

                if ($hasNoAutoDiscovery) {
                    // When #[NoAutoDiscovery] is set, only keep methods with explicit #[Route]
                    return $hasExplicitRoute;
                }

                // Without #[NoAutoDiscovery]: keep if conventional CRUD name OR has explicit #[Route]
                $isConventional = in_array($action->method->name, $this->conventionalMethods, true);

                return $isConventional || $hasExplicitRoute;
            });

            // Pluralize the controller's base URI for convention-based controllers
            if (!$hasNoAutoDiscovery) {
                $pendingRoute->uri = $this->pluralizeUri($pendingRoute->uri);
            }

            // Correct the relative URI for each conventional CRUD action
            $filteredActions->each(function (PendingRouteAction $action) use ($hasNoAutoDiscovery) {
                // Only fix URIs for convention-based methods (not explicit #[Route] overrides)
                if ($hasNoAutoDiscovery) {
                    return;
                }

                if ($action->getAttribute(Route::class) !== null) {
                    return;
                }

                if (!in_array($action->method->name, $this->conventionalMethods, true)) {
                    return;
                }

                $action->uri = $this->buildConventionalRelativeUri($action);
            });

            $pendingRoute->actions = $filteredActions->values();
        });

        return $pendingRoutes;
    }

    /**
     * Build the canonical relative URI for a CRUD action
     *
     * Derives the relative URI based on the method name and its first model/scalar
     * parameter. The controller base URI will be prepended by AddControllerUriToActions.
     *
     * @param PendingRouteAction $action The pending route action
     *
     * @return string Relative URI segment
     */
    protected function buildConventionalRelativeUri(PendingRouteAction $action): string
    {
        $methodName = $action->method->name;

        return match ($methodName) {
            'index', 'store' => '',
            'create' => 'create',
            'show', 'update', 'destroy' => $this->buildParamSegment($action),
            'edit' => $this->buildParamSegment($action) . '/edit',
            default => $action->uri,
        };
    }

    /**
     * Build a URI parameter segment from the action's first URL parameter
     *
     * Extracts the first URL-eligible parameter from the method signature and returns
     * it as a route parameter placeholder (e.g., {contact}).
     *
     * @param PendingRouteAction $action The pending route action
     *
     * @return string Parameter segment like {contact}
     */
    protected function buildParamSegment(PendingRouteAction $action): string
    {
        /** @var \Illuminate\Support\Collection<int, \ReflectionParameter> $urlParams */
        $urlParams = $action->modelParameters;

        if ($urlParams->isEmpty()) {
            // Fall back to any URL-typed scalar parameter
            $urlParams = collect($action->method->getParameters())->filter(function (\ReflectionParameter $p) {
                $type = $p->getType();

                if (!$type instanceof \ReflectionNamedType) {
                    return false;
                }

                return in_array($type->getName(), ['int', 'string', 'float', 'bool', 'mixed'], true);
            });
        }

        if ($urlParams->isEmpty()) {
            return '';
        }

        $firstParam = $urlParams->first();

        return '{' . $firstParam->getName() . '}';
    }

    /**
     * Pluralize the last segment of a URI path
     *
     * Converts the final path component to plural form using Laravel's Str::plural.
     * Only the last segment is pluralized; intermediate path segments are preserved.
     *
     * Examples:
     *   contact  -> contacts
     *   user-profile -> user-profiles
     *   admin/contact -> admin/contacts
     *
     * @param string $uri Controller URI derived from filesystem path
     *
     * @return string URI with final segment pluralized
     */
    protected function pluralizeUri(string $uri): string
    {
        if ($uri === '') {
            return $uri;
        }

        $segments = explode('/', $uri);
        $lastIndex = count($segments) - 1;
        $segments[$lastIndex] = Str::plural($segments[$lastIndex]);

        return implode('/', $segments);
    }
}
