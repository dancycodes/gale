<?php

namespace Dancycodes\Gale\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gale Dump Intercept Middleware — captures dd()/dump() output in Gale requests (F-057)
 *
 * When `config('gale.debug')` is true, this middleware wraps the controller execution
 * in an output buffer. Any VarDumper HTML output (from `dump()` or `dd()`) is captured
 * and injected as `gale-debug-dump` events into the Gale response.
 *
 * The VarDumper HTML format is only active when running as a real HTTP request (not CLI),
 * which causes Symfony's VarDumper to use the HtmlDumper and write to the output buffer.
 * In tests, `$_SERVER['VAR_DUMPER_FORMAT'] = 'html'` forces HTML format.
 *
 * Handles two cases:
 * - `dump()`: Output is captured after the controller returns. The middleware modifies the
 *   existing JSON response by prepending `gale-debug-dump` events (BR-057.1).
 * - `dd()`: Calls `exit(1)` directly, so it cannot be intercepted via try/catch. A
 *   `register_shutdown_function` is registered to capture the buffered VarDumper HTML
 *   and send a `gale-debug-dump` JSON response before the process terminates (BR-057.2).
 *
 * Only active for JSON/HTTP mode responses. SSE streaming mode handles dd() separately
 * via `GaleResponse::handleShutdownOutput()` registered in the `stream()` callback.
 *
 * Business rules:
 * - BR-057.1: dump() output captured without halting response processing
 * - BR-057.2: dd() output captured in shutdown; minimal JSON response preserves page state
 * - BR-057.3: VarDumper HTML formatting (colors, collapsible) preserved
 * - BR-057.4: Works in HTTP (JSON) mode
 * - BR-057.7: Only active when config('gale.debug') is true
 *
 * @see \Dancycodes\Gale\Http\GaleResponse::handleShutdownOutput() for SSE mode handling
 */
class GaleDumpInterceptMiddleware
{
    /**
     * Maximum allowed dump output size in bytes (1MB limit for oversized dumps)
     */
    private const MAX_DUMP_SIZE = 1_048_576; // 1MB

    /**
     * Handle an incoming request.
     *
     * Wraps controller execution in an output buffer for Gale requests (when debug
     * mode is enabled). Captures VarDumper output and injects it as debug events
     * into the existing Gale JSON response.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // BR-057.7: Only active when gale.debug is true AND this is a Gale request
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (! config('gale.debug', false) || ! $request->isGale()) {
            return $next($request);
        }

        // Register a shutdown function to handle dd() calls.
        // dd() calls exit(1), which cannot be caught with try/catch.
        // The shutdown function runs after exit(), allowing us to capture
        // the buffered VarDumper HTML and send a minimal Gale JSON response.
        $request->attributes->set('_gale_dump_intercept_active', true);
        register_shutdown_function([$this, 'handleShutdown'], $request);

        ob_start();

        try {
            $response = $next($request);

            // Capture any buffered dump() output
            $output = ob_get_clean();

            if (! empty(trim($output ?? '')) && $this->looksLikeVarDumper($output ?? '')) {
                // Inject dump events into the already-built response (BR-057.1)
                return $this->injectDumpIntoResponse($output ?? '', $response);
            }

            return $response;

        } catch (\Throwable $e) {
            // Capture and discard any buffered output before re-throwing
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            throw $e;
        } finally {
            // Signal shutdown function that we completed normally (no dd() exit)
            $request->attributes->set('_gale_dump_intercept_active', false);

            // Clean up any remaining buffer level from this middleware
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
    }

    /**
     * Shutdown handler for dd() calls (BR-057.2)
     *
     * Called after dd() triggers exit(1). At this point the output buffer
     * contains the VarDumper HTML. We extract it, build a JSON response with
     * a gale-debug-dump event, and output it directly.
     *
     * Note: headers_sent() is typically false at this point because Laravel's
     * response has not yet been sent (exit was called before Symfony's kernel
     * could send the response).
     *
     * This method is public because it's called via register_shutdown_function().
     *
     * @param  \Illuminate\Http\Request  $request  The original Gale request
     */
    public function handleShutdown(Request $request): void
    {
        // Only act if the middleware was still active when exit() was called
        // (meaning dd() was the cause — not a normal completion path)
        if (! $request->attributes->get('_gale_dump_intercept_active', false)) {
            return;
        }

        // Capture all remaining output buffers (dd() may leave multiple levels)
        $output = '';
        while (ob_get_level() > 0) {
            $output = ob_get_clean().$output;
        }

        if (empty(trim($output))) {
            return;
        }

        // Only handle if this looks like VarDumper HTML output (BR-057.8)
        if (! $this->looksLikeVarDumper($output)) {
            return;
        }

        $json = $this->buildDumpJson($output);

        if ($json === null) {
            return;
        }

        if (! headers_sent()) {
            header('Content-Type: application/json');
            header('X-Gale-Response: true');
            header('Cache-Control: no-cache');
        }

        echo $json;
    }

