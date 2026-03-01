<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\Attributes\Group;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Group Attribute Handler Transformer (URI + Middleware + Domain phase)
 *
 * Applies URI prefix, middleware, and domain settings from the #[Group] attribute
 * on controller classes to all method-level route actions during discovery.
 *
 * This transformer handles the prefix, middleware, and domain aspects of #[Group].
 * The name prefix (`as`) is applied by HandleGroupNamePrefix, which runs after
 * AddDefaultRouteName so that auto-generated names are also prefixed.
 *
 * Validation:
 * - Throws LogicException when both #[Prefix] and #[Group] are on the same class.
 * - An empty/null prefix in #[Group] is a no-op for URI transformation.
 *
 * Prefix normalization:
 * - Trailing slashes removed
 * - Leading slash auto-added
 * - Empty string after trimming = no prefix applied
 *
 * Middleware stacking:
 * - Group-level middleware is prepended to every action (applied before method middleware).
 * - Handled separately from HandleMiddlewareAttribute to keep concerns separated.
 *
 * Domain:
 * - Group-level domain is applied to every action.
 * - Method-level #[Route(domain:)] overrides it (applied later by HandleDomainAttribute).
 *
 * @see \Dancycodes\Gale\Routing\Attributes\Group
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleGroupNamePrefix
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandlePrefixAttribute
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class HandleGroupAttribute implements PendingRouteTransformer
{
    /**
     * Transform pending routes by applying Group attribute settings (prefix, middleware, domain)
     *
     * Validates that #[Prefix] and #[Group] are not used simultaneously. Then applies
     * the URI prefix, middleware, and domain from #[Group] to every action in the controller.
     *
     * @param  Collection<int, PendingRoute>  $pendingRoutes  Pending routes to transform
     * @return Collection<int, PendingRoute> Transformed pending routes with group settings applied
     *
     * @throws LogicException When both #[Prefix] and #[Group] are on the same class
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            $groupAttribute = $pendingRoute->getAttribute(Group::class);

            if (! $groupAttribute instanceof Group) {
                return;
            }

            // BR-F068-08: Both #[Prefix] and #[Group] on the same class is not allowed
            $prefixAttribute = $pendingRoute->getAttribute(Prefix::class);

            if ($prefixAttribute instanceof Prefix) {
                throw new LogicException(sprintf(
                    'Controller [%s] has both #[Prefix] and #[Group] attributes. Only one grouping mechanism is allowed per class.',
                    $pendingRoute->fullyQualifiedClassName,
                ));
            }

            $this->applyUriPrefix($pendingRoute, $groupAttribute);
            $this->applyMiddleware($pendingRoute, $groupAttribute);
            $this->applyDomain($pendingRoute, $groupAttribute);
        });

        return $pendingRoutes;
    }

    /**
     * Apply the URI prefix from #[Group] to all action URIs
     *
     * Replaces the filesystem-derived controller URI segment with the declared prefix.
     * Empty prefix (after normalization) is treated as a no-op.
     *
     * @param  PendingRoute  $pendingRoute  The pending route whose actions to update
     * @param  Group  $groupAttribute  The resolved Group attribute instance
     */
    protected function applyUriPrefix(PendingRoute $pendingRoute, Group $groupAttribute): void
    {
        if ($groupAttribute->prefix === null) {
            return;
        }

        $prefix = trim($groupAttribute->prefix, '/');

        // Empty prefix after normalization — treat as no-op (BR edge case)
        if ($prefix === '') {
            return;
        }

        $controllerUri = $pendingRoute->uri;

        $pendingRoute->actions->each(function (PendingRouteAction $action) use ($prefix, $controllerUri) {
            $actionUri = $action->uri;

            if ($controllerUri === '' || $actionUri === $controllerUri) {
                // Index / __invoke: action URI is just the controller URI
                $action->uri = $prefix;
            } elseif (str_starts_with($actionUri, $controllerUri.'/')) {
                // Method URI: replace the filesystem-derived controller segment
                $methodSegment = substr($actionUri, strlen($controllerUri) + 1);
                $action->uri = $prefix.'/'.$methodSegment;
            } else {
                // Fallback: prepend prefix to whatever URI the action has
                $action->uri = $prefix.'/'.ltrim($actionUri, '/');
            }
        });
    }

    /**
     * Apply middleware from #[Group] to all action middleware stacks
     *
     * Group-level middleware is prepended (applied before method middleware) by
     * calling addMiddleware() which deduplicates automatically.
     *
     * @param  PendingRoute  $pendingRoute  The pending route whose actions to update
     * @param  Group  $groupAttribute  The resolved Group attribute instance
     */
    protected function applyMiddleware(PendingRoute $pendingRoute, Group $groupAttribute): void
    {
        if (empty($groupAttribute->middleware)) {
            return;
        }

        $pendingRoute->actions->each(function (PendingRouteAction $action) use ($groupAttribute) {
            $action->addMiddleware($groupAttribute->middleware);
        });
    }

    /**
     * Apply domain constraint from #[Group] to all actions
     *
     * Sets domain on every action. Method-level #[Route(domain:)] may override
     * this later via HandleDomainAttribute.
     *
     * @param  PendingRoute  $pendingRoute  The pending route whose actions to update
     * @param  Group  $groupAttribute  The resolved Group attribute instance
     */
    protected function applyDomain(PendingRoute $pendingRoute, Group $groupAttribute): void
    {
        if ($groupAttribute->domain === null) {
            return;
        }

        $pendingRoute->actions->each(function (PendingRouteAction $action) use ($groupAttribute) {
            $action->domain = $groupAttribute->domain;
        });
    }
}
