<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Gale Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Laravel Gale - a seamless integration of Alpine Gale
    | with Laravel. These settings control route discovery and behavior of
    | the Gale SSE response system.
    |
    */

    'route_discovery' => [
        'enabled' => false,  // Opt-in by default

        /*
         * Routes will be registered for all controllers found in
         * these directories.
         */
        'discover_controllers_in_directory' => [
            // app_path('Http/Controllers'),
        ],

        /*
         * Routes will be registered for all views found in these directories.
         * The key of an item will be used as the prefix of the uri.
         */
        'discover_views_in_directory' => [
            // 'docs' => resource_path('views/docs'),
        ],

        /*
         * After having discovered all controllers, these classes will manipulate the routes
         * before registering them to Laravel.
         */
        'pending_route_transformers' => [
            ...Dancycodes\Gale\Routing\Config::defaultRouteTransformers(),
        ],
    ],
];
