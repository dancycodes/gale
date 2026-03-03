<?php

/**
 * F-112 — PHP Unit: Redirect, Fragment, Middleware
 * Section: GaleErrorHandler
 *
 * Tests the error handler that converts exceptions during Gale requests into
 * structured Gale error responses, including:
 * - ValidationException → Gale messages event
 * - 401 Unauthorized → redirect to login
 * - 419 CSRF → gale:csrf-expired dispatch
 * - Generic exceptions → _error state + gale:error dispatch
 * - Non-Gale requests → null (pass-through)
 *
 * @see packages/dancycodes/gale/src/Http/GaleErrorHandler.php
 */

use Dancycodes\Gale\Http\GaleErrorHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a Gale-flagged request.
 */
function makeGaleRequest(string $uri = '/'): Request
{
    $request = Request::create($uri, 'GET');
    $request->headers->set('Gale-Request', 'true');

    return $request;
}

/**
 * Create a non-Gale request.
 */
function makeRegularRequest(string $uri = '/'): Request
{
    return Request::create($uri, 'GET');
}

/**
 * Build a ValidationException with the given messages.
 *
 * @param array<string, string[]> $messages
 */
function makeValidationException(array $messages = ['email' => ['The email field is required.']]): ValidationException
{
    $validator = \Mockery::mock(Validator::class);
    $validator->shouldReceive('errors')->andReturn(new \Illuminate\Support\MessageBag($messages));

    return new ValidationException($validator);
}

/**
 * Get the JSON content from a response, decoded as an array.
 *
 * @return array<string, mixed>|null
 */
function decodeJson(\Symfony\Component\HttpFoundation\Response $response): ?array
{
    $content = $response->getContent();
    if (!$content) {
        return null;
    }

    return json_decode($content, true);
}

/**
 * Get SSE or JSON response content as string.
 */
function getResponseContent(\Symfony\Component\HttpFoundation\Response $response): string
{
    if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
        ob_start();
        $response->sendContent();

        return ob_get_clean() ?: '';
    }

    return $response->getContent() ?: '';
}

// Remove any app()->instance('request', ...) bindings set by tests in this file
// so they don't contaminate subsequent test files (e.g. GaleRedirectPestTest).
afterEach(function () {
    app()->forgetInstance('request');
    app()->forgetInstance('url');
});

// ---------------------------------------------------------------------------
// SECTION 1: Non-Gale requests — must return null (pass-through)
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::handle() — non-Gale requests', function () {
    it('returns null for non-Gale requests (any exception)', function () {
        $request = makeRegularRequest();
        $result = GaleErrorHandler::handle(new \RuntimeException('Oops'), $request);

        expect($result)->toBeNull();
    });

    it('returns null for non-Gale requests with HttpException', function () {
        $request = makeRegularRequest();
        $result = GaleErrorHandler::handle(new HttpException(404, 'Not found'), $request);

        expect($result)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// SECTION 2: ValidationException — returns null (handled by renderable)
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::handle() — ValidationException pass-through', function () {
    it('returns null for ValidationException even on Gale requests', function () {
        $request = makeGaleRequest();
        $result = GaleErrorHandler::handle(makeValidationException(), $request);

        // ValidationException is delegated to the renderable handler (BR-014.5)
        expect($result)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// SECTION 3: 401 Unauthorized — redirect to login
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::handle() — 401 redirects to login', function () {
    it('returns a response (not null) for AuthenticationException on Gale requests', function () {
        app()->instance('request', makeGaleRequest());
        request()->headers->set('Gale-Request', 'true');

        $request = makeGaleRequest();
        $result = GaleErrorHandler::handle(new AuthenticationException, $request);

        expect($result)->not->toBeNull();
    });

    it('contains window.location in the response for 401 (redirect to login)', function () {
        app()->instance('request', makeGaleRequest());
        request()->headers->set('Gale-Request', 'true');

        config(['gale.login_url' => '/login']);

        $request = makeGaleRequest();
        $result = GaleErrorHandler::handle(new AuthenticationException, $request);

        expect($result)->not->toBeNull();
        $content = getResponseContent($result);
        expect($content)->toContain('window.location');
    });

    it('uses the login_url from config for 401 redirect', function () {
        app()->instance('request', makeGaleRequest());
        request()->headers->set('Gale-Request', 'true');

        config(['gale.login_url' => '/custom-login']);

        $request = makeGaleRequest();
        $result = GaleErrorHandler::handle(new AuthenticationException, $request);

        $content = getResponseContent($result);
        expect($content)->toContain('custom-login');
    });

    it('resolves status code 401 for AuthenticationException', function () {
        $status = GaleErrorHandler::resolveStatusCode(new AuthenticationException);

        expect($status)->toBe(401);
    });
});

// ---------------------------------------------------------------------------
// SECTION 4: 419 CSRF Token Mismatch — gale:csrf-expired dispatch
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::handle() — 419 CSRF token mismatch', function () {
    it('returns a response for a 419 HttpException on Gale requests', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new HttpException(419, 'Page Expired'), $request);

        expect($result)->not->toBeNull();
    });

    it('returns a 419 status code for the CSRF exception response', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new HttpException(419, 'Page Expired'), $request);

        expect($result->getStatusCode())->toBe(419);
    });

    it('dispatches gale:csrf-expired event in the 419 response', function () {
        $request = makeGaleRequest();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $result = GaleErrorHandler::handle(new HttpException(419, 'Page Expired'), $request);

        $content = getResponseContent($result);
        expect($content)->toContain('csrf-expired');
    });
});

