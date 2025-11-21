<?php

namespace Dancycodes\Gale\Routing\Attributes;

use Attribute;

/**
 * Soft-Deleted Model Binding Attribute
 *
 * Configures route model binding to include soft-deleted Eloquent models when
 * resolving route parameters. By default, Laravel's implicit route model binding
 * excludes models with non-null deleted_at timestamps. This attribute overrides
 * that behavior to allow accessing trashed models through route parameters.
 *
 * When applied to a controller class, all routes within that controller include
 * soft-deleted models in binding resolution. When applied to individual methods,
 * only that specific route includes trashed models.
 *
 * Only affects models using Laravel's SoftDeletes trait. Models without soft-delete
 * functionality are unaffected by this attribute.
 *
 * @see \Dancycodes\Gale\Routing\Discovery\Discover
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleWithTrashedAttribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WithTrashed implements DiscoveryAttribute {}
