<?php

namespace Dancycodes\Gale\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Gale Error Response Handler (F-014)
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
 * Non-Gale requests pass through unchanged.
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

        // Only convert for Gale requests (BR-014.9)
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (! $request->isGale()) {
            return null;
        }

        $status = self::resolveStatusCode($e);
        $message = self::resolveMessage($e, $status);

        try {
            // 401 Unauthorized: redirect to login (spec: Error Categories table)
            if ($status === 401) {
                $loginUrl = config('gale.login_url', '/login');
                $response = gale()->redirect($loginUrl)->toResponse($request);
                $response->setStatusCode(401);

                return $response;
            }

            // 419 CSRF Token Mismatch: dispatch specific event (BR-014.4)
            if ($status === 419) {
                $gale = gale()
                    ->state('_error', [
                        'status' => $status,
                        'message' => $message,
                    ])
                    ->dispatch('gale:csrf-expired', [
                        'status' => $status,
                        'message' => $message,
                    ]);

                $response = $gale->toResponse($request);
                $response->setStatusCode($status);

                return $response;
            }

            // All other errors: set _error state + dispatch gale:error (BR-014.1, BR-014.2, BR-014.3)
            $gale = gale()
                ->state('_error', [
                    'status' => $status,
                    'message' => $message,
                ])
                ->dispatch('gale:error', [
                    'status' => $status,
                    'message' => $message,
                ]);

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
     * 3. Production mode: generic safe messages by status code (BR-014.11)
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

        // In debug mode, expose the exception message for developers (BR-014.10)
        if (config('app.debug') && ! empty($e->getMessage())) {
            return $e->getMessage();
        }

        // Production mode: use generic messages by status code (BR-014.11)
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            419 => 'Page Expired',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'An error occurred',
        };
    }
}
