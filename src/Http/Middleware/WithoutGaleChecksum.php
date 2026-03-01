<?php

namespace Dancycodes\Gale\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-Out Middleware — Disable Gale Checksum Verification for a Route (F-013)
 *
 * Apply this middleware to routes that should NOT enforce state checksum
 * verification. Common use cases include:
 *   - Public endpoints where no Alpine state is ever sent
 *   - Legacy integrations that send raw JSON without _checksum
 *   - Testing/debug endpoints where you need to bypass security
 *
 * Usage in route definition:
 *   Route::post('/public/endpoint', [Controller::class, 'action'])
 *       ->middleware('gale.without-checksum');
 *
 * Usage via attribute (F-013 BR-013.9):
 *   Applying the middleware alias is the canonical way to disable per-route.
 *
 * This middleware sets a request attribute that VerifyGaleChecksum reads to
 * skip its verification logic.
 *
 * @see \Dancycodes\Gale\Http\Middleware\VerifyGaleChecksum
 */
class WithoutGaleChecksum
{
    /**
     * Request attribute name used to signal checksum bypass
     */
    public const BYPASS_ATTRIBUTE = 'gale_checksum_bypassed';

    /**
     * Handle an incoming request by flagging it to bypass checksum verification.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::BYPASS_ATTRIBUTE, true);

        return $next($request);
    }
}
