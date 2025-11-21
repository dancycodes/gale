<?php

/**
 * Gale Package Global Helper Functions
 *
 * Provides Laravel-style global helper functions for accessing Gale services
 * throughout the application. These helpers follow Laravel's conventions for
 * global functions, mirroring patterns established by view(), request(), response(),
 * and other framework helpers.
 *
 * All helpers retrieve singleton instances from the service container, ensuring
 * consistent state throughout the request lifecycle. Functions are conditionally
 * defined to prevent conflicts with application-level helper definitions.
 *
 *
 * @see \Dancycodes\Gale\Http\GaleResponse
 * @see \Dancycodes\Gale\Services\GaleFileStorage
 */
if (!function_exists('gale')) {
    /**
     * Create a fresh GaleResponse builder instance
     *
     * Returns a new GaleResponse instance each time it's called. This ensures
     * clean state for each route handler, preventing state bleeding between
     * requests in long-running processes (tests, PHP-FPM worker reuse, etc.).
     *
     * For building complex responses incrementally within a single route handler,
     * store the result in a variable and chain methods on it.
     *
     * @return \Dancycodes\Gale\Http\GaleResponse Fresh response builder instance
     *
     * @see \Dancycodes\Gale\Http\GaleResponse
     */
    function gale(): \Dancycodes\Gale\Http\GaleResponse
    {
        return new \Dancycodes\Gale\Http\GaleResponse;
    }
}

if (!function_exists('galeStorage')) {
    /**
     * Access the GaleFileStorage service for base64 file operations
     *
     * Retrieves the singleton GaleFileStorage instance that handles storing,
     * validating, and converting base64-encoded files transmitted through Gale
     * state. Provides methods for storing files to Laravel's filesystem and
     * retrieving public URLs.
     *
     * This service seamlessly integrates base64 file uploads from Alpine Gale's
     * client-side encoding with Laravel's storage system, handling MIME type
     * detection, file validation, and storage disk management.
     *
     * @return \Dancycodes\Gale\Services\GaleFileStorage Singleton storage service instance
     *
     * @see \Dancycodes\Gale\Services\GaleFileStorage
     * @see \Dancycodes\Gale\Validation\GaleBase64Validator
     */
    function galeStorage(): \Dancycodes\Gale\Services\GaleFileStorage
    {
        return app(\Dancycodes\Gale\Services\GaleFileStorage::class);
    }
}
