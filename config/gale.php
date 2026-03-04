<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Gale Configuration (F-028)
    |--------------------------------------------------------------------------
    |
    | Configuration for Laravel Gale - a seamless integration of Alpine Gale
    | with Laravel. These settings control response mode, security, debug
    | features, route discovery, and the Gale reactive response system.
    |
    | All values are validated during application boot. Invalid values throw
    | InvalidArgumentException immediately for clear, fast failure rather
    | than silently misbehaving at runtime.
    |
    | ┌─────────────────────────────┬──────────┬──────────────────────────────┬─────────────────────────────────┐
    | │ Key                         │ Type     │ Default                      │ Valid Values / Range            │
    | ├─────────────────────────────┼──────────┼──────────────────────────────┼─────────────────────────────────┤
    | │ mode                        │ string   │ 'http'                       │ 'http', 'sse'                   │
    | │ morph_markers               │ bool     │ true                         │ true, false                     │
    | │ debug                       │ bool     │ false                        │ true, false                     │
    | │ sanitize_html               │ bool     │ true                         │ true, false                     │
    | │ allow_scripts               │ bool     │ false                        │ true, false                     │
    | │ csp_nonce                   │ ?string  │ null                         │ null, 'auto', non-empty string  │
    | │ redirect.allowed_domains    │ array    │ []                           │ array of non-empty strings      │
    | │ redirect.allow_external     │ bool     │ false                        │ true, false                     │
    | │ redirect.log_blocked        │ bool     │ true                         │ true, false                     │
    | │ headers.x_content_type_opts │ string|f │ 'nosniff'                    │ non-empty string or false       │
    | │ headers.x_frame_options     │ string|f │ 'SAMEORIGIN'                 │ 'SAMEORIGIN', 'DENY', false     │
    | │ headers.cache_control       │ string|f │ 'no-store, no-cache, ...'    │ non-empty string or false       │
    | │ headers.custom              │ array    │ []                           │ assoc array of header pairs     │
    | │ route_discovery.enabled     │ bool     │ false                        │ true, false                     │
    | │ route_discovery.conventions │ bool     │ true                         │ true, false                     │
    | └─────────────────────────────┴──────────┴──────────────────────────────┴─────────────────────────────────┘
    |
    | Environment variables: GALE_MODE, GALE_MORPH_MARKERS, GALE_DEBUG,
    | GALE_SANITIZE_HTML, GALE_ALLOW_SCRIPTS, GALE_CSP_NONCE
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
    | Type: string | Default: 'http' | Allowed: 'http', 'sse' | Env: GALE_MODE
    |
    */

    'mode' => env('GALE_MODE', 'http'),

    /*
    |--------------------------------------------------------------------------
    | Redirect Allowed Domains (legacy — kept for backwards compatibility)
    |--------------------------------------------------------------------------
    |
    | @deprecated Use 'redirect.allowed_domains' instead. This key is still
    | read by GaleRedirect::back() and GaleRedirect::intended() but the new
    | 'redirect' section takes precedence.
    |
    */

    'redirect_allowed_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Redirect Security (F-020)
    |--------------------------------------------------------------------------
    |
    | Comprehensive open-redirect prevention for both backend (GaleRedirect)
    | and frontend (navigate.js / json-processor.js).
    |
    | allowed_domains:
    |   Explicit whitelist of external domains permitted for redirects.
    |   Supports exact hostnames and *.wildcard subdomain patterns.
    |   Examples:
    |     'payment.stripe.com'   — exact match
    |     '*.myapp.com'          — any subdomain of myapp.com AND myapp.com itself
    |
    | allow_external:
    |   When true, all external domain redirects are permitted without whitelist
    |   checking. The dangerous-protocol check (javascript:, data:, etc.) always
    |   runs regardless of this setting.
    |   Default: false (same-origin only unless domains are whitelisted).
    |
    | log_blocked:
    |   When true, blocked redirect attempts are logged with console.warn and
    |   pushed to the Gale debug panel.
    |   Default: true.
    |
    | SECURITY WARNING: Setting allow_external=true disables domain checking.
    | Only use this when you explicitly trust all redirect URLs your server emits.
    |
    | allowed_domains — Type: array   | Default: []    | Items: non-empty strings
    | allow_external  — Type: boolean | Default: false
    | log_blocked     — Type: boolean | Default: true
    |
    */

    'redirect' => [
        'allowed_domains' => [
            // Empty = same-origin only (default)
            // Add trusted external domains:
            // 'payment.stripe.com',
            // '*.myapp.com',  // wildcard subdomain support
        ],
        'allow_external' => false,
        'log_blocked' => true,
    ],

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
    | Type: boolean | Default: true | Env: GALE_MORPH_MARKERS
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
    | Type: boolean | Default: false | Env: GALE_DEBUG
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
    | sanitize_html — Type: boolean | Default: true  | Env: GALE_SANITIZE_HTML
    | allow_scripts — Type: boolean | Default: false | Env: GALE_ALLOW_SCRIPTS
    |
    */

    'sanitize_html' => env('GALE_SANITIZE_HTML', true),

    'allow_scripts' => env('GALE_ALLOW_SCRIPTS', false),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy Nonce (F-018)
    |--------------------------------------------------------------------------
    |
    | When your application sets a CSP header with 'nonce-{value}', configure
    | this setting so Gale can attach the nonce to:
    |
    |   1. The @gale script tag (so the browser allows the Gale bundle to load)
    |   2. gale-execute-script / gale->js() dynamically inserted script tags
    |      (so server-sent JavaScript executes without 'unsafe-inline')
    |
    | Setting options:
    |
    |   null (default): No nonce — CSP policy either does not require nonces or
    |     uses a hash-based approach. The @gale script tag has no nonce attribute.
    |     gale->js() calls WITHOUT a nonce are skipped with a console.warn when
    |     the page has strict CSP (BR-018.5).
    |
    |   'auto': Gale reads window.GALE_CSP_NONCE at JS init time (the nonce you
    |     injected via your own middleware). Recommended for per-request nonces.
    |
    |   '<nonce-string>': A static nonce value (uncommon; nonces should rotate).
    |
    | How to integrate with your CSP middleware:
    |
    |   In your middleware, generate a nonce, store it in the response header,
    |   and expose it to Gale via config or a Blade variable:
    |
    |   // In a Blade layout, after setting config('gale.csp_nonce', $nonce):
    |   @gale(['nonce' => config('gale.csp_nonce')])
    |
    | SECURITY WARNING: Never reuse nonces across requests. Each page load must
    | have a unique nonce generated via random_bytes(16) or similar.
    |
    | Type: ?string | Default: null | Allowed: null, 'auto', non-empty string
    | Env: GALE_CSP_NONCE
    |
    */

    'csp_nonce' => env('GALE_CSP_NONCE', null),

    /*
    |--------------------------------------------------------------------------
    | Security Headers (F-022)
    |--------------------------------------------------------------------------
    |
    | Controls which security-hardening HTTP headers are automatically added
    | to all Gale responses (both HTTP JSON and SSE streaming).
    |
    | Set any value to false to disable that specific header. An empty string
    | is also treated as disabled.
    |
    | x_content_type_options:
    |   Prevents MIME type sniffing attacks. The 'nosniff' value instructs
    |   browsers to honour the declared Content-Type and not guess it.
    |   Set to false to disable. (BR-022.1)
    |
    | x_frame_options:
    |   Prevents clickjacking by controlling whether the response may be embedded
    |   in a <frame>, <iframe>, <embed>, or <object>.
    |   Values: 'SAMEORIGIN' (default), 'DENY', or false to disable. (BR-022.2)
    |
    | cache_control:
    |   State-bearing responses should not be cached. The default value prevents
    |   browsers and proxies from caching Gale JSON/SSE responses.
    |   SSE responses always use 'no-cache' regardless of this value.
    |   Set to false to let browser/proxy defaults apply. (BR-022.3)
    |
    | custom:
    |   Add any additional HTTP response headers to all Gale responses. (BR-022.7)
    |
    | x_content_type_options — Type: string|false | Default: 'nosniff'
    | x_frame_options        — Type: string|false | Default: 'SAMEORIGIN' | Allowed: 'SAMEORIGIN', 'DENY', false
    | cache_control          — Type: string|false | Default: 'no-store, no-cache, must-revalidate'
    | custom                 — Type: array        | Default: []
    |
    */

    'headers' => [
        'x_content_type_options' => 'nosniff',
        'x_frame_options' => 'SAMEORIGIN',
        'cache_control' => 'no-store, no-cache, must-revalidate',
        'custom' => [
            // 'X-Custom-Header' => 'value',
        ],
    ],

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
