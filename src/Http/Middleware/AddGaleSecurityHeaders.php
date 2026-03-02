<?php

namespace Dancycodes\Gale\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adds security-hardening HTTP headers to all Gale responses (F-022)
 *
 * Automatically attaches the following headers to every Gale HTTP and SSE response:
 *
 *  - X-Content-Type-Options: nosniff      — prevents MIME type sniffing
 *  - X-Frame-Options: SAMEORIGIN         — prevents clickjacking (configurable)
 *  - Cache-Control: no-store, no-cache, must-revalidate — prevents caching of sensitive state
 *  - Pragma: no-cache                    — HTTP/1.0 proxy compatibility
 *  - X-Accel-Buffering: no               — SSE: nginx reverse-proxy passthrough
 *
 * All headers are individually configurable via config('gale.headers'):
 *
 *   'headers' => [
 *       'x_content_type_options' => 'nosniff',                         // false to disable
 *       'x_frame_options'        => 'SAMEORIGIN',                      // 'DENY', 'SAMEORIGIN', or false
 *       'cache_control'          => 'no-store, no-cache, must-revalidate', // Custom value or false
 *       'custom'                 => ['X-Custom-Header' => 'value'],    // arbitrary extra headers
 *   ],
 *
 * Business rules:
 *  - BR-022.8: Only adds headers to Gale responses (X-Gale-Response header is present)
 *  - BR-022.9: Does NOT overwrite headers already set by the application controller
 *  - BR-022.4/5: SSE responses (text/event-stream) receive X-Accel-Buffering: no and Cache-Control: no-cache
 *  - BR-022.3: State-bearing (non-SSE) responses receive Cache-Control: no-store, no-cache, must-revalidate
 *  - BR-022.6: Each header can be individually disabled by setting its config value to false
 *  - BR-022.7: Custom headers from the 'custom' config array are always applied
 *
 * @see config/gale.php  'headers' section
 */
class AddGaleSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * Passes the request down the pipeline, then inspects the response.
     * If the response is a Gale response (X-Gale-Response header present),
     * security headers are applied according to the config. Non-Gale responses
     * are returned unmodified (BR-022.8).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // BR-022.8: Only add headers to Gale responses.
        // X-Gale-Response is set by GaleResponse::toResponse() on all Gale outputs.
        if (! $response->headers->has('X-Gale-Response')) {
            return $response;
        }

        $isSSE = $this->isSSEResponse($response);

        $this->applySecurityHeaders($response, $isSSE);

        return $response;
    }

    /**
     * Determine whether the response is an SSE (text/event-stream) response
     *
     * Used to select the appropriate Cache-Control header value and to decide
     * whether X-Accel-Buffering should be added (SSE-only, BR-022.4).
     */
    protected function isSSEResponse(Response $response): bool
    {
        // StreamedResponse is always SSE in Gale (stream() callback path)
        if ($response instanceof StreamedResponse) {
            return true;
        }

        // Regular SSE mode (non-streaming): check Content-Type header
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/event-stream');
    }

    /**
     * Apply configured security headers to the given response
     *
     * All headers are applied only when absent (BR-022.9: controller values win).
     * Invalid header values (containing control characters or forbidden chars) are
     * skipped with a logged warning, per the edge case in the spec.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response  Gale response to mutate
     * @param  bool  $isSSE  Whether the response is an SSE streaming response
     */
    protected function applySecurityHeaders(Response $response, bool $isSSE): void
    {
        $config = config('gale.headers', []);

        // --- X-Content-Type-Options (BR-022.1) ---
        $xContentType = $this->resolveConfigValue($config, 'x_content_type_options', 'nosniff');
        if ($xContentType !== false && ! $response->headers->has('X-Content-Type-Options')) {
            $this->setHeaderSafe($response, 'X-Content-Type-Options', $xContentType);
        }

        // --- X-Frame-Options (BR-022.2) ---
        $xFrameOptions = $this->resolveConfigValue($config, 'x_frame_options', 'SAMEORIGIN');
        if ($xFrameOptions !== false && ! $response->headers->has('X-Frame-Options')) {
            $this->setHeaderSafe($response, 'X-Frame-Options', $xFrameOptions);
        }

        // --- Cache-Control (BR-022.3 for state-bearing, BR-022.5 for SSE) ---
        // Cache-Control is always applied (even when already set by the framework) because
        // GaleResponse sets a weak 'no-cache' default that must be strengthened.
        // BR-022.9 "controller values win" applies to application-controller-set headers
        // like X-Frame-Options, not to GaleResponse's own framework defaults.
        if ($isSSE) {
            // SSE responses use 'no-cache' — no-store would break SSE reconnection
            $sseCache = $this->resolveConfigValue($config, 'cache_control', 'no-cache');
            if ($sseCache !== false) {
                $this->setHeaderSafe($response, 'Cache-Control', 'no-cache');
            }
        } else {
            // State-bearing HTTP responses must not be cached at all (BR-022.3 MUST rule)
            $cacheControl = $this->resolveConfigValue(
                $config,
                'cache_control',
                'no-store, no-cache, must-revalidate'
            );
            if ($cacheControl !== false) {
                $this->setHeaderSafe($response, 'Cache-Control', $cacheControl);
            }
        }

        // --- Pragma: no-cache (BR-022.10 — HTTP/1.0 proxy compatibility) ---
        if (! $response->headers->has('Pragma')) {
            $this->setHeaderSafe($response, 'Pragma', 'no-cache');
        }

        // --- X-Accel-Buffering: no — SSE only (BR-022.4) ---
        if ($isSSE && ! $response->headers->has('X-Accel-Buffering')) {
            $this->setHeaderSafe($response, 'X-Accel-Buffering', 'no');
        }

        // --- Custom headers (BR-022.7) ---
        $custom = isset($config['custom']) && is_array($config['custom']) ? $config['custom'] : [];
        foreach ($custom as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }
            if (! $response->headers->has($name)) {
                $this->setHeaderSafe($response, $name, $value);
            }
        }
    }

    /**
     * Resolve a config header value with a default fallback
     *
     * Returns false if the config explicitly disables the header (false value or empty string).
     * Returns the config value when set, or the given $default when not configured.
     *
     * @param  array<string, mixed>  $config  The resolved gale.headers config array
     * @param  string  $key  The config key within gale.headers
     * @param  string  $default  The default header value when not configured
     * @return string|false The header value to use, or false to skip the header
     */
    protected function resolveConfigValue(array $config, string $key, string $default): string|false
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];

        // Explicitly disabled (BR-022.6): false or empty string disables the header
        if ($value === false || $value === '' || $value === null) {
            return false;
        }

        if (! is_string($value)) {
            return $default;
        }

        return $value;
    }

    /**
     * Set a header on the response, skipping invalid values with a logged warning
     *
     * HTTP header values must not contain control characters (CR, LF, NUL).
     * Invalid values are logged as warnings and skipped (edge case in spec).
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response  Target response
     * @param  string  $name  Header name
     * @param  string  $value  Header value to set
     */
    protected function setHeaderSafe(Response $response, string $name, string $value): void
    {
        // Detect control characters (CR, LF, NUL, VT, FF) that are forbidden in header values
        if (preg_match('/[\x00-\x08\x0A-\x0D\x7F]/', $value)) {
            Log::warning("Gale security header skipped: {$name} contains invalid characters.", [
                'header' => $name,
                'value_length' => strlen($value),
            ]);

            return;
        }

        $response->headers->set($name, $value);
    }
}
