<?php

/**
 * Gale Package Global Helper Functions
 *
 * Provides Laravel-style global helper functions for accessing Gale services
 * throughout the application. These helpers follow Laravel's conventions for
 * global functions, mirroring patterns established by view(), request(), response(),
 * and other framework helpers.
 *
 * All helpers retrieve scoped instances from the service container, ensuring
 * consistent state throughout the request lifecycle. Functions are conditionally
 * defined to prevent conflicts with application-level helper definitions.
 *
 *
 * @see \Dancycodes\Gale\Http\GaleResponse
 */
if (!function_exists('gale')) {
    /**
     * Get the scoped GaleResponse builder instance
     *
     * Returns the request-scoped GaleResponse instance from the container.
     * Using scoped binding ensures the same instance is returned throughout
     * a single request, allowing events to accumulate across multiple calls.
     * The instance is automatically fresh for each new request.
     *
     * @return \Dancycodes\Gale\Http\GaleResponse Request-scoped response builder instance
     *
     * @see \Dancycodes\Gale\Http\GaleResponse
     */
    function gale(): \Dancycodes\Gale\Http\GaleResponse
    {
        return app('gale.response');
    }
}
