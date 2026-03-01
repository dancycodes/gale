<?php

namespace Dancycodes\Gale\Http\Middleware;

use Closure;
use Dancycodes\Gale\Security\StateChecksum;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify HMAC-SHA256 Checksum on Incoming Gale Requests (F-013)
 *
 * For every Gale request that carries an Alpine state payload, this middleware
 * verifies that the submitted `_checksum` field matches a freshly recomputed
 * HMAC-SHA256 over the state. Requests without state (empty body) are allowed
 * through without a checksum requirement (BR-013.6).
 *
 * When verification fails the middleware returns HTTP 403 Forbidden so that the
 * frontend can dispatch the `gale:security-error` event (BR-013.5, BR-013.7).
 *
 * Routes may opt out of checksum verification by:
 *   - Applying the WithoutGaleChecksum middleware alias
 *   - Applying the #[WithoutGaleChecksum] PHP attribute (resolved separately)
 *
 * @see \Dancycodes\Gale\Security\StateChecksum
 * @see \Dancycodes\Gale\Http\Middleware\WithoutGaleChecksum
 */
class VerifyGaleChecksum
{
    /**
     * Handle an incoming request and verify the Gale state checksum.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only run for Gale requests (BR-013.5)
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (! $request->isGale()) {
            return $next($request);
        }

        // Respect WithoutGaleChecksum middleware bypass flag (BR-013.9)
        // Check both the request attribute (set when WithoutGaleChecksum runs first)
        // and the route middleware list (when this middleware runs before route middleware)
        if ($request->attributes->get(WithoutGaleChecksum::BYPASS_ATTRIBUTE, false)) {
            return $next($request);
        }

        if ($this->routeHasWithoutChecksumMiddleware($request)) {
            return $next($request);
        }

        // Retrieve the JSON body as an array
        $body = $request->json()->all();

        // Requests without state payload do not require a checksum (BR-013.6)
        if (empty($body)) {
            return $next($request);
        }

        // Extract the submitted checksum
        $submittedChecksum = $body[StateChecksum::KEY] ?? null;

        // State present but no checksum → reject (BR-013.5)
        if (! is_string($submittedChecksum) || $submittedChecksum === '') {
            return $this->rejectRequest('checksum_missing');
        }

        // Verify using timing-safe comparison (BR-013.8)
        if (! StateChecksum::verify($body, $submittedChecksum)) {
            return $this->rejectRequest('checksum_mismatch');
        }

        return $next($request);
    }

    /**
     * Check if the current route has the WithoutGaleChecksum middleware assigned.
     *
     * This handles the case where VerifyGaleChecksum runs as a global web-group
     * middleware (before route-specific middleware). We inspect the matched route's
     * middleware list to detect the bypass intent without requiring WithoutGaleChecksum
     * to run first (BR-013.9).
     */
    protected function routeHasWithoutChecksumMiddleware(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $routeMiddleware = $route->gatherMiddleware();

        foreach ($routeMiddleware as $middleware) {
            if ($middleware === WithoutGaleChecksum::class) {
                return true;
            }

            if ($middleware === 'gale.without-checksum') {
                return true;
            }

            // Handle middleware with parameters (e.g. "gale.without-checksum:param")
            if (is_string($middleware) && str_starts_with($middleware, 'gale.without-checksum:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a 403 Forbidden JSON response that the frontend can read to dispatch
     * the `gale:security-error` event.
     *
     * The response carries a structured error body so the Alpine-Gale error handler
     * knows it is a security event and not a generic server error (BR-013.7).
     *
     * @param  string  $reason  Machine-readable rejection reason
     */
    protected function rejectRequest(string $reason): Response
    {
        return response()->json([
            'error' => 'checksum_invalid',
            'reason' => $reason,
        ], 403, [
            'X-Gale-Security-Error' => $reason,
        ]);
    }
}
