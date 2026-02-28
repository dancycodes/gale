<?php

namespace Dancycodes\Gale\Routing\Attributes;

use Attribute;

/**
 * Rate Limit Route Attribute
 *
 * Applies Laravel's throttle middleware to a route via PHP attributes. Translates
 * to `throttle:{maxAttempts},{decayMinutes}` middleware automatically during route
 * registration, using Laravel's built-in RateLimiter system.
 *
 * A named rate limiter can be used instead of inline limits by specifying the
 * `limiter` parameter. Named limiters must be registered in AppServiceProvider
 * using RateLimiter::for().
 *
 * Examples:
 *   #[RateLimit(60)]               -> throttle:60,1
 *   #[RateLimit(10, decayMinutes: 5)] -> throttle:10,5
 *   #[RateLimit(limiter: 'api')]   -> throttle:api
 *
 * @see \Dancycodes\Gale\Routing\PendingRouteTransformers\HandleMiddlewareAttribute
 * @see \Illuminate\Routing\Middleware\ThrottleRequests
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RateLimit implements DiscoveryAttribute
{
    /**
     * Initialize rate limit attribute
     *
     * @param int $maxAttempts Maximum number of requests allowed within the decay window
     * @param int $decayMinutes Time window in minutes before the rate limit resets
     * @param string|null $limiter Named rate limiter defined via RateLimiter::for()
     */
    public function __construct(
        public int $maxAttempts = 60,
        public int $decayMinutes = 1,
        public ?string $limiter = null,
    ) {}

    /**
     * Convert rate limit configuration to throttle middleware string
     *
     * Returns named limiter format (`throttle:{limiter}`) when a named limiter is
     * specified, otherwise returns inline format (`throttle:{maxAttempts},{decayMinutes}`).
     *
     * @return string Throttle middleware string for Laravel's router
     */
    public function toMiddlewareString(): string
    {
        if ($this->limiter !== null) {
            return "throttle:{$this->limiter}";
        }

        return "throttle:{$this->maxAttempts},{$this->decayMinutes}";
    }
}
