<?php

namespace Dancycodes\Gale\Http;

use Closure;
use Dancycodes\Gale\View\Fragment\BladeFragment;
use Illuminate\Contracts\Support\Responsable;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GaleResponse - Fluent Response Builder for Reactive HTTP Responses
 *
 * Provides a fluent API for constructing Server-Sent Events (SSE) responses compatible
 * with the Alpine Gale reactive framework. This class implements the Responsable interface,
 * allowing direct return from Laravel route handlers and controllers.
 *
 * The response builder operates in two distinct modes:
 * - Normal Mode: Accumulates events in memory and sends all events when converted to a response
 * - Streaming Mode: Sends events immediately as they are added, enabling long-running operations
 *
 * Core capabilities include:
 * - DOM manipulation through element patching (append, prepend, replace, remove, etc.)
 * - Reactive state updates synchronized between server and client (RFC 7386 JSON Merge Patch)
 * - JavaScript execution in the browser context
 * - Fragment-based partial view rendering for granular UI updates
 * - Browser history manipulation (pushState/replaceState)
 * - Client-side navigation with query parameter merging strategies
 * - Custom event dispatching for component communication
 * - Full-page redirects with session flash data support
 *
 * All reactive methods automatically detect Gale requests using the Gale-Request
 * header and gracefully degrade for standard HTTP requests when a web fallback is provided.
 *
 * @see \Illuminate\Contracts\Support\Responsable
 * @see \Dancycodes\Gale\Http\GaleRedirect
 */
class GaleResponse implements Responsable
{
    /** @var array<int, string> */
    protected array $events = [];

    protected bool $streamingMode = false;

    protected ?Closure $streamCallback = null;

    /** @var mixed */
    protected $webResponse = null;

    /**
     * Reset the response builder state for reuse
     *
     * Clears all accumulated events and resets flags to their initial state.
     * Called automatically after toResponse() to allow singleton reuse across requests.
     */
    public function reset(): void
    {
        $this->events = [];
        $this->streamingMode = false;
        $this->streamCallback = null;
        $this->webResponse = null;
    }

    /**
     * Retrieve Server-Sent Events HTTP headers for streaming responses
     *
     * Returns standardized headers required for SSE communication compatible with
     * the Alpine Gale protocol. Includes cache control, content type, buffering directives,
     * and protocol-specific connection handling for HTTP/1.1.
     *
     * @return array<string, string> Associative array of header names and values
     */
    public static function headers(): array
    {
        $headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'X-Gale-Response' => 'true',
        ];

        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
        if (is_string($protocol) && $protocol === 'HTTP/1.1') {
            $headers['Connection'] = 'keep-alive';
        }

