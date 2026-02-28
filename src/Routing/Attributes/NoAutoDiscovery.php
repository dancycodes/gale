<?php

namespace Dancycodes\Gale\Routing\Attributes;

use Attribute;

/**
 * No Auto-Discovery Attribute
 *
 * Disables convention-based controller method auto-discovery for the annotated controller.
 * When applied to a controller class, none of its methods are auto-registered based on
 * naming conventions (index, show, store, etc.). Methods must use explicit #[Route]
 * attributes or traditional web.php definitions to be registered.
 *
 * This differs from #[DoNotDiscover] which prevents any route discovery entirely.
 * #[NoAutoDiscovery] only disables the convention-based approach while still allowing
 * explicit #[Route] attribute registrations to work normally.
 *
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleConventionBasedDiscovery
 */
#[Attribute(Attribute::TARGET_CLASS)]
class NoAutoDiscovery implements DiscoveryAttribute {}
