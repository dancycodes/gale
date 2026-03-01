<?php

namespace Dancycodes\Gale\Http\Middleware;

use Closure;
use Dancycodes\Gale\Http\GaleResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gale Pipeline Middleware — runs registered before/after hooks (F-064)
 *
 * Before-hooks run BEFORE the controller executes, giving them access to the request.
 * After-hooks are registered on GaleResponse and run inside toResponse() after the
 * response is built but before it is sent to the browser.
 *
 * This middleware ONLY activates for Gale requests (Gale-Request header present).
 * Non-Gale requests pass through without running any hooks.
 *
 * @see \Dancycodes\Gale\Http\GaleResponse::beforeRequest()
 * @see \Dancycodes\Gale\Http\GaleResponse::afterResponse()
 */
class GalePipelineMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Runs registered before-hooks for Gale requests, then delegates to the next
     * middleware/controller. After-hooks are run inside GaleResponse::toResponse().
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only run pipeline hooks for Gale requests (BR-F064-05, BR-F064-07)
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if ($request->isGale()) {
            GaleResponse::runBeforeHooks($request);
        }

        return $next($request);
    }
}
