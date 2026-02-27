<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Gale Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Laravel Gale - a seamless integration of Alpine Gale
    | with Laravel. These settings control route discovery and the default
    | response mode for the Gale reactive response system.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Response Mode
    |--------------------------------------------------------------------------
    |
    | Controls how GaleResponse serializes its output by default. Valid values
    | are 'http' (JSON response) and 'sse' (Server-Sent Events stream).
    |
    | - 'http': Responds with Content-Type: application/json containing a JSON
    |           payload of batched events. This is the default and recommended
    |           mode for most deployments.
    |
    | - 'sse':  Responds with Content-Type: text/event-stream, streaming events
    |           using the Server-Sent Events protocol. Use this if your entire
    |           application relies on SSE transport.
    |
    | Individual requests can override this via the Gale-Mode request header,
    | and gale()->stream() always uses SSE regardless of this setting.
    |
    */

    'mode' => env('GALE_MODE', 'http'),

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
