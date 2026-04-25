# Debug & Testing Reference

Complete reference for the debug panel, error overlay, console logging, dd/dump interception, performance timing, state diffs, and request logging.

## Debug Panel

Collapsible overlay panel visible only in development mode (`APP_DEBUG=true`).

### Activation

- Server: `config('gale.debug_panel')` controls the panel. Defaults to `null`, which falls back to `config('app.debug')` (i.e., `APP_DEBUG`). Set explicitly via `GALE_DEBUG_PANEL=true` in `.env` to override.
- Client: `window.GALE_DEBUG_MODE = true` is injected by the `@gale` Blade directive when `gale.debug_panel` resolves to true (verified at `GaleServiceProvider.php:179-181`).
- In production (`APP_DEBUG=false` and `GALE_DEBUG_PANEL` unset), all debug modules are no-ops (stripped from production builds via the esbuild `strip-debug` plugin).

> **`gale.debug` is a different config key** — it controls `dd()`/`dump()` interception (handled by `GaleDumpInterceptMiddleware`), NOT the debug panel. They are independent. You can have one on and the other off.

### Toggle

- Keyboard: `Ctrl+Shift+G` (Windows/Linux) or `Cmd+Shift+G` (Mac)
- API: `Alpine.gale.debug.toggle()` / `Alpine.gale.debug.open()` / `Alpine.gale.debug.close()`
- Open/closed state and panel height are persisted in `localStorage`

### Default Tabs

| Tab | Content | Max Entries |
|-----|---------|-------------|
| Requests | URL, method, status, timing, payload size | 200 |
| State | State diffs (before/after for each patch) | 100 |
| Errors | Error type, message, URL, stack trace | 200 |

### Custom Tabs

```js
Alpine.gale.debug.registerTab('analytics', 'Analytics');
Alpine.gale.debug.pushTab('analytics', {
    timestamp: Date.now(),
    event: 'page_view',
    url: window.location.href,
});
```

### Public API

```js
Alpine.gale.debug.toggle();               // Toggle panel
Alpine.gale.debug.open();                  // Open panel
Alpine.gale.debug.close();                 // Close panel
Alpine.gale.debug.pushRequest(entry);      // Add to Requests tab
Alpine.gale.debug.pushState(entry);        // Add to State tab
Alpine.gale.debug.pushError(entry);        // Add to Errors tab
Alpine.gale.debug.registerTab(id, label);  // Register custom tab
Alpine.gale.debug.pushTab(tabId, entry);   // Push to custom tab
Alpine.gale.debug.clear();                 // Clear all entries
Alpine.gale.debug.isEnabled();             // Boolean: debug panel active
```

### Data Store

Debug data is stored in `Alpine.store('_galeDebug')` which includes tab entries, open state, active tab, and height.

## Error Overlay

Full-screen error overlay for development. Shows PHP exceptions, stack traces, and error details.

### Activation

Only active when `window.GALE_DEBUG_MODE === true` (same as debug panel).

### How It Works

1. Server exception during a Gale request emits a `gale-error` SSE event
2. Frontend error overlay renders the error with:
   - Error class and message
   - File path and line number
   - Stack trace
   - Request URL and method
3. Page layout is preserved underneath the overlay

### API

```js
Alpine.gale.showErrorOverlay({
    message: 'Something went wrong',
    file: 'app/Http/Controllers/ProductController.php',
    line: 42,
    trace: '...',
});
```

In production builds, `showErrorOverlay` is a no-op.

## Console Logging

Configurable console output for Gale operations.

### Log Levels

| Level | Output |
|-------|--------|
| `'off'` | No console output (production default) |
| `'info'` | Key events: requests, responses, state patches, errors |
| `'verbose'` | Everything: full payloads, SSE events, morph details, timing |

### Configuration

```js
// Via configure()
Alpine.gale.configure({ debug: { logLevel: 'info' } });
Alpine.gale.configure({ debug: 'verbose' });  // String shorthand

// Direct API
Alpine.gale.setLogLevel('verbose');
Alpine.gale.getLogLevel();  // 'off' | 'info' | 'verbose'
```

### Auto-Detection

If `window.GALE_DEBUG_MODE === true` (set by `@gale` when `APP_DEBUG=true`), the console logger auto-detects and defaults to `'info'` level.

## dd/dump Interception

When `config('gale.debug') = true`, `dd()` and `dump()` calls during Gale requests are intercepted.

### How It Works

1. `GaleDumpInterceptMiddleware` captures output buffer
2. In SSE mode: sends output as `gale-debug-dump` event
3. In HTTP mode: adds dump data to JSON response
4. Page layout is preserved (no white screen of death)
5. Debug panel shows the dump output

### Usage

```php
// In a controller during a Gale request
public function debug()
{
    dump($request->all());        // Captured, sent to debug panel
    dd(User::first());            // Captured, sent to debug panel
    return gale()->state('ok', true);
}
```

