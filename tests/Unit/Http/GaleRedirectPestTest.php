<?php

/**
 * F-112 — PHP Unit: Redirect, Fragment, Middleware
 * Section: GaleRedirect
 *
 * Comprehensive Pest unit tests for GaleRedirect covering every redirect type,
 * flash data handling, security validation, and both HTTP/SSE output modes.
 *
 * @see packages/dancycodes/gale/src/Http/GaleRedirect.php
 */

use Dancycodes\Gale\Http\GaleRedirect;
use Dancycodes\Gale\Http\GaleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Make a fresh GaleRedirect with a fresh GaleResponse.
 */
function makeRedirect(?string $url = '/dashboard'): GaleRedirect
{
    return new GaleRedirect($url, new GaleResponse);
}

/**
 * Make a Gale HTTP request (adds Gale-Request header).
 */
function setupGaleRedirectRequest(): void
{
    request()->headers->set('Gale-Request', 'true');
    request()->headers->remove('Gale-Mode');
}

/**
 * Decode the JSON events array from an HTTP-mode GaleRedirect response.
 *
 * @return array<int, array{type: string, data: mixed}>
 */
function redirectHttpEvents(JsonResponse $response): array
{
    $body = json_decode($response->getContent(), true);

    return $body['events'] ?? [];
}

/**
 * Check if any event data (stringified) contains the given substring.
 */
function anyEventContains(\Symfony\Component\HttpFoundation\Response $response, string $needle): bool
{
    $content = '';

    if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean() ?: '';
    } elseif ($response instanceof JsonResponse) {
        $content = $response->getContent() ?: '';
    } else {
        $content = $response->getContent() ?: '';
    }

    return str_contains($content, $needle);
}

// ---------------------------------------------------------------------------
// SECTION 1: Basic Construction & to() / away()
// ---------------------------------------------------------------------------

