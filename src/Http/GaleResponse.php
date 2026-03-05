<?php

namespace Dancycodes\Gale\Http;

use Closure;
use Dancycodes\Gale\Security\StateChecksum;
use Dancycodes\Gale\View\Fragment\BladeFragment;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Traits\Macroable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GaleResponse - Fluent Response Builder for Reactive HTTP Responses
 *
 * Provides a fluent API for constructing Server-Sent Events (SSE) responses compatible
 * with the Alpine Gale reactive framework. This class implements the Responsable interface,
 * allowing direct return from Laravel route handlers and controllers.
 *
 * The response builder operates in two distinct modes:
 * - Normal Mode: Accumulates events in memory and sends all events when converted to a response
 * - Streaming Mode: Sends events immediately as they are added, enabling long-running operations
 *
 * Core capabilities include:
 * - DOM manipulation through element patching (append, prepend, replace, remove, etc.)
 * - Reactive state updates synchronized between server and client (RFC 7386 JSON Merge Patch)
 * - JavaScript execution in the browser context
 * - Fragment-based partial view rendering for granular UI updates
 * - Browser history manipulation (pushState/replaceState)
 * - Client-side navigation with query parameter merging strategies
 * - Custom event dispatching for component communication
 * - Full-page redirects with session flash data support
 *
 * All reactive methods automatically detect Gale requests using the Gale-Request
 * header and gracefully degrade for standard HTTP requests when a web fallback is provided.
 *
 * @see \Illuminate\Contracts\Support\Responsable
 * @see \Dancycodes\Gale\Http\GaleRedirect
 */
class GaleResponse implements Responsable
{
    use Macroable {
        __call as macroableCall;
    }

    /**
     * Registered before-hooks that run before the controller builds a GaleResponse (F-064)
     *
     * Each hook receives the Illuminate\Http\Request and must return void.
     * Hooks are called in registration order (FIFO) for every Gale request.
     *
     * @var array<int, Closure> list of before-hook callables
     */
    protected static array $beforeHooks = [];

    /**
     * Registered after-hooks that run after the GaleResponse is fully built but
     * before it is sent to the browser (F-064)
     *
     * Each hook receives (mixed $response, \Illuminate\Http\Request $request) and
     * may return a replacement response or null to keep the original.
     * Hooks are called in registration order (FIFO) for every Gale request.
     *
     * @var array<int, Closure> list of after-hook callables
     */
    protected static array $afterHooks = [];

    /** @var array<int, string> SSE-formatted event strings */
    protected array $events = [];

    /**
     * Structured event data for JSON serialization (HTTP mode)
     *
     * Each entry is an associative array with 'type' (string) and 'data' (array) keys,
     * mirroring the SSE event format in a JSON-encodable structure.
     *
     * @var array<int, array{type: string, data: array<string, mixed>}>
     */
    protected array $jsonEvents = [];

    protected bool $streamingMode = false;

    protected ?Closure $streamCallback = null;

    /** @var mixed */
    protected $webResponse = null;

    /**
     * Tracks whether URL has been set for current response (prevents multiple navigate calls)
     */
    protected bool $urlSet = false;

    /**
     * SSE event ID for replay support (optional)
     */
    protected ?string $eventId = null;

    /**
     * SSE retry duration in milliseconds (optional, default is 1000ms per SSE spec)
     */
    protected ?int $retryDuration = null;

    /**
     * Pending redirect to be executed when toResponse() is called
     * Set by when()/unless() callbacks that return a GaleRedirect
     */
    protected ?GaleRedirect $pendingRedirect = null;

    /**
     * Accumulated flash data from flash() calls (F-061)
     *
     * Flushed as a `_flash` state patch event in toResponse() so all flash()
     * calls in a single request are batched into one state event (BR-F061-07).
     *
     * @var array<string, mixed>
     */
    protected array $pendingFlash = [];

    /**
     * Accumulated server-debug entries from debug() calls (F-076)
     *
     * Each entry is collected in memory and flushed as individual `gale-debug` events
     * when the response is built. Entries are ordered FIFO (insertion order).
     * Empty when APP_DEBUG=false (debug() is a no-op in production — BR-F076-06).
     *
     * @var array<int, array{label: string, data: mixed, timestamp: string}>
     */
    protected array $debugEntries = [];

    /**
     * Whether ETag-based conditional response is enabled for this response (BR-F027-08)
     *
     * When true, toResponse() generates an ETag header from the response content hash
     * and returns 304 Not Modified when the client's If-None-Match header matches.
     * Opt-in per endpoint via gale()->etag() or globally via config('gale.etag').
     */
    protected bool $etagEnabled = false;

    /**
     * Additional HTTP response headers to include in the final response (F-034)
     *
     * Set via withHeaders(). Applied to both JSON (HTTP mode) and SSE responses.
     * Primary use case: Gale-Cache-Bust header for history cache invalidation.
     *
     * @var array<string, string>
     */
    protected array $extraHeaders = [];

    /**
     * When true, forces HTTP/JSON mode regardless of the Gale-Mode request header.
     *
     * Used by the ValidationException renderable in bootstrap/app.php to ensure
     * validation error responses are always returned as JSON (application/json) so
     * the SSE frontend can read the 422 body correctly (sse.js BR-F079-07).
     * An SSE StreamedResponse with 422 status has content-type text/event-stream
     * which is unreadable as JSON by the browser's fetch error handler.
     */
    protected bool $forceHttp = false;

    /**
     * Valid response modes for Gale
     *
     * @var array<int, string>
     */
    public const VALID_MODES = ['http', 'sse'];

    /**
     * Default response mode when none is configured or an invalid value is set
     */
    public const DEFAULT_MODE = 'http';

    /**
     * Resolve the configured default response mode
     *
     * Reads `config('gale.mode')` and validates it against the allowed values.
     * Invalid or missing values fall back to 'http' (fail-safe).
     *
     * This is the lowest-priority mode selector. Request headers (F-013) and
     * per-action options (F-007) take precedence over this config value.
     *
     * @return string The resolved mode: 'http' or 'sse'
     */
    public static function resolveMode(): string
    {
        $mode = config('gale.mode');

        if (!is_string($mode) || !in_array($mode, self::VALID_MODES, true)) {
            return self::DEFAULT_MODE;
        }

        return $mode;
    }

    /**
     * Resolve the effective response mode for the current request
     *
     * Checks the `Gale-Mode` request header first (per-request override),
     * then falls back to `resolveMode()` (config-based default).
     *
     * Mode resolution priority (lowest to highest):
     * 1. config('gale.mode') via resolveMode()
     * 2. Gale-Mode request header (this method)
     * 3. stream() callback presence — always SSE (checked in toResponse)
     *
     * Invalid header values are silently ignored and fall back to config.
     *
     * @param \Illuminate\Http\Request|null $request Laravel request instance or null for auto-detection
     *
     * @return string The resolved mode: 'http' or 'sse'
     */
    public static function resolveRequestMode($request = null): string
    {
        $request = $request ?? request();

        $headerMode = $request->header('Gale-Mode');

        if (is_string($headerMode) && $headerMode !== '') {
            $normalized = strtolower(trim($headerMode));

            if (in_array($normalized, self::VALID_MODES, true)) {
                return $normalized;
            }
        }

        return self::resolveMode();
    }

    /**
     * Reset all mutable state on this instance (BR-F030-03, BR-F030-05)
     *
     * Clears every accumulated field so the next request starts with a clean slate.
     * Called from toResponse() in a finally block, guaranteeing cleanup even when
     * an exception propagates (BR-F030-06). Also called from the StreamedResponse
     * closure's finally block so SSE streams clean up after completion.
     *
     * Safe to call multiple times — idempotent (all assignments are to zero values).
     */
    public function reset(): void
    {
        $this->events = [];
        $this->jsonEvents = [];
        $this->streamingMode = false;
        $this->streamCallback = null;
        $this->webResponse = null;
        $this->urlSet = false;
        $this->eventId = null;
        $this->retryDuration = null;
        $this->pendingRedirect = null;
        $this->etagEnabled = false;
        $this->extraHeaders = [];
        $this->pendingFlash = [];
        $this->debugEntries = [];
        $this->forceHttp = false;
    }

    /**
     * Force this response to use HTTP/JSON mode regardless of the Gale-Mode request header.
     *
     * Used for validation error responses (422) that must return application/json so the
     * SSE frontend can parse the events body from a non-200 response (sse.js BR-F079-07).
     * Without this, an SSE request returning 422 would produce a text/event-stream body
     * that the browser fetch error handler cannot parse as JSON.
     *
     * @return static Returns this instance for method chaining
     */
    public function forceHttp(): self
    {
        $this->forceHttp = true;

        return $this;
    }

