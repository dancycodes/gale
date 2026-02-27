<?php

namespace Dancycodes\Gale\Http;

use Illuminate\Contracts\Support\Responsable;

/**
 * Gale Redirect Builder - Full-Page Browser Redirects with Session Flash
 *
 * Provides fluent API for constructing full-page browser redirects that break out of reactive
 * mode and perform traditional navigation via window.location. Supports Laravel-style session
 * flash data for passing messages, errors, and input to destination pages.
 *
 * Unlike GaleResponse navigation methods which update content reactively, redirects generated
 * by this class trigger complete page reloads with browser history changes. Flash data is stored
 * in Laravel session before redirect execution and persists only for the next request.
 *
 * The redirect is implemented via JavaScript setTimeout to allow session write operations to
 * complete before navigation begins. This prevents race conditions where redirect occurs before
 * flash data is fully written to session storage.
 *
 * Implements Responsable interface allowing direct return from controllers. Automatically handles
 * session initialization, flash data storage, URL validation for security (same-domain checks),
 * and JavaScript generation for browser navigation.
 *
 * @see \Dancycodes\Gale\Http\GaleResponse
 * @see \Illuminate\Contracts\Support\Responsable
 */
class GaleRedirect implements Responsable
{
    /** @var \Dancycodes\Gale\Http\GaleResponse Parent response builder */
    protected GaleResponse $galeResponse;

    /** @var string|null Target URL for redirect (can be set later via to(), route(), back(), etc.) */
    protected ?string $url = null;

    /** @var array<string, mixed> Session flash data to persist for next request */
    protected array $flashData = [];

    /**
     * Initialize redirect builder with optional target URL and parent response
     *
     * URL can be provided here or set later using to(), route(), back(), home(), intended(), etc.
     * This matches Laravel's redirect() helper which works without requiring an immediate URL.
     *
     * @param  string|null  $url  Optional destination URL for redirect
     * @param  \Dancycodes\Gale\Http\GaleResponse  $galeResponse  Parent response builder instance
     */
    public function __construct(?string $url, GaleResponse $galeResponse)
    {
        $this->url = $url;
        $this->galeResponse = $galeResponse;
    }

    /**
     * Set redirect destination URL (Laravel compatibility)
     *
     * Provides explicit URL setting matching Laravel's redirect()->to() pattern.
     *
     * @param  string  $url  Destination URL for redirect
     * @return static Returns this instance for method chaining
     */
    public function to(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Redirect to external URL without same-domain validation
     *
     * Use this for redirects to external domains. Unlike to(), this method
     * explicitly signals that the URL is external and bypasses domain checks.
     *
     * @param  string  $url  External URL to redirect to
     * @return static Returns this instance for method chaining
     */
    public function away(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Add data to session flash for next request
     *
     * Accepts either key-value pair or associative array of flash data. Flash data persists
     * in session only for the immediately following request, then is automatically removed.
     * Multiple calls accumulate flash data rather than replacing it.
     *
     * @param string|array<string, mixed> $key Flash data key or associative array
     * @param mixed $value Flash data value when $key is string, ignored when $key is array
     *
     * @return static Returns this instance for method chaining
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->flashData = array_merge($this->flashData, $key);
        } else {
            $this->flashData[$key] = $value;
        }

        return $this;
    }

    /**
     * Flash request input data to session for next request
     *
     * Stores form input data in session under '_old_input' key, typically used to repopulate
     * forms after validation failures. Uses current request input if no specific input provided.
     *
     * @param array<string, mixed>|null $input Input data to flash, or null for current request input
     *
     * @return static Returns this instance for method chaining
     */
    public function withInput(?array $input = null): self
    {
        $input = $input ?: request()->input();

        return $this->with('_old_input', $input);
    }

    /**
     * Flash validation errors to session for next request
     *
     * Stores error data in session under 'errors' key for display in destination page.
     * Accepts various error formats including MessageBag, Validator, or array.
     *
     * @param mixed $errors Error data in various supported formats
     *
     * @return static Returns this instance for method chaining
     */
    public function withErrors(mixed $errors): self
    {
        return $this->with('errors', $errors);
    }

