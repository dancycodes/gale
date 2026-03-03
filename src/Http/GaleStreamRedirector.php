<?php

namespace Dancycodes\Gale\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Store;

/**
 * Stream-compatible Redirector for Gale SSE responses
 *
 * Extends Laravel's Redirector to intercept redirect calls within stream() context
 * and perform JavaScript-based navigation instead of returning RedirectResponse objects.
 * This allows standard Laravel redirect() calls to work within SSE streaming callbacks.
 *
 * When a redirect method is called, this class outputs SSE events that trigger
 * window.location navigation in the browser, then terminates the PHP process.
 */
class GaleStreamRedirector extends Redirector
{
    /**
     * Create a new stream redirector instance.
     *
     * @param UrlGenerator $generator URL generator for building redirect URLs
     * @param Store|null $session Session store for intended URL support
     */
    public function __construct(UrlGenerator $generator, ?Store $session = null)
    {
        parent::__construct($generator);

        if ($session !== null) {
            $this->setSession($session);
        }
    }

    /**
     * Create a new redirect response to the given path.
     *
     * @param string $path
     * @param int $status
     * @param array<string, mixed> $headers
     * @param bool|null $secure
     *
     * @return never
     */
    public function to($path, $status = 302, $headers = [], $secure = null): RedirectResponse
    {
        $this->performStreamRedirect((string) url($path, [], $secure));
    }

    /**
     * Create a new redirect response to a named route.
     *
     * @param string $route
     * @param array<string, mixed> $parameters
     * @param int $status
     * @param array<string, mixed> $headers
     *
     * @return never
     */
    public function route($route, $parameters = [], $status = 302, $headers = []): RedirectResponse
    {
        $this->performStreamRedirect(route($route, $parameters));
    }

    /**
     * Create a new redirect response to the previous location.
     *
     * @param int $status
     * @param array<string, mixed> $headers
     * @param string|false $fallback
     *
     * @return never
     */
    public function back($status = 302, $headers = [], $fallback = false): RedirectResponse
    {
        $url = $this->generator->previous($fallback);
        $this->performStreamRedirect($url);
    }

    /**
     * Create a new redirect response to the current URI.
     *
     * @param int $status
     * @param array<string, mixed> $headers
     *
     * @return never
     */
    public function refresh($status = 302, $headers = []): RedirectResponse
    {
        $this->performStreamRedirect($this->generator->getRequest()->getUri());
    }

    /**
     * Create a new redirect response to an external URL (no validation).
     *
     * @param string $path
     * @param int $status
     * @param array<string, mixed> $headers
     *
     * @return never
     */
    public function away($path, $status = 302, $headers = []): RedirectResponse
    {
        $this->performStreamRedirect($path);
    }

    /**
     * Create a new redirect response to the given HTTPS path.
     *
     * @param string $path
     * @param int $status
     * @param array<string, mixed> $headers
     *
     * @return never
     */
    public function secure($path, $status = 302, $headers = []): RedirectResponse
    {
        $this->performStreamRedirect(url($path, [], true));
    }

    /**
     * Create a new redirect response to a controller action.
     *
     * @param array<int, mixed>|string $action
     * @param array<string, mixed> $parameters
     * @param int $status
     * @param array<string, mixed> $headers
     *
     * @return never
     */
    public function action($action, $parameters = [], $status = 302, $headers = []): RedirectResponse
    {
        $this->performStreamRedirect(action($action, $parameters));
    }

    /**
     * Create a new redirect response, while putting the current URL in the session.
     *
     * @param string $path
     * @param int $status
     * @param array<string, mixed> $headers
     * @param bool|null $secure
     *
     * @return never
     */
    public function guest($path, $status = 302, $headers = [], $secure = null): RedirectResponse
    {
        $this->session->put('url.intended', $this->generator->full());
        $this->performStreamRedirect((string) url($path, [], $secure));
    }

    /**
     * Create a new redirect response to the previously intended location.
     *
     * @param string $default
     * @param int $status
     * @param array<string, mixed> $headers
     * @param bool|null $secure
     *
     * @return never
     */
    public function intended($default = '/', $status = 302, $headers = [], $secure = null): RedirectResponse
    {
        $pulled = $this->session->pull('url.intended', $default);
        $url = is_string($pulled) ? $pulled : (string) $default;
        $this->performStreamRedirect((string) url($url, [], $secure));
    }

    /**
     * Perform the actual stream redirect via SSE
     *
     * Outputs SSE event data that triggers browser navigation via window.location,
     * flushes output buffers, and terminates the PHP process.
     *
     * @param string $url Target URL for redirect
     */
    protected function performStreamRedirect(string $url): never
    {
        $safeUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeUrl = is_string($safeUrl) ? $safeUrl : '""';

        echo "event: gale-patch-elements\n";
        echo "data: elements <script>window.location.href = {$safeUrl};</script>\n";
        echo "data: selector body\n";
        echo "data: mode append\n\n";

        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        exit;
    }
}
