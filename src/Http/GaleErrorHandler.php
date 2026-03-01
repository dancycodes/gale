<?php

namespace Dancycodes\Gale\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Gale Error Response Handler (F-014, F-058)
 *
 * Converts unhandled exceptions during Gale requests into structured
 * Gale error responses, preventing raw HTML error pages from being
 * injected into the DOM. Returns responses with `_error` state and
 * appropriate event dispatches based on error type.
 *
 * Error categories:
 * - 401: Redirect to login via gale()->redirect()
 * - 419: Dispatch gale:csrf-expired event
 * - 422: Skipped (handled by F-010 ValidationException handler)
 * - All others: Set _error state + dispatch gale:error event
 *
 * F-058 additions:
 * - Debug mode (app.debug=true): includes exception class, file, line, and stack trace
 * - Production: only generic safe messages — no class names, paths, or traces exposed
 * - All standard HTTP error codes handled (401, 403, 404, 419, 422, 429, 500, 503)
 * - Error data uses { error: true, status, message } shape in HTTP mode (BR-F058-02)
 *
 * Non-Gale requests pass through unchanged (BR-BR-F058-07).
 */
class GaleErrorHandler
{
    /**
     * Handle an exception for a Gale request
     *
     * Returns a structured Gale response or null if the exception
     * should not be handled (non-Gale request or ValidationException).
     */
    public static function handle(\Throwable $e, Request $request): ?Response
    {
        // Skip ValidationException — handled by F-010 renderable (BR-014.5)
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return null;
        }

        // Only convert for Gale requests (BR-014.9, BR-F058-07)
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (! $request->isGale()) {
            return null;
        }

        $status = self::resolveStatusCode($e);
        $message = self::resolveMessage($e, $status);

        // F-058 BR-F058-04/BR-F058-05: Build error detail — stack trace only in debug mode
        $errorDetail = self::buildErrorDetail($e, $status, $message);

        try {
            // 401 Unauthorized: redirect to login (BR-F012-01, BR-F012-02, BR-F012-03)
            // For HTTP mode: gale()->redirect() produces a JSON response containing a JS redirect
            //   event — 200 status is correct so the frontend processes the event body.
            // For SSE mode: gale()->redirect() produces a text/event-stream response — SSE
            //   requires a 200 status to establish the event stream; setting 401 would cause
            //   the browser's EventSource/fetch to reject the connection before any events arrive.
            // In both cases the login URL is encoded in the response body/events, so the frontend
            //   navigates to login without needing a 401 HTTP status code.
            if ($status === 401) {
                $loginUrl = config('gale.login_url', '/login');

                return gale()->redirect($loginUrl)->toResponse($request);
            }

            // 419 CSRF Token Mismatch: dispatch specific event (BR-014.4)
            if ($status === 419) {
                $gale = gale()
                    ->state('_error', $errorDetail)
                    ->dispatch('gale:csrf-expired', $errorDetail);

                $response = $gale->toResponse($request);
                $response->setStatusCode($status);

                return $response;
            }

            // All other errors: set _error state + dispatch gale:error (BR-014.1, BR-014.2, BR-014.3)
            // BR-F058-02: error detail shape { error: true, status, message } (+ trace fields in debug)
            $gale = gale()
                ->state('_error', $errorDetail)
                ->dispatch('gale:error', $errorDetail);

            $response = $gale->toResponse($request);
            $response->setStatusCode($status);

            return $response;

        } catch (\Throwable) {
            // Double exception fallback (edge case: error handler throws)
            // Return minimal JSON to prevent complete failure
            return new JsonResponse(
                data: ['error' => true],
                status: $status,
                headers: ['X-Gale-Response' => 'true'],
            );
        }
    }

    /**
     * Build the error detail object for the response
     *
     * F-058 BR-F058-02: HTTP mode errors return { error: true, status, message }
     * F-058 BR-F058-04: In debug mode, also include exception class, file, line, trace
     * F-058 BR-F058-05: Production MUST NOT expose class names, file paths, or stack traces
     *
     * @return array<string, mixed>
     */
    public static function buildErrorDetail(\Throwable $e, int $status, string $message): array
    {
        // Base shape — always safe to expose (BR-F058-02)
        $detail = [
            'error' => true,
            'status' => $status,
            'message' => $message,
        ];

        // Debug mode only: expose exception internals for developers (BR-F058-04)
        // Production: no class names, file paths, or stack traces (BR-F058-05)
        if (config('app.debug')) {
            $detail['exception'] = get_class($e);
            $detail['file'] = $e->getFile();
            $detail['line'] = $e->getLine();

            // Summarise trace: top 10 frames, each with file, line, function, class
            // Full raw trace is not sent to avoid excessively large payloads
            $trace = [];
            foreach (array_slice($e->getTrace(), 0, 10) as $frame) {
                $trace[] = [
                    'file' => $frame['file'] ?? '[internal]',
                    'line' => $frame['line'] ?? 0,
                    'function' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
                ];
            }
            $detail['trace'] = $trace;
        }

        return $detail;
    }

    /**
     * Resolve HTTP status code from an exception
     *
     * HttpExceptions carry their own status code. AuthenticationException maps
     * to 401 Unauthorized. All other exception types default to 500 Internal
     * Server Error (BR-014.12).
     */
    public static function resolveStatusCode(\Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        // AuthenticationException (from auth middleware) maps to 401 Unauthorized
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return 401;
        }

        return 500;
    }

    /**
     * Resolve error message based on exception type and debug mode
     *
     * Priority:
     * 1. HttpException messages from abort() -- developer-defined, always safe
     * 2. Debug mode: expose the actual exception message (BR-014.10)
     * 3. Production mode: generic safe messages by status code (BR-014.11, BR-F058-05)
     */
    public static function resolveMessage(\Throwable $e, int $status): string
    {
        // HttpException messages (from abort()) are developer-defined and safe to expose
        if ($e instanceof HttpExceptionInterface) {
            $httpMessage = $e->getMessage();
            if (! empty($httpMessage)) {
                return $httpMessage;
            }
        }

        // In debug mode, expose the exception message for developers (BR-014.10, BR-F058-04)
        if (config('app.debug') && ! empty($e->getMessage())) {
            return $e->getMessage();
        }

        // Production mode: use generic messages by status code (BR-014.11, BR-F058-05)
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'An error occurred',
        };
    }
}