    /**
     * Inject VarDumper HTML into an existing Gale JSON response
     *
     * Modifies the JsonResponse's events array to prepend a `gale-debug-dump` event
     * with the captured VarDumper HTML. Other event types (SSE streaming) are not
     * modified — the dump is a debug-only concern.
     *
     * For non-JSON responses (SSE mode), returns the response unchanged. SSE streaming
     * handles dd() separately via GaleResponse::handleShutdownOutput().
     *
     * @param  string    $html      Raw VarDumper HTML from output buffer
     * @param  Response  $response  The already-built Gale response
     * @return Response  Modified response with dump event prepended
     */
    private function injectDumpIntoResponse(string $html, Response $response): Response
    {
        // Only modify JSON responses (HTTP mode)
        if (! ($response instanceof JsonResponse)) {
            return $response;
        }

        $html = $this->truncateIfOversized($html);

        // Decode the existing events array and prepend the dump event
        $data = $response->getData(true);

        if (! is_array($data) || ! isset($data['events']) || ! is_array($data['events'])) {
            $data = ['events' => []];
        }

        // Prepend dump event so it appears before any state changes (BR-057.1)
        array_unshift($data['events'], [
            'type' => 'gale-debug-dump',
            'data' => ['html' => $html],
        ]);

        $response->setData($data);

        return $response;
    }

    /**
     * Build a gale-debug-dump JSON response string from VarDumper HTML
     *
     * Builds a minimal Gale JSON response: { events: [{ type: 'gale-debug-dump', ... }] }
     * Used by handleShutdown() for dd() calls. Exposed as public for unit testing.
     *
     * @param  string  $html  Raw VarDumper HTML (may be oversized — truncated internally)
     * @return string|null JSON string, or null if HTML is empty after processing
     */
    public function buildDumpJson(string $html): ?string
    {
        $html = $this->truncateIfOversized($html);

        if (empty(trim($html))) {
            return null;
        }

        $events = [
            ['type' => 'gale-debug-dump', 'data' => ['html' => $html]],
        ];

        return json_encode(
            ['events' => $events],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: null;
    }

    /**
     * Check if content looks like Symfony VarDumper HTML output
     *
     * VarDumper HTML output contains the `sf-dump` CSS class and/or the
     * `Sfdump` JavaScript initialization function. Using these as markers
     * avoids injecting arbitrary buffered output as debug events.
     *
     * @param  string  $content  Output buffer content
     */
    private function looksLikeVarDumper(string $content): bool
    {
        return strpos($content, 'sf-dump') !== false
            || strpos($content, 'Sfdump') !== false
            || strpos($content, 'pre.sf-dump') !== false;
    }

    /**
     * Truncate HTML if it exceeds the 1MB size limit
     *
     * @param  string  $html  Raw HTML content
     * @return string HTML content, truncated to MAX_DUMP_SIZE if needed
     */
    private function truncateIfOversized(string $html): string
    {
        if (strlen($html) > self::MAX_DUMP_SIZE) {
            return substr($html, 0, self::MAX_DUMP_SIZE)
                .'<p style="color:orange;font-weight:bold;">[... output truncated — exceeded 1MB limit ...]</p>';
        }

        return $html;
    }
}
