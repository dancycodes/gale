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

    /*
    |--------------------------------------------------------------------------
    | Redirect Allowed Domains
    |--------------------------------------------------------------------------
    |
    | An optional list of domains (and wildcard patterns) that are explicitly
    | whitelisted for use with GaleRedirect::back() and GaleRedirect::intended().
    |
    | When this list is non-empty, a previous/intended URL whose host matches any
    | entry is accepted without further registrable-domain comparison.
    |
    | Supports:
    |   - Exact hostnames:  'example.com', 'app.example.com'
    |   - Wildcard prefix:  '*.example.com' (matches any subdomain AND the bare domain)
    |
    | When the list is empty (default), Gale falls back to registrable-domain
    | comparison (last two DNS labels), which accepts subdomain relationships such
    | as api.example.com ↔ app.example.com automatically.
    |
    */

    'redirect_allowed_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Blade Morph Markers (F-048)
    |--------------------------------------------------------------------------
    |
    | When enabled (default), Gale injects HTML comment markers around
    | @if, @foreach, @switch, @forelse, and other conditional/loop blocks:
    |
    |   <!--gale-block-start:{hash}-->
    |   <!--gale-block-end:{hash}-->
    |
    | These markers provide stable anchor points for the morph algorithm
    | so that conditional/loop changes (e.g. an @if branch flipping) don't
    | cause incorrect DOM matching, which would destroy adjacent Alpine state.
    |
    | Set to false in production to omit markers and reduce HTML payload.
    | Note: disabling reduces morph accuracy when conditional blocks change.
    |
    */

    'morph_markers' => env('GALE_MORPH_MARKERS', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode (F-057)
    |--------------------------------------------------------------------------
    |
    | When enabled, Gale intercepts dd() and dump() output during Gale requests
    | and renders it in a debug overlay panel instead of corrupting the JSON or
    | SSE response. Set to false in production to avoid the output buffer overhead.
    |
    | When false, dd() and dump() output will corrupt Gale responses as usual.
    |
    */

    'debug' => env('GALE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | XSS Sanitization (F-014)
    |--------------------------------------------------------------------------
    |
    | Controls whether HTML received in gale-patch-elements events is sanitized
    | before being inserted into the DOM. Enabled by default to protect against
    | XSS attacks from compromised or malicious server responses.
    |
    | sanitize_html: When true (default), the sanitizer strips <script> tags,
    |   removes on* event handler attributes, and neutralizes javascript: URLs.
    |
    | allow_scripts: When false (default), <script> tags are stripped. Set to
    |   true only in fully trusted environments where you control all HTML
    |   content and need inline scripts to execute.
    |
    | SECURITY WARNING: Setting sanitize_html=false disables all XSS protection.
    | Only do this if you fully trust all HTML content returned by your server.
    |
    */

    'sanitize_html' => env('GALE_SANITIZE_HTML', true),

    'allow_scripts' => env('GALE_ALLOW_SCRIPTS', false),

    'route_discovery' => [
        'enabled' => false,  // Opt-in by default

        /*
         * Convention-based method auto-discovery settings.
         *
         * When 'conventions' is true, controller methods whose names match standard
         * CRUD conventions (index, create, store, show, edit, update, destroy) are
         * automatically registered as routes without requiring #[Route] attributes.
         *
         * Non-conventional public methods (e.g. sendNotification) are NOT registered
         * unless they have an explicit #[Route] attribute.
         *
         * Apply #[NoAutoDiscovery] to a controller class to disable convention-based
         * discovery for that specific controller while still allowing explicit #[Route]
         * attributes on its methods.
         */
        'conventions' => true,

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