    /**
     * Convert to HTTP response implementing Responsable interface
     *
     * Flashes accumulated session data and generates JavaScript for browser navigation.
     * The frontend uses redirect: 'manual' in fetch, so this SSE response containing the
     * redirect script will be properly received and executed.
     *
     * For non-Gale requests, falls back to standard Laravel redirect response.
     *
     * @param \Illuminate\Http\Request|null $request Laravel request instance or null for auto-detection
     *
     * @return \Symfony\Component\HttpFoundation\Response SSE response for Gale, RedirectResponse for non-Gale
     */
    public function toResponse($request = null): \Symfony\Component\HttpFoundation\Response
    {
        $request = $request ?? request();

        // Validate URL is set - throw helpful exception if not
        if ($this->url === null) {
            throw new \LogicException(
                'Redirect URL not set. Use redirect("/path"), redirect()->to("/path"), ' .
                'redirect()->back(), redirect()->route("name"), redirect()->home(), or redirect()->intended().'
            );
        }

        // For non-Gale requests, use standard Laravel redirect
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!$request->isGale()) {
            $redirect = redirect($this->url);

            // Apply flash data
            if (!empty($this->flashData)) {
                foreach ($this->flashData as $key => $value) {
                    session()->flash((string) $key, $value);
                }
            }

            return $redirect;
        }

        // Flash data to session for the next request
        if (!empty($this->flashData)) {
            foreach ($this->flashData as $key => $value) {
                session()->flash((string) $key, $value);
            }
        }

        $safeUrl = json_encode($this->url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Direct navigation - no setTimeout needed since frontend handles response properly
        $script = "window.location.href = {$safeUrl}";

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $this->galeResponse
            ->js($script, ['autoRemove' => true])
            ->toResponse($request);

        return $response;
    }

    /**
     * Redirect to previous URL with automatic same-domain validation
     *
     * Retrieves previous URL from Laravel's URL helper and validates it exists, differs from
     * current URL, and belongs to same domain for security. Falls back to provided URL if any
     * validation fails or if previous URL is external.
     *
     * @param string $fallback Fallback URL when no valid previous URL available
     *
     * @return static Returns this instance for method chaining
     */
    public function back(string $fallback = '/'): self
    {
        $previousUrl = url()->previous();

        if (
            !$previousUrl ||
            $previousUrl === request()->url() ||
            $previousUrl === request()->fullUrl()
        ) {
            $this->url = (string) url($fallback);
        } else {
            $previousDomain = parse_url((string) $previousUrl, PHP_URL_HOST);
            $currentDomain = parse_url((string) request()->url(), PHP_URL_HOST);

            if ($previousDomain === $currentDomain) {
                $this->url = $previousUrl;
            } else {
                $this->url = (string) url($fallback);
            }
        }

        return $this;
    }

    /**
     * Reload current page with optional query parameter preservation
     *
     * Redirects to current URL, optionally preserving existing query parameters and URL fragments.
     * Query preservation uses fullUrl(), while non-preservation uses base url() without parameters.
     * Fragment preservation attempts to extract from HTTP_REFERER when available.
     *
     * @param bool $preserveQuery Whether to maintain current query string parameters
     * @param bool $preserveFragment Whether to maintain URL fragment identifier
     *
     * @return static Returns this instance for method chaining
     */
    public function refresh(bool $preserveQuery = true, bool $preserveFragment = false): self
    {
        if ($preserveQuery) {
            $url = request()->fullUrl();

            if ($preserveFragment && isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
                $fragment = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_FRAGMENT);
                if ($fragment) {
                    $url .= '#' . $fragment;
                }
            }

            $this->url = $url;
        } else {
            $this->url = request()->url();
        }