        return $headers;
    }

    /**
     * Render a complete Blade view and patch it into the DOM
     *
     * Compiles the specified Blade view with provided data and sends the rendered HTML
     * as a DOM patch event. Only processes for Gale requests unless $web is true.
     *
     * @param string $view Blade view name (dot notation supported)
     * @param array<string, mixed> $data Variables to pass to the view template
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     * @param bool $web Whether to set this view as the fallback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function view(string $view, array $data = [], array $options = [], bool $web = false): self
    {

        if ($web) {
            /** @phpstan-ignore argument.type (view-string is Laravel's type hint) */
            $this->web(view($view, $data));
        }

        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        /** @phpstan-ignore argument.type (view-string is Laravel's type hint) */
        $html = view($view, $data)->render();

        return $this->patchElements($html, $options);
    }

    /**
     * Render a specific Blade fragment and patch it into the DOM
     *
     * Extracts and renders only the specified fragment from the Blade view, avoiding
     * full view compilation. Fragments are defined using @fragment/@endfragment directives.
     *
     * @param string $view Blade view containing the target fragment
     * @param string $fragment Name of the fragment to render
     * @param array<string, mixed> $data Variables to pass to the fragment
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    public function fragment(string $view, string $fragment, array $data = [], array $options = []): self
    {
        return $this->patchFragment($view, $fragment, $data, $options);
    }

    /**
     * Render and patch multiple fragments from various views
     *
     * Processes multiple fragment specifications in a single method call, where each
     * fragment configuration specifies the view, fragment name, data, and patch options.
     *
     * @param array<int, array{view: string, fragment: string, data?: array<string, mixed>, options?: array<string, mixed>}> $fragments Array of fragment configurations
     *
     * @return static Returns this instance for method chaining
     */
    public function fragments(array $fragments): self
    {
        return $this->patchFragments($fragments);
    }

    /**
     * Patch raw HTML content into the DOM
     *
     * Sends arbitrary HTML content as a DOM patch event without view compilation.
     * Useful for dynamically generated markup or client-provided HTML strings.
     *
     * @param string $html Raw HTML markup to patch
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     * @param bool $web Whether to set this HTML as the fallback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function html(string $html, array $options = [], bool $web = false): self
    {
        if ($web) {
            $this->web(response($html));
        }

        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        return $this->patchElements($html, $options);
    }

    /**
     * Render a Blade partial view and patch it into the DOM
     *
     * Alias for view() method using partial terminology. Commonly used for
     * rendering reusable UI sections that can be updated independently.
     *
     * @param string $view Blade view name (dot notation supported)
     * @param array<string, mixed> $data Variables to pass to the view template
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     * @param bool $web Whether to set this view as the fallback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function partial(string $view, array $data = [], array $options = [], bool $web = false): self
    {
        return $this->view($view, $data, $options, $web);
    }

    /**
     * Update a specific named component's state
     *
     * Sends a gale-patch-component event to update the Alpine state of a
     * component registered with x-component directive. Uses RFC 7386 JSON
     * Merge Patch semantics.
     *
     * @param string $componentName Name of the component (from x-component attribute)
     * @param array<string, mixed> $state State updates (key-value pairs)
     * @param array<string, mixed> $options Optional: onlyIfMissing
     *
     * @return static Returns this instance for method chaining
     */
    public function componentState(string $componentName, array $state, array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $dataLines = $this->buildComponentEvent($componentName, $state, $options);
        $this->handleEvent('gale-patch-component', $dataLines);

        return $this;
    }

    /**
     * Update reactive state in the client-side Alpine x-data store
     *
     * Sends state updates to synchronize server state with the client using RFC 7386
     * JSON Merge Patch semantics. Accepts either a single key-value pair or an
     * associative array of multiple state values.
     *
     * @param string|array<string, mixed> $key State key or associative array of state
     * @param mixed $value State value when $key is a string, ignored when $key is array
     *
     * @return static Returns this instance for method chaining
     */
    public function state(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            return $this->patchState($key);
        }

        return $this->patchState([$key => $value]);
    }

    /**
     * Remove state keys from the client-side Alpine x-data store
     *
     * Deletes specified state keys from the client using RFC 7386 JSON Merge Patch
     * semantics where null values indicate deletion. Specific state keys must be
     * provided as the frontend manages its own state.
     *
     * @param string|array<int, string>|null $state State key(s) to delete, or null (no-op)
     *
     * @return static Returns this instance for method chaining
     */
    public function forget(string|array|null $state = null): self
    {
        if (is_null($state)) {
            // Cannot forget all state without knowing what keys exist
            // The frontend manages its own state, so we need specific keys
            return $this;
        }

        $stateKeys = $this->parseStateKeys($state);

        return $this->forgetState($stateKeys);
    }

    /**
     * Execute JavaScript code in the browser context
     *
     * Injects and executes JavaScript code by creating a script element in the DOM.
     * The script element is automatically removed after execution unless configured otherwise.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script element options (attributes, autoRemove)
     *
     * @return static Returns this instance for method chaining
     */
    public function js(string $script, array $options = []): self
    {
        return $this->executeScript($script, $options);
    }

    /**
     * Alias for js() method using script terminology
     *
     * Provides alternative method name for JavaScript execution. Functionally
     * identical to the js() method.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script element options (attributes, autoRemove)
     *
     * @return static Returns this instance for method chaining
     */
    public function script(string $script, array $options = []): self
    {
        return $this->js($script, $options);
    }

    /**
     * Dispatch a custom browser event for inter-component communication
     *
     * Creates and dispatches a CustomEvent either globally on the window object or
     * targeted to specific DOM elements via CSS selectors. Event data is accessible
     * through the event.detail property. Supports standard CustomEvent options including
     * bubbling, cancelable, and composed properties.
     *
     * @param string $eventName Name of the CustomEvent to dispatch
     * @param array<string, mixed> $data Event payload accessible via event.detail
     * @param array<string, mixed> $options Dispatch configuration (selector, window, bubbles, cancelable, composed)
     *
     * @throws \InvalidArgumentException When event name is empty
     *
     * @return static Returns this instance for method chaining
     */
    public function dispatch(string $eventName, array $data = [], array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // Validate event name
        if (empty($eventName)) {
            throw new \InvalidArgumentException('Event name cannot be empty');
        }

        // Extract options with defaults
        $selector = $options['selector'] ?? null;
        $window = $options['window'] ?? (!$selector); // Default to window if no selector
        $bubbles = $options['bubbles'] ?? true;
        $cancelable = $options['cancelable'] ?? true;
        $composed = $options['composed'] ?? true;

        // Safe JSON encoding
        $safeEventName = json_encode($eventName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeData = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeBubbles = $bubbles ? 'true' : 'false';
        $safeCancelable = $cancelable ? 'true' : 'false';
        $safeComposed = $composed ? 'true' : 'false';

        // Generate dispatch script
        if ($selector) {
            // Targeted dispatch to CSS selector(s)
            $safeSelector = json_encode($selector, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            $script = "
                (function() {
                    const targets = document.querySelectorAll({$safeSelector});
                    const eventName = {$safeEventName};
                    const data = {$safeData};

                    if (targets.length === 0) {
                        console.warn('[Gale Dispatch] No elements found for selector:', {$safeSelector});
                        return;
                    }

                    targets.forEach(target => {
                        target.dispatchEvent(new CustomEvent(eventName, {
                            detail: data,
                            bubbles: {$safeBubbles},
                            cancelable: {$safeCancelable},
                            composed: {$safeComposed}
                        }));
                    });
                })();
            ";
        } elseif ($window) {
            // Global dispatch to window
            $script = "
                window.dispatchEvent(new CustomEvent({$safeEventName}, {
                    detail: {$safeData},
                    bubbles: {$safeBubbles},
                    cancelable: {$safeCancelable},
                    composed: {$safeComposed}
                }));
            ";
        } else {
            // Fallback to body dispatch
            $script = "
                document.body.dispatchEvent(new CustomEvent({$safeEventName}, {
                    detail: {$safeData},
                    bubbles: {$safeBubbles},
                    cancelable: {$safeCancelable},
                    composed: {$safeComposed}
                }));
            ";
        }

        return $this->executeScript($script, ['autoRemove' => true]);
    }

    /**
     * Remove matched elements from the DOM
     *
     * Deletes all elements matching the specified CSS selector from the document.
     *
     * @param string $selector CSS selector targeting elements to remove
     *
     * @return static Returns this instance for method chaining
     */
    public function remove(string $selector): self
    {
        return $this->removeElements($selector);
    }

    /**
     * Append HTML content as the last child of matched elements
     *
     * Inserts the provided HTML inside targeted elements, after their existing children.
     * Corresponds to Datastar patch mode 'append'.
     *
     * @param string $selector CSS selector targeting parent elements
     * @param string $html HTML markup to append
     *
     * @return static Returns this instance for method chaining
     */
    public function append(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'append',
        ]);
    }

    /**
     * Prepend HTML content as the first child of matched elements
     *
     * Inserts the provided HTML inside targeted elements, before their existing children.
     * Corresponds to Datastar patch mode 'prepend'.
     *
     * @param string $selector CSS selector targeting parent elements
     * @param string $html HTML markup to prepend
     *
     * @return static Returns this instance for method chaining
     */
    public function prepend(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'prepend',
        ]);
    }

    /**
     * Replace matched elements entirely with new HTML
     *
     * Substitutes all elements matching the selector with the provided HTML markup.
     * Corresponds to Datastar patch mode 'replace'.
     *
     * @param string $selector CSS selector targeting elements to replace
     * @param string $html Replacement HTML markup
     *
     * @return static Returns this instance for method chaining
     */
    public function replace(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'replace',
        ]);
    }

    /**
     * Insert HTML content immediately before matched elements
     *
     * Places the provided HTML as a sibling before each targeted element.
     * Corresponds to Datastar patch mode 'before'.
     *
     * @param string $selector CSS selector targeting reference elements
     * @param string $html HTML markup to insert
     *
     * @return static Returns this instance for method chaining
     */
    public function before(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'before',
        ]);
    }

    /**
     * Insert HTML content immediately after matched elements
     *
     * Places the provided HTML as a sibling after each targeted element.
     * Corresponds to Datastar patch mode 'after'.
     *
     * @param string $selector CSS selector targeting reference elements
     * @param string $html HTML markup to insert
     *
     * @return static Returns this instance for method chaining
     */
    public function after(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'after',
        ]);
    }

    /**
     * Replace the inner HTML of matched elements
     *
     * Replaces all children of targeted elements with the provided HTML markup, preserving
     * the elements themselves. Corresponds to Datastar patch mode 'inner'.
     *
     * @param string $selector CSS selector targeting container elements
     * @param string $html HTML markup for new inner content
     *
     * @return static Returns this instance for method chaining
     */
    public function inner(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'inner',
        ]);
    }

    /**
     * Replace the outer HTML of matched elements
     *
     * Replaces targeted elements entirely including the elements themselves and their children.
     * Corresponds to Datastar patch mode 'outer'.
     *
     * @param string $selector CSS selector targeting elements to replace
     * @param string $html HTML markup for complete replacement
     *
     * @return static Returns this instance for method chaining
     */
    public function outer(string $selector, string $html): self
    {
        return $this->patchElements($html, [
            'selector' => $selector,
            'mode' => 'outer',
        ]);
    }

    /**
     * Patch HTML elements into the DOM using Alpine Gale protocol
     *
     * Constructs and handles a gale-patch-elements event with the provided HTML content
     * and patching options. Behavior depends on response mode: accumulates in normal mode,
     * sends immediately in streaming mode.
     *
     * @param string $elements HTML markup to patch
     * @param array<string, mixed> $options Patching configuration (selector, mode, useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchElements(string $elements, array $options = []): self
    {
        $dataLines = $this->buildElementsEvent($elements, $options);
        $this->handleEvent('gale-patch-elements', $dataLines);

        return $this;
    }

    /**
     * Update reactive state using RFC 7386 JSON Merge Patch
     *
     * Synchronizes server-side state with the client by sending state updates
     * via SSE. Uses RFC 7386 JSON Merge Patch semantics where null values delete
     * properties and other values are merged. Only processes for Gale requests
     * to avoid unnecessary computation.
     *
     * @param array<string, mixed> $state Associative array of state keys and values
     * @param array<string, mixed> $options State update options (onlyIfMissing)
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchState(array $state, array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $dataLines = $this->buildStateEvent($state, $options);
        $this->handleEvent('gale-patch-state', $dataLines);

        return $this;
    }

    /**
     * Execute JavaScript code in the browser context
     *
     * Constructs and handles a gale-patch-elements event containing a script element.
     * Behavior depends on response mode: accumulates in normal mode, sends immediately
     * in streaming mode.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script configuration (attributes, autoRemove)
     *
     * @return static Returns this instance for method chaining
     */
    protected function executeScript(string $script, array $options = []): self
    {
        $dataLines = $this->buildScriptEvent($script, $options);
        $this->handleEvent('gale-patch-elements', $dataLines);

        return $this;
    }

    /**
     * Remove matched elements from the DOM
     *
     * Constructs and handles a gale-patch-elements event with mode 'remove'.
     * Behavior depends on response mode: accumulates in normal mode, sends immediately
     * in streaming mode.
     *
     * @param string $selector CSS selector targeting elements to remove
     * @param array<string, mixed> $options Removal configuration (useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    protected function removeElements(string $selector, array $options = []): self
    {
        $options['selector'] = $selector;
        $options['mode'] = 'remove';
        $dataLines = $this->buildRemovalEvent($options);
        $this->handleEvent('gale-patch-elements', $dataLines);

        return $this;
    }

    /**
     * Create a fluent redirect builder with session flash support
     *
     * Returns a GaleRedirect instance that provides methods for full-page browser redirects
     * with Laravel session flash data. Redirects perform JavaScript-based navigation using
     * window.location assignments rather than reactive signal updates.
     *
     * @param string $url Target URL for the redirect
     *
     * @return \Dancycodes\Gale\Http\GaleRedirect Redirect builder instance
     */
    public function redirect(string $url): GaleRedirect
    {
        return new GaleRedirect($url, $this);
    }

    /**
     * Enable streaming mode for long-running operations
     *
     * Switches the response builder from accumulation mode to streaming mode, where events
     * are sent immediately as methods are called. The provided callback receives this instance
     * and executes in streaming context. Any events accumulated before stream() was called are
     * flushed first. Handles output buffering, exception rendering, dump/dd integration, and
     * redirect behavior for streaming responses.
     *
     * @param \Closure $callback Function receiving this instance in streaming mode
     *
     * @return static Returns this instance for method chaining
     */
    public function stream(Closure $callback): self
    {
        $this->streamCallback = function ($gale) use ($callback) {
            try {
                ob_start();

                $this->overrideRedirectForStream();
                $this->overrideDumpForStream();

                $callback($gale);

            } catch (\Throwable $e) {
                $this->handleNativeException($e);
            } finally {
                $this->handleStreamOutput();
                $this->restoreOriginalHandlers();
            }
        };

        return $this;
    }

    /**
     * Override Laravel redirect helper for streaming mode
     *
     * Binds custom redirect implementation that performs JavaScript-based navigation
     * via window.location and terminates the stream. Required because standard Laravel
     * redirects return Response objects incompatible with active SSE streams.
     */
    protected function overrideRedirectForStream(): void
    {
        app()->bind('redirect', function () {
            return new class
            {
                /**
                 * @param array<string, mixed> $headers
                 */
                public function to(string $path, int $status = 302, array $headers = [], ?bool $secure = null): never
                {
                    $this->performRealRedirect(url($path));
                }

                /**
                 * @param array<string, mixed> $params
                 * @param array<string, mixed> $headers
                 */
                public function route(string $route, array $params = [], int $status = 302, array $headers = []): never
                {
                    $this->performRealRedirect(route($route, $params));
                }

                /**
                 * @param array<string, mixed> $headers
                 */
                public function back(int $status = 302, array $headers = [], string|bool $fallback = false): never
                {
                    $this->performRealRedirect(url()->previous() ?: url('/'));
                }

                private function performRealRedirect(string $url): never
                {
                    echo "event: datastar-patch-elements\n";
                    echo 'data: elements <script>window.location.href = ' . json_encode($url) . ";</script>\n";
                    echo "data: selector body\n";
                    echo "data: mode append\n\n";

                    if (ob_get_level()) {
                        ob_end_flush();
                    }
                    flush();
                    exit;
                }

                /**
                 * @param array<int, mixed> $args
                 */
                public function __call(string $method, array $args): mixed
                {
                    $result = app('redirect')->{$method}(...$args);
                    if ($result instanceof \Illuminate\Http\RedirectResponse) {
                        $this->performRealRedirect($result->getTargetUrl());
                    }

                    return $result;
                }
            };
        });
    }

    /**
     * Override Symfony VarDumper for streaming mode
     *
     * Replaces the default VarDumper handler to intercept dd() and dump() calls,
     * rendering them as full HTML pages that replace the document and terminate
     * the stream. Prevents dump output from corrupting the SSE event stream.
     */
    protected function overrideDumpForStream(): void
    {
        \Symfony\Component\VarDumper\VarDumper::setHandler(function ($var) {
            $html = $this->generateNativeDumpHtml($var);
            $this->replaceDocumentAndExit($html);
        });
    }

    /**
     * Generate HTML page for variable dump output
     *
     * Creates a styled HTML document containing the variable dump using Symfony's
     * HtmlDumper. Replicates Laravel's dd() styling with dark theme and monospace fonts.
     *
     * @param mixed $var Variable to dump
     *
     * @return string Complete HTML document with embedded dump output
     */
    protected function generateNativeDumpHtml(mixed $var): string
    {
        $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner;
        $dumper = new \Symfony\Component\VarDumper\Dumper\HtmlDumper;

        $output = '';
        $dumper->dump($cloner->cloneVar($var), function ($line) use (&$output) {
            $output .= $line;
        });

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laravel dd() Output</title>
    <style>
        body { background: #18171B; color: #FF8400; font-family: monospace; margin: 0; padding: 20px; }
        pre.sf-dump { background: #18171B !important; }
        .sf-dump { font-family: monospace; font-size: 12px; line-height: 1.2em; color: #FF8400; word-wrap: break-word; white-space: pre-wrap; position: relative; z-index: 99999; word-break: break-all; }
        .sf-dump .sf-dump-compact { display: none; }
        .sf-dump abbr { text-decoration: none; cursor: help; }
        .sf-dump a { text-decoration: none; cursor: pointer; outline: none; color: inherit; }
        .sf-dump .sf-dump-ellipsis { color: #A0A0A0; }
        .sf-dump .sf-dump-key { color: #A626A4; }
        .sf-dump .sf-dump-public { color: #222222; }
        .sf-dump .sf-dump-protected { color: #C41A16; }
        .sf-dump .sf-dump-private { color: #C41A16; }
        .sf-dump .sf-dump-str { color: #C41A16; }
        .sf-dump .sf-dump-note { color: #1299DA; }
        .sf-dump .sf-dump-ref { color: #6E7681; }
        .sf-dump .sf-dump-meta { color: #B729D9; }
    </style>
</head>
<body>' . $output . '</body>
</html>';
    }

    /**
     * Process output buffer and replace document if content exists
     *
     * Captures buffered output from the streaming callback and wraps it in a complete
     * HTML document if non-empty. Used to display echo statements and other direct
     * output during streaming mode. Terminates stream after document replacement.
     */
    protected function handleStreamOutput(): void
    {
        $output = ob_get_contents();
        if (ob_get_level()) {
            ob_end_clean();
        }

        /** @phpstan-ignore argument.type (ob_get_contents can return false on empty buffer) */
        if (!empty(trim($output))) {
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laravel Output</title>
</head>
<body style="background: #18171B; color: white; font-family: monospace; padding: 20px;">
    ' . $output . '
</body>
</html>';

            $this->replaceDocumentAndExit($html);
        }
    }

    /**
     * Replace entire browser document and terminate stream
     *
     * Sends JavaScript that replaces the complete document content using document.write()
     * and terminates the SSE stream. Used for showing full-page content during streaming
     * such as dump output, exceptions, or direct output captures.
     *
     * @param string $html Complete HTML document to display
     */
    protected function replaceDocumentAndExit(string $html): void
    {
        echo "event: datastar-patch-elements\n";
        echo 'data: elements <script>document.open(); document.write(' . json_encode($html) . "); document.close();</script>\n";
        echo "data: selector body\n";
        echo "data: mode append\n\n";

        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        exit;
    }

    /**
     * Restore original Laravel service bindings
     *
     * Removes custom redirect and VarDumper bindings established for streaming mode,
     * restoring default Laravel behavior. Called in finally block to ensure cleanup
     * occurs even when exceptions are thrown.
     */
    protected function restoreOriginalHandlers(): void
    {
        app()->forgetInstance('redirect');
        \Symfony\Component\VarDumper\VarDumper::setHandler(null);
    }

    /**
     * Render exception using Laravel exception handler
     *
     * Delegates exception rendering to Laravel's exception handler to generate appropriate
     * error pages (Ignition in development, generic in production). Falls back to basic
     * error HTML if exception handler itself throws an exception.
     *
     * @param \Throwable $e Exception to render
     */
    protected function handleNativeException(\Throwable $e): void
    {
        try {
            $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            $response = $handler->render(request(), $e);
            $html = $response->getContent();

            /** @phpstan-ignore argument.type (getContent can return false, guarded by try-catch) */
            $this->replaceDocumentAndExit($html);

        } catch (\Throwable $renderError) {
            $fallbackHtml = $this->generateFallbackErrorHtml($e);
            $this->replaceDocumentAndExit($fallbackHtml);
        }
    }

    /**
     * Generate basic error HTML page when exception handler fails
     *
     * Creates minimal error page displaying exception message, file location, and stack
     * trace. Used as fallback when Laravel's exception handler itself throws an exception.
     *
     * @param \Throwable $e Original exception
     *
     * @return string HTML error page
     */
    protected function generateFallbackErrorHtml(\Throwable $e): string
    {
        return '<!DOCTYPE html>
<html>
<head><title>Error</title></head>
<body style="background: #ef4444; color: white; padding: 20px; font-family: monospace;">
    <h1>Exception in Stream</h1>
    <p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
    <p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>
    <pre style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 5px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>
</body>
</html>';
    }

    /**
     * Convert to HTTP response implementing Responsable interface
     *
     * Transforms this builder into a framework-compatible response object. For non-Gale
     * requests, returns the web fallback if configured, otherwise throws LogicException.
     * For Gale requests, creates a StreamedResponse with SSE headers that outputs either
     * accumulated events (normal mode) or executes streaming callback (streaming mode).
     *
     * @param \Illuminate\Http\Request|null $request Laravel request instance or null for auto-detection
     *
     * @throws \LogicException When no web fallback provided for non-Gale request
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|mixed StreamedResponse for Gale, fallback for web
     */
    public function toResponse($request = null): mixed
    {
        $request = $request ?? request();

        // Capture current state and reset for next request (singleton reuse)
        $events = $this->events;
        $streamCallback = $this->streamCallback;
        $webResponse = $this->webResponse;
        $this->reset();

        // Handle non-Gale requests
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!$request->isGale()) {
            if ($webResponse === null) {
                // Return 204 No Content for API routes without web fallback
                // This makes componentState(), state(), etc. gracefully handle non-Gale requests
                return response()->noContent();
            }

            return is_callable($webResponse)
                ? ($webResponse)()
                : $webResponse;
        }

        // Handle Gale requests
        if ($streamCallback) {
            // Streaming mode: use StreamedResponse for real-time output
            $response = new StreamedResponse(function () use ($events, $streamCallback) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $this->executeStreamingModeWithCallback($streamCallback);
            });

            foreach (self::headers() as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        // Single-shot mode: use regular Response (works better with test environments)
        $output = ": keepalive\n\n";
        foreach ($events as $event) {
            $output .= $event;
        }

        return response($output, 200, self::headers());
    }

    /**
     * Handle event routing based on current response mode
     *
     * Central event dispatcher that routes events to appropriate handler based on whether
     * the response is in streaming or normal mode. Short-circuits immediately for non-Gale
     * requests to avoid unnecessary processing.
     *
     * @param string $eventType SSE event type (datastar-patch-elements, datastar-patch-signals)
     * @param array<int, string> $dataLines SSE data lines for the event
     */
    protected function handleEvent(string $eventType, array $dataLines): void
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return;
        }

        if ($this->streamingMode) {
            $this->sendEventImmediately($eventType, $dataLines);
        } else {
            $this->addEventToQueue($eventType, $dataLines);
        }
    }

    /**
     * Execute response in streaming mode
     *
     * Flushes any events accumulated before streaming began, switches to streaming mode,
     * then executes the callback. All method calls during callback execution send events
     * immediately rather than accumulating them.
     */
    protected function executeStreamingMode(): void
    {
        foreach ($this->events as $event) {
            echo $event;
            $this->flushOutput();
        }

        $this->streamingMode = true;
        /** @phpstan-ignore argument.type (streamCallback is set in stream() method) */
        call_user_func($this->streamCallback, $this);
    }

    /**
     * Execute response in normal single-shot mode
     *
     * Outputs all accumulated events sequentially and closes the connection. Used when
     * stream() method was not called, representing standard request-response pattern.
     */
    protected function executeSingleShotMode(): void
    {
        $this->executeSingleShotModeWithEvents($this->events);
    }

    /**
     * Execute single-shot mode with provided events
     *
     * @param array<int, string> $events Events to output
     */
    protected function executeSingleShotModeWithEvents(array $events): void
    {
        foreach ($events as $event) {
            echo $event;
            $this->flushOutput();
        }
    }

    /**
     * Execute streaming mode with provided callback
     *
     * @param Closure $callback Callback function
     */
    protected function executeStreamingModeWithCallback(Closure $callback): void
    {
        $originalCallback = $this->streamCallback;
        $this->streamCallback = $callback;
        $this->executeStreamingMode();
        $this->streamCallback = $originalCallback;
    }

    /**
     * Send SSE event immediately to client
     *
     * Formats and outputs event directly, then flushes buffers to ensure immediate
     * transmission. Used exclusively in streaming mode.
     *
     * @param string $eventType SSE event type
     * @param array<int, string> $dataLines SSE data lines
     */
    protected function sendEventImmediately(string $eventType, array $dataLines): void
    {
        $output = $this->formatEvent($eventType, $dataLines);
        echo $output;
        $this->flushOutput();
    }

    /**
     * Add formatted SSE event to accumulation queue
     *
     * Stores event for later transmission when response is converted. Used exclusively
     * in normal mode before stream() is called.
     *
     * @param string $eventType SSE event type
     * @param array<int, string> $dataLines SSE data lines
     */
    protected function addEventToQueue(string $eventType, array $dataLines): void
    {
        $this->events[] = $this->formatEvent($eventType, $dataLines);
    }

    /**
     * Flush PHP output buffers to client
     *
     * Forces immediate transmission of buffered output to client. Used in streaming
     * mode to ensure events are received as soon as they're generated.
     */
    protected function flushOutput(): void
    {
        if (ob_get_contents()) {
            ob_end_flush();
        }
        flush();
    }

    /**
     * Format SSE event according to Datastar protocol
     *
     * Constructs properly formatted Server-Sent Event with event type and data lines,
     * terminated by blank line as required by SSE specification.
     *
     * @param string $eventType Event type line (event: xxx)
     * @param array<int, string> $dataLines Data payload lines (data: xxx)
     *
     * @return string Formatted SSE event block
     */
    protected function formatEvent(string $eventType, array $dataLines): string
    {
        $output = ["event: {$eventType}"];

        foreach ($dataLines as $line) {
            /** @phpstan-ignore function.alreadyNarrowedType (dataLines array items are already strings per PHPDoc) */
            $lineStr = is_string($line) ? $line : (string) $line;
            $output[] = "data: {$lineStr}";
        }

        $output[] = '';

        return implode("\n", $output) . "\n";
    }

    /**
     * Build SSE data lines for element patching event
     *
     * Constructs array of SSE data lines for datastar-patch-elements event including
     * selector, mode, view transition flag, settle time, limit, and multi-line HTML element content.
     *
     * @param string $elements HTML content to patch
     * @param array<string, mixed> $options Patching options (selector, mode, useViewTransition, settle, limit)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildElementsEvent(string $elements, array $options): array
    {
        $dataLines = [];

        if (!empty($options['selector'])) {
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            $dataLines[] = 'selector ' . (string) $options['selector'];
        }

        if (!empty($options['mode'])) {
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            $dataLines[] = 'mode ' . (string) $options['mode'];
        }

        if (!empty($options['useViewTransition'])) {
            $dataLines[] = 'useViewTransition true';
        }

        // Settle time for CSS transitions (in milliseconds)
        if (!empty($options['settle'])) {
            $dataLines[] = 'settle ' . (int) $options['settle'];
        }

        // Limit number of targets to patch
        if (!empty($options['limit'])) {
            $dataLines[] = 'limit ' . (int) $options['limit'];
        }

        $elementLines = explode("\n", trim($elements));
        foreach ($elementLines as $line) {
            $dataLines[] = "elements {$line}";
        }

        return $dataLines;
    }

    /**
     * Build SSE data lines for state patching event
     *
     * Constructs array of SSE data lines for gale-patch-state event including
     * onlyIfMissing flag and JSON-encoded state data split across multiple lines.
     * Uses RFC 7386 JSON Merge Patch semantics.
     *
     * @param array<string, mixed> $state State keys and values to patch
     * @param array<string, mixed> $options State options (onlyIfMissing)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildStateEvent(array $state, array $options): array
    {
        $dataLines = [];

        if (!empty($options['onlyIfMissing'])) {
            $dataLines[] = 'onlyIfMissing true';
        }

        $stateJson = json_encode($state);
        /** @phpstan-ignore argument.type (json_encode result is always string for arrays) */
        $jsonLines = explode("\n", $stateJson);

        foreach ($jsonLines as $line) {
            $dataLines[] = "state {$line}";
        }

        return $dataLines;
    }

    /**
     * Build SSE data lines for component state patching event
     *
     * Constructs array of SSE data lines for gale-patch-component event including
     * component name, onlyIfMissing flag, and JSON-encoded state data.
     *
     * @param string $componentName Component name (from x-component attribute)
     * @param array<string, mixed> $state State keys and values to patch
     * @param array<string, mixed> $options State options (onlyIfMissing)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildComponentEvent(string $componentName, array $state, array $options): array
    {
        $dataLines = [];

        // Component name
        $dataLines[] = "component {$componentName}";

        if (!empty($options['onlyIfMissing'])) {
            $dataLines[] = 'onlyIfMissing true';
        }

        $stateJson = json_encode($state);
        /** @phpstan-ignore argument.type (json_encode result is always string for arrays) */
        $dataLines[] = "state {$stateJson}";

        return $dataLines;
    }

    /**
     * Build SSE data lines for script execution event
     *
     * Constructs array of SSE data lines for executing JavaScript by creating a script
     * element appended to document body. Supports custom attributes and auto-removal
     * after execution using Alpine's x-init directive.
     *
     * @param string $script JavaScript code to execute
     * @param array<string, mixed> $options Script configuration (attributes, autoRemove)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildScriptEvent(string $script, array $options): array
    {
        /** @var array<string, string> $attributes */
        $attributes = $options['attributes'] ?? [];
        $autoRemove = $options['autoRemove'] ?? true;

        $scriptTag = '<script';

        foreach ($attributes as $key => $value) {
            $scriptTag .= ' ' . (string) $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }

        if ($autoRemove) {
            // Use Alpine x-init to remove script after execution
            $scriptTag .= ' x-init="$nextTick(() => $el.remove())"';
        }

        $scriptTag .= '>' . $script . '</script>';

        // Split script tag by newlines to properly format SSE data lines
        $dataLines = ['selector body', 'mode append'];
        $scriptLines = explode("\n", trim($scriptTag));
        foreach ($scriptLines as $line) {
            $dataLines[] = "elements {$line}";
        }

        return $dataLines;
    }

    /**
     * Build SSE data lines for element removal event
     *
     * Constructs array of SSE data lines for removing DOM elements using patch mode 'remove'.
     * Supports View Transitions API for smooth removal animations.
     *
     * @param array<string, mixed> $options Removal configuration (selector, useViewTransition)
     *
     * @return array<int, string> Array of SSE data lines
     */
    protected function buildRemovalEvent(array $options): array
    {
        $dataLines = [
            /** @phpstan-ignore cast.string (mixed array value, safe to cast) */
            'selector ' . (string) $options['selector'],
            'mode remove',
        ];

        if (!empty($options['useViewTransition'])) {
            $dataLines[] = 'useViewTransition true';
        }

        return $dataLines;
    }

    /**
     * Render and patch a Blade fragment without full view compilation
     *
     * Extracts the specified fragment from the view using BladeFragmentParser and renders
     * it with provided data, then patches the result into the DOM. Only processes for Gale
     * requests to avoid unnecessary fragment extraction for standard requests.
     *
     * @param string $view Blade view containing the fragment
     * @param string $fragment Fragment name to extract and render
     * @param array<string, mixed> $data Variables to pass to fragment
     * @param array<string, mixed> $options DOM patching options (selector, mode, useViewTransition)
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchFragment(string $view, string $fragment, array $data = [], array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $fragmentHtml = BladeFragment::render($view, $fragment, $data);

        return $this->patchElements($fragmentHtml, $options);
    }

    /**
     * Render and patch multiple Blade fragments in sequence
     *
     * Processes array of fragment configurations, rendering and patching each fragment.
     * Enables updating multiple UI sections in a single response without full page reloads.
     *
     * @param array<int, array{view: string, fragment: string, data?: array<string, mixed>, options?: array<string, mixed>}> $fragments Fragment configurations
     *
     * @return static Returns this instance for method chaining
     */
    protected function patchFragments(array $fragments): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        foreach ($fragments as $fragmentConfig) {
            $view = $fragmentConfig['view'];
            $fragment = $fragmentConfig['fragment'];
            $data = $fragmentConfig['data'] ?? [];
            $options = $fragmentConfig['options'] ?? [];

            $this->patchFragment($view, $fragment, $data, $options);
        }

        return $this;
    }

    /**
     * Render fragment and return HTML string without patching
     *
     * Extracts and renders fragment but returns the HTML as a string instead of patching
     * it into the DOM. Useful for embedding fragment content in other contexts or for
     * manual DOM manipulation.
     *
     * @param string $view Blade view containing the fragment
     * @param string $fragment Fragment name to extract and render
     * @param array<string, mixed> $data Variables to pass to fragment
     *
     * @return string Rendered HTML content of the fragment
     */
    protected function renderFragment(string $view, string $fragment, array $data = []): string
    {
        return BladeFragment::render($view, $fragment, $data);
    }

    /**
     * Configure fallback response for non-Gale requests
     *
     * Sets the response to return when the request does not include the Datastar-Request
     * header. Accepts any value that Laravel can convert to a response, including Response
     * objects, views, redirects, or closures that return these types. If not configured,
     * LogicException is thrown for non-Gale requests when toResponse() is called.
     *
     * @param mixed $response Response value or closure returning response
     *
     * @return static Returns this instance for method chaining
     */
    public function web(mixed $response): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (request()->isGale()) {
            return $this;
        }

        $this->webResponse = $response;

        return $this;
    }

    /**
     * Execute callback conditionally based on evaluated condition
     *
     * Provides Laravel-style conditional method chaining where callbacks receive this
     * instance and can add events conditionally. Condition can be boolean value or
     * callable that receives this instance and returns boolean. Supports optional
     * fallback callback for else branch.
     *
     * @param mixed $condition Boolean value or callable returning boolean
     * @param callable $callback Callback to execute if condition is truthy
     * @param callable|null $fallback Optional callback to execute if condition is falsy
     *
     * @return static Returns this instance for method chaining
     */
    public function when($condition, callable $callback, ?callable $fallback = null): self
    {
        $conditionResult = is_callable($condition) ? $condition($this) : $condition;

        if ($conditionResult) {
            $callback($this);
        } elseif ($fallback) {
            $fallback($this);
        }

        return $this;
    }

    /**
     * Execute callback when condition evaluates to false
     *
     * Inverted conditional that executes callback when condition is falsy. Equivalent
     * to when() with negated condition. Useful for clearer conditional logic in certain
     * scenarios.
     *
     * @param mixed $condition Boolean value or callable returning boolean
     * @param callable $callback Callback to execute if condition is falsy
     * @param callable|null $fallback Optional callback to execute if condition is truthy
     *
     * @return static Returns this instance for method chaining
     */
    public function unless($condition, callable $callback, ?callable $fallback = null): self
    {
        return $this->when(!$condition, $callback, $fallback);
    }

    /**
     * Execute callback only for Gale requests
     *
     * Convenience method for conditional execution based on request type. Automatically
     * detects Gale requests via Datastar-Request header and executes callback only
     * when present. Supports optional fallback for non-Gale requests.
     *
     * @param callable $callback Callback to execute for Gale requests
     * @param callable|null $fallback Optional callback for non-Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function whenGale(callable $callback, ?callable $fallback = null): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        return $this->when(request()->isGale(), $callback, $fallback);
    }

    /**
     * Execute callback only for non-Gale requests
     *
     * Inverse of whenGale() that executes callback for standard HTTP requests without
     * Datastar-Request header. Useful for providing alternate behavior for traditional
     * full-page requests.
     *
     * @param callable $callback Callback to execute for non-Gale requests
     * @param callable|null $fallback Optional callback for Gale requests
     *
     * @return static Returns this instance for method chaining
     */
    public function whenNotGale(callable $callback, ?callable $fallback = null): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        return $this->when(!request()->isGale(), $callback, $fallback);
    }

    /**
     * Delete specified state keys from client-side Alpine x-data store
     *
     * Converts state keys to deletion array with null values per RFC 7386,
     * then sends update event to remove state from client. Supports single state
     * key or array of state keys.
     *
     * @param string|array<int, string> $state State key or array of state keys to delete
     *
     * @return static Returns this instance for method chaining
     */
    protected function forgetState(string|array $state): self
    {
        $deletionArray = $this->parseStateForDeletion($state);

        return $this->patchState($deletionArray);
    }

    /**
     * Create deletion array with null values for state removal
     *
     * Transforms state keys into associative array format required for deletion,
     * where each state key maps to null value to trigger client-side removal per
     * RFC 7386 JSON Merge Patch. The 'errors' state receives special treatment and is
     * reset to an empty array instead of null, as it should always remain an array for
     * the x-message directive to function correctly.
     *
     * @param string|array<int, string> $state State key or array of state keys
     *
     * @return array<string, null|array<empty, empty>> Deletion array with state keys as keys, null or empty array as values
     */
    private function parseStateForDeletion(string|array $state): array
    {
        $stateKeys = $this->parseStateKeys($state);

        $deletionArray = [];
        foreach ($stateKeys as $stateKey) {
            // Special handling for errors state - reset to empty array instead of null
            // The errors state is used by x-message directive and should always be an array
            $deletionArray[$stateKey] = ($stateKey === 'errors') ? [] : null;
        }

        return $deletionArray;
    }

    /**
     * Extract state keys from various input formats
     *
     * Normalizes different input formats (single string, indexed array, associative array)
     * into flat array of state key strings. For associative arrays, extracts keys as
     * state keys. For indexed arrays, uses values as state keys.
     *
     * @param string|array<int|string, mixed> $state State key(s) in various formats
     *
     * @return array<int, string> Normalized array of state key strings
     */
    private function parseStateKeys(string|array $state): array
    {
        if (is_string($state)) {
            return [$state];
        }

        if (array_keys($state) !== range(0, count($state) - 1)) {
            return array_map(fn ($key) => (string) $key, array_keys($state));
        }

        /** @phpstan-ignore cast.string (State values are converted to strings for consistency) */
        return array_map(fn ($value) => (string) $value, array_values($state));
    }

    /**
     * Execute callback for Gale navigate requests matching optional key
     *
     * Conditional execution specific to navigate requests identified by Gale-Navigate
     * header. Supports filtering by navigate key for targeted updates (e.g., sidebar vs
     * main content). When first parameter is callable, treats request as "any navigate".
     *
     * @param string|array<int, string>|callable|null $key Navigate key filter, array of keys, or callback for any navigate
     * @param callable|null $callback Callback to execute when navigate request matches
     * @param callable|null $fallback Optional callback for non-matching requests
     *
     * @return static Returns this instance for method chaining
     */
    public function whenGaleNavigate(string|array|callable|null $key = null, ?callable $callback = null, ?callable $fallback = null): self
    {
        if (is_callable($key)) {
            $fallback = $callback;
            $callback = $key;
            $key = null;
        }

        /** @phpstan-ignore method.notFound (isGaleNavigate is a Request macro) */
        $isNavigateRequest = request()->isGaleNavigate($key);

        if ($isNavigateRequest && $callback) {
            $callback($this);
        } elseif (!$isNavigateRequest && $fallback) {
            $fallback($this);
        }

        return $this;
    }

    /**
     * Force immediate full-page reload breaking out of reactive mode
     *
     * Performs a complete page refresh using window.location.reload(), discarding
     * all client-side state and re-initializing the application. Used for scenarios
     * requiring complete state reset or when reactive updates are insufficient.
     *
     * @return static Returns this instance for method chaining
     */
    public function reload(): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        // Use standard JavaScript for page reload
        $script = 'window.location.reload()';

        return $this->executeScript($script, ['autoRemove' => true]);
    }

    /**
     * Generate navigation script using Alpine Gale action
     *
     * @param array<string, mixed> $options
     *
     * @phpstan-ignore method.unused (Reserved for future frontend integration)
     */
    private function generateNavigateScript(string $url, ?string $key, array $options = []): string
    {
        $safeUrl = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeKey = json_encode($key ?: 'true', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeOptions = json_encode($this->normalizeNavigationOptions($options, $url), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Call @navigate Datastar action (proper integration)
        return "@navigate({$safeUrl}, {$safeKey}, {$safeOptions})";
    }

    /**
     * Generate back navigation script using Datastar action
     *
     * @param array<string, mixed> $options
     *
     * @phpstan-ignore method.unused (Reserved for future frontend integration)
     */
    private function generateBackScript(string $fallbackUrl, string $key, array $options = []): string
    {
        $safeFallbackUrl = json_encode($fallbackUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeKey = json_encode($key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeOptions = json_encode($this->normalizeNavigationOptions($options, $fallbackUrl), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Call @back Datastar action (proper integration)
        return "@back({$safeFallbackUrl}, {$safeKey}, {$safeOptions})";
    }

    /**
     * Generate refresh script using Datastar action
     *
     * @param array<string, mixed> $options
     *
     * @phpstan-ignore method.unused (Reserved for future frontend integration)
     */
    private function generateRefreshScript(string $key, array $options = []): string
    {
        $safeKey = json_encode($key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        // For refresh, we always want to merge by default since we're refreshing current state
        $refreshOptions = ['merge' => $options['merge'] ?? true] + $options;
        $safeOptions = json_encode($this->normalizeNavigationOptions($refreshOptions, ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Call @refresh Datastar action (proper integration)
        return "@refresh({$safeKey}, {$safeOptions})";
    }

    /**
     * Normalize navigation options with SMART defaults based on URL
     *
     * @param array<string, mixed> $options Raw options from user
     * @param string $url The target URL to analyze
     *
     * @return array<string, mixed> Normalized options with smart defaults
     */
    private function normalizeNavigationOptions(array $options = [], string $url = ''): array
    {
        $merge = $options['merge'] ?? null;

        // If merge not explicitly set, use smart default based on URL
        if ($merge === null) {
            $merge = $this->shouldMergeByDefault($url);
        }

        return [
            'merge' => $merge,
            'only' => $options['only'] ?? null,
            'except' => $options['except'] ?? null,
        ];
    }

    /**
     * Determine if URL should merge by default (matches frontend logic)
     *
     * Logic:
     * a) Simple URLs without query params: /dashboard → DON'T merge
     * b) URLs with query params: /dashboard?search=john → DO merge
     * c) Query-only URLs: ?search=john → DO merge
     */
    private function shouldMergeByDefault(string $url): bool
    {
        // Query-only URLs (start with ?) should always merge
        if (str_starts_with($url, '?')) {
            return true;
        }

        // Check if URL has query parameters
        return str_contains($url, '?');
    }

    /**
     * Smart navigation based on context (convenience method)
     *
     * Automatically chooses merge behavior based on common patterns
     *
     * @param string $url Target URL
     * @param string|null $key Navigation key
     * @param string $context Context hint (auth, admin, export, etc.)
     */
    public function smartNavigate(string $url, ?string $key = null, string $context = 'default'): self
    {
        $options = $this->getSmartNavigationOptions($url, $context);

        return $this->navigate($url, $key ?? 'true', $options);
    }

    /**
     * Get smart navigation options based on context
     *
     * @return array<string, mixed>
     */
    private function getSmartNavigationOptions(string $url, string $context): array
    {
        // Auth-related routes should clear parameters
        if ($context === 'auth' || preg_match('/\/(login|logout|register|reset)/', $url)) {
            return ['merge' => false];
        }

        // Export routes might want to preserve search/filter context
        if ($context === 'export' || str_contains($url, '/export')) {
            return ['except' => ['page']]; // Remove pagination but keep filters
        }

        // Admin routes might want selective preservation
        if ($context === 'admin' || str_starts_with($url, '/admin/')) {
            return ['except' => ['user_context']];
        }

        // Error/reset scenarios should clear everything
        if ($context === 'error' || $context === 'reset') {
            return ['merge' => false];
        }

        // Default: preserve everything
        return ['merge' => true];
    }

    /**
     * Navigate to URL with explicit merge control and comprehensive options
     *
     * REPLACES THE EXISTING navigate() METHOD WITH EXPLICIT BEHAVIOR
     *
     * @param string|array<string, mixed> $url URL string or array of query parameters
     * @param string $key Navigation key for Datastar routing
     * @param array<string, mixed> $options Navigation options
     */
    public function navigate(string|array $url, string $key = 'true', array $options = []): self
    {
        /** @phpstan-ignore method.notFound (isGale is a Request macro) */
        if (!request()->isGale()) {
            return $this;
        }

        $urlManager = app(\Dancycodes\Gale\Services\GaleUrlManager::class);
        $urlManager->enforceUrlSingleUse();

        // Process input based on type - NO backend merging, let frontend handle it
        if (is_array($url)) {
            // JSON query array navigation - convert to current path with queries
            $queryString = $this->buildQueryString($url);
            $currentPath = request()->getPathInfo();
            $finalUrl = $queryString ? "{$currentPath}?{$queryString}" : $currentPath;
        } else {
            // Traditional string URL navigation - send as-is
            $finalUrl = $url;
        }

        $urlManager->validateUrl($finalUrl);

        // Generate navigation script with comprehensive options
        // Frontend @navigate action will handle all merging based on window.location
        $script = $this->generateEnhancedNavigateScript($finalUrl, $key, $options);

        return $this->executeScript($script, ['autoRemove' => true]);
    }

    /**
     * Navigate with explicit merge behavior (RECOMMENDED)
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param bool $merge Whether to merge with current query parameters
     * @param array<string, mixed> $options Additional options (only, except, replace)
     */
    public function navigateWith(string|array $url, string $key = 'true', bool $merge = false, array $options = []): self
    {
        return $this->navigate($url, $key, array_merge($options, ['merge' => $merge]));
    }

    /**
     * Navigate and merge with current parameters (preserves context)
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param array<string, mixed> $options Additional options
     */
    public function navigateMerge(string|array $url, string $key = 'true', array $options = []): self
    {
        return $this->navigateWith($url, $key, true, $options);
    }

    /**
     * Navigate with clean slate (no parameter merging)
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param array<string, mixed> $options Additional options
     */
    public function navigateClean(string|array $url, string $key = 'true', array $options = []): self
    {
        return $this->navigateWith($url, $key, false, $options);
    }

    /**
     * Navigate preserving only specific parameters
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param array<int, string> $only Parameters to preserve
     * @param string $key Navigation key
     */
    public function navigateOnly(string|array $url, array $only, string $key = 'true'): self
    {
        return $this->navigate($url, $key, ['merge' => true, 'only' => $only]);
    }

    /**
     * Navigate preserving all except specific parameters
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param array<int, string> $except Parameters to exclude
     * @param string $key Navigation key
     */
    public function navigateExcept(string|array $url, array $except, string $key = 'true'): self
    {
        return $this->navigate($url, $key, ['merge' => true, 'except' => $except]);
    }

    /**
     * Navigate using replaceState instead of pushState
     *
     * @param string|array<string, mixed> $url URL or query array
     * @param string $key Navigation key
     * @param array<string, mixed> $options Additional options
     */
    public function navigateReplace(string|array $url, string $key = 'true', array $options = []): self
    {
        return $this->navigate($url, $key, array_merge($options, ['replace' => true]));
    }

    /**
     * CONVENIENCE METHODS FOR COMMON PATTERNS
     */

    /**
     * Navigate to current page with new query parameters (maintains path)
     *
     * @param array<string, mixed> $queries Query parameters to set
     * @param string $key Navigation key
     * @param bool $merge Whether to merge with existing parameters
     */
    public function updateQueries(array $queries, string $key = 'filters', bool $merge = true): self
    {
        return $this->navigate($queries, $key, ['merge' => $merge]);
    }

    /**
     * Clear specific query parameters
     *
     * @param array<int, string> $paramNames Parameters to clear
     * @param string $key Navigation key
     */
    public function clearQueries(array $paramNames, string $key = 'clear'): self
    {
        $clearQueries = array_fill_keys($paramNames, null);

        return $this->navigate($clearQueries, $key, ['merge' => true]);
    }

    /**
     * Reset to page 1 while preserving other filters
     *
     * @param string $key Navigation key
     */
    public function resetPagination(string $key = 'pagination'): self
    {
        return $this->navigate(['page' => 1], $key, ['merge' => true]);
    }

    /**
     * INTERNAL PROCESSING METHODS
     */

    /**
     * Build query string from associative array
     *
     * @param array<string, mixed> $queries Query parameters
     *
     * @return string Query string (without ?)
     */
    private function buildQueryString(array $queries): string
    {
        $params = [];

        foreach ($queries as $key => $value) {
            if ($value === null || $value === '') {
                // Skip null/empty values - they clear parameters
                continue;
            }

            if (is_array($value)) {
                // Handle arrays (multi-select, checkboxes, etc.)
                foreach ($value as $item) {
                    if ($item !== null && $item !== '') {
                        /** @phpstan-ignore cast.string (Safe cast for URL encoding) */
                        $params[] = urlencode((string) $key) . '[]=' . urlencode((string) $item);
                    }
                }
            } else {
                // Handle scalar values
                /** @phpstan-ignore cast.string (Safe cast for URL encoding) */
                $params[] = urlencode((string) $key) . '=' . urlencode((string) $value);
            }
        }

        return implode('&', $params);
    }

    /**
     * Generate enhanced navigation script using Datastar action
     *
     * @param string $url Target URL
     * @param string $key Navigation key
     * @param array<string, mixed> $options Navigation options
     *
     * @return string JavaScript code
     */
    private function generateEnhancedNavigateScript(string $url, string $key, array $options): string
    {
        // Build options for frontend - pass ALL navigation options
        $frontendOptions = [];

        if (isset($options['merge'])) {
            $frontendOptions['merge'] = (bool) $options['merge'];
        }

        if (!empty($options['only'])) {
            $frontendOptions['only'] = $options['only'];
        }

        if (!empty($options['except'])) {
            $frontendOptions['except'] = $options['except'];
        }

        if (!empty($options['replace'])) {
            $frontendOptions['replace'] = true;
        }

        // DATASTAR-NATIVE APPROACH: Use signals to trigger navigation
        // Set a special __galeNavigate signal that the frontend watcher will detect
        // This is the proper Datastar way - reactive signal-based triggering!
        $navigationData = [
            'url' => $url,
            'key' => $key,
            'options' => $frontendOptions,
            'timestamp' => microtime(true), // Ensure uniqueness to trigger reactivity
        ];

        $safeData = json_encode($navigationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        // Dispatch a custom DOM event that our navigate watcher will listen to
        // This works in script execution context and is Datastar-compatible
        return "document.dispatchEvent(new CustomEvent('gale:navigate', { detail: {$safeData} }))";
    }

    // COMPATIBILITY METHODS - DEPRECATED BUT MAINTAINED

    /**
     * @deprecated Use navigateWith() or navigate() with explicit options
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    public function route(string $routeName, array $params = [], ?string $key = null, array $options = []): self
    {
        $url = route($routeName, $params);

        return $this->navigate($url, $key ?? 'route', $options);
    }

    /**
     * @deprecated Use navigateWith() or navigate() with explicit options
     *
     * @param array<string, mixed> $options
     */
    public function back(string $fallbackUrl = '/', string $key = 'back', array $options = []): self
    {
        // Try to get referrer, fallback to provided URL
        $backUrl = request()->headers->get('referer', $fallbackUrl);

        // Only use referrer if it's from same origin
        if ($backUrl && $backUrl !== request()->url()) {
            $referrerHost = parse_url($backUrl, PHP_URL_HOST);
            $currentHost = parse_url(request()->url(), PHP_URL_HOST);

            if ($referrerHost !== $currentHost) {
                $backUrl = $fallbackUrl;
            }
        }

        return $this->navigate($backUrl ?? $fallbackUrl, $key, array_merge(['merge' => true], $options));
    }

    /**
     * @deprecated Use updateQueries() or navigate() with query array
     *
     * @param array<string, mixed> $options
     */
    public function refresh(string $key = 'refresh', array $options = []): self
    {
        $currentUrl = request()->fullUrl();

        return $this->navigate($currentUrl, $key, array_merge(['merge' => true], $options));
    }
}
