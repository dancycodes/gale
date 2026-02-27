<?php

namespace Dancycodes\Gale\Routing;

use Dancycodes\Gale\Routing\PendingRouteTransformers\AddControllerUriToActions;
use Dancycodes\Gale\Routing\PendingRouteTransformers\AddDefaultRouteName;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDomainAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDoNotDiscoverAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleFullUriAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleHttpMethodsAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleMiddlewareAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleRouteNameAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleUriAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleUrisOfNestedControllers;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleWheresAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleWithTrashedAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\MoveRoutesStartingWithParametersLast;
use Dancycodes\Gale\Routing\PendingRouteTransformers\RejectDefaultControllerMethodRoutes;
use Dancycodes\Gale\Routing\PendingRouteTransformers\ValidateOptionalParameters;

class Config
{
    /**
     * @return array<class-string>
     */
    public static function defaultRouteTransformers(): array
    {
        return [
            RejectDefaultControllerMethodRoutes::class,
            HandleDoNotDiscoverAttribute::class,
            AddControllerUriToActions::class,
            HandleUrisOfNestedControllers::class,
            HandleRouteNameAttribute::class,
            HandleMiddlewareAttribute::class,
            HandleHttpMethodsAttribute::class,
            HandleUriAttribute::class,
            HandleFullUriAttribute::class,
            HandleWithTrashedAttribute::class,
            HandleWheresAttribute::class,
            AddDefaultRouteName::class,
            HandleDomainAttribute::class,
            ValidateOptionalParameters::class,
            MoveRoutesStartingWithParametersLast::class,
        ];
    }
}
