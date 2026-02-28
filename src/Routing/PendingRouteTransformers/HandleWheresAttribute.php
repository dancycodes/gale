<?php

namespace Dancycodes\Gale\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\Attributes\Where;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRoute;
use Dancycodes\Gale\Routing\PendingRoutes\PendingRouteAction;
use Illuminate\Support\Collection;
use ReflectionAttribute;

/**
 * Where Constraint Attribute Handler Transformer
 *
 * Applies regular expression constraints from Where attributes to route parameters
 * during discovery. Supports constraint cascading where controller-level Where
 * attributes apply to all methods containing matching parameters, merged with
 * method-level Where constraints.
 *
 * Multiple Where attributes can be applied to the same controller or method to
 * constrain different parameters with separate regular expressions. The Where
 * attribute is marked IS_REPEATABLE, so all instances are collected and applied.
 *
 * @see \Dancycodes\Gale\Routing\Attributes\Where
 * @see \Dancycodes\Gale\Routing\RouteRegistrar
 */
class HandleWheresAttribute implements PendingRouteTransformer
{
    /**
     * Transform pending routes by applying parameter constraints from Where attributes
     *
     * First applies ALL controller-level Where constraints if present, then applies
     * ALL method-level Where constraints which are merged with (not replaced by) controller
     * constraints. When multiple Where attributes target the same parameter, later
     * constraints override earlier ones.
     *
     * @param  Collection<int, PendingRoute>  $pendingRoutes  Pending routes to transform
     * @return Collection<int, PendingRoute> Transformed pending routes with parameter constraints
     */
    public function transform(Collection $pendingRoutes): Collection
    {
        $pendingRoutes->each(function (PendingRoute $pendingRoute) {
            $pendingRoute->actions->each(function (PendingRouteAction $action) use ($pendingRoute) {
                // Apply ALL class-level Where attributes (IS_REPEATABLE supports multiples)
                foreach ($pendingRoute->class->getAttributes(Where::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                    $whereAttribute = $attr->newInstance();
                    if ($whereAttribute instanceof Where) {
                        $action->addWhere($whereAttribute);
                    }
                }

                // Apply ALL method-level Where attributes (override class-level for same param)
                foreach ($action->method->getAttributes(Where::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                    $whereAttribute = $attr->newInstance();
                    if ($whereAttribute instanceof Where) {
                        $action->addWhere($whereAttribute);
                    }
                }
            });
        });

        return $pendingRoutes;
    }
}