        return $this;
    }

    /**
     * Redirect to application root URL
     *
     * Sets redirect destination to base application URL ('/'), typically the homepage
     * or main landing page configured in application routing.
     *
     * @return static Returns this instance for method chaining
     */
    public function home(): self
    {
        $this->url = url('/');

        return $this;
    }

    /**
     * Redirect to Laravel named route with parameters
     *
     * Generates URL from route name and parameters using Laravel's route() helper,
     * with option for relative or absolute URL generation. Throws exception if
     * specified route does not exist in application route definitions.
     *
     * @param string $routeName Laravel route name
     * @param array<string, mixed> $parameters Route parameter values
     * @param bool $absolute Whether to generate absolute URL with domain
     *
     * @throws \InvalidArgumentException When route name does not exist
     *
     * @return static Returns this instance for method chaining
     */
    public function route(string $routeName, array $parameters = [], bool $absolute = true): self
    {
        try {
            $this->url = route($routeName, $parameters, $absolute);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Route '{$routeName}' does not exist.");
        }

        return $this;
    }

    /**
     * Redirect to intended URL from authentication guard or default fallback
     *
     * Retrieves and removes intended URL from session (typically set by authentication
     * middleware), with same-domain validation for security. Falls back to provided default
     * URL if no intended URL exists or if intended URL is from external domain.
     *
     * @param string $default Fallback URL when no valid intended URL available
     *
     * @return static Returns this instance for method chaining
     */
    public function intended(string $default = '/'): self
    {
        $intendedUrl = session()->pull('url.intended', $default);

        if ($intendedUrl !== $default && is_string($intendedUrl)) {
            $intendedDomain = parse_url($intendedUrl, PHP_URL_HOST);
            $currentDomain = parse_url((string) request()->url(), PHP_URL_HOST);

            if ($intendedDomain !== $currentDomain) {
                $intendedUrl = $default;
            }
        }

        $urlString = is_string($intendedUrl) ? $intendedUrl : $default;
        $this->url = (string) url($urlString);

        return $this;
    }

    /**
     * Force immediate page reload with optional cache bypass
     *
     * Executes window.location.reload() in browser to refresh current page. When forceReload
     * is true, bypasses browser cache by passing true to reload() function. Flashes any pending
     * session data before reload to preserve messages and errors across refresh.
     *
     * For non-Gale requests, redirects back to current URL to trigger a refresh.
     *
     * @param bool $forceReload Whether to bypass browser cache and force server fetch
     *
     * @return \Symfony\Component\HttpFoundation\Response SSE response for Gale, RedirectResponse for non-Gale
     */
    public function forceReload(bool $forceReload = false): \Symfony\Component\HttpFoundation\Response
    {
        // For non-Gale requests, redirect to current URL
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            // Flash data to session
            if (!empty($this->flashData)) {
                foreach ($this->flashData as $key => $value) {
                    session()->flash((string) $key, $value);
                }
            }

            return redirect(request()->fullUrl());
        }

        // Flash data to session for the next request
        if (!empty($this->flashData)) {
            foreach ($this->flashData as $key => $value) {
                session()->flash((string) $key, $value);
            }
        }

        $reloadParam = $forceReload ? 'true' : 'false';

        // Direct reload - no setTimeout needed
        $script = "window.location.reload({$reloadParam})";

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $this->galeResponse
            ->js($script, ['autoRemove' => true])
            ->toResponse();

        return $response;
    }

    /**
     * Redirect to previous URL with named route fallback
     *
     * Attempts to redirect to previous URL, falling back to specified named route if no
     * previous URL exists or if previous URL matches current URL. Combines back() and route()
     * functionality in single method for conditional navigation logic.
     *
     * @param string $routeName Fallback route name when previous URL unavailable
     * @param array<string, mixed> $routeParameters Parameters for fallback route
     *
     * @throws \InvalidArgumentException When fallback route name does not exist
     *
     * @return static Returns this instance for method chaining
     */
    public function backOr(string $routeName, array $routeParameters = []): self
    {
        $previousUrl = url()->previous();

        if (!$previousUrl || $previousUrl === request()->url()) {
            return $this->route($routeName, $routeParameters);
        }

        return $this->back();
    }
}