    /**
     * Inject VarDumper HTML output as a gale-debug-dump event (F-057)
     *
     * Called by GaleDumpInterceptMiddleware when dump() or dd() output is captured
     * during a Gale request. Sends the raw VarDumper HTML to the frontend where
     * it is rendered in a dismissible debug overlay.
     *
     * The event carries the complete VarDumper HTML including inline styles and
     * JavaScript so collapsible dump nodes work correctly in the overlay (BR-057.3).
     *
     * Multiple calls accumulate as separate events — each dump() call appears as
     * a separate entry in the overlay (BR-057.6).
     *
     * Only active when config('gale.debug') is true (BR-057.7). The middleware
     * guards against this before calling this method, but the guard here provides
     * defense-in-depth.
     *
     * @param string $html Raw VarDumper HTML from output buffer capture
     *
     * @return static Returns this instance for method chaining
     */
    public function debugDump(string $html): self
    {
        if (!config('gale.debug', false)) {
            return $this;
        }

        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // Build SSE data line: base64-encode HTML to avoid SSE line-break issues
        $encoded = base64_encode($html);
        $dataLines = ["html {$encoded}"];

        // Build structured data for JSON serialization (HTTP mode)
        $structuredData = [
            'html' => $html,
        ];

        $this->handleEvent('gale-debug-dump', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Send arbitrary debug data from the server to the browser debug panel (F-076)
     *
     * Collects data to be sent to the client-side "Server Debug" tab in the Gale Debug Panel.
     * Accepts an optional string label as the first argument (two-argument form), or just
     * the data value (one-argument form — label defaults to "debug").
     *
     * Multiple debug() calls in a single request are all collected in order (BR-F076-03).
     *
     * In production (APP_DEBUG=false) this method is a no-op — no data is collected and
     * no event is emitted (BR-F076-06). Developers may safely leave debug() calls in code
     * without risk of information leakage.
     *
     * Supported data types (BR-F076-01, BR-F076-09):
     * - Scalars (string, int, float, bool, null)
     * - Arrays (associative and indexed)
     * - Objects implementing Arrayable or JsonSerializable (auto-serialized)
     * - Eloquent models (serialized via toArray() — loaded relationships included)
     * - Closures and non-serializable resources (converted to string representation)
     * - Circular references (truncated with "[Circular]" marker — BR-F076-10)
     *
     * Usage:
     *   gale()->debug($someArray);                    // one-argument — auto-label "debug"
     *   gale()->debug('my label', $someObject);       // two-argument — custom label
     *   gale()->debug('before validation', $request->all());
     *
     * In SSE/stream mode: each debug() call emits a `gale-debug` SSE event immediately
     * at the point it was called (BR-F076-05). In HTTP mode: all accumulated entries
     * are sent as `gale-debug` events in the JSON events array (BR-F076-04).
     *
     * @param mixed $labelOrData String label (two-arg form) or the data itself (one-arg form)
     * @param mixed $data Data to debug (only used in two-argument form)
     *
     * @return static Returns this instance for method chaining
     */
    public function debug(mixed $labelOrData = null, mixed $data = null): static
    {
        // Production guard (BR-F076-06) — no-op when APP_DEBUG=false
        if (!config('app.debug', false)) {
            return $this;
        }

        // Determine label and data from argument signature
        if (func_num_args() === 2) {
            // Two-argument form: debug($label, $data)
            $label = is_string($labelOrData) ? $labelOrData : 'debug';
            $payload = $data;
        } else {
            // One-argument form: debug($data)
            $label = 'debug';
            $payload = $labelOrData;
        }

        // Serialize the payload to a JSON-safe value (BR-F076-01, BR-F076-09, BR-F076-10)
        $serialized = $this->serializeDebugData($payload);

        // Collect the entry (BR-F076-03)
        $this->debugEntries[] = [
            'label' => $label,
            'data' => $serialized,
            'timestamp' => now()->format('H:i:s.v'),
        ];

        // In streaming mode: emit immediately as a gale-debug SSE event (BR-F076-05)
        if ($this->streamingMode) {
            $this->emitDebugEntry([
                'label' => $label,
                'data' => $serialized,
                'timestamp' => now()->format('H:i:s.v'),
            ]);
        }

        return $this;
    }

    /**
     * Serialize an arbitrary PHP value to a JSON-safe representation (F-076)
     *
     * Handles all PHP types that may be passed to debug():
     * - Scalars: passed through as-is
     * - Arrayable/JsonSerializable: serialized via their interface (BR-F076-09)
     * - Eloquent Models: toArray() (BR-F076 Eloquent edge case)
     * - Circular references: detected and replaced with "[Circular]" (BR-F076-10)
     * - Very large data (>100KB JSON): truncated with warning (BR-F076 edge case)
     * - Closures/Resources: replaced with string representation (BR-F076 edge case)
     *
     * @param mixed $data The raw value passed by the developer
     *
     * @return mixed JSON-safe value (array, string, scalar, or null)
     */
    protected function serializeDebugData(mixed $data): mixed
    {
        // Handle Arrayable (Eloquent models, Collections, etc.) — BR-F076-09
        if ($data instanceof \Illuminate\Contracts\Support\Arrayable) {
            $data = $data->toArray();
        }

        // Handle JsonSerializable — BR-F076-09
        if ($data instanceof \JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        // Handle resources (file handles, streams, etc.) — BR-F076 edge case
        if (is_resource($data)) {
            return '[Resource: ' . get_resource_type($data) . ']';
        }

        // Handle closures — BR-F076 edge case
        if ($data instanceof \Closure) {
            return '[Closure]';
        }

        // Handle objects that aren't Arrayable/JsonSerializable — use get_object_vars
        if (is_object($data)) {
            $data = (array) $data;
        }

        // Scalars and arrays pass through — detect circular references via JSON encode/decode
        try {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($encoded === false) {
                // Partial output was produced — return with circular marker note
                return ['__debug_warning' => 'Data contained non-serializable values or circular references'];
            }

            // Check size limit: truncate at 100KB (BR-F076 edge case: very large data)
            $size = strlen($encoded);
            $limitBytes = 100 * 1024; // 100KB

            if ($size > $limitBytes) {
                $sizeKb = round($size / 1024, 1);

                return [
                    '__debug_warning' => "Debug data truncated (original: {$sizeKb}KB)",
                    '__debug_truncated' => true,
                ];
            }

            return json_decode($encoded, associative: true, flags: JSON_OBJECT_AS_ARRAY);
        } catch (\Throwable) {
            return ['__debug_warning' => 'Data could not be serialized'];
        }
    }

    /**
     * Emit a single debug entry as a gale-debug SSE event immediately (F-076)
     *
     * Used in SSE streaming mode where debug() calls must emit inline in the stream
     * at the exact point they were called (BR-F076-05, Timing edge case).
     *
     * @param array{label: string, data: mixed, timestamp: string} $entry Debug entry
     */
    protected function emitDebugEntry(array $entry): void
    {
        $payload = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';

        $dataLines = ["json {$payload}"];

        $this->sendEventImmediately('gale-debug', $dataLines);
    }

    /**
     * Flush all accumulated debug entries as gale-debug events (F-076)
     *
     * Called from toResponse() just before the response is finalized.
     * In HTTP mode: adds each entry as a gale-debug event in the JSON events array.
     * In SSE (non-streaming) mode: adds each entry to the SSE event queue.
     *
     * Not called in streaming mode — streaming mode emits entries immediately via debug().
     */
    protected function flushDebugEntries(): void
    {
        if (empty($this->debugEntries)) {
            return;
        }

        foreach ($this->debugEntries as $entry) {
            $payload = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';

            $dataLines = ["json {$payload}"];

            $structuredData = $entry;

            $this->addEventToQueue('gale-debug', $dataLines, $structuredData);
        }
    }

    /**
     * Register a before-hook that runs before the controller builds a GaleResponse (F-064)
     *
     * The hook receives the current Request. Multiple hooks run in registration order.
     * Hooks only run for Gale requests (Gale-Request header present).
     *
     * Named `beforeRequest` to avoid collision with the DOM-insertion `before($selector, $html)` method.
     *
     * Example (in AppServiceProvider::boot()):
     *   GaleResponse::beforeRequest(function (Request $request) {
     *       logger('Gale request started for: ' . $request->path());
     *   });
     *
     * @param Closure $hook Callable receiving (\Illuminate\Http\Request) : void
     */
    public static function beforeRequest(Closure $hook): void
    {
        static::$beforeHooks[] = $hook;
    }

    /**
     * Register an after-hook that runs after the GaleResponse is built (F-064)
     *
     * The hook receives ($response, Request). It MAY return a replacement response,
     * or return null/void to keep the original response. Hooks run in registration order.
     * Hooks only run for Gale requests (Gale-Request header present).
     *
     * Named `afterResponse` to avoid collision with the DOM-insertion `after($selector, $html)` method.
     *
     * Example (in AppServiceProvider::boot()):
     *   GaleResponse::afterResponse(function (mixed $response, Request $request) {
     *       $response->headers->set('X-Gale-Debug-Time', microtime(true));
     *       return $response;
     *   });
     *
     * @param Closure $hook Callable receiving (mixed $response, \Illuminate\Http\Request) : mixed
     */
    public static function afterResponse(Closure $hook): void
    {
        static::$afterHooks[] = $hook;
    }

    /**
     * Run all registered before-hooks for the current Gale request (F-064)
     *
     * Called from GalePipelineMiddleware before the controller executes.
     * Only runs when the request has the Gale-Request header.
     *
     * @param \Illuminate\Http\Request $request The current request
     */
    public static function runBeforeHooks($request): void
    {
        foreach (static::$beforeHooks as $hook) {
            $hook($request);
        }
    }

    /**
     * Run all registered after-hooks for the current Gale response (F-064)
     *
     * Called from toResponse() after the response is built but before returning it.
     * Each hook may return a replacement response; if it does, that becomes the
     * new response for subsequent hooks and the final return value.
     *
     * @param mixed $response The built Gale response
     * @param \Illuminate\Http\Request $request The current request
     *
     * @return mixed The (potentially replaced) response
     */
    public static function runAfterHooks(mixed $response, $request): mixed
    {
        foreach (static::$afterHooks as $hook) {
            $replacement = $hook($response, $request);
            if ($replacement !== null) {
                $response = $replacement;
            }
        }

        return $response;
    }

    /**
     * Clear all registered before and after hooks (F-064)
     *
     * Primarily used in tests to reset hook state between test cases.
     * Should not be called in production code.
     */
    public static function clearHooks(): void
    {
        static::$beforeHooks = [];
        static::$afterHooks = [];
    }

    /**
     * Set additional HTTP headers to include in the Gale response (F-034)
     *
     * Headers are merged into the final response regardless of mode (HTTP or SSE).
     * Primary use case: Gale-Cache-Bust for history cache invalidation.
     *
     * @param array<string, string> $headers Associative array of header name => value
     *
     * @return static Fluent interface for chaining
     */
    public function withHeaders(array $headers): static
    {
        $this->extraHeaders = array_merge($this->extraHeaders, $headers);

        // F-037: When the developer explicitly sets Cache-Control via withHeaders(),
        // mark it so the security headers middleware (F-022) won't override it.
        // This enables controllers to set Cache-Control: max-age=N for client-side
        // response caching (BR-037.5) without the security middleware overwriting it.
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Cache-Control') === 0) {
                $this->extraHeaders['X-Gale-Developer-Cache-Control'] = 'true';
                break;
            }
        }

        return $this;
    }

    /**
     * Retrieve Server-Sent Events HTTP headers for streaming responses
     *
     * Returns standardized headers required for SSE communication compatible with
     * the Alpine Gale protocol. Includes cache control, content type, buffering directives,
     * and protocol-specific connection handling for HTTP/1.1.
     *
     * @return array<string, string> Associative array of header names and values
     */
    public static function headers(): array
    {
        $headers = [
            // BR-F027-05: SSE streaming responses must never be cached
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'X-Gale-Response' => 'true',
            // Vary on Gale-Request so browsers/CDNs never serve cached Gale JSON
            // for a regular browser navigation (back button, idle reload, bfcache).
            'Vary' => 'Gale-Request',
        ];

        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
        if (is_string($protocol) && $protocol === 'HTTP/1.1') {
            $headers['Connection'] = 'keep-alive';
        }

        return $headers;
    }

    /**
     * Set SSE event ID for replay support
     *
     * The event ID is sent with each SSE event and allows clients to resume
     * from a specific point if the connection is lost. The browser will send
     * the last event ID in the Last-Event-ID header when reconnecting.
     *
     * @param string $id Unique identifier for the event
     *
     * @return static Returns this instance for method chaining
     */
    public function withEventId(string $id): self
    {
        $this->eventId = $id;

        return $this;
    }

    /**
     * Set SSE retry duration for reconnection
     *
     * Specifies the reconnection time in milliseconds that the browser should
     * wait before attempting to reconnect after the connection is lost.
     * Default per SSE spec is 1000ms (1 second).
     *
     * @param int $milliseconds Reconnection time in milliseconds
     *
     * @return static Returns this instance for method chaining
     */
    public function withRetry(int $milliseconds): self
    {
        $this->retryDuration = $milliseconds;

        return $this;
    }

    /**
     * Enable ETag-based conditional response for this endpoint (BR-F027-08)
     *
     * When enabled, toResponse() generates an ETag header from a hash of the response
     * content. If the client sends an If-None-Match header that matches the ETag, the
     * server returns 304 Not Modified with an empty body, saving bandwidth.
     *
     * ETag is opt-in because:
     * - Non-idempotent endpoints with side effects should not serve 304 responses
     * - Static fragments that rarely change are the ideal use case
     * - ETag is NEVER applied to SSE streaming responses (BR-F027-10)
     *
     * @return static Returns this instance for method chaining
     */
    public function etag(): self
    {
        $this->etagEnabled = true;

        return $this;
    }

    /**
     * Render a complete Blade view and patch it into the DOM
     *
     * Compiles the specified Blade view with provided data and sends the rendered HTML
     * as a DOM patch event. Only processes for Gale requests unless $web is true.
     *
     * @param string $view Blade view name (dot notation supported)
     * @param array<string, mixed> $data Variables to pass to the view template
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     * @param bool $web Whether to set this view as the fallback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function view(string $view, array $data = [], array $options = [], bool $web = false): self
    {

        if ($web) {
            /** @phpstan-ignore argument.type (view-string is Laravel's type hint) */
            $this->web(view($view, $data));
        }

        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        /** @phpstan-ignore argument.type (view-string is Laravel's type hint) */
        $html = view($view, $data)->render();

