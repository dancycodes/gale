<?php

namespace Dancycodes\Gale\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-Convert RedirectResponse for Gale Requests (F-011)
 *
 * Intercepts standard Laravel RedirectResponse objects when the current request
 * is a Gale request and converts them into Gale-compatible redirect responses.
 * This allows developers to use standard redirect() calls in controllers that
 * handle both Gale and non-Gale requests.
 *
 * The middleware runs after the controller, inspects the response, and if it is
 * a RedirectResponse during a Gale request, replaces it with a gale()->redirect()
 * response that the frontend can process (JSON event or SSE event depending on mode).
 *
 * Flash data (with()), validation errors (withErrors()), and old input (withInput())
 * are already committed to the session by RedirectResponse before this middleware
 * sees the response, so they are automatically preserved.
 *
 * Non-Gale requests pass through completely unchanged (standard 302 redirect).
 *
 * @see \Dancycodes\Gale\Http\GaleRedirect
 * @see \Dancycodes\Gale\Http\GaleResponse
 */
class ConvertRedirectForGale
{
    /**
     * Handle an incoming request and convert redirect responses for Gale.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only intercept RedirectResponse instances (BR-011.6)
        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        // Only convert for Gale requests (BR-011.6)
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (! $request->isGale()) {
            return $response;
        }

        // Extract the target URL from the redirect (BR-011.2)
        $targetUrl = $response->getTargetUrl();

        // Flash data (with()), errors (withErrors()), and old input (withInput())
        // have already been committed to the session by RedirectResponse's methods.
        // They are automatically preserved -- no manual extraction needed (BR-011.3, BR-011.4, BR-011.5).

        // Build the Gale redirect response (BR-011.1, BR-011.7, BR-011.8, BR-011.9)
        // gale()->redirect() creates a GaleRedirect which generates either:
        //   - JSON with gale-redirect event (HTTP mode, BR-011.8)
        //   - SSE with JS navigation event (SSE mode, BR-011.9)
        return gale()->redirect($targetUrl)->toResponse($request);
    }
}
