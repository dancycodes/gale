# Debug & Troubleshooting

> **See also:** [Backend API Reference](backend-api.md) | [Core Concepts](core-concepts.md) | [Frontend API Reference](frontend-api.md)

When things go wrong in a server-driven reactive system, the problem could be on the server (PHP), in transit (HTTP/SSE), or on the client (Alpine/JS). This guide shows you how to locate and fix issues across the entire stack using Gale's built-in debug tooling.

---

## Table of Contents

- [Debugging Checklist](#debugging-checklist)
- [Debug Panel](#debug-panel)
  - [Enabling the Debug Panel](#enabling-the-debug-panel)
  - [Disabling the Debug Panel](#disabling-the-debug-panel)
  - [Panel Not Showing?](#panel-not-showing)
  - [Panel Tabs](#panel-tabs)
  - [Managing Panel Data](#managing-panel-data)
- [Console Logging](#console-logging)
  - [Log Levels](#log-levels)
  - [Setting the Log Level](#setting-the-log-level)
  - [What Each Level Logs](#what-each-level-logs)
- [Server-Side Debug Helper](#server-side-debug-helper)
  - [Basic Usage](#basic-usage)
  - [Labeled Debug Messages](#labeled-debug-messages)
  - [Supported Data Types](#supported-data-types)
  - [Production Safety](#production-safety)
- [Error Overlay](#error-overlay)
  - [What Triggers the Overlay](#what-triggers-the-overlay)
  - [Dismissing the Overlay](#dismissing-the-overlay)
  - [Disabling the Error Overlay](#disabling-the-error-overlay)
- [Browser DevTools](#browser-devtools)
  - [Network Tab — HTTP Mode](#network-tab--http-mode)
  - [Network Tab — SSE Mode](#network-tab--sse-mode)
  - [Console Tab](#console-tab)
  - [Elements Panel](#elements-panel)
- [Performance Diagnostics](#performance-diagnostics)
  - [Timing Panel](#timing-panel)
  - [Response Size Analysis](#response-size-analysis)
  - [Morph Frequency](#morph-frequency)
  - [Memory Usage Patterns](#memory-usage-patterns)
  - [Configuring Warning Thresholds](#configuring-warning-thresholds)
- [Mode-Specific Debugging](#mode-specific-debugging)
  - [HTTP Mode Issues](#http-mode-issues)
  - [SSE Mode Issues](#sse-mode-issues)
- [Server Infrastructure](#server-infrastructure)
  - [Nginx — SSE Buffering](#nginx--sse-buffering)
  - [Apache — SSE Buffering](#apache--sse-buffering)
  - [Load Balancer Timeouts](#load-balancer-timeouts)
  - [PHP-FPM for Long-Running SSE](#php-fpm-for-long-running-sse)
  - [Content Security Policy Setup](#content-security-policy-setup)
- [Common Issues](#common-issues)
- [Reporting Bugs](#reporting-bugs)

---

## Debugging Checklist

Follow this checklist for any Gale issue. It narrows the problem location systematically.

**Step 1: Confirm Gale is loaded**
- Open browser console. Is there a `[Gale]` log line or no JS errors?
- Is `@gale` in the `<head>` of your layout (not `<body>`, not in a partial)?

**Step 2: Confirm the request is sent**
- Open DevTools Network tab. Does a request appear when you click the action?
- If no request: missing `x-data` on the component, or missing `@click`.

**Step 3: Inspect the server response**
- In the Network tab, click the request. Check the response body.
- In HTTP mode: the response should be `Content-Type: application/json` with an `events` array.
- In SSE mode: `Content-Type: text/event-stream` with `event:` / `data:` lines.

**Step 4: Confirm the controller returns gale()**
- Every Gale controller MUST return `gale()->...`. A bare `view()`, `response()`, or `redirect()` will not trigger reactive updates.

**Step 5: Check state key names**
- `patchState(['count' => 1])` — the key `count` must match the `x-data` property name exactly.
- Open the Debug Panel > State tab to see exactly what the server sent.

**Step 6: Check for client-side errors**
- Open the Debug Panel > Errors tab, or check the browser console for `[Gale]` error messages.
- The Error Overlay appears automatically for unhandled server errors (5xx) when `APP_DEBUG=true`.

**Step 7: Narrow to server, transport, or client**
- Server problem: the response body contains wrong data, a PHP error, or an exception page.
- Transport problem: SSE buffering, proxy timeout, content-type mismatch.
- Client problem: state key mismatch, Alpine expression error, morph ID collision.

---

## Debug Panel

### Enabling the Debug Panel

The debug panel is a floating overlay in the bottom-right corner of the browser. It is active only in development (`APP_DEBUG=true`).

Enable it in your config:

```php
// config/gale.php
return [
    'debug' => env('GALE_DEBUG', false),
];
```

```env
# .env
GALE_DEBUG=true
```

After changing `.env`, run `php artisan config:clear` if you use a config cache.

> **Note:** `GALE_DEBUG` controls the Gale debug panel. `APP_DEBUG` controls PHP error display and also governs whether `gale()->debug()` emits data. Both should be `true` in development.

Toggle the panel open and closed with **Ctrl+Shift+G** (Windows/Linux) or **Cmd+Shift+G** (Mac). The panel remembers its open/closed state and height between page loads via `localStorage`.

### Disabling the Debug Panel

Set `GALE_DEBUG=false` in `.env`. The panel is not injected into the page at all — no DOM, no JS overhead.

In production, the panel is automatically disabled regardless of config values. The GaleServiceProvider only injects the debug script when `APP_DEBUG=true`.

### Panel Not Showing?

If the panel does not appear after setting `GALE_DEBUG=true`:

1. **Clear the config cache:** `php artisan config:clear`
2. **Republish Gale assets:** `php artisan vendor:publish --tag=gale-assets --force`
3. **Rebuild JS:** `npm run build` (or `npm run dev`)
4. **Check `@gale` placement:** It must be in `<head>`, before `</head>`. If it is in `<body>`, the debug script may load too late.
5. **Check `window.GALE_DEBUG_MODE`:** Open the browser console and type `window.GALE_DEBUG_MODE`. It should be `true`. If it is `false` or `undefined`, the PHP config is not reaching the page.
6. **Hard refresh:** Ctrl+Shift+R (Windows) / Cmd+Shift+R (Mac) to bypass the browser cache.

### Panel Tabs

The debug panel has three built-in tabs:

**Requests tab**

Shows every Gale request as it completes. Each entry displays:
- HTTP method and URL
- Response status code
- Total duration (from request start to state applied)
- Mode badge (HTTP or SSE)
- Expandable payload and response body

Click any entry to expand it and see the full request/response detail.

**State tab**

Shows a diff of Alpine component state after every server-initiated patch. Each entry shows:
- Which state keys changed (yellow), were added (green), or were removed (red)
- The before and after values for changed keys
- The URL that triggered the state change

This tab is the fastest way to confirm the server sent the correct data and identify key name mismatches.

> **Tip:** If the State tab shows the correct data arriving but the UI is not updating, the problem is in the Alpine template — check `x-text`, `x-show`, or `x-model` expressions, not the server response.

**Errors tab**

Logs all Gale errors with their type, status, and context. Errors dismissed from the Error Overlay still appear here for review.

### Managing Panel Data

Clear all logged entries with `Alpine.gale.debug.clear()` in the browser console.

The panel stores up to 100 entries per tab before auto-clearing (FIFO). If you need to see older entries during a long session, call `clear()` to reset and capture a fresh batch.

---

## Console Logging

Gale logs to the browser console at configurable verbosity levels. All entries are prefixed with `[Gale]` for easy filtering. Use the Console tab filter: type `[Gale]` to isolate Gale output.

### Log Levels

Three levels are available:

| Level | What It Logs |
|-------|-------------|
| `off` | Nothing. Default in production (`APP_DEBUG=false`). |
| `info` | Request/response summaries, navigation events, errors with context. |
| `verbose` | Everything at `info` plus state diffs, DOM morph details, SSE event parsing, and event dispatches. |

The default in development (`APP_DEBUG=true`) is `info`.

### Setting the Log Level

**Before Alpine initializes** (in your layout `<head>`):

```html
<script>
window.GALE_DEBUG = 'verbose';
</script>
```

**At runtime** (browser console or application JS):

```javascript
Alpine.gale.configure({
    debug: { logLevel: 'verbose' }
});
```

**To silence all output** (temporarily in development):

```javascript
Alpine.gale.configure({ debug: { logLevel: 'off' } });
```

> **Note:** The `window.GALE_DEBUG` global is checked once at Alpine initialization time. For runtime changes after Alpine has started, use `Alpine.gale.configure()`.

### What Each Level Logs

**`info` level logs:**
- Every completed request: `[Gale] POST /increment -> 200 (45ms)`
- Every failed request with status and message
- Navigation events: `[Gale] Navigate: /dashboard`

**`verbose` level additionally logs:**
- State diffs: `[Gale] State diff (/increment): 2 changes` (expanded with `~ count: 0 -> 1`)
- DOM morphs: `[Gale] Morph: #result-list [inner] (3 changed, 1 added)`
- Event dispatches: `[Gale] Dispatch: gale:after-response`
- SSE event parsing: `[Gale] SSE event: gale-patch-state`

All entries use `console.groupCollapsed()` for structured, non-intrusive output. Click the collapsed group arrow to see the detail.

---

## Server-Side Debug Helper

`gale()->debug()` sends data from your Laravel controller to the browser's Debug Panel "Server Debug" custom tab.

### Basic Usage

```php
use App\Http\Responses\GaleResponse;
use Illuminate\Http\Request;

public function process(Request $request): GaleResponse
{
    $result = $this->calculate($request->input('value'));

    return gale()
        ->debug($result)          // sends $result to the debug panel
        ->patchState(['output' => $result->formatted()]);
}
```

### Labeled Debug Messages

Use the two-argument form to attach a label that appears in the debug panel:

```php
return gale()
    ->debug('before validation', $request->all())
    ->debug('computed result', $result->toArray())
    ->patchState(['output' => $result->formatted()]);
```

Multiple `debug()` calls in a single request are all collected in order and appear as separate entries in the panel.

### Supported Data Types

`gale()->debug()` accepts any PHP value:

| Type | Behavior |
|------|----------|
| Scalars (string, int, float, bool, null) | Passed through directly |
| Arrays | Sent as JSON |
| Objects with `toArray()` or `JsonSerializable` | Auto-serialized |
| Eloquent models | Serialized via `toArray()` (loaded relationships included) |
| Closures / resources | Converted to string representation |
| Circular references | Truncated with `[Circular]` marker |
| Very large data (>100KB JSON) | Truncated with a warning |

**In SSE streaming mode**, each `debug()` call emits a `gale-debug` SSE event at the exact point it is called — useful for debugging the state at specific points in a long-running stream:

```php
return gale()->stream(function () {
    gale()->debug('start', ['phase' => 'processing']);
    $result = $this->expensiveOperation();
    gale()->debug('done', $result->toArray());
    gale()->patchState(['result' => $result->formatted()]);
});
```

### Production Safety

`gale()->debug()` is a **no-op** in production. It checks `config('app.debug')` — when `false`, the method returns immediately without collecting or emitting any data. You can safely leave `debug()` calls in your code without risk of data leakage in production.

> **Warning:** Do not pass sensitive data (passwords, tokens, PII) to `gale()->debug()` even with this safety guarantee. Use it for debugging intermediate state, not logging sensitive inputs.

---

## Error Overlay

The Error Overlay is a full-screen modal that appears in development when a Gale request fails with a server error (5xx) or when a client-side JavaScript error occurs during a Gale operation.

### What Triggers the Overlay

- **Server errors** (5xx) — red overlay with HTTP status, message, request context, and response body
- **CSRF token expired** (419) — red overlay with a "reload the page" instruction
- **Client-side JS errors** during Gale operations (morph failure, parse error) — orange overlay with the JS error message and stack trace

**Does NOT trigger the overlay:**
- 422 validation errors (shown via `x-message` instead)
- 401/403 authorization errors (handled via `gale:error` events)
- 429 rate limit responses (handled via `gale:error` events)

### Dismissing the Overlay

- Click the **X** button in the top-right corner
- Press **Escape**
- Click the dark backdrop outside the panel
- Click the **Dismiss** button in the footer

If multiple errors occurred before you dismissed, the next queued error appears automatically.

Dismissed errors remain in the Debug Panel > Errors tab for later review.

### Disabling the Error Overlay

The overlay is automatically disabled in production (`APP_DEBUG=false`). If you want to suppress it in development without disabling the debug panel entirely, disable it in your Gale config:

```php
// config/gale.php
return [
    'debug' => env('GALE_DEBUG', false),
    'error_overlay' => env('GALE_ERROR_OVERLAY', true),
];
```

```env
GALE_ERROR_OVERLAY=false
```

---

## Browser DevTools

### Network Tab — HTTP Mode

In HTTP mode (default), Gale requests appear as regular `fetch()` calls:

1. Open DevTools > Network tab
2. Filter by **Fetch/XHR**
3. Trigger a Gale action
4. Find the request to your controller URL
5. **Headers tab:** Confirm `Content-Type: application/json` in the response headers, and `Gale-Request: true` in the request headers
6. **Response tab:** You should see a JSON object:

```json
{
  "events": [
    { "type": "gale-patch-state", "data": { "updates": { "count": 1 } } }
  ]
}
```

If the response is HTML instead of JSON, the controller is not returning `gale()->...`.

### Network Tab — SSE Mode

In SSE mode (opt-in), Gale requests appear as EventStream connections:

1. Open DevTools > Network tab
2. Filter by **Other** (or all request types)
3. Trigger an SSE action (`$action('/url', {sse: true})`)
4. Find the request. It should have `Content-Type: text/event-stream` in the response headers
5. **EventStream tab (Chrome):** Shows each SSE event as it arrives — `event`, `data`, `id` fields

If the EventStream tab is missing or the request shows `Content-Type: application/json` instead, the server is not responding in SSE mode. Check that the controller calls `gale()->stream()` or that the request was sent with `Gale-Mode: sse`.

### Console Tab

Filter the console by `[Gale]` to see Gale-specific output. At `info` level, every request appears as a one-line summary:

```
[Gale] POST /increment -> 200 (45ms)
[Gale] Navigate: /dashboard
```

At `verbose` level, expand the collapsed groups to see state diffs and morph details.

**Gale lifecycle events** are also available via `document.addEventListener`:

```javascript
document.addEventListener('gale:before-request', (e) => {
    console.log('Request to:', e.detail.url);
});

document.addEventListener('gale:after-response', (e) => {
    console.log('Response status:', e.detail.status);
});

document.addEventListener('gale:error', (e) => {
    console.log('Error:', e.detail.type, e.detail.status, e.detail.message);
});
```

### Elements Panel

Use the Elements panel to track DOM morphs:

1. Right-click an element and select **Inspect**
2. Trigger a Gale action
3. Watch the element in the Elements panel — morphed nodes flash briefly when updated

> **Tip:** In `verbose` mode, the browser console shows morph details: `[Gale] Morph: #my-list [inner] (2 changed)`. Use this to find unexpected morph operations.

---

## Performance Diagnostics

### Timing Panel

The Debug Panel > Requests tab shows detailed timing for each request:

| Phase | What it measures |
|-------|-----------------|
| Request to TTFB | Time from request start to first byte received (headers) |
| TTFB to response end | Time to read the full response body |
| State patch | Time to apply RFC 7386 JSON Merge Patch to Alpine state |
| DOM morph | Time to morph/update DOM elements |
| Total | End-to-end from request start to state applied |

Warning badges appear on entries that exceed thresholds:
- **Yellow:** Response time (TTFB) > 500ms
- **Orange:** DOM morph > 100ms
- **Red:** Total operation > 1000ms

### Response Size Analysis

Large JSON responses slow down parsing. To investigate:

1. Open DevTools > Network tab
2. Click a Gale request, open **Response tab**
3. Check the response body size (shown in the Network tab columns)

If responses are large:
- Only return state keys that actually changed, not the full component state
- Use `gale()->fragment('section-id')` to return only the changed HTML fragment instead of a full re-render
- Use `gale()->patchState([...])` with only the keys that need updating

### Morph Frequency

Excessive DOM morphs degrade performance. Use `verbose` console logging to count morph operations per request:

```javascript
Alpine.gale.configure({ debug: { logLevel: 'verbose' } });
```

Then trigger your action and count `[Gale] Morph:` lines in the console. Each line represents one `patchElements()` call. If you see many morphs per request, consolidate your `patchElements()` calls server-side.

### Memory Usage Patterns

Watch for memory growth over time in long-running pages with frequent polling:

1. Open DevTools > Memory tab
2. Take a baseline heap snapshot
3. Run 50-100 polling cycles
4. Take another snapshot
5. Compare — look for growing Alpine component counts or detached DOM nodes

If you find memory growth:
- Check that polling intervals are properly cleaned up when the component is removed (`x-interval` handles cleanup automatically)
- Ensure morph lifecycle hooks registered via `Alpine.gale.onMorph()` are cleaned up
- Check for event listeners added in `x-init` that are not removed in Alpine's `cleanup()` callback

### Configuring Warning Thresholds

Adjust timing warning thresholds for your application's expected performance profile:

```javascript
Alpine.gale.configure({
    debug: {
        thresholds: {
            response: 300,    // ms — yellow warning (default: 500)
            domMorph: 50,     // ms — orange warning (default: 100)
            total: 800,       // ms — red warning (default: 1000)
        }
    }
});
```

---

## Mode-Specific Debugging

### HTTP Mode Issues

**Symptom: Response is HTML instead of JSON**

Cause: The controller is not returning `gale()->...`.

```php
// Wrong — returns HTML
return view('my-view', $data);

// Correct — returns Gale JSON
return gale()->view('my-view', $data);
```

**Symptom: JSON parse error in console**

Cause: The server returned non-JSON content (e.g. a PHP error page, redirect, or plain text).

- Check the Network tab response body for the raw content
- If it is an HTML error page, enable `APP_DEBUG=true` to see the full PHP exception
- If it is a redirect, check that `GaleRedirect` middleware is registered in `bootstrap/app.php`

**Symptom: `Content-Type: text/html` in Gale request response**

Cause: A middleware or exception handler intercepted the request before Gale could respond.

- Check `bootstrap/app.php` middleware registration order
- Ensure `HandleGaleRequests` middleware runs before your custom middleware

**Symptom: Action responds correctly once, then stops**

Cause: CSRF token expired. The Gale CSRF system automatically refreshes tokens, but if refresh fails (419 response), all subsequent requests are blocked.

- Check the Network tab for a 419 response
- Reload the page to get a fresh CSRF token
- Check that your session is not expiring prematurely

### SSE Mode Issues

**Symptom: SSE action fires once, then connection closes**

SSE is opt-in per-request: `$action('/url', {sse: true})`. Each call opens a new SSE connection, streams events, and closes. This is normal behavior — SSE mode is not a persistent connection.

For persistent server push, use `$action('/channel', {sse: true})` with a long-running controller that loops and flushes events.

**Symptom: EventStream appears in Network tab but no events arrive**

Cause: Nginx or another reverse proxy is buffering the SSE response.

Solution: See [Nginx — SSE Buffering](#nginx--sse-buffering) below.

**Symptom: SSE events arrive only in batches (delayed)**

Cause: PHP output buffering is enabled.

In your controller, explicitly flush the buffer before streaming:

```php
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
```

Or use `gale()->stream()` which handles this automatically.

**Symptom: SSE connection drops every 30 seconds**

Cause: Load balancer or proxy timeout. See [Load Balancer Timeouts](#load-balancer-timeouts).

**Symptom: `gale-patch-state` events received but state does not update**

Cause: Component name mismatch when using `gale()->patchComponent('name', [...])`.

- Check the `x-component` attribute value on the target element
- Check the string passed to `patchComponent()` — they must match exactly
- Open the Debug Panel > State tab to confirm the event arrived with the correct data

**Symptom: SSE stream shows 422 but form is filled correctly**

Cause: In SSE mode, validation errors must be returned with `forceHttp()` so the JS 422 handler receives `Content-Type: application/json`.

```php
// In bootstrap/app.php renderable or controller exception handler:
gale()->messages($errors)->errors($allErrors)->forceHttp();
```

---

## Server Infrastructure

### Nginx — SSE Buffering

Nginx buffers proxy responses by default, which blocks SSE events from reaching the browser.

Add these directives to your Nginx server block or location block:

```nginx
location / {
    proxy_pass http://your-backend;
    proxy_buffering off;
    proxy_cache off;
    proxy_set_header X-Accel-Buffering no;
    proxy_set_header Connection '';
    proxy_http_version 1.1;
    chunked_transfer_encoding on;
}
```

Alternatively, add the `X-Accel-Buffering: no` response header from PHP for SSE responses only:

```php
// In GaleResponse or your SSE controller
response()->header('X-Accel-Buffering', 'no');
```

Gale's `gale()->stream()` sets this header automatically.

### Apache — SSE Buffering

In Apache with `mod_proxy`, disable buffering:

```apache
<Location />
    ProxyPass http://your-backend/
    ProxyPassReverse http://your-backend/
    SetEnv proxy-nokeepalive 1
    SetEnv proxy-initial-not-pooled 1
</Location>
```

If using PHP-FPM via `mod_proxy_fcgi`:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
</FilesMatch>
FcgidOutputBufferSize 0
```

### Load Balancer Timeouts

Most load balancers have a default idle timeout (60–300 seconds) that kills long-running SSE connections.

**AWS ALB:** Set the idle timeout on the target group to 3600 seconds (or higher for very long streams).

**HAProxy:**

```
defaults
    timeout tunnel 1h
```

**Nginx as load balancer:**

```nginx
upstream backend {
    server 127.0.0.1:8000;
    keepalive 32;
}

server {
    location / {
        proxy_pass http://backend;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

**Laravel Herd (local development):** No timeout configuration needed — Herd handles SSE correctly out of the box.

### PHP-FPM for Long-Running SSE

PHP-FPM has a default request timeout that terminates long-running SSE connections.

```ini
# /etc/php/8.4/fpm/pool.d/www.conf
request_terminate_timeout = 3600
```

Also adjust PHP's own execution time limit:

```php
// At the top of your SSE controller or in gale()->stream() callback
set_time_limit(3600);
```

Or globally in `php.ini`:

```ini
max_execution_time = 3600
```

> **Note:** `gale()->stream()` automatically calls `set_time_limit(0)` for the duration of the stream callback.

### Content Security Policy Setup

If your application uses a `Content-Security-Policy` header, the Gale JS must be allowed:

**Inline scripts** (used by `@gale` directive for configuration):

```
Content-Security-Policy: script-src 'self' 'nonce-{NONCE}';
```

Pass the nonce to the `@gale` directive:

```blade
@gale(['nonce' => $nonce])
```

**EventSource connections** (SSE) — no extra CSP directive needed if your API is same-origin.

**Connect-src for cross-origin SSE:**

```
Content-Security-Policy: connect-src 'self' https://your-api.example.com;
```

**Style-src for debug panel** (development only):

The debug panel uses inline styles via `style` attributes. If you have a strict `style-src`, allow `'unsafe-inline'` in development, or use a nonce:

```
Content-Security-Policy: style-src 'self' 'unsafe-inline';
```

> **Tip:** Use environment-specific CSP headers — strict in production, relaxed in development. The debug panel is only active in development, so `'unsafe-inline'` in `style-src` is safe to add only to your dev environment CSP.

---

## Common Issues

The table below covers the most frequently encountered problems, organized by the symptom you see.

| # | Symptom | Category | Cause | Solution |
|---|---------|----------|-------|----------|
| 1 | **Nothing happens when I click an action button** | State | Missing `x-data` on the component or button is outside any `x-data` element | Wrap the button in a `<div x-data="{ ... }">` — all Gale magics (`$action`, `$post`, etc.) require an Alpine component context |
| 2 | **The page does not react to server responses** | State | Controller returning `view()` instead of `gale()->view()` | Change all controller return statements to `return gale()->view('view-name', $data);` |
| 3 | **State does not update after a request** | State | Key name mismatch between `patchState()` and `x-data` property | Open Debug Panel > State tab — the diff shows exactly what keys the server sent. Verify they match your `x-data` property names exactly (case-sensitive) |
| 4 | **Validation errors do not appear** | Form | `messages` key missing from `x-data`, or `x-message` field name does not match the validation key | Add `messages: {}` to `x-data` and ensure `x-message="email"` matches `'email'` in your validation rules |
| 5 | **File uploads return 422 validation errors** | Form | Using `$post` instead of `$postx` for file uploads | Use `$postx('/upload')` for requests that include files — `$postx` uses `FormData` and sets `Content-Type: multipart/form-data` |
| 6 | **Navigation creates a full page reload instead of SPA** | Navigation | Missing `x-navigate` on the link or container, or the navigation target controller returns `view()` | Add `x-navigate` to the `<a>` or a wrapping container, and ensure the target controller returns `gale()->view()` |
| 7 | **SSE stream receives no events** | SSE | Nginx or proxy buffering SSE responses | Set `proxy_buffering off;` in Nginx config or add `X-Accel-Buffering: no` response header. See [Nginx — SSE Buffering](#nginx--sse-buffering) |
| 8 | **Alpine expressions throw `ReferenceError`** | State | A state key set to `null` by the server (RFC 7386 delete) is referenced directly in an expression | Use `$data.keyName ?? ''` instead of bare `keyName` in expressions — `$data` is always defined even after a key is deleted |
| 9 | **`$action` does nothing, no network request** | State | Button is not inside an `x-data` component, or there is a JavaScript error earlier on the page that stopped Alpine from initializing | Check the console for errors. Confirm `window.Alpine` is defined. Confirm the button's closest ancestor with `x-data` is present |
| 10 | **Debug panel shows correct state but UI is wrong** | State | Alpine template expression error — the data arrived correctly but the template cannot display it | Check `x-text`, `x-show`, `x-model` expressions for typos or null access errors. Use `$data.key ?? ''` pattern for optional values |
| 11 | **SSE connection drops and never reconnects** | SSE | Load balancer or proxy timeout killing the connection | Increase idle timeout on your load balancer or proxy. See [Load Balancer Timeouts](#load-balancer-timeouts) |
| 12 | **`gale()->debug()` entries do not appear in panel** | Debug | `APP_DEBUG` is false, or `GALE_DEBUG` is false | Confirm both `APP_DEBUG=true` and `GALE_DEBUG=true` in `.env`. `gale()->debug()` requires `app.debug=true`; the panel requires `gale.debug=true` |
| 13 | **CSRF token error (419) on every request** | Security | Session expired or CSRF cookie not sent | Reload the page. Ensure your frontend sends the CSRF cookie (Gale does this automatically via the CSRF module). Check that `session.lifetime` in `config/session.php` is long enough |
| 14 | **Console log spam — too verbose** | Debug | Log level set to `verbose` | Set log level to `info`: `Alpine.gale.configure({ debug: { logLevel: 'info' } })` in the browser console |
| 15 | **Error overlay blocks the UI and keeps appearing** | Debug | Multiple unhandled server errors queued | Dismiss each overlay by pressing Escape. Then fix the underlying server error shown in the overlay. To temporarily disable: set `GALE_ERROR_OVERLAY=false` in `.env` |

---

## Reporting Bugs

If you encounter a bug not covered here:

1. Enable the debug panel (`GALE_DEBUG=true`) and reproduce the issue
2. Capture the Request and Errors tabs from the debug panel
3. Note the browser console output (set `verbose` log level first)
4. Open an issue at [github.com/dancycodes/gale](https://github.com/dancycodes/gale) with:
   - Gale version (`composer show dancycodes/gale`)
   - Laravel version (`php artisan --version`)
   - PHP version (`php --version`)
   - Alpine Gale version
   - Steps to reproduce
   - Debug panel output (Request + State + Errors tabs)
   - Browser console output

---

## Next Steps

- [Backend API Reference](backend-api.md) — complete `gale()` method reference including all `debug()` options
- [Core Concepts](core-concepts.md) — understand the request/response flow and dual-mode architecture
- [Frontend API Reference](frontend-api.md) — all Alpine magics, directives, and `Alpine.gale.configure()` options