describe('GaleRedirect construction', function () {
    beforeEach(fn () => setupGaleRedirectRequest());

    it('creates a GaleRedirect instance', function () {
        expect(makeRedirect())->toBeInstanceOf(GaleRedirect::class);
    });

    it('returns self from to() for chaining', function () {
        $redirect = makeRedirect(null);
        $result = $redirect->to('/profile');

        expect($result)->toBeInstanceOf(GaleRedirect::class);
    });

    it('returns self from away() for chaining', function () {
        config(['gale.redirect.allow_external' => true]);

        $redirect = makeRedirect(null);
        $result = $redirect->away('https://external.example.com');

        expect($result)->toBeInstanceOf(GaleRedirect::class);
    });

    it('throws LogicException when URL is not set before toResponse()', function () {
        $redirect = makeRedirect(null);

        expect(fn () => $redirect->toResponse(request()))->toThrow(\LogicException::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 2: HTTP Mode — JSON output with window.location script
// ---------------------------------------------------------------------------

describe('GaleRedirect HTTP mode output', function () {
    beforeEach(fn () => setupGaleRedirectRequest());

    it('returns a JsonResponse for Gale HTTP requests', function () {
        $response = makeRedirect('/dashboard')->toResponse(request());

        expect($response)->toBeInstanceOf(JsonResponse::class);
    });

    it('includes the gale-redirect event with the target URL', function () {
        $response = makeRedirect('/dashboard')->toResponse(request());

        // F-012: GaleRedirect uses emitRedirect() which emits a gale-redirect event (not window.location JS)
        expect(anyEventContains($response, 'gale-redirect'))->toBeTrue();
        expect(anyEventContains($response, '/dashboard'))->toBeTrue();
    });

    it('includes the target URL in the response', function () {
        $response = makeRedirect('/users/profile')->toResponse(request());

        // The URL may appear JSON-escaped (\/users\/profile) or unescaped (/users/profile)
        $content = $response->getContent() ?: '';
        $hasUrl = str_contains($content, '/users/profile') || str_contains($content, '\/users\/profile');
        expect($hasUrl)->toBeTrue();
    });

    it('sets the X-Gale-Response header', function () {
        $response = makeRedirect('/dashboard')->toResponse(request());

        expect($response->headers->get('X-Gale-Response'))->toBe('true');
    });

    it('URL-encodes special characters safely (JSON encoding)', function () {
        $response = makeRedirect('/search?q=test&cat=news')->toResponse(request());

        // Should not contain unescaped < or > which would be XSS vectors
        expect($response->getContent())->not->toContain('<script>');
        // F-012: emitRedirect emits gale-redirect event with the URL
        expect(anyEventContains($response, 'gale-redirect'))->toBeTrue();
        expect(anyEventContains($response, '/search'))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// SECTION 3: Redirect types — to, back, home, route, away, intended
// ---------------------------------------------------------------------------

describe('GaleRedirect redirect types', function () {
    beforeEach(fn () => setupGaleRedirectRequest());

    it('to() sets the destination URL', function () {
        $response = makeRedirect(null)->to('/settings')->toResponse(request());

        expect(anyEventContains($response, '/settings'))->toBeTrue();
    });

    it('away() sets an external destination URL', function () {
        config(['gale.redirect.allow_external' => true]);

        $response = makeRedirect(null)->away('https://external.example.com/page')->toResponse(request());

        expect(anyEventContains($response, 'external.example.com'))->toBeTrue();
    });

    it('home() redirects to root URL', function () {
        $response = makeRedirect(null)->home()->toResponse(request());

        // home() calls url('/') — the gale-redirect event contains the URL
        expect(anyEventContains($response, 'gale-redirect'))->toBeTrue();
    });

    it('back() falls back to provided fallback when no previous URL', function () {
        session()->forget('_previous.url');

        $response = makeRedirect(null)->back('/fallback-url')->toResponse(request());

        expect(anyEventContains($response, 'fallback-url'))->toBeTrue();
    });

    it('back() uses previous URL when same-domain', function () {
        $previousUrl = 'http://localhost/previous-page';
        session()->put('_previous.url', $previousUrl);

        $response = makeRedirect(null)->back('/fallback')->toResponse(request());

        expect(anyEventContains($response, 'previous-page'))->toBeTrue();
    });

    it('route() generates URL from named route', function () {
        Route::get('/test-named-route', fn () => 'ok')->name('test.named.route');

        $response = makeRedirect(null)->route('test.named.route')->toResponse(request());

        expect(anyEventContains($response, 'test-named-route'))->toBeTrue();
    });

    it('route() throws InvalidArgumentException for missing route', function () {
        $redirect = makeRedirect(null);

        expect(fn () => $redirect->route('no.such.route'))->toThrow(\InvalidArgumentException::class);
    });

    it('intended() redirects to URL stored in session', function () {
        session()->put('url.intended', 'http://localhost/protected-page');

        $response = makeRedirect(null)->intended('/default')->toResponse(request());

        expect(anyEventContains($response, 'protected-page'))->toBeTrue();
    });

    it('intended() uses default when no intended URL in session', function () {
        session()->forget('url.intended');

        $response = makeRedirect(null)->intended('/default-page')->toResponse(request());

        expect(anyEventContains($response, 'default-page'))->toBeTrue();
    });

    it('intended() removes the URL from session after pulling', function () {
        session()->put('url.intended', 'http://localhost/protected-page');

        makeRedirect(null)->intended('/default')->toResponse(request());

        expect(session()->has('url.intended'))->toBeFalse();
    });

    it('refresh() uses current request URL', function () {
        $response = makeRedirect(null)->refresh()->toResponse(request());

        // refresh() sets the URL to the current request URL, emits a gale-redirect event
        expect(anyEventContains($response, 'gale-redirect'))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// SECTION 4: Flash data via with(), withInput(), withErrors()
// ---------------------------------------------------------------------------

describe('GaleRedirect flash data', function () {
    beforeEach(fn () => setupGaleRedirectRequest());

    it('with() flashes a single key-value pair to session', function () {
        makeRedirect('/dashboard')->with('success', 'Saved!')->toResponse(request());

        expect(session()->get('success'))->toBe('Saved!');
    });

    it('with() accepts an associative array and flashes all keys', function () {
        makeRedirect('/dashboard')->with(['status' => 'ok', 'count' => 5])->toResponse(request());

        expect(session()->get('status'))->toBe('ok');
        expect(session()->get('count'))->toBe(5);
    });

    it('multiple with() calls accumulate flash data', function () {
        makeRedirect('/dashboard')
            ->with('first', 'one')
            ->with('second', 'two')
            ->with('third', 'three')
            ->toResponse(request());

        expect(session()->get('_flash.new'))->toContain('first');
        expect(session()->get('_flash.new'))->toContain('second');
        expect(session()->get('_flash.new'))->toContain('third');
    });

    it('with() returns self for chaining', function () {
        $redirect = makeRedirect('/dashboard');

        expect($redirect->with('key', 'val'))->toBeInstanceOf(GaleRedirect::class);
    });

    it('withInput() flashes current request input under _old_input', function () {
        request()->merge(['name' => 'Jane', 'email' => 'jane@example.com']);

        makeRedirect('/form')->withInput()->toResponse(request());

        $oldInput = session()->get('_old_input');
        expect($oldInput)->toBeArray();
        expect($oldInput['name'])->toBe('Jane');
        expect($oldInput['email'])->toBe('jane@example.com');
    });

    it('withErrors() flashes error data under errors key', function () {
        $errors = ['email' => ['The email field is required.']];

        makeRedirect('/login')->withErrors($errors)->toResponse(request());

        expect(session()->get('errors'))->toBe($errors);
    });

    it('no flash data does not crash toResponse()', function () {
        $response = makeRedirect('/dashboard')->toResponse(request());

        expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\Response::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 5: forceReload()
// ---------------------------------------------------------------------------

describe('GaleRedirect forceReload()', function () {
    beforeEach(fn () => setupGaleRedirectRequest());

    it('forceReload() returns a response with window.location.reload', function () {
        $response = makeRedirect(null)->forceReload();

        expect(anyEventContains($response, 'window.location.reload'))->toBeTrue();
    });

    it('forceReload(true) passes true to window.location.reload', function () {
        $response = makeRedirect(null)->forceReload(true);

        expect(anyEventContains($response, 'reload(true)'))->toBeTrue();
    });

    it('forceReload(false) passes false to window.location.reload', function () {
        $response = makeRedirect(null)->forceReload(false);

        expect(anyEventContains($response, 'reload(false)'))->toBeTrue();
    });

    it('forceReload() preserves flash data', function () {
        makeRedirect(null)->with('msg', 'hello')->forceReload();

        expect(session()->get('msg'))->toBe('hello');
    });
});

// ---------------------------------------------------------------------------
// SECTION 6: Non-Gale requests — pass-through as standard 302
// ---------------------------------------------------------------------------

describe('GaleRedirect non-Gale request pass-through', function () {
    it('returns a standard RedirectResponse for non-Gale requests', function () {
        // No Gale-Request header
        request()->headers->remove('Gale-Request');

        $response = makeRedirect('/dashboard')->toResponse(request());

        expect($response)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
        expect($response->getStatusCode())->toBe(302);
    });

    it('flashes session data even for non-Gale requests', function () {
        request()->headers->remove('Gale-Request');

        makeRedirect('/dashboard')->with('notice', 'done')->toResponse(request());

        expect(session()->get('notice'))->toBe('done');
    });
});

// ---------------------------------------------------------------------------
// SECTION 7: Security — dangerous protocol blocking (BR-020.5)
// ---------------------------------------------------------------------------

describe('GaleRedirect security validation', function () {
    beforeEach(fn () => setupGaleRedirectRequest());

    it('blocks javascript: protocol redirects', function () {
        expect(fn () => makeRedirect('javascript:alert(1)')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('blocks data: protocol redirects', function () {
        expect(fn () => makeRedirect('data:text/html,<h1>XSS</h1>')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('blocks vbscript: protocol redirects', function () {
        expect(fn () => makeRedirect('vbscript:MsgBox(1)')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('allows relative path redirects (starts with /)', function () {
        $response = makeRedirect('/safe/path')->toResponse(request());

        expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\Response::class);
    });

    it('blocks external domains not in allowed_domains when allow_external is false', function () {
        config([
            'gale.redirect.allow_external' => false,
            'gale.redirect.allowed_domains' => [],
        ]);

        expect(fn () => makeRedirect('https://evil.example.com/steal')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('allows external domain when listed in allowed_domains', function () {
        config([
            'gale.redirect.allow_external' => false,
            'gale.redirect.allowed_domains' => ['trusted.example.com'],
        ]);

        $response = makeRedirect('https://trusted.example.com/page')->toResponse(request());

        expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\Response::class);
    });
});
