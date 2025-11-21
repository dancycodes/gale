<?php

namespace Dancycodes\Gale\Routing\Attributes;

use Attribute;

/**
 * Route Discovery Exclusion Attribute
 *
 * Prevents specific controllers or methods from being automatically registered
 * as routes during the route discovery process. When applied to a class, all
 * methods within that controller are excluded. When applied to a method, only
 * that specific action is excluded while other methods remain discoverable.
 *
 * This attribute is useful for utility methods, internal helper methods, or
 * controllers that should be registered manually through traditional route
 * definitions rather than automatic discovery.
 *
 * @see \Dancycodes\Gale\Routing\Discovery\Discover
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDoNotDiscoverAttribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class DoNotDiscover implements DiscoveryAttribute {}