        return $this->patchElements($html, $options);
    }

    /**
     * Render a specific Blade fragment and patch it into the DOM
     *
     * Extracts and renders only the specified fragment from the Blade view, avoiding
     * full view compilation. Fragments are defined using @fragment/@endfragment directives.
     *
     * @param string $view Blade view containing the target fragment
     * @param string $fragment Name of the fragment to render
     * @param array<string, mixed> $data Variables to pass to the fragment
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    public function fragment(string $view, string $fragment, array $data = [], array $options = []): self
    {
        return $this->patchFragment($view, $fragment, $data, $options);
    }

    /**
     * Render and patch multiple fragments from various views
     *
     * Processes multiple fragment specifications in a single method call, where each
     * fragment configuration specifies the view, fragment name, data, and patch options.
     *
     * @param array<int, array{view: string, fragment: string, data?: array<string, mixed>, options?: array<string, mixed>}> $fragments Array of fragment configurations
     *
     * @return static Returns this instance for method chaining
     */
    public function fragments(array $fragments): self
    {
        return $this->patchFragments($fragments);
    }

    /**
     * Patch raw HTML content into the DOM
     *
     * Sends arbitrary HTML content as a DOM patch event without view compilation.
     * Useful for dynamically generated markup or client-provided HTML strings.
     *
     * @param string $html Raw HTML markup to patch
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     * @param bool $web Whether to set this HTML as the fallback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function html(string $html, array $options = [], bool $web = false): self
    {
        if ($web) {
            $this->web(response($html));
        }

        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        return $this->patchElements($html, $options);
    }

    /**
     * Patch an Alpine.js global store with new data (F-051)
     *
     * Sends a `gale-patch-store` event that merges the given data into the named
     * Alpine.store() using RFC 7386 JSON Merge Patch semantics (null values delete
     * keys, missing keys are preserved, nested objects are merged shallowly).
     *
     * The frontend validates that the store exists (was registered via Alpine.store())
     * before patching. If the store is not found, a console error is logged and the
     * patch is skipped — no exception is thrown.
     *
     * Multiple patchStore() calls in one response each emit a separate event (BR-F051-03),
     * allowing multiple stores to be updated independently in a single request.
     *
     * Works in both HTTP mode (JSON events array) and SSE mode (text/event-stream).
     *
     * Example:
     *   gale()->patchStore('cart', ['total' => 42, 'items' => []])
     *   gale()->patchStore('cart', ['total' => 42])->patchStore('notifications', ['unread' => 3])
     *
     * @param string $storeName Name of the Alpine store (from Alpine.store('name', ...))
     * @param array<string, mixed> $data Data to merge into the store (RFC 7386 Merge Patch)
     *
     * @return static Returns this instance for method chaining
     */
    public function patchStore(string $storeName, array $data): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // Build SSE data lines: store name + JSON data payload
        $dataJson = json_encode($data);
        $dataLines = [
            "store {$storeName}",
            "data {$dataJson}",
        ];

        // Build structured data for JSON serialization (BR-F051-08)
        // Event payload format: { store: 'name', data: { ... } }
        $structuredData = [
            'store' => $storeName,
            'data' => $data,
        ];

        $this->handleEvent('gale-patch-store', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Update a specific named component's state
     *
     * Sends a gale-patch-component event to update the Alpine state of a
     * component registered with x-component directive. Uses RFC 7386 JSON
     * Merge Patch semantics.
     *
     * @param string $componentName Name of the component (from x-component attribute)
     * @param array<string, mixed> $state State updates (key-value pairs)
     * @param array<string, mixed> $options Optional: onlyIfMissing
     *
     * @return static Returns this instance for method chaining
     */
    public function componentState(string $componentName, array $state, array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $dataLines = $this->buildComponentEvent($componentName, $state, $options);

        // Build structured data for JSON serialization (BR-003.7)
        $structuredData = [
            'component' => $componentName,
            'state' => $state,
        ];
        if (!empty($options['onlyIfMissing'])) {
            $structuredData['onlyIfMissing'] = true;
        }

        $this->handleEvent('gale-patch-component', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Update all components matching a tag with a state patch
     *
     * Sends a gale-patch-component event with tag-based targeting instead of
     * name-based targeting. The frontend resolves the tag to all registered
     * components with that tag and applies the state patch to each one using
     * RFC 7386 JSON Merge Patch semantics.
     *
     * @param string $tag Tag name to target (from x-component data-tags attribute)
     * @param array<string, mixed> $state State updates (key-value pairs)
     *
     * @return static Returns this instance for method chaining
     */
    public function tagState(string $tag, array $state): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $dataLines = [];
        $dataLines[] = "tag {$tag}";
        $stateJson = json_encode($state);
        $dataLines[] = "state {$stateJson}";

        $structuredData = [
            'tag' => $tag,
            'state' => $state,
        ];

        $this->handleEvent('gale-patch-component', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Invoke a method on a named component
     *
     * Sends a gale-invoke-method event to call a method on a component
     * registered with x-component directive. The method must exist on the
     * component's Alpine x-data object.
     *
     * @param string $componentName Name of the component (from x-component attribute)
     * @param string $method Method name to invoke
     * @param array<int, mixed> $args Arguments to pass to the method
     *
     * @return static Returns this instance for method chaining
     */
    public function componentMethod(string $componentName, string $method, array $args = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $dataLines = $this->buildMethodInvocationEvent($componentName, $method, $args);

        // Build structured data for JSON serialization (BR-003.8)
        $structuredData = [
            'component' => $componentName,
            'method' => $method,
            'args' => $args,
        ];

        $this->handleEvent('gale-invoke-method', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Update reactive state in the client-side Alpine x-data store
     *
     * Sends state updates to synchronize server state with the client using RFC 7386
     * JSON Merge Patch semantics. Accepts either a single key-value pair or an
     * associative array of multiple state values.
     *
     * @param string|array<string, mixed> $key State key or associative array of state
     * @param mixed $value State value when $key is a string, ignored when $key is array
     * @param array<string, mixed> $options State options (onlyIfMissing)
     *
     * @return static Returns this instance for method chaining
     */
    public function state(string|array $key, mixed $value = null, array $options = []): self
    {
        if (is_array($key)) {
            return $this->patchState($key, $options);
        }

        return $this->patchState([$key => $value], $options);
    }

    /**
     * Set reactive messages for client-side display via x-message directive
     *
     * Convenience method for setting the 'messages' state. Messages are displayed
     * automatically by x-message directives bound to corresponding field names.
     *
     * Example usage:
     * - Validation errors: gale()->messages(['email' => 'Invalid email'])
     * - Success messages: gale()->messages(['_success' => 'Profile saved!'])
     * - Clear all: gale()->clearMessages()
     *
     * @param array<string, string> $messages Field names mapped to message strings
     *
     * @return static Returns this instance for method chaining
     */
    public function messages(array $messages): self
    {
        return $this->state('messages', $messages);
    }

    /**
     * Clear all reactive messages from client-side state
     *
     * Removes all messages by setting the 'messages' state to an empty array.
     * Useful after successful form submission to clear validation errors.
     *
     * @return static Returns this instance for method chaining
     */
    public function clearMessages(): self
    {
        return $this->state('messages', []);
    }

    /**
     * Set validation errors in the `errors` state key for client-side display (F-062)
     *
     * Sends field-level validation errors as arrays keyed by field name, matching
     * Laravel's native ValidationException format. Each field maps to an array of
     * one or more error message strings (BR-F062-02).
     *
     * This is the preferred format for $request->validate() auto-conversion because:
     * - It preserves ALL error messages per field (not just the first)
     * - It uses the `errors` state key (separate from `messages` used for general UI)
     * - It is displayed via `x-message.from.errors="fieldname"`
     *
     * Example:
     *   gale()->errors(['email' => ['The email field is required.']]);
     *   gale()->errors(['email' => ['Invalid format.', 'Too long.']]);
     *
     * Frontend display:
     *   <p x-message.from.errors="email" class="text-red-600 text-sm"></p>
     *
     * @param array<string, array<int, string>> $errors Field names mapped to arrays of error strings
     *
     * @return static Returns this instance for method chaining
     */
    public function errors(array $errors): self
    {
        return $this->state('errors', $errors);
    }

    /**
     * Clear all reactive validation errors from client-side state (F-062)
     *
     * Removes all errors by setting the `errors` state to an empty array.
     * Useful after successful form submission to clear validation error indicators.
     *
     * @return static Returns this instance for method chaining
     */
    public function clearErrors(): self
    {
        return $this->state('errors', []);
    }

    /**
     * Deliver session flash data to the frontend as reactive state (F-061)
     *
     * Stores data in the Laravel session as flash (available for next request) AND
     * delivers it immediately to the frontend as `_flash` state so the current response
     * can also display it reactively. This satisfies BR-F061-06: flash data must be
     * delivered to the frontend in a structured format for display.
     *
     * Accepts either a key-value pair or an associative array of flash values.
     * Multiple calls accumulate into the same `_flash` state object (BR-F061-07).
     *
     * Flash data is consumed after being read on the next request (BR-F061-04).
     * Works in both HTTP and SSE transport modes (BR-F061-08).
     *
     * Examples:
     *   gale()->flash('success', 'Record saved!')
     *   gale()->flash('warning', 'Please review')
     *   gale()->flash(['status' => 'updated', 'count' => 5])
     *   gale()->flash('success', 'Saved')->state('count', 42)
     *
     * Frontend display (add _flash to x-data):
     *   <div x-show="_flash.success" x-text="_flash.success"></div>
     *
     * @param string|array<string, mixed> $key Flash key or associative array of flash data
     * @param mixed $value Flash value when $key is string, ignored when $key is array
     *
     * @return static Returns this instance for method chaining
     */
    public function flash(string|array $key, mixed $value = null): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            // For non-Gale requests: still flash to session so server-rendered pages
            // can display the flash data via session('key') or $errors
            if (is_array($key)) {
                foreach ($key as $k => $v) {
                    session()->flash((string) $k, $v);
                }
            } else {
                session()->flash($key, $value);
            }

            return $this;
        }

        // Normalize to array
        /** @var array<string, mixed> $flashData */
        $flashData = is_array($key) ? $key : [$key => $value];

        // 1. Store in Laravel session as flash — available for the NEXT request (BR-F061-01)
        foreach ($flashData as $k => $v) {
            session()->flash((string) $k, $v);
        }

        // 2. Deliver to the frontend as `_flash` state (BR-F061-06: structured format for display)
        if ($this->streamingMode) {
            // In streaming mode: emit immediately as a state patch merged with any prior _flash
            // (BR-F061-08: works in SSE transport mode; same as other state methods in streaming)
            $mergedFlash = array_merge($this->pendingFlash, $flashData);
            $this->pendingFlash = [];
            $this->patchState(['_flash' => $mergedFlash]);
        } else {
            // In normal mode: accumulate — flushed as a single _flash state event in toResponse()
            // (BR-F061-07: multiple flash() calls are batched into one state event)
            $this->pendingFlash = array_merge($this->pendingFlash, $flashData);
        }

        return $this;
    }

    /**
     * Remove state keys from the client-side Alpine x-data store
     *
     * Deletes specified state keys from the client using RFC 7386 JSON Merge Patch
     * semantics where null values indicate deletion. Specific state keys must be
     * provided as the frontend manages its own state.
     *
     * @param string|array<int, string>|null $state State key(s) to delete, or null (no-op)
     *
     * @return static Returns this instance for method chaining
     */
    public function forget(string|array|null $state = null): self
    {
        if (is_null($state)) {
            // Cannot forget all state without knowing what keys exist
            // The frontend manages its own state, so we need specific keys
            return $this;
        }

        $stateKeys = $this->parseStateKeys($state);

        return $this->forgetState($stateKeys);
    }

    /**
     * Execute JavaScript code in the browser context
     *
     * Injects and executes JavaScript code by creating a script element in the DOM.
     * The script element is automatically removed after execution unless configured otherwise.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script element options (attributes, autoRemove)
     *
     * @return static Returns this instance for method chaining
     */
    public function js(string $script, array $options = []): self
    {
        return $this->executeScript($script, $options);
    }

    /**
     * Dispatch an Alpine-compatible custom browser event (F-054)
     *
     * Dispatches a `CustomEvent` on `window` (default) or on the first element
     * matching the optional CSS `$target` selector. The event detail is set to
     * `$data` and is accessible via `$event.detail` in Alpine listeners.
     *
     * Usage:
     *   gale()->dispatch('show-toast', ['message' => 'Saved!'])          // window dispatch
     *   gale()->dispatch('refresh', [], '#sidebar')                       // targeted dispatch
     *   gale()->dispatch('cart-updated')->dispatch('notify', $data)       // chaining
     *
     * Alpine listener (window):
     *   x-on:show-toast.window="handle($event.detail)"
     *
     * Alpine listener (targeted element, e.g. #sidebar):
     *   x-on:refresh="loadItems()"   (no .window modifier needed)
     *
     * Event payload format (BR-F054-08):
     *   { event: 'name', data: { ... }, target: '#selector' | null }
     *
     * Business rules:
     *   - No target (or null): dispatched on window (BR-F054-01)
     *   - With target: dispatched on the first matching element (BR-F054-02)
     *   - No match for target: dispatched on window as fallback + console.warn (BR-F054-03)
     *   - Events dispatched in order added (BR-F054-04)
     *   - Standard CustomEvent compatible with Alpine @event.window and $event.detail (BR-F054-05)
     *   - Chainable (BR-F054-06)
     *   - Works in HTTP and SSE modes (BR-F054-07)
     *
     * @param string $eventName Name of the CustomEvent (kebab-case recommended)
     * @param array<string, mixed> $data Event payload accessible via $event.detail
     * @param string|null $target Optional CSS selector to target a specific element
     *
     * @throws \InvalidArgumentException When event name is empty
     *
     * @return static Returns this instance for method chaining
     */
    public function dispatch(string $eventName, array $data = [], ?string $target = null): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // BR-F054-08: Empty event name — log error on frontend, do not dispatch
        if (empty($eventName)) {
            throw new \InvalidArgumentException('Event name cannot be empty');
        }

        // Build structured payload: { event, data, target } (BR-F054-08)
        $structuredData = [
            'event' => $eventName,
            'data' => $data,
            'target' => $target,
        ];

        // Build SSE data lines for streaming mode
        $dataJson = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $dataLines = [
            "event {$eventName}",
            "data {$dataJson}",
        ];
        if ($target !== null) {
            $dataLines[] = "target {$target}";
        }

        // Store JSON event for HTTP mode
        $this->addJsonEvent('gale-dispatch', $structuredData);

        // Emit SSE event for SSE mode (instead of script execution)
        $this->handleEvent('gale-dispatch', $dataLines);

        return $this;
    }

    /**
     * Remove matched elements from the DOM
     *
     * Deletes all elements matching the specified CSS selector from the document.
     *
     * @param string $selector CSS selector targeting elements to remove
     *
     * @return static Returns this instance for method chaining
     */
    public function remove(string $selector): self
    {
        return $this->removeElements($selector);
    }

    /**
     * Append HTML content as the last child of matched elements
     *
     * Inserts the provided HTML inside targeted elements, after their existing children.
     *
     * @param string $selector CSS selector targeting parent elements
     * @param string $html HTML markup to append
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function append(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'append',
        ]));
    }

    /**
     * Prepend HTML content as the first child of matched elements
     *
     * Inserts the provided HTML inside targeted elements, before their existing children.
     *
     * @param string $selector CSS selector targeting parent elements
     * @param string $html HTML markup to prepend
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function prepend(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'prepend',
        ]));
    }

    /**
     * Replace matched elements entirely with new HTML (alias for outer)
     *
     * Substitutes all elements matching the selector with the provided HTML markup.
     * This is an alias for outer() - uses server-driven state from x-data in response.
     *
     * @param string $selector CSS selector targeting elements to replace
     * @param string $html Replacement HTML markup
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function replace(string $selector, string $html, array $options = []): self
    {
        return $this->outer($selector, $html, $options);
    }

    /**
     * Insert HTML content immediately before matched elements
     *
     * Places the provided HTML as a sibling before each targeted element.
     *
     * @param string $selector CSS selector targeting reference elements
     * @param string $html HTML markup to insert
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function before(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'before',
        ]));
    }

    /**
     * Insert HTML content immediately after matched elements
     *
     * Places the provided HTML as a sibling after each targeted element.
     *
     * @param string $selector CSS selector targeting reference elements
     * @param string $html HTML markup to insert
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function after(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'after',
        ]));
    }

    /**
     * Replace the inner HTML of matched elements (server-driven state)
     *
     * Replaces all children of targeted elements with the provided HTML markup, preserving
     * the wrapper elements themselves. State comes from x-data in the response HTML.
     *
     * @param string $selector CSS selector targeting container elements
     * @param string $html HTML markup for new inner content
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function inner(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'inner',
        ]));
    }

    /**
     * Replace the outer HTML of matched elements (server-driven state)
     *
     * Replaces targeted elements entirely including the elements themselves and their children.
     * State comes from x-data in the response HTML. This is the DEFAULT mode.
     *
     * @param string $selector CSS selector targeting elements to replace
     * @param string $html HTML markup for complete replacement
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function outer(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'outer',
        ]));
    }

    /**
     * Smart morph the outer HTML of matched elements (client-preserved state)
     *
     * Uses Alpine.morph() for intelligent diffing that preserves client-side state
     * like form inputs, counters, and local Alpine state.
     *
     * @param string $selector CSS selector targeting elements to morph
     * @param string $html HTML markup to morph into
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function outerMorph(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'outerMorph',
        ]));
    }

    /**
     * Smart morph the inner HTML of matched elements (client-preserved state)
     *
     * Uses Alpine.morph() for intelligent diffing that preserves the wrapper element's
     * client-side state while morphing only its children.
     *
     * @param string $selector CSS selector targeting container elements
     * @param string $html HTML markup to morph children into
     * @param array<string, mixed> $options Additional options (scroll, show, focusScroll, useViewTransition, settle, limit)
     *
     * @return static Returns this instance for method chaining
     */
    public function innerMorph(string $selector, string $html, array $options = []): self
    {
        return $this->patchElements($html, array_merge($options, [
            'selector' => $selector,
            'mode' => 'innerMorph',
        ]));
    }

    /**
     * Alias for outerMorph() - backward compatibility with Gale v1
     *
     * @param string $selector CSS selector targeting elements to morph
     * @param string $html HTML markup to morph into
     * @param array<string, mixed> $options Additional options
     *
     * @return static Returns this instance for method chaining
     */
    public function morph(string $selector, string $html, array $options = []): self
    {
        return $this->outerMorph($selector, $html, $options);
    }

    /**
     * Alias for remove() - HTMX compatibility
     *
     * @param string $selector CSS selector targeting elements to delete
     *
     * @return static Returns this instance for method chaining
     */
    public function delete(string $selector): self
    {
        return $this->remove($selector);
    }

    /**
     * Patch HTML elements into the DOM using Alpine Gale protocol
     *
     * Constructs and handles a gale-patch-elements event with the provided HTML content
     * and patching options. Behavior depends on response mode: accumulates in normal mode,
     * sends immediately in streaming mode.
     *
     * @param string $elements HTML markup to patch
     * @param array<string, mixed> $options Patching configuration (selector, mode, useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchElements(string $elements, array $options = []): self
    {
        $dataLines = $this->buildElementsEvent($elements, $options);

        // Build structured data for JSON serialization (BR-003.6)
        $structuredData = ['html' => $elements];
        if (!empty($options['selector']) && is_string($options['selector'])) {
            $structuredData['selector'] = $options['selector'];
        }
        if (!empty($options['mode']) && is_string($options['mode'])) {
            $structuredData['mode'] = $options['mode'];
        }
        if (!empty($options['useViewTransition'])) {
            $structuredData['useViewTransition'] = true;
        }
        if (!empty($options['settle']) && (is_int($options['settle']) || is_float($options['settle']))) {
            $structuredData['settle'] = (int) $options['settle'];
        }
        if (!empty($options['limit']) && (is_int($options['limit']) || is_float($options['limit']))) {
            $structuredData['limit'] = (int) $options['limit'];
        }
        if (!empty($options['scroll']) && is_string($options['scroll'])) {
            $structuredData['scroll'] = $options['scroll'];
        }
        if (!empty($options['show']) && is_string($options['show'])) {
            $structuredData['show'] = $options['show'];
        }
        if (!empty($options['focusScroll'])) {
            $structuredData['focusScroll'] = true;
        }

        $this->handleEvent('gale-patch-elements', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Update reactive state using RFC 7386 JSON Merge Patch
     *
     * Synchronizes server-side state with the client by sending state updates
     * via SSE. Uses RFC 7386 JSON Merge Patch semantics where null values delete
     * properties and other values are merged. Only processes for Gale requests
     * to avoid unnecessary computation.
     *
     * @param array<string, mixed> $state Associative array of state keys and values
     * @param array<string, mixed> $options State update options (onlyIfMissing)
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchState(array $state, array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // Sign the state payload with HMAC-SHA256 before sending to the client (F-013, BR-013.1)
        // The checksum is computed over the base state (without _checksum itself) and appended
        // as the reserved `_checksum` key so it travels with the exact state it signs.
        $signedState = StateChecksum::sign($state);

        $dataLines = $this->buildStateEvent($signedState, $options);

        // Build structured data for JSON serialization (BR-003.5)
        // State data is the signed state object; onlyIfMissing is included as metadata
        $structuredData = $signedState;
        if (!empty($options['onlyIfMissing'])) {
            $structuredData = array_merge(['onlyIfMissing' => true], $signedState);
        }

        $this->handleEvent('gale-patch-state', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Execute JavaScript code in the browser context
     *
     * Constructs and handles a gale-patch-elements event containing a script element.
     * Behavior depends on response mode: accumulates in normal mode, sends immediately
     * in streaming mode.
     *
     * F-018 (BR-018.4): When a CSP nonce is needed, pass it via $options['nonce'] or
     * let it auto-resolve from config('gale.csp_nonce'). The nonce is included in the
     * JSON event so the frontend can attach it to the dynamically inserted script tag.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script configuration (attributes, autoRemove, nonce)
     *
     * @return static Returns this instance for method chaining
     */
    protected function executeScript(string $script, array $options = []): self
    {
        // F-018 (BR-018.4): Resolve CSP nonce for dynamic script execution.
        // Priority: $options['nonce'] > config('gale.csp_nonce') > null
        $nonceRaw = $options['nonce'] ?? config('gale.csp_nonce', null);
        $nonce = is_string($nonceRaw) ? $nonceRaw : null;
        if ($nonce !== null) {
            // Pass nonce as an attribute so SSE mode script tags also receive it
            $existingAttributesRaw = $options['attributes'] ?? null;
            $existingAttributes = is_array($existingAttributesRaw) ? $existingAttributesRaw : [];
            $options['attributes'] = array_merge($existingAttributes, ['nonce' => $nonce]);
        }

        $dataLines = $this->buildScriptEvent($script, $options);

        // Build structured data for JSON serialization (acceptance criteria #6)
        // Script execution events use the gale-execute-script type in JSON format
        // to distinguish from regular element patches
        $structuredData = array_filter([
            'script' => $script,
            'nonce' => $nonce,
            'options' => array_filter([
                'autoRemove' => $options['autoRemove'] ?? true,
                'attributes' => $options['attributes'] ?? null,
            ], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);

        // Use gale-execute-script type for JSON (distinct from gale-patch-elements)
        // While SSE reuses gale-patch-elements with a script tag, JSON mode uses a
        // dedicated event type for cleaner client-side processing
        $this->addJsonEvent('gale-execute-script', $structuredData);
        $this->handleEvent('gale-patch-elements', $dataLines);

        return $this;
    }

    /**
     * Remove matched elements from the DOM
     *
     * Constructs and handles a gale-patch-elements event with mode 'remove'.
     * Behavior depends on response mode: accumulates in normal mode, sends immediately
     * in streaming mode.
     *
     * @param string $selector CSS selector targeting elements to remove
     * @param array<string, mixed> $options Removal configuration (useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    protected function removeElements(string $selector, array $options = []): self
    {
        $options['selector'] = $selector;
        $options['mode'] = 'remove';
        $dataLines = $this->buildRemovalEvent($options);

        // Build structured data for JSON serialization
        $structuredData = [
            'selector' => $selector,
            'mode' => 'remove',
        ];
        if (!empty($options['useViewTransition'])) {
            $structuredData['useViewTransition'] = true;
        }

        $this->handleEvent('gale-patch-elements', $dataLines, $structuredData);

        return $this;
    }

    /**
     * Create a fluent redirect builder with session flash support
     *
     * Returns a GaleRedirect instance that provides methods for full-page browser redirects
     * with Laravel session flash data. Redirects perform JavaScript-based navigation using
     * window.location assignments rather than reactive signal updates.
     *
     * URL can be provided here or set later using to(), route(), back(), home(), intended(), etc.
     * This matches Laravel's redirect() helper which works without requiring an immediate URL.
     *
     * Examples:
     *   gale()->redirect('/path')                    // Direct URL
     *   gale()->redirect()->to('/path')             // Same as above
     *   gale()->redirect()->back()                  // Previous URL
     *   gale()->redirect()->route('home')           // Named route
     *   gale()->redirect()->home()                  // App root
     *   gale()->redirect()->intended('/fallback')   // Auth intended URL
     *
     * @param string|null $url Optional target URL for the redirect
     *
     * @return \Dancycodes\Gale\Http\GaleRedirect Redirect builder instance
     */
    public function redirect(?string $url = null): GaleRedirect
    {
        return new GaleRedirect($url, $this);
    }

    /**
     * Trigger a file download from a Gale action (F-039)
     *
     * Sends a `gale-download` event to the client containing a signed temporary URL.
     * The client fetches the URL separately and triggers a browser download, preserving
     * the current page (no navigation). Supports both file paths and raw content.
     *
     * In HTTP mode: emits a `gale-download` JSON event with the signed URL.
     * In SSE mode:  emits a `gale-download` SSE event with the signed URL.
     *
     * The actual file is served by `GaleDownloadServeController::serve()`, which verifies
     * the signature, sets `Content-Disposition: attachment`, and streams the file.
     *
     * Chainable: `gale()->download($path, 'report.pdf')->patchState(['lastExport' => now()])`
     *
     * @param string $pathOrContent Absolute filesystem path OR raw file content string
     * @param string $filename Download filename presented to the browser
     * @param string|null $mimeType Optional MIME type (auto-detected from extension when null)
     * @param bool $isContent True when $pathOrContent is raw content, not a file path
     *
     * @throws \InvalidArgumentException When a file path does not exist
     *
     * @return static Returns this instance for method chaining
     */
    public function download(
        string $pathOrContent,
        string $filename,
        ?string $mimeType = null,
        bool $isContent = false
    ): self {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // BR-039.8: Sanitize filename — strip path separators and control characters
        $safeFilename = $this->sanitizeDownloadFilename($filename);

        if ($isContent) {
            // Raw content: store in cache temporarily with a signed retrieval token
            $token = $this->buildDownloadToken($safeFilename, $mimeType, content: $pathOrContent);
        } else {
            // File path: validate existence, then build token pointing to the path
            if (!file_exists($pathOrContent)) {
                throw new \InvalidArgumentException(
                    "Download file not found: {$pathOrContent}"
                );
            }
            $token = $this->buildDownloadToken($safeFilename, $mimeType, path: $pathOrContent);
        }

        $downloadUrl = $this->buildSignedDownloadUrl($token);

        $structuredData = [
            'url' => $downloadUrl,
            'filename' => $safeFilename,
        ];

        // SSE data lines: url + filename
        $dataLines = [
            "url {$downloadUrl}",
            "filename {$safeFilename}",
        ];

        $this->addJsonEvent('gale-download', $structuredData);
        $this->handleEvent('gale-download', $dataLines);

        return $this;
    }

    /**
     * Sanitize a download filename for use in Content-Disposition headers (BR-039.8)
     *
     * Strips path traversal characters (/ \ .. null bytes) and trims whitespace.
     * Preserves dots for extensions, unicode characters, spaces, and hyphens.
     * Falls back to 'download' if the result would be empty.
     *
     * @param string $filename Raw filename from developer/user
     *
     * @return string Sanitized filename safe for Content-Disposition header
     */
    protected function sanitizeDownloadFilename(string $filename): string
    {
        // Remove null bytes
        $safe = str_replace("\0", '', $filename);

        // Strip directory separators and traversal sequences
        $safe = str_replace(['/', '\\', '..'], '', $safe);

        // Remove leading dots (hidden files) and trim whitespace
        $safe = ltrim(trim($safe), '.');

        return $safe !== '' ? $safe : 'download';
    }

    /**
     * Build a signed download token stored in the Laravel cache (BR-039.7, BR-039.9)
     *
     * Stores the download parameters (path or content + filename + MIME) in the cache
     * with a short TTL, keyed by a random token. The token is then signed with the app key
     * so it cannot be guessed or forged.
     *
     * @param string $filename Sanitized filename for the download
     * @param string|null $mimeType MIME type (null = auto-detect)
     * @param string|null $path Absolute path to the file on disk
     * @param string|null $content Raw file content (for dynamic downloads)
     *
     * @return string Signed opaque token
     */
    protected function buildDownloadToken(
        string $filename,
        ?string $mimeType,
        ?string $path = null,
        ?string $content = null
    ): string {
        $token = bin2hex(random_bytes(16));

        $payload = [
            'filename' => $filename,
            'mime' => $mimeType,
            'path' => $path,
            // Store content only for small dynamic files; large files must use path
            'content' => ($content !== null && strlen($content) <= 1_048_576) ? $content : null,
            'expires' => time() + 300, // 5-minute window
        ];

        // For large content, write to a temp file and store path
        if ($content !== null && strlen($content) > 1_048_576) {
            $tmpPath = sys_get_temp_dir() . '/gale-dl-' . $token;
            file_put_contents($tmpPath, $content);
            $payload['path'] = $tmpPath;
            $payload['is_tmp'] = true;
        }

        \Illuminate\Support\Facades\Cache::put('gale_download:' . $token, $payload, 300);

        // Sign the token using HMAC so it cannot be forged
        $appKeyRaw = config('app.key');
        $sig = hash_hmac('sha256', $token, is_string($appKeyRaw) ? $appKeyRaw : '');

        return $token . '.' . $sig;
    }

    /**
     * Build the absolute URL for the signed download endpoint
     *
     * @param string $signedToken Signed token from buildDownloadToken()
     *
     * @return string Absolute download URL
     */
    protected function buildSignedDownloadUrl(string $signedToken): string
    {
        return url('/gale/download/' . urlencode($signedToken));
    }

    /**
     * Enable streaming mode for long-running operations
     *
     * Switches the response builder from accumulation mode to streaming mode, where events
     * are sent immediately as methods are called. The provided callback receives this instance
     * and executes in streaming context. Any events accumulated before stream() was called are
     * flushed first. Handles output buffering, exception rendering, and redirect behavior for
     * streaming responses.
     *
     * dd() and dump() are handled naturally by Laravel - output is captured via shutdown
     * function (for dd() which calls exit) or output buffer (for dump()).
     *
     * @param \Closure $callback Function receiving this instance in streaming mode
     *
     * @return static Returns this instance for method chaining
     */
    /**
     * Create a push channel broadcaster for a named channel (F-038, BR-038.1)
     *
     * Returns a GalePushChannel instance that queues SSE events for delivery
     * to all clients subscribed to the given channel via x-listen or $listen.
     *
     * Usage:
     *   gale()->push('notifications')->patchState(['count' => 5])->send();
     *   gale()->push('dashboard')->patchElements('#stats', $html)->send();
     *
     * The push channel uses Laravel's cache to queue events for connected clients.
     * Call ->send() to flush the queued events to the channel (BR-038.1).
     *
     * BR-038.10: Multiple channels can be pushed to simultaneously by calling push()
     * multiple times with different channel names.
     *
     * @param string $channel Channel name to broadcast to
     *
     * @return GalePushChannel Fluent push channel broadcaster
     */
    public function push(string $channel): GalePushChannel
    {
        return new GalePushChannel($channel);
    }

    public function stream(Closure $callback): self
    {
        $this->streamCallback = function ($gale) use ($callback) {
            try {
                ob_start();

                $this->overrideRedirectForStream();

                // Register shutdown function to capture dd() output (dd() calls exit)
                // This runs after script termination, capturing any buffered output
                register_shutdown_function([$this, 'handleShutdownOutput']);

                $callback($gale);

                // Validate any buffered output INSIDE the try block so that
                // InvalidArgumentException (BR-F009-03) can be caught and displayed
                // as a proper Ignition/error page rather than corrupting the SSE stream
                $this->validateAndFlushStreamBuffer();

            } catch (\Throwable $e) {
                // Drain the output buffer before emitting the error event
                // to prevent captured raw output from leaking into the SSE stream
                if (ob_get_level()) {
                    ob_end_clean();
                }
                // F-058 BR-F058-03: SSE stream exceptions emit a gale-error event and
                // close the stream — the page layout is NOT replaced. This keeps the
                // frontend component tree intact and lets the gale:error handler display
                // the error inline without a full-page replacement.
                $this->emitSseErrorEvent($e);
            } finally {
                // Clean up any remaining buffer (e.g. from HTML-format dd/dump output)
                $this->handleStreamOutput();
                $this->restoreOriginalHandlers();
            }
        };

        return $this;
    }

    /**
     * Validate buffered output after the stream callback completes (normal flow only)
     *
     * Called inside the try block so that InvalidArgumentException from debug-mode
     * validation can be caught and displayed as an Ignition/error page via
     * handleNativeException(), rather than escaping the finally block silently.
     *
     * @throws \InvalidArgumentException When non-SSE output is captured in debug mode (BR-F009-03)
     */
    protected function validateAndFlushStreamBuffer(): void
    {
        $output = ob_get_contents();

        // Nothing buffered — nothing to validate
        if ($output === false || trim($output) === '') {
            return;
        }

        // HTML output (dd/dump) is allowed and handled by handleStreamOutput() in finally
        if ($this->looksLikeHtml($output)) {
            return;
        }

        // Non-HTML, non-SSE output — validate and throw or emit gale-error (BR-F009-03/04)
        $this->validateStreamOutput($output);

        // If validateStreamOutput did not throw (production mode), drain the buffer so it
        // does not reach handleStreamOutput() again and get displayed as garbage HTML
        if (ob_get_level()) {
            ob_end_clean();
        }
    }

    /**
     * Validate that captured output from a stream callback is valid SSE format
     *
     * Any direct echo/print output captured by the output buffer is validated here.
     * Valid SSE lines start with: event:, data:, id:, retry:, : (comment), or are blank.
     * In debug mode throws InvalidArgumentException; in production emits a gale-error event.
     *
     * @param string $output Output captured from the stream callback
     *
     * @throws \InvalidArgumentException When output is invalid SSE format and app is in debug mode
     */
    protected function validateStreamOutput(string $output): void
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return;
        }

        $lines = preg_split('/\r?\n/', $trimmed) ?: [];
        $invalidLines = [];

        foreach ($lines as $line) {
            // Blank lines are valid SSE event separators
            if ($line === '') {
                continue;
            }

            // Valid SSE field prefixes per the SSE specification
            $isValid = str_starts_with($line, 'event:')
                || str_starts_with($line, 'data:')
                || str_starts_with($line, 'id:')
                || str_starts_with($line, 'retry:')
                || str_starts_with($line, ':');  // SSE comment

            if (!$isValid) {
                $invalidLines[] = $line;
            }
        }

        if (empty($invalidLines)) {
            return;
        }

        $preview = implode('\n', array_slice($invalidLines, 0, 3));

        if (config('app.debug', false)) {
            throw new \InvalidArgumentException(
                'Gale stream callback produced non-SSE output. '
                . 'Direct echo/print inside stream() violates the SSE format. '
                . 'Use $gale->state(), $gale->patchState(), or other GaleResponse methods instead. '
                . "Invalid output: \"{$preview}\""
            );
        }

        // Production: log the error and emit a structured gale-error SSE event
        $errorMessage = 'Gale stream callback produced non-SSE output (debug disabled). '
            . "Invalid lines: \"{$preview}\"";

        \Illuminate\Support\Facades\Log::error($errorMessage, [
            'output_preview' => $preview,
            'invalid_line_count' => count($invalidLines),
        ]);

        echo "event: gale-error\n";
        echo 'data: ' . json_encode([
            'type' => 'stream-validation',
            'message' => 'Stream callback produced non-SSE output. Check server logs.',
        ]) . "\n\n";
        flush();
    }

    /**
     * Intercept stray echo/print output from the buffer during streaming (BR-F009-02)
     *
     * Called before each SSE event is sent in streaming mode. Checks the output buffer
     * for any non-SSE content produced by direct echo/print calls in the stream callback.
     * If found, the stray output is drained and validated: in debug mode an exception is
     * thrown (BR-F009-03), in production a gale-error event is emitted (BR-F009-04).
     *
     * This provides per-event inline validation rather than post-hoc checking, preventing
     * invalid content from leaking into the SSE stream between valid events.
     *
     * After intercepting, the output buffer is closed so that the caller can echo SSE
     * content directly to the client. The caller is responsible for re-opening the buffer.
     *
     * @throws \InvalidArgumentException When non-SSE output is captured in debug mode
     *
     * @return bool Whether a validation buffer was active (caller should re-open if true)
     */
    protected function interceptStrayBufferOutput(): bool
    {
        if (ob_get_level() === 0) {
            return false;
        }

        $buffered = ob_get_contents();

        // Close the validation buffer — caller will echo SSE content directly
        ob_end_clean();

        if ($buffered === false || $buffered === '') {
            return true;
        }

        // HTML output (dd/dump) gets the full-page replacement treatment via
        // handleStreamOutput() in the finally block. Re-echo it so that handler
        // can pick it up, then let the exception propagate or continue normally.
        if ($this->looksLikeHtml($buffered)) {
            // Re-open a temporary buffer so the HTML doesn't go to the client yet
            ob_start();
            echo $buffered;

            return true;
        }

        // Non-HTML, non-SSE output — validate (throws in debug, emits gale-error in prod)
        $this->validateStreamOutput($buffered);

        return true;
    }

    /**
     * Override Laravel redirect helper for streaming mode
     *
     * Binds GaleStreamRedirector which extends Laravel's Redirector to intercept
     * redirect calls and perform JavaScript-based navigation via window.location.
     * This satisfies PHP's return type requirements while enabling redirects in streams.
     */
    protected function overrideRedirectForStream(): void
    {
        app()->bind('redirect', function ($app) {
            /** @var \Illuminate\Routing\UrlGenerator $urlGenerator */
            $urlGenerator = $app['url'];
            /** @var \Illuminate\Session\Store|null $session */
            $session = $app->bound('session.store') ? $app['session.store'] : null;

            return new GaleStreamRedirector($urlGenerator, $session);
        });
    }

    /**
     * Handle shutdown output capture for dd() calls
     *
     * This method is registered as a shutdown function to capture output from dd()
     * which calls exit(). The shutdown function runs after script termination,
     * allowing us to capture and send the native Laravel dd() output via SSE.
     *
     * Note: This is public because it's called via register_shutdown_function()
     */
    public function handleShutdownOutput(): void
    {
        // Get any buffered output (from dd(), dump(), echo, etc.)
        $output = '';
        while (ob_get_level() > 0) {
            $output = ob_get_clean() . $output;
        }

        // If there's output, wrap it and send to replace the document
        if (!empty(trim($output))) {
            $html = $this->wrapOutputAsHtml($output);

            // Send via SSE to replace document (trusted Laravel backend content)
            echo "event: gale-patch-elements\n";
            echo 'data: elements <script>document.open(); document.write(' . json_encode($html) . "); document.close();</script>\n";
            echo "data: selector body\n";
            echo "data: mode append\n\n";
            flush();
        }
    }

    /**
     * Check if content appears to be HTML
     */
    private function looksLikeHtml(string $content): bool
    {
        // Check for common HTML patterns (tags, entities, Symfony dump classes)
        return preg_match('/<[a-z][\s\S]*>/i', $content) === 1
            || strpos($content, 'sf-dump') !== false
            || strpos($content, '<!DOCTYPE') !== false;
    }

    /**
     * Wrap raw output as a complete HTML document
     *
     * If the output looks like HTML (e.g., dd/dump output), wraps it in a basic
     * HTML structure when it lacks a doctype. Plain text is wrapped in a monospace
     * pre-formatted block. Already-complete HTML documents are returned as-is.
     *
     * @param string $output Raw output content
     *
     * @return string Complete HTML document
     */
    private function wrapOutputAsHtml(string $output): string
    {
        if ($this->looksLikeHtml($output)) {
            // Already complete HTML document - return as-is
            if (stripos($output, '<!DOCTYPE') !== false || stripos($output, '<html') !== false) {
                return $output;
            }

            // HTML fragment - wrap in basic structure
            return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laravel Output</title>
</head>
<body>' . $output . '</body>
</html>';
        }

        // Plain text - wrap in monospace pre-formatted block
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laravel Output</title>
</head>
<body style="background: #18171B; color: white; font-family: monospace; padding: 20px;">
<pre>' . htmlspecialchars($output) . '</pre>
</body>
</html>';
    }

    /**
     * Process output buffer and replace document if content exists
     *
     * Captures buffered output from the streaming callback. HTML output (like Laravel's
     * native dd/dump) is displayed in the browser via document replacement. Plain text
     * that is not valid SSE format triggers stream validation (throws in debug,
     * emits gale-error in production). Valid SSE output is passed through as-is.
     * Terminates stream after document replacement.
     */
    protected function handleStreamOutput(): void
    {
        $output = ob_get_contents();
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Early return if buffer is empty or false
        if ($output === false || trim($output) === '') {
            return;
        }

        // HTML output (e.g. dd(), dump(), Ignition error pages) is forwarded to the browser
        // as a full-page document replacement — this is intentional dd/dump behavior (BR-F009-02)
        if ($this->looksLikeHtml($output)) {
            $html = $this->wrapOutputAsHtml($output);
            $this->replaceDocumentAndExit($html);

            return;
        }

        // Non-HTML output must be valid SSE format (BR-F009-01, BR-F009-02, BR-F009-03, BR-F009-04)
        $this->validateStreamOutput($output);
    }

    /**
     * Replace entire browser document and terminate stream
     *
     * Sends JavaScript that replaces the complete document content using document.write()
     * and terminates the SSE stream. Used for showing full-page content during streaming
     * such as dump output, exceptions, or direct output captures.
     *
     * @param string $html Complete HTML document to display
     */
    protected function replaceDocumentAndExit(string $html): void
    {
        echo "event: gale-patch-elements\n";
        echo 'data: elements <script>document.open(); document.write(' . json_encode($html) . "); document.close();</script>\n";
        echo "data: selector body\n";
        echo "data: mode append\n\n";

        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        exit;
    }

    /**
     * Restore original Laravel service bindings
     *
     * Removes custom redirect binding established for streaming mode,
     * restoring default Laravel behavior. Called in finally block to ensure cleanup
     * occurs even when exceptions are thrown.
     */
    protected function restoreOriginalHandlers(): void
    {
        app()->forgetInstance('redirect');
    }

    /**
     * Emit a gale-error SSE event for an exception thrown inside stream() (F-058 BR-F058-03)
     *
     * Emits a structured gale-error SSE event so the frontend can display the error inline
     * without replacing the page. In debug mode, includes exception class, file, line, and
     * a condensed stack trace. In production, only the generic message is included.
     *
     * @param \Throwable $e Exception thrown inside the stream callback
     */
    protected function emitSseErrorEvent(\Throwable $e): void
    {
        $status = GaleErrorHandler::resolveStatusCode($e);
        $message = GaleErrorHandler::resolveMessage($e, $status);
        $errorDetail = GaleErrorHandler::buildErrorDetail($e, $status, $message);

        echo "event: gale-error\n";
        echo 'data: ' . json_encode($errorDetail) . "\n\n";
    }

    /**
     * Render exception using Laravel exception handler
     *
     * Delegates exception rendering to Laravel's exception handler to generate appropriate
     * error pages (Ignition in development, generic in production). Falls back to basic
     * error HTML if exception handler itself throws an exception.
     *
     * @param \Throwable $e Exception to render
     */
    protected function handleNativeException(\Throwable $e): void
    {
        try {
            $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            $response = $handler->render(request(), $e);
            $html = $response->getContent();

            /** @phpstan-ignore argument.type (getContent can return false, guarded by try-catch) */
            $this->replaceDocumentAndExit($html);

        } catch (\Throwable $renderError) {
            $fallbackHtml = $this->generateFallbackErrorHtml($e);
            $this->replaceDocumentAndExit($fallbackHtml);
        }
    }

    /**
     * Generate basic error HTML page when exception handler fails
     *
     * Creates minimal error page displaying exception message, file location, and stack
     * trace. Used as fallback when Laravel's exception handler itself throws an exception.
     *
     * @param \Throwable $e Original exception
     *
     * @return string HTML error page
     */
    protected function generateFallbackErrorHtml(\Throwable $e): string
    {
        return '<!DOCTYPE html>
<html>
<head><title>Error</title></head>
<body style="background: #ef4444; color: white; padding: 20px; font-family: monospace;">
    <h1>Exception in Stream</h1>
    <p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
    <p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>
    <pre style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 5px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>
</body>
</html>';
    }

    /**
     * Serialize accumulated events to a JSON-encodable array (HTTP mode)
     *
     * Converts the accumulated response events into a structured JSON format suitable
     * for HTTP mode responses. Each event is represented as an object with `type` and
     * `data` fields, mirroring the SSE event format in a JSON-friendly structure.
     *
     * The same event types that exist in SSE mode (gale-patch-state, gale-patch-elements,
     * gale-patch-component, gale-invoke-method, gale-execute-script, gale-dispatch,
     * gale-redirect) are represented as objects in the `events` array.
     *
     * This method does NOT reset the response state (BR-003.10). Resetting is handled
     * by toResponse() to allow the singleton to be reused across requests.
     *
     * @return array{events: array<int, array{type: string, data: array<string, mixed>}>} JSON-encodable array
     */
    public function toJson(): array
    {
        return [
            'events' => $this->jsonEvents,
        ];
    }

    /**
     * Encode accumulated events as a JSON string (HTTP mode)
     *
     * Convenience method that JSON-encodes the output of toJson() with appropriate
     * flags for safe embedding in HTTP responses.
     *
     * @return string JSON string representation of accumulated events
     */
    public function toJsonString(): string
    {
        return (string) json_encode(
            $this->toJson(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Convert to HTTP response implementing Responsable interface
     *
     * Transforms this builder into a framework-compatible response object.
     *
     * For non-Gale requests: returns the web fallback if configured, otherwise 204 No Content.
     * For Gale requests in HTTP mode: returns a JsonResponse with Content-Type application/json.
     * For Gale requests in SSE mode: returns a StreamedResponse with Content-Type text/event-stream.
     *
     * Mode resolution priority:
     * 1. stream() callback presence — always SSE (highest priority)
     * 2. Gale-Mode request header — per-request override
     * 3. config('gale.mode') — server-side default
     *
     * @param \Illuminate\Http\Request|null $request Laravel request instance or null for auto-detection
     *
     * @throws \LogicException When no web fallback provided for non-Gale request
     *
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse|mixed
     */
    public function toResponse($request = null): mixed
    {
        $request = $request ?? request();

        // Flush any pending flash data as a _flash state event before capturing locals (F-061)
        // This ensures all flash() calls in the request are batched into one state patch.
        $this->flushPendingFlash();

        // Flush accumulated debug entries as gale-debug events before capturing locals (F-076)
        // Only emits when APP_DEBUG=true and not in streaming mode (streaming emits immediately).
        if (!$this->streamingMode) {
            $this->flushDebugEntries();
        }

        // Capture current state into locals — reset() in finally guarantees cleanup
        // even if an exception is thrown before or during processing (BR-F030-06)
        $events = $this->events;
        $jsonEvents = $this->jsonEvents;
        $streamCallback = $this->streamCallback;
        $webResponse = $this->webResponse;
        $pendingRedirect = $this->pendingRedirect;
        $etagEnabled = $this->etagEnabled || (bool) config('gale.etag', false);
        $extraHeaders = $this->extraHeaders;

        try {
            // Handle pending redirect from when()/unless() callbacks
            // This takes precedence over other response types
            if ($pendingRedirect !== null) {
                return $pendingRedirect->toResponse($request);
            }

            // Handle non-Gale requests
            /** @phpstan-ignore method.notFound (isGale is a Request macro) */
            if (!$request->isGale()) {
                if ($webResponse === null) {
                    // Return 204 No Content for API routes without web fallback
                    // This makes componentState(), state(), etc. gracefully handle non-Gale requests
                    return response()->noContent();
                }

                return is_callable($webResponse)
                    ? ($webResponse)()
                    : $webResponse;
            }

            // Handle Gale requests — stream() always forces SSE (BR-009.1, BR-009.2, BR-009.3)
            // Mode resolution is bypassed entirely when streamCallback is set
            if ($streamCallback) {
                // Streaming mode: use StreamedResponse for real-time output (BR-009.6)
                $response = new StreamedResponse(function () use ($streamCallback, $events) {
                    try {
                        // Close session before streaming begins (BR-009.7)
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_write_close();
                        }

                        // Allow unlimited execution time for SSE streams (BR-009.8)
                        set_time_limit(0);

                        // Restore pre-accumulated events so they are flushed first (BR-009.4)
                        $this->events = $events;

                        $this->executeStreamingModeWithCallback($streamCallback);
                    } finally {
                        // BR-F030-06: Reset after stream completes so instance is clean
                        // for the next request in persistent worker environments (Octane)
                        $this->reset();
                    }
                });

                foreach (self::headers() as $name => $value) {
                    $response->headers->set($name, $value);
                }

                // BR-F027-05: SSE streaming responses must never be cached
                $response->headers->set('Cache-Control', 'no-store');
                $response->headers->set('Vary', 'Gale-Request');

                // F-064: Run after-hooks on the streaming response (headers can be modified before streaming starts)
                return static::runAfterHooks($response, $request);
            }

            // Resolve mode: Gale-Mode header > config('gale.mode') > 'http' default (BR-004.8)
            // forceHttp() overrides the header — used for validation error responses (BR-F088-SSE)
            $mode = $this->forceHttp ? 'http' : self::resolveRequestMode($request);

            if ($mode === 'http') {
                // HTTP mode: return JsonResponse with serialized events (BR-004.1, BR-004.6)
                //
                // BR-F027-04: State patches use 'no-cache' — the browser always revalidates
                // before serving a cached copy. This enables ETag conditional requests (304)
                // when etag() is enabled, and is harmless without ETag (browser revalidates
                // but has no cached copy to serve).
                $responseHeaders = array_merge([
                    'X-Gale-Response' => 'true',
                    'Cache-Control' => 'no-cache',
                    'Vary' => 'Gale-Request',
                ], $extraHeaders);

                // BR-F027-01, BR-F027-08: Add ETag header when opt-in is set
                // BR-F027-10: ETag is never applied to SSE streaming responses (handled above)
                if ($etagEnabled) {
                    $jsonBody = (string) json_encode(
                        ['events' => $jsonEvents],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );

                    // Generate ETag from MD5 hash of the response body (weak ETag with W/ prefix)
                    $etag = '"' . md5($jsonBody) . '"';
                    $responseHeaders['ETag'] = $etag;

                    // BR-F027-03: Return 304 Not Modified when If-None-Match header matches
                    // BR-F027-09: 304 responses are not errors — they are a successful cache hit
                    $ifNoneMatch = $request->header('If-None-Match');

                    if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                        return response('', 304, [
                            'ETag' => $etag,
                            'X-Gale-Response' => 'true',
                            'Cache-Control' => 'no-cache',
                            'Vary' => 'Gale-Request',
                        ]);
                    }
                }

                $jsonResponse = new JsonResponse(
                    data: ['events' => $jsonEvents],
                    status: 200,
                    headers: $responseHeaders,
                    json: false,
                );

                // F-064: Run after-hooks — hooks may modify or replace the response
                return static::runAfterHooks($jsonResponse, $request);
            }

            // SSE mode: use regular Response (works better with test environments) (BR-004.2)
            $output = ": keepalive\n\n";
            foreach ($events as $event) {
                $output .= $event;
            }

            $sseHeaders = array_merge(self::headers(), $extraHeaders);

            $sseResponse = response($output, 200, $sseHeaders);

            // F-064: Run after-hooks on the SSE response
            return static::runAfterHooks($sseResponse, $request);
        } finally {
            // BR-F030-06: Guarantee state reset even if an exception is thrown
            // during response building. This safety net ensures no state bleeds
            // into the next request in persistent worker environments (Octane).
            $this->reset();
        }
    }

    /**
     * Flush accumulated flash() data as a `_flash` state patch event (F-061)
     *
     * Called once in toResponse() before capturing locals, so all flash() calls
     * in the request are collapsed into a single gale-patch-state event with the
     * reserved `_flash` key (BR-F061-07). If no flash data is pending, this is a no-op.
     *
     * The `_flash` state key is reserved for Gale flash data. Components add `_flash: {}`
     * to their x-data and use `x-show="_flash.success"` to display flash messages.
     *
     * Also picks up session flash data set via session()->flash() directly in controller
     * code, making that data available to the frontend in the SAME response (BR-F061-01).
     * Flash keys are read from the session's `_flash.new` list (keys queued for next request).
     */
    protected function flushPendingFlash(): void
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return;
        }

        // Collect flash data from the explicit flash() API calls on this instance
        $flashData = $this->pendingFlash;

        // Also pick up any session flash data set directly via session()->flash() this request.
        // Laravel's session tracks newly flashed keys in session('_flash.new').
        $sessionFlashKeys = session()->get('_flash.new', []);

        if (is_array($sessionFlashKeys)) {
            foreach ($sessionFlashKeys as $flashKey) {
                if (!is_string($flashKey) && !is_int($flashKey)) {
                    continue;
                }
                $flashKeyStr = (string) $flashKey;
                // Only include if not already set via the flash() API (API takes precedence)
                if (!isset($flashData[$flashKeyStr])) {
                    $flashValue = session()->get($flashKeyStr);

                    if ($flashValue !== null) {
                        $flashData[$flashKeyStr] = $flashValue;
                    }
                }
            }
        }

        if (empty($flashData)) {
            return;
        }

        // Inject as _flash state — uses patchState which signs the payload (F-013)
        $this->patchState(['_flash' => $flashData]);

        // Clear pending flash — session flash remains for the next request (BR-F061-04)
        $this->pendingFlash = [];
    }

    /**
     * Handle event routing based on current response mode
     *
     * Central event dispatcher that routes events to appropriate handler based on whether
     * the response is in streaming or normal mode. Short-circuits immediately for non-Gale
     * requests to avoid unnecessary processing.
     *
     * Also stores structured event data for JSON serialization (HTTP mode) when provided.
     *
     * @param string $eventType SSE event type (gale-patch-elements, gale-patch-state, etc.)
     * @param array<int, string> $dataLines SSE data lines for the event
     * @param array<string, mixed>|null $structuredData Optional structured data for JSON serialization
     */
    protected function handleEvent(string $eventType, array $dataLines, ?array $structuredData = null): void
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return;
        }

        if ($this->streamingMode) {
            $this->sendEventImmediately($eventType, $dataLines);
        } else {
            $this->addEventToQueue($eventType, $dataLines, $structuredData);
        }
    }

    /**
     * Execute response in streaming mode
     *
     * Flushes any events accumulated before streaming began, switches to streaming mode,
     * then executes the callback. All method calls during callback execution send events
     * immediately rather than accumulating them.
     */
    protected function executeStreamingMode(): void
    {
        foreach ($this->events as $event) {
            echo $event;
            $this->flushOutput();
        }

        $this->streamingMode = true;
        /** @phpstan-ignore argument.type (streamCallback is set in stream() method) */
        call_user_func($this->streamCallback, $this);
    }

    /**
     * Execute response in normal single-shot mode
     *
     * Outputs all accumulated events sequentially and closes the connection. Used when
     * stream() method was not called, representing standard request-response pattern.
     */
    protected function executeSingleShotMode(): void
    {
        $this->executeSingleShotModeWithEvents($this->events);
    }

    /**
     * Execute single-shot mode with provided events
     *
     * @param array<int, string> $events Events to output
     */
    protected function executeSingleShotModeWithEvents(array $events): void
    {
        foreach ($events as $event) {
            echo $event;
            $this->flushOutput();
        }
    }

    /**
     * Execute streaming mode with provided callback
     *
     * @param Closure $callback Callback function
     */
    protected function executeStreamingModeWithCallback(Closure $callback): void
    {
        $originalCallback = $this->streamCallback;
        $this->streamCallback = $callback;
        $this->executeStreamingMode();
        $this->streamCallback = $originalCallback;
    }

    /**
     * Send SSE event immediately to client
     *
     * Formats and outputs event directly, then flushes buffers to ensure immediate
     * transmission. Used exclusively in streaming mode.
     *
     * @param string $eventType SSE event type
     * @param array<int, string> $dataLines SSE data lines
     */
    protected function sendEventImmediately(string $eventType, array $dataLines): void
    {
        // BR-F009-02: Check for stray echo/print output in the buffer before sending
        // the next SSE event. This catches non-SSE output inline (per-event) rather than
        // post-hoc, preventing invalid content from leaking into the SSE stream.
        // The interceptor also manages the buffer lifecycle: it closes the current
        // validation buffer so the SSE event can be echoed directly to the client,
        // then re-opens a fresh buffer for capturing subsequent stray output.
        $hadValidationBuffer = $this->interceptStrayBufferOutput();

        $output = $this->formatEvent($eventType, $dataLines);
        echo $output;
        $this->flushOutput();

        // Re-establish the validation buffer so the next echo/print is captured
        if ($hadValidationBuffer) {
            ob_start();
        }
    }

    /**
     * Add formatted SSE event to accumulation queue
     *
     * Stores event for later transmission when response is converted. Used exclusively
     * in normal mode before stream() is called. Also stores structured data for JSON
     * serialization when provided.
     *
     * @param string $eventType SSE event type
     * @param array<int, string> $dataLines SSE data lines
     * @param array<string, mixed>|null $structuredData Optional structured data for JSON serialization
     */
    protected function addEventToQueue(string $eventType, array $dataLines, ?array $structuredData = null): void
    {
        $this->events[] = $this->formatEvent($eventType, $dataLines);

        if ($structuredData !== null) {
            $this->jsonEvents[] = [
                'type' => $eventType,
                'data' => $structuredData,
            ];
        }
    }

    /**
     * Add a structured event directly to the JSON events queue
     *
     * Used when the JSON event type differs from the SSE event type (e.g.,
     * script execution uses gale-patch-elements in SSE but gale-execute-script in JSON).
     *
     * @param string $eventType JSON event type
     * @param array<string, mixed> $data Structured event data
     */
    protected function addJsonEvent(string $eventType, array $data): void
    {
        $this->jsonEvents[] = [
            'type' => $eventType,
            'data' => $data,
        ];
    }

    /**
     * Flush PHP output buffers to client
     *
     * Forces immediate transmission of buffered output to client. Used in streaming
     * mode to ensure events are received as soon as they're generated.
     */
    protected function flushOutput(): void
    {
        // Flush ALL output buffers for progressive streaming
        // This is critical - must flush all levels, not just one
        while (ob_get_level() > 0 && ob_get_contents() !== false) {
            ob_end_flush();
        }
        flush();
    }

    /**
     * Format SSE event according to SSE specification
     *
     * Constructs properly formatted Server-Sent Event with optional id, retry duration,
     * event type and data lines, terminated by blank line as required by SSE specification.
     *
     * SSE format:
     * - id: <event-id>       (optional, for replay support)
     * - retry: <ms>          (optional, reconnection time)
     * - event: <event-type>  (required)
     * - data: <line>         (one or more data lines)
     * - <blank line>         (terminates the event)
     *
     * @param string $eventType Event type line (event: xxx)
     * @param array<int, string> $dataLines Data payload lines (data: xxx)
     *
     * @return string Formatted SSE event block
     */
    protected function formatEvent(string $eventType, array $dataLines): string
    {
        $output = [];

        // Add event ID for replay support (if set)
        if ($this->eventId !== null) {
            $output[] = "id: {$this->eventId}";
        }

        // Add retry duration (if set and different from default)
        if ($this->retryDuration !== null) {
            $output[] = "retry: {$this->retryDuration}";
        }

        $output[] = "event: {$eventType}";

        foreach ($dataLines as $line) {
            $lineStr = is_string($line) ? $line : (string) $line;
            $output[] = "data: {$lineStr}";
        }

        $output[] = '';

        return implode("\n", $output) . "\n";
    }

    /**
     * Build SSE data lines for element patching event
     *
     * Constructs array of SSE data lines for gale-patch-elements event including
     * selector, mode, view transition flag, settle time, limit, viewport modifiers,
     * and multi-line HTML element content.
     *
     * @param string $elements HTML content to patch
     * @param array<string, mixed> $options Patching options (selector, mode, useViewTransition, settle, limit, scroll, show, focusScroll)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildElementsEvent(string $elements, array $options): array
    {
        $dataLines = [];

        if (!empty($options['selector'])) {
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            $dataLines[] = 'selector ' . (string) $options['selector'];
        }

        if (!empty($options['mode'])) {
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            $dataLines[] = 'mode ' . (string) $options['mode'];
        }

        if (!empty($options['useViewTransition'])) {
            $dataLines[] = 'useViewTransition true';
        }

        // Settle time for CSS transitions (in milliseconds)
        if (!empty($options['settle'])) {
            /** @phpstan-ignore cast.int (mixed array value from user options, expected numeric) */
            $dataLines[] = 'settle ' . (int) $options['settle'];
        }

        // Limit number of targets to patch
        if (!empty($options['limit'])) {
            /** @phpstan-ignore cast.int (mixed array value from user options, expected numeric) */
            $dataLines[] = 'limit ' . (int) $options['limit'];
        }

        // Viewport modifier: auto-scroll target ('top' or 'bottom')
        if (!empty($options['scroll'])) {
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            $dataLines[] = 'scroll ' . (string) $options['scroll'];
        }

        // Viewport modifier: scroll into viewport ('top' or 'bottom')
        if (!empty($options['show'])) {
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            $dataLines[] = 'show ' . (string) $options['show'];
        }

        // Viewport modifier: restore focus scroll position
        if (!empty($options['focusScroll'])) {
            $dataLines[] = 'focusScroll true';
        }

        $elementLines = explode("\n", trim($elements));
        foreach ($elementLines as $line) {
            $dataLines[] = "elements {$line}";
        }

        return $dataLines;
    }

    /**
     * Build SSE data lines for state patching event
     *
     * Constructs array of SSE data lines for gale-patch-state event including
     * onlyIfMissing flag and JSON-encoded state data split across multiple lines.
     * Uses RFC 7386 JSON Merge Patch semantics.
     *
     * @param array<string, mixed> $state State keys and values to patch
     * @param array<string, mixed> $options State options (onlyIfMissing)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildStateEvent(array $state, array $options): array
    {
        $dataLines = [];

        if (!empty($options['onlyIfMissing'])) {
            $dataLines[] = 'onlyIfMissing true';
        }

        $stateJson = json_encode($state);
        /** @phpstan-ignore argument.type (json_encode result is always string for arrays) */
        $jsonLines = explode("\n", $stateJson);

        foreach ($jsonLines as $line) {
            $dataLines[] = "state {$line}";
        }

        return $dataLines;
    }

    /**
     * Build SSE data lines for component state patching event
     *
     * Constructs array of SSE data lines for gale-patch-component event including
     * component name, onlyIfMissing flag, and JSON-encoded state data.
     *
     * @param string $componentName Component name (from x-component attribute)
     * @param array<string, mixed> $state State keys and values to patch
     * @param array<string, mixed> $options State options (onlyIfMissing)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildComponentEvent(string $componentName, array $state, array $options): array
    {
        $dataLines = [];

        // Component name
        $dataLines[] = "component {$componentName}";

        if (!empty($options['onlyIfMissing'])) {
            $dataLines[] = 'onlyIfMissing true';
        }

        $stateJson = json_encode($state);
        $dataLines[] = "state {$stateJson}";

        return $dataLines;
    }

    /**
     * Build SSE data lines for method invocation event
     *
     * Constructs array of SSE data lines for gale-invoke-method event including
     * component name, method name, and JSON-encoded arguments array.
     *
     * @param string $componentName Component name (from x-component attribute)
     * @param string $method Method name to invoke
     * @param array<int, mixed> $args Arguments to pass to the method
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildMethodInvocationEvent(string $componentName, string $method, array $args): array
    {
        $dataLines = [];

        // Component name
        $dataLines[] = "component {$componentName}";

        // Method name
        $dataLines[] = "method {$method}";

        // Arguments as JSON
        $argsJson = json_encode($args);
        $dataLines[] = "args {$argsJson}";

        return $dataLines;
    }

    /**
     * Build SSE data lines for script execution event
     *
     * Constructs array of SSE data lines for executing JavaScript by creating a script
     * element appended to document body. Supports custom attributes and auto-removal
     * after execution using Alpine's x-init directive.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script configuration (attributes, autoRemove)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildScriptEvent(string $script, array $options): array
    {
        /** @var array<string, string> $attributes */
        $attributes = $options['attributes'] ?? [];
        $autoRemove = $options['autoRemove'] ?? true;

        $scriptTag = '<script';

        foreach ($attributes as $key => $value) {
            $scriptTag .= ' ' . (string) $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }

        if ($autoRemove) {
            // Use Alpine x-init to remove script after execution
            $scriptTag .= ' x-init="$nextTick(() => $el.remove())"';
        }

        $scriptTag .= '>' . $script . '</script>';

        // Split script tag by newlines to properly format SSE data lines
        $dataLines = ['selector body', 'mode append'];
        $scriptLines = explode("\n", trim($scriptTag));
        foreach ($scriptLines as $line) {
            $dataLines[] = "elements {$line}";
        }

        return $dataLines;
    }

    /**
     * Build SSE data lines for element removal event
     *
     * Constructs array of SSE data lines for removing DOM elements using patch mode 'remove'.
     * Supports View Transitions API for smooth removal animations.
     *
     * @param array<string, mixed> $options Removal configuration (selector, useViewTransition)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildRemovalEvent(array $options): array
    {
        $dataLines = [
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            'selector ' . (string) $options['selector'],
            'mode remove',
        ];

        if (!empty($options['useViewTransition'])) {
            $dataLines[] = 'useViewTransition true';
        }

        return $dataLines;
    }

    /**
     * Render and patch a Blade fragment without full view compilation
     *
     * Extracts the specified fragment from the view using BladeFragmentParser and renders
     * it with provided data, then patches the result into the DOM. Only processes for Gale
     * requests to avoid unnecessary fragment extraction for standard requests.
     *
     * @param string $view Blade view containing the fragment
     * @param string $fragment Fragment name to extract and render
     * @param array<string, mixed> $data Variables to pass to fragment
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchFragment(string $view, string $fragment, array $data = [], array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $fragmentHtml = BladeFragment::render($view, $fragment, $data);

        return $this->patchElements($fragmentHtml, $options);
    }

    /**
     * Render and patch multiple Blade fragments in sequence
     *
     * Processes array of fragment configurations, rendering and patching each fragment.
     * Enables updating multiple UI sections in a single response without full page reloads.
     *
     * @param array<int, array{view: string, fragment: string, data?: array<string, mixed>, options?: array<string, mixed>}> $fragments Fragment configurations
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchFragments(array $fragments): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        foreach ($fragments as $fragmentConfig) {
            $view = $fragmentConfig['view'];
            $fragment = $fragmentConfig['fragment'];
            $data = $fragmentConfig['data'] ?? [];
            $options = $fragmentConfig['options'] ?? [];

            $this->patchFragment($view, $fragment, $data, $options);
        }

        return $this;
    }

    /**
     * Render fragment and return HTML string without patching
     *
     * Extracts and renders fragment but returns the HTML as a string instead of patching
     * it into the DOM. Useful for embedding fragment content in other contexts or for
     * manual DOM manipulation.
     *
     * @param string $view Blade view containing the fragment
     * @param string $fragment Fragment name to extract and render
     * @param array<string, mixed> $data Variables to pass to fragment
     *
     * @return string Rendered HTML content of the fragment
     */
    protected function renderFragment(string $view, string $fragment, array $data = []): string
    {
        return BladeFragment::render($view, $fragment, $data);
    }

    /**
     * Configure fallback response for non-Gale requests
     *
     * Sets the response to return when the request does not include the Gale-Request
     * header. Accepts any value that Laravel can convert to a response, including Response
     * objects, views, redirects, or closures that return these types. If not configured,
     * LogicException is thrown for non-Gale requests when toResponse() is called.
     *
     * @param mixed $response Response value or closure returning response
     *
     * @return static Returns this instance for method chaining
     */
    public function web(mixed $response): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (request()->isGale()) {
            return $this;
        }

        $this->webResponse = $response;

        return $this;
    }

    /**
     * Execute callback conditionally based on evaluated condition
     *
     * Provides Laravel-style conditional method chaining where callbacks receive this
     * instance and can add events conditionally. Condition can be boolean value or
     * callable that receives this instance and returns boolean. Supports optional
     * fallback callback for else branch.
     *
     * If the callback returns a GaleRedirect, it will be stored and executed when
     * toResponse() is called, allowing redirects within conditional blocks.
     *
     * @param mixed $condition Boolean value or callable returning boolean
     * @param callable $callback Callback to execute if condition is truthy
     * @param callable|null $fallback Optional callback to execute if condition is falsy
     *
     * @return static Returns this instance for method chaining
     */
    public function when($condition, callable $callback, ?callable $fallback = null): self
    {
        $conditionResult = is_callable($condition) ? $condition($this) : $condition;

        if ($conditionResult) {
            $result = $callback($this);
            if ($result instanceof GaleRedirect) {
                $this->pendingRedirect = $result;
            }
        } elseif ($fallback) {
            $result = $fallback($this);
            if ($result instanceof GaleRedirect) {
                $this->pendingRedirect = $result;
            }
        }

        return $this;
    }

    /**
     * Execute callback when condition evaluates to false
     *
     * Inverted conditional that executes callback when condition is falsy. Equivalent
     * to when() with negated condition. Useful for clearer conditional logic in certain
     * scenarios.
     *
     * @param mixed $condition Boolean value or callable returning boolean
     * @param callable $callback Callback to execute if condition is falsy
     * @param callable|null $fallback Optional callback to execute if condition is truthy
     *
     * @return static Returns this instance for method chaining
     */
    public function unless($condition, callable $callback, ?callable $fallback = null): self
    {
        return $this->when(!$condition, $callback, $fallback);
    }

    /**
     * Execute callback only for Gale requests
     *
     * Convenience method for conditional execution based on request type. Automatically
     * detects Gale requests via Gale-Request header and executes callback only
     * when present. Supports optional fallback for non-Gale requests.
     *
     * @param callable $callback Callback to execute for Gale requests
     * @param callable|null $fallback Optional callback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function whenGale(callable $callback, ?callable $fallback = null): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        return $this->when(request()->isGale(), $callback, $fallback);
    }

    /**
     * Execute callback only for non-Gale requests
     *
     * Inverse of whenGale() that executes callback for standard HTTP requests without
     * Gale-Request header. Useful for providing alternate behavior for traditional
     * full-page requests.
     *
     * @param callable $callback Callback to execute for non-Gale requests
     * @param callable|null $fallback Optional callback for Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function whenNotGale(callable $callback, ?callable $fallback = null): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        return $this->when(!request()->isGale(), $callback, $fallback);
    }

    /**
     * Delete specified state keys from client-side Alpine x-data store
     *
     * Converts state keys to deletion array with null values per RFC 7386,
     * then sends update event to remove state from client. Supports single state
     * key or array of state keys.
     *
     * @param string|array<int, string> $state State key or array of state keys to delete
     *
     * @return static Returns this instance for method chaining
     */
    protected function forgetState(string|array $state): self
    {
        $deletionArray = $this->parseStateForDeletion($state);

        return $this->patchState($deletionArray);
    }

    /**
     * Create deletion array with null values for state removal
     *
     * Transforms state keys into associative array format required for deletion,
     * where each state key maps to null value to trigger client-side removal per
     * RFC 7386 JSON Merge Patch. The 'errors' state receives special treatment and is
     * reset to an empty array instead of null, as it should always remain an array for
     * the x-message directive to function correctly.
     *
     * @param string|array<int, string> $state State key or array of state keys
     *
     * @return array<string, null|array<empty, empty>> Deletion array with state keys as keys, null or empty array as values
     */
    private function parseStateForDeletion(string|array $state): array
    {
        $stateKeys = $this->parseStateKeys($state);

        $deletionArray = [];
        foreach ($stateKeys as $stateKey) {
            // Special handling for messages/errors state - reset to empty array instead of null
            // Both are used by x-message directive and should always be arrays
            $deletionArray[$stateKey] = in_array($stateKey, ['messages', 'errors'], strict: true) ? [] : null;
        }

        return $deletionArray;
    }

    /**
     * Extract state keys from various input formats
     *
     * Normalizes different input formats (single string, indexed array, associative array)
     * into flat array of state key strings. For associative arrays, extracts keys as
     * state keys. For indexed arrays, uses values as state keys.
     *
     * @param string|array<int|string, mixed> $state State key(s) in various formats
     *
     * @return array<int, string> Normalized array of state key strings
     */
    private function parseStateKeys(string|array $state): array
    {
        if (is_string($state)) {
            return [$state];
        }

        if (array_keys($state) !== range(0, count($state) - 1)) {
            return array_map(fn ($key) => (string) $key, array_keys($state));
        }

        /** @phpstan-ignore cast.string (State values are converted to strings for consistency) */
        return array_map(fn ($value) => (string) $value, array_values($state));
    }

    /**
     * Execute callback for Gale navigate requests matching optional key
     *
     * Conditional execution specific to navigate requests identified by Gale-Navigate
     * header. Supports filtering by navigate key for targeted updates (e.g., sidebar vs
     * main content). When first parameter is callable, treats request as "any navigate".
     *
     * @param string|array<int, string>|callable|null $key Navigate key filter, array of keys, or callback for any navigate
     * @param callable|null $callback Callback to execute when navigate request matches
     * @param callable|null $fallback Optional callback for non-matching requests
     *
     * @return static Returns this instance for method chaining
     */
    public function whenGaleNavigate(string|array|callable|null $key = null, ?callable $callback = null, ?callable $fallback = null): self
    {
        if (is_callable($key)) {
            $fallback = $callback;
            $callback = $key;
            $key = null;
        }

        /** @phpstan-ignore method.notFound (isGaleNavigate is a Request macro) */
        $isNavigateRequest = request()->isGaleNavigate($key);

        if ($isNavigateRequest && $callback) {
            $callback($this);
        } elseif (!$isNavigateRequest && $fallback) {
            $fallback($this);
        }

        return $this;
    }

    /**
     * Force immediate full-page reload breaking out of reactive mode
     *
     * Performs a complete page refresh using window.location.reload(), discarding
     * all client-side state and re-initializing the application. Used for scenarios
     * requiring complete state reset or when reactive updates are insufficient.
     *
     * @return static Returns this instance for method chaining
     */
    public function reload(): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // Use standard JavaScript for page reload
        $script = 'window.location.reload()';

        return $this->executeScript($script, ['autoRemove' => true]);
    }

    /**
     * Navigate to URL with explicit merge control and comprehensive options
     *
     * REPLACES THE EXISTING navigate() METHOD WITH EXPLICIT BEHAVIOR
     *
     * @param string|array<string, mixed> $url URL string or array of query parameters
     * @param string $key Navigation key for Gale routing
     * @param array<string, mixed> $options Navigation options
     */
    public function navigate(string|array $url, string $key = 'true', array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $this->enforceUrlSingleUse();

        // Process input based on type - NO backend merging, let frontend handle it
        if (is_array($url)) {
            // JSON query array navigation - convert to current path with queries
            $queryString = $this->buildQueryString($url);
            $currentPath = request()->getPathInfo();
            $finalUrl = $queryString ? "{$currentPath}?{$queryString}" : $currentPath;
        } else {
            // Traditional string URL navigation - send as-is
            $finalUrl = $url;
        }

        $this->validateNavigateUrl($finalUrl);

        // Generate navigation script with comprehensive options
        // Frontend @navigate action will handle all merging based on window.location
        $script = $this->generateEnhancedNavigateScript($finalUrl, $key, $options);

        return $this->executeScript($script, ['autoRemove' => true]);
    }

    /**
     * Navigate with explicit merge behavior (RECOMMENDED)
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param bool $merge Whether to merge with current query parameters
     * @param array<string, mixed> $options Additional options (only, except, replace)
     */
    public function navigateWith(string|array $url, string $key = 'true', bool $merge = false, array $options = []): self
    {
        return $this->navigate($url, $key, array_merge($options, ['merge' => $merge]));
    }

    /**
     * Navigate and merge with current parameters (preserves context)
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param array<string, mixed> $options Additional options
     */
    public function navigateMerge(string|array $url, string $key = 'true', array $options = []): self
    {
        return $this->navigateWith($url, $key, true, $options);
    }

    /**
     * Navigate with clean slate (no parameter merging)
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param array<string, mixed> $options Additional options
     */
    public function navigateClean(string|array $url, string $key = 'true', array $options = []): self
    {
        return $this->navigateWith($url, $key, false, $options);
    }

    /**
     * Navigate preserving only specific parameters
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param array<int, string> $only Parameters to preserve
     * @param string $key Navigation key
     */
    public function navigateOnly(string|array $url, array $only, string $key = 'true'): self
    {
        return $this->navigate($url, $key, ['merge' => true, 'only' => $only]);
    }

    /**
     * Navigate preserving all except specific parameters
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param array<int, string> $except Parameters to exclude
     * @param string $key Navigation key
     */
    public function navigateExcept(string|array $url, array $except, string $key = 'true'): self
    {
        return $this->navigate($url, $key, ['merge' => true, 'except' => $except]);
    }

    /**
     * Navigate using replaceState instead of pushState
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param array<string, mixed> $options Additional options
     */
    public function navigateReplace(string|array $url, string $key = 'true', array $options = []): self
    {
        return $this->navigate($url, $key, array_merge($options, ['replace' => true]));
    }

    /**
     * CONVENIENCE METHODS FOR COMMON PATTERNS
     */

    /**
     * Navigate to current page with new query parameters (maintains path)
     *
     * @param array<string, mixed> $queries Query parameters to set
     * @param string $key Navigation key
     * @param bool $merge Whether to merge with existing parameters
     */
    public function updateQueries(array $queries, string $key = 'filters', bool $merge = true): self
    {
        return $this->navigate($queries, $key, ['merge' => $merge]);
    }

    /**
     * Clear specific query parameters
     *
     * @param array<int, string> $paramNames Parameters to clear
     * @param string $key Navigation key
     */
    public function clearQueries(array $paramNames, string $key = 'clear'): self
    {
        $clearQueries = array_fill_keys($paramNames, null);

        return $this->navigate($clearQueries, $key, ['merge' => true]);
    }

    /**
     * INTERNAL PROCESSING METHODS
     */

    /**
     * Build query string from associative array
     *
     * @param array<string, mixed> $queries Query parameters
     *
     * @return string Query string (without ?)
     */
    private function buildQueryString(array $queries): string
    {
        $params = [];

        foreach ($queries as $key => $value) {
            if ($value === null || $value === '') {
                // Skip null/empty values - they clear parameters
                continue;
            }

            if (is_array($value)) {
                // Handle arrays (multi-select, checkboxes, etc.)
                foreach ($value as $item) {
                    if ($item !== null && $item !== '') {
                        /** @phpstan-ignore cast.string (Safe cast for URL encoding) */
                        $params[] = urlencode((string) $key) . '[]=' . urlencode((string) $item);
                    }
                }
            } else {
                // Handle scalar values
                /** @phpstan-ignore cast.string (Safe cast for URL encoding) */
                $params[] = urlencode((string) $key) . '=' . urlencode((string) $value);
            }
        }

        return implode('&', $params);
    }

    /**
     * Generate enhanced navigation script using Gale action
     *
     * @param string $url Target URL
     * @param string $key Navigation key
     * @param array<string, mixed> $options Navigation options
     *
     * @return string JavaScript code
     */
    private function generateEnhancedNavigateScript(string $url, string $key, array $options): string
    {
        // Build options for frontend - pass ALL navigation options
        $frontendOptions = [];

        if (isset($options['merge'])) {
            $frontendOptions['merge'] = (bool) $options['merge'];
        }

        if (!empty($options['only'])) {
            $frontendOptions['only'] = $options['only'];
        }

        if (!empty($options['except'])) {
            $frontendOptions['except'] = $options['except'];
        }

        if (!empty($options['replace'])) {
            $frontendOptions['replace'] = true;
        }

        // Use a custom DOM event to trigger navigation
        // The frontend navigate watcher listens for 'gale:navigate' events
        $navigationData = [
            'url' => $url,
            'key' => $key,
            'options' => $frontendOptions,
            'timestamp' => microtime(true), // Ensure uniqueness to trigger reactivity
        ];

        $safeData = json_encode($navigationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        // Dispatch a custom DOM event that our navigate watcher will listen to
        return "document.dispatchEvent(new CustomEvent('gale:navigate', { detail: {$safeData} }))";
    }

    // ==========================================
    // URL Validation (Internal Methods)
    // ==========================================

    /**
     * Enforce single URL operation constraint per response
     *
     * Prevents multiple navigate calls in a single response to avoid conflicting
     * history state changes. Throws exception if URL already set.
     *
     * @throws \LogicException When URL operation already executed for current response
     */
    private function enforceUrlSingleUse(): void
    {
        if ($this->urlSet) {
            throw new \LogicException('URL can only be set once per response. Use navigate() only once per response.');
        }

        $this->urlSet = true;
    }

    /**
     * Validate URL format and enforce same-origin policy
     *
     * Checks URL format validity and enforces same-origin policy for absolute URLs
     * to prevent open redirect vulnerabilities. Relative paths are allowed.
     *
     * @param string $url URL to validate
     *
     * @throws \InvalidArgumentException When URL format invalid or cross-origin URL detected
     */
    private function validateNavigateUrl(string $url): void
    {
        // Allow relative paths (start with / or don't have ://)
        if ($this->isRelativePath($url)) {
            return;
        }

        // Validate absolute URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("Invalid URL format: {$url}");
        }

        // Enforce same-origin policy for absolute URLs
        $this->validateSameOrigin($url);
    }

    /**
     * Determine if URL string represents a relative path
     */
    private function isRelativePath(string $url): bool
    {
        return str_starts_with($url, '/') || !str_contains($url, '://');
    }

    /**
     * Validate URL host matches current request host (same-origin policy)
     *
     * @throws \InvalidArgumentException When URL host differs from current request host
     */
    private function validateSameOrigin(string $url): void
    {
        $parsedUrl = parse_url($url);
        $currentHost = request()->getHost();

        if (isset($parsedUrl['host']) && $parsedUrl['host'] !== $currentHost) {
            throw new \InvalidArgumentException("Cross-origin URLs not allowed. Got: {$parsedUrl['host']}, Expected: {$currentHost}");
        }
    }
}
