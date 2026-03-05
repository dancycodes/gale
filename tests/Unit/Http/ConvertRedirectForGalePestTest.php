<?php

/**
 * F-112 — PHP Unit: Redirect, Fragment, Middleware
 * Section: ConvertRedirectForGale middleware
 *
 * Tests the middleware that intercepts standard Laravel RedirectResponse instances
 * during Gale requests and converts them to Gale-compatible redirect events.
 * Non-Gale requests must pass through unchanged (standard 302).
 *
 * @see packages/dancycodes/gale/src/Http/Middleware/ConvertRedirectForGale.php
 */

use Dancycodes\Gale\Http\Middleware\ConvertRedirectForGale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a fake Gale HTTP request (has Gale-Request header).
 */
function makeGaleHttpRequestForMiddleware(string $uri = '/'): Request
{
    $request = Request::create($uri, 'GET');
    $request->headers->set('Gale-Request', 'true');

    return $request;
}

/**
 * Build a fake non-Gale request.
 */
function makeNonGaleRequest(string $uri = '/'): Request
{
    return Request::create($uri, 'GET');
}

/**
 * Run the ConvertRedirectForGale middleware with the given request and next closure.
 *
 * @param \Closure(): \Symfony\Component\HttpFoundation\Response $next
 */
function runMiddleware(Request $request, \Closure $next): \Symfony\Component\HttpFoundation\Response
{
    return (new ConvertRedirectForGale)->handle($request, $next);
}

// Remove app()->instance('request', ...) bindings after each test so they do
// not contaminate subsequent Pest test files sharing the same app instance.
afterEach(function () {
    app()->forgetInstance('request');
    app()->forgetInstance('url');
});

// ---------------------------------------------------------------------------
// SECTION 1: Gale request with RedirectResponse — must convert
// ---------------------------------------------------------------------------

describe('ConvertRedirectForGale — Gale request with RedirectResponse', function () {
    it('converts a 302 RedirectResponse to a Gale JSON response for Gale requests', function () {
        $request = makeGaleHttpRequestForMiddleware();
        // Bind the test request into the container so gale()->redirect() helper works
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $response = runMiddleware($request, fn () => new RedirectResponse('/dashboard'));

        // Must NOT be a standard 302 redirect
        expect($response)->not->toBeInstanceOf(RedirectResponse::class);
        // Must be a Gale response (JsonResponse or StreamedResponse with Gale header)
        expect($response->headers->get('X-Gale-Response'))->toBe('true');
    });

    it('includes the target URL in the converted Gale response', function () {
        $request = makeGaleHttpRequestForMiddleware();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $response = runMiddleware($request, fn () => new RedirectResponse('/target-page'));
        $content = '';

        if ($response instanceof JsonResponse) {
            $content = $response->getContent() ?: '';
        } elseif ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = ob_get_clean() ?: '';
        }

        expect($content)->toContain('target-page');
    });

    it('contains gale-redirect event with the target URL in the converted response', function () {
        $request = makeGaleHttpRequestForMiddleware();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $response = runMiddleware($request, fn () => new RedirectResponse('/go-here'));
        $content = '';

        if ($response instanceof JsonResponse) {
            $content = $response->getContent() ?: '';
        } elseif ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = ob_get_clean() ?: '';
        }

        // F-012: emitRedirect() emits gale-redirect event, not window.location JS
        expect($content)->toContain('gale-redirect');
        expect($content)->toContain('go-here');
    });
});

// ---------------------------------------------------------------------------
// SECTION 2: Non-Gale request — must pass through unchanged
// ---------------------------------------------------------------------------

describe('ConvertRedirectForGale — non-Gale request pass-through', function () {
    it('passes through a RedirectResponse unchanged for non-Gale requests', function () {
        $request = makeNonGaleRequest();
        app()->instance('request', $request);

        $response = runMiddleware($request, fn () => new RedirectResponse('/somewhere'));

        expect($response)->toBeInstanceOf(RedirectResponse::class);
        expect($response->getStatusCode())->toBe(302);
        expect($response->getTargetUrl())->toContain('/somewhere');
    });

    it('preserves the redirect target URL for non-Gale requests', function () {
        $request = makeNonGaleRequest();
        app()->instance('request', $request);

        $response = runMiddleware($request, fn () => new RedirectResponse('/original-url'));

        expect($response->getTargetUrl())->toContain('/original-url');
    });
});

// ---------------------------------------------------------------------------
// SECTION 3: Non-redirect responses — always pass through
// ---------------------------------------------------------------------------

describe('ConvertRedirectForGale — non-redirect responses pass through', function () {
    it('passes through a JsonResponse unchanged (Gale request)', function () {
        $request = makeGaleHttpRequestForMiddleware();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $jsonResponse = new JsonResponse(['ok' => true]);
        $response = runMiddleware($request, fn () => $jsonResponse);

        expect($response)->toBe($jsonResponse);
    });

    it('passes through a 200 OK response unchanged (non-Gale request)', function () {
        $request = makeNonGaleRequest();
        app()->instance('request', $request);

        $okResponse = new \Illuminate\Http\Response('Hello', 200);
        $response = runMiddleware($request, fn () => $okResponse);

        expect($response)->toBe($okResponse);
        expect($response->getStatusCode())->toBe(200);
    });

    it('passes through a 200 OK response unchanged (Gale request)', function () {
        $request = makeGaleHttpRequestForMiddleware();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $okResponse = new \Illuminate\Http\Response('content', 200);
        $response = runMiddleware($request, fn () => $okResponse);

        expect($response)->toBe($okResponse);
    });
});

// ---------------------------------------------------------------------------
// SECTION 4: Flash data preserved (committed to session by RedirectResponse)
// ---------------------------------------------------------------------------

describe('ConvertRedirectForGale — flash data preservation', function () {
    it('flash data committed to session by RedirectResponse is available after conversion', function () {
        $request = makeGaleHttpRequestForMiddleware();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        // Pre-flash data to session (simulating what redirect()->with() does)
        session()->flash('status', 'Profile updated!');

        $response = runMiddleware($request, fn () => new RedirectResponse('/dashboard'));

        // Session flash data should survive — ConvertRedirectForGale does not clear it
        expect(session()->has('status'))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// SECTION 5: Middleware pipeline — verifies both branches in one flow
// ---------------------------------------------------------------------------

describe('ConvertRedirectForGale — both request branches', function () {
    it('Gale request with redirect produces X-Gale-Response, non-Gale produces 302', function () {
        $galeRequest = makeGaleHttpRequestForMiddleware('/test');
        app()->instance('request', $galeRequest);
        request()->headers->set('Gale-Request', 'true');

        $galeResponse = runMiddleware($galeRequest, fn () => new RedirectResponse('/after-redirect'));
        expect($galeResponse->headers->get('X-Gale-Response'))->toBe('true');

        $regularRequest = makeNonGaleRequest('/test');
        app()->instance('request', $regularRequest);

        $regularResponse = runMiddleware($regularRequest, fn () => new RedirectResponse('/after-redirect'));
        expect($regularResponse->getStatusCode())->toBe(302);
    });

    it('chained middleware does not alter a non-redirect Gale response', function () {
        $galeRequest = makeGaleHttpRequestForMiddleware('/test');
        app()->instance('request', $galeRequest);
        request()->headers->set('Gale-Request', 'true');

        $original = new JsonResponse(['events' => []], 200, ['X-Gale-Response' => 'true']);
        $result = runMiddleware($galeRequest, fn () => $original);

        expect($result)->toBe($original);
    });
});