When `config('gale.debug') = false`, dd/dump behave normally (white screen).

### Backend Debug Method

```php
// Send debug data to the browser debug panel
gale()->debug($request->all());
gale()->debug('query result', $users->toArray());
```

No-op when `APP_DEBUG=false`.

## Performance Timing

Tracks timing for Gale operations and shows warnings when thresholds are exceeded.

### Thresholds

| Metric | Default | Warning Color |
|--------|---------|---------------|
| Response (TTFB) | 500ms | Yellow |
| DOM Morph | 100ms | Orange |
| Total Operation | 1000ms | Red |

### Configuration

```js
Alpine.gale.configure({
    debug: {
        thresholds: {
            response: 500,    // ms
            domMorph: 100,    // ms
            total: 1000,      // ms
        },
    },
});
```

### How It Works

1. Timer starts when `$action` is called
2. TTFB measured when first response byte arrives
3. DOM morph time measured during patch application
4. Total measured from action start to completion
5. Threshold violations highlighted in debug panel Requests tab

## State Diff

Tracks before/after state for every `gale-patch-state` event.

### What It Shows

| Field | Description |
|-------|-------------|
| Before | State snapshot before the patch was applied |
| After | State snapshot after the patch was applied |
| Changed Keys | List of properties that actually changed |
| Timestamp | When the patch was applied |

Entries are pushed to the debug panel State tab. Max 100 entries (configurable).

## Request Logger

Logs all Gale HTTP and SSE requests.

### What It Tracks

| Field | Description |
|-------|-------------|
| URL | Request URL |
| Method | HTTP method |
| Status | Response status code |
| Mode | 'http' or 'sse' |
| Duration | Total request time (ms) |
| Payload Size | Request body size |
| Response Size | Response body size |
| Events | Number of SSE events received |
| Timestamp | When the request was made |

Entries are pushed to the debug panel Requests tab.

## Payload Size Warning

In development mode, responses exceeding the configured threshold trigger a `console.warn`.

```js
Alpine.gale.configure({
    warnPayloadSize: 102400,  // 100KB default. 0 = warn on all.
});
```

Helps identify over-fetching or unnecessarily large responses.

## Global Error Handler

Register a callback for all Gale errors.

```js
const unregister = Alpine.gale.onError((error) => {
    // error: { type, status, message, url, recoverable, retry }
    console.error('Gale error:', error);

    // Return false to suppress default error handling
    return false;
});
```

### Per-Request Error Handler

```html
<button @click="$action('/risky', {
    onError: (error) => {
        console.log('This specific request failed:', error);
        return false; // Suppress global handler
    }
})">Try</button>
```

Per-request `onError` returning `false` suppresses the global handler.

## Error Events

### Frontend Events

| Event | Target | Detail | Description |
|-------|--------|--------|-------------|
| `gale:error` | window | `{ type, message, url }` | General Gale error |
| `gale:security-error` | window | `{ reason }` | Checksum verification failed |
| `gale:redirect-blocked` | document | `{ url, reason }` | Redirect blocked by security |
| `gale:config-changed` | document | `{ changes }` | Configuration was updated |

### SSE Error Events

| SSE Event Type | Purpose |
|----------------|---------|
| `gale-error` | Server-side error (exception) |
| `gale-debug` | Debug data from `gale()->debug()` |
| `gale-debug-dump` | Captured dd/dump output |

## Testing Patterns

### Feature Test — Gale Request

```php
it('returns gale state', function () {
    $response = $this->postJson('/increment', [], [
        'Gale-Request' => 'true',
        'Gale-Mode' => 'http',
    ]);

    $response->assertOk();
    $response->assertJsonPath('events.0.type', 'gale-patch-state');
});
```

### Feature Test — Validation

```php
it('returns validation errors for gale requests', function () {
    $response = $this->postJson('/store', ['name' => ''], [
        'Gale-Request' => 'true',
    ]);

    // ValidationException auto-converts to gale()->state('messages', [...]) (NOT errors).
    // The emitted event is gale-patch-state with the messages slot populated.
    $response->assertJsonPath('events.0.type', 'gale-patch-state');
});
```

### Feature Test — Navigate

```php
it('handles navigate requests', function () {
    $response = $this->getJson('/products', [
        'Gale-Request' => 'true',
        'GALE-NAVIGATE' => 'true',
        'GALE-NAVIGATE-KEY' => 'main',
    ]);

    $response->assertOk();
});
```

### Feature Test — Redirect

```php
it('converts redirects for gale requests', function () {
    $response = $this->postJson('/action-that-redirects', [], [
        'Gale-Request' => 'true',
    ]);

    // Standard redirect() is converted to gale redirect event
    $response->assertJsonPath('events.0.type', 'gale-redirect');
});
```

### Teardown for Test Isolation

```js
// In JavaScript tests
afterEach(() => {
    Alpine.gale.teardown();
});
```

`teardown()` removes all listeners, observers, connections, and caches, ensuring clean state between tests.