// ---------------------------------------------------------------------------
// SECTION 5: Generic exceptions — _error state + gale:error dispatch
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::handle() — generic exceptions', function () {
    it('returns a response (not null) for RuntimeException on Gale requests', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new \RuntimeException('Something broke'), $request);

        expect($result)->not->toBeNull();
    });

    it('returns 500 status code for unhandled RuntimeException', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new \RuntimeException('Oops'), $request);

        expect($result->getStatusCode())->toBe(500);
    });

    it('contains _error in the response content', function () {
        $request = makeGaleRequest();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $result = GaleErrorHandler::handle(new \RuntimeException('Broken'), $request);

        $content = getResponseContent($result);
        expect($content)->toContain('_error');
    });

    it('contains gale:error dispatch in the response', function () {
        $request = makeGaleRequest();
        app()->instance('request', $request);
        request()->headers->set('Gale-Request', 'true');

        $result = GaleErrorHandler::handle(new \RuntimeException('Error'), $request);

        $content = getResponseContent($result);
        expect($content)->toContain('gale:error');
    });

    it('returns 404 for HttpException(404)', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new HttpException(404, 'Not Found'), $request);

        expect($result->getStatusCode())->toBe(404);
    });

    it('returns 403 for HttpException(403)', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new HttpException(403, 'Forbidden'), $request);

        expect($result->getStatusCode())->toBe(403);
    });

    it('returns 503 for HttpException(503)', function () {
        $request = makeGaleRequest();

        $result = GaleErrorHandler::handle(new HttpException(503, 'Service Unavailable'), $request);

        expect($result->getStatusCode())->toBe(503);
    });

    it('returns 403 for AuthorizationException (F-060 BR-F060-05)', function () {
        $request = makeGaleRequest();

        $status = GaleErrorHandler::resolveStatusCode(new AuthorizationException('This action is unauthorized.'));

        expect($status)->toBe(403);
    });
});

// ---------------------------------------------------------------------------
// SECTION 6: resolveStatusCode() static method
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::resolveStatusCode()', function () {
    it('returns the status code from an HttpException', function () {
        $status = GaleErrorHandler::resolveStatusCode(new HttpException(404));

        expect($status)->toBe(404);
    });

    it('returns 401 for AuthenticationException', function () {
        $status = GaleErrorHandler::resolveStatusCode(new AuthenticationException);

        expect($status)->toBe(401);
    });

    it('returns 403 for AuthorizationException', function () {
        $status = GaleErrorHandler::resolveStatusCode(new AuthorizationException('Unauthorized.'));

        expect($status)->toBe(403);
    });

    it('returns 500 for generic RuntimeException', function () {
        $status = GaleErrorHandler::resolveStatusCode(new \RuntimeException('Generic'));

        expect($status)->toBe(500);
    });

    it('returns 500 for generic LogicException', function () {
        $status = GaleErrorHandler::resolveStatusCode(new \LogicException('Logic error'));

        expect($status)->toBe(500);
    });
});

// ---------------------------------------------------------------------------
// SECTION 7: resolveMessage() static method
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::resolveMessage()', function () {
    it('returns the HttpException message when non-empty', function () {
        $e = new HttpException(404, 'Custom Not Found Message');
        $message = GaleErrorHandler::resolveMessage($e, 404);

        expect($message)->toBe('Custom Not Found Message');
    });

    it('returns fallback generic message for 404 when HttpException message is empty', function () {
        $e = new HttpException(404);
        $message = GaleErrorHandler::resolveMessage($e, 404);

        expect($message)->toBe('Not Found');
    });

    it('returns AuthorizationException message when non-empty', function () {
        $e = new AuthorizationException('This action is unauthorized.');
        $message = GaleErrorHandler::resolveMessage($e, 403);

        expect($message)->toBe('This action is unauthorized.');
    });

    it('returns generic 500 message for RuntimeException in non-debug mode', function () {
        config(['app.debug' => false]);

        $e = new \RuntimeException('Internal secret details');
        $message = GaleErrorHandler::resolveMessage($e, 500);

        expect($message)->toBe('Server Error');
        // Must NOT expose internal exception message in production
        expect($message)->not->toContain('Internal secret details');
    });

    it('returns the exception message in debug mode', function () {
        config(['app.debug' => true]);

        $e = new \RuntimeException('Detailed debug message');
        $message = GaleErrorHandler::resolveMessage($e, 500);

        expect($message)->toBe('Detailed debug message');
    });
});

// ---------------------------------------------------------------------------
// SECTION 8: buildErrorDetail() static method
// ---------------------------------------------------------------------------

describe('GaleErrorHandler::buildErrorDetail()', function () {
    it('returns an array with error=true, status, and message keys', function () {
        $e = new \RuntimeException('Test');
        $detail = GaleErrorHandler::buildErrorDetail($e, 500, 'Server Error');

        expect($detail)->toBeArray();
        expect($detail['error'])->toBeTrue();
        expect($detail['status'])->toBe(500);
        expect($detail['message'])->toBe('Server Error');
    });

    it('does not include exception/file/line/trace in production mode', function () {
        config(['app.debug' => false]);

        $e = new \RuntimeException('Secret');
        $detail = GaleErrorHandler::buildErrorDetail($e, 500, 'Server Error');

        expect($detail)->not->toHaveKey('exception');
        expect($detail)->not->toHaveKey('file');
        expect($detail)->not->toHaveKey('line');
        expect($detail)->not->toHaveKey('trace');
    });

    it('includes exception/file/line/trace in debug mode (BR-F058-04)', function () {
        config(['app.debug' => true]);

        $e = new \RuntimeException('Debug exception');
        $detail = GaleErrorHandler::buildErrorDetail($e, 500, 'Server Error');

        expect($detail)->toHaveKey('exception');
        expect($detail)->toHaveKey('file');
        expect($detail)->toHaveKey('line');
        expect($detail)->toHaveKey('trace');
    });

    it('trace in debug mode is an array of at most 10 frames', function () {
        config(['app.debug' => true]);

        $e = new \RuntimeException('Deep trace');
        $detail = GaleErrorHandler::buildErrorDetail($e, 500, 'Server Error');

        expect($detail['trace'])->toBeArray();
        expect(count($detail['trace']))->toBeLessThanOrEqual(10);
    });
});
