# Backend API Reference

Complete reference for `GaleResponse`, `GaleRedirect`, `GalePushChannel`, and related backend classes.

## Artisan Commands

| Command | Purpose |
|---------|---------|
| `php artisan gale:install` | Publishes `vendor/gale/js/gale.js` + `vendor/gale/css/gale.css` to `public/`. Run after `composer require dancycodes/gale` and after every `composer update`. Use `--force` to overwrite existing files. |
| `php artisan gale:routes [--method=] [--path=] [--name=] [--controller=] [--json]` | Lists routes registered by Gale's attribute-based discovery (when `route_discovery.enabled = true`). Filter by method, path glob, name pattern, or controller class. `--json` for machine-readable output. |

## The gale() Helper

```php
function gale(): \Dancycodes\Gale\Http\GaleResponse
```

Returns the request-scoped `GaleResponse` singleton from the container. Same instance throughout a single request. Automatically resets after `toResponse()`.

## Alternative Helpers

Where `gale()` is unavailable (mailables, notifications, etc.), use these macros:

| Helper | Equivalent | Notes |
|--------|-----------|-------|
| `response()->gale()` | `gale()` | Registered as a `ResponseFactory` macro. Same scoped instance. |
| `View::renderFragment($view, $fragment, $data)` | `gale()->fragment(...)->...` | Returns rendered HTML string only — no event accumulation. Useful for embedding fragments in mail/notifications/queue jobs. |

## Request Macros

Auto-registered on every `Illuminate\Http\Request` instance:

| Macro | Returns | Description |
|-------|---------|-------------|
| `$request->isGale()` | bool | True when `Gale-Request` header is present |
| `$request->galeMode()` | string | Resolved mode: `'http'` or `'sse'` (header → config → `'http'`) |
| `$request->state(?string $key = null, $default = null)` | mixed | Read state from request JSON body. With no key, returns the full state array. With a dot-notation key (`"address.city"`), returns the nested value with `$default` fallback. |
| `$request->validateState(array $rules, array $messages = [], array $attributes = [])` | array | **Gale-native validator.** Reads state via `$request->state()`, runs Laravel validator, throws `GaleMessageException` on failure (auto-renders to `messages` state). On success, sends `gale()->state('messages', $cleared)` to clear stale messages from the validated fields and returns the validated data. Selective clearing: only the validated field keys are cleared from `messages`; other fields keep their messages. Wildcard rules like `items.*.name` clear all matching keys. |
| `$request->isGaleNavigate(string\|array\|null $key = null)` | bool | True for navigate requests. Pass a key (or array of keys) to check for a specific navigate key match. |
| `$request->galeNavigateKey()` | ?string | Raw `GALE-NAVIGATE-KEY` header value, or null when absent |
| `$request->galeNavigateKeys()` | array | Comma-split navigate keys as a trimmed array |

```php
// Use validateState for Gale-aware validation:
$validated = $request->validateState([
    'name'  => 'required|string|max:255',
    'email' => 'required|email|unique:users',
]);

// Equivalent — both work, but validateState is more idiomatic for Gale:
$validated = $request->validate([...]); // Standard Laravel — also auto-converts via GaleMessageException
```

Both `validate()` and `validateState()` route to `GaleMessageException` on failure, which renders to the **`messages` state slot** (`gale()->state('messages', [...])`). Display via `<span x-message="fieldname">` (NOT `x-message.from.errors`). See the **State Management** section below for the messages-vs-errors distinction.

## GaleResponse — Fluent Response Builder

Implements `Responsable`. Return directly from controllers: `return gale()->state(...)`.

### State Management

#### state(string|array $key, mixed $value = null, array $options = []): static

Set reactive state on the client's Alpine x-data. Uses RFC 7386 JSON Merge Patch.

```php
// Single key
gale()->state('count', 42);

// Multiple keys
gale()->state(['count' => 42, 'name' => 'John']);

// With onlyIfMissing — only sets if key doesn't exist on client
gale()->state(['defaults' => []], ['onlyIfMissing' => true]);
```

#### messages(array $messages): static

Set messages displayed by `<span x-message="fieldname">`. Convenience wrapper for `state('messages', ...)`.

```php
gale()->messages(['email' => 'Invalid email', '_success' => 'Saved!']);
```

> **This is also where `$request->validate()` and `$request->validateState()` auto-write on failure.**
> The `GaleMessageException::render()` method (at `Exceptions/GaleMessageException.php:79-81`) returns
> `gale()->state('messages', $messages)`. So **the standard validation flow ends up in `messages` state**,
> displayed via `<span x-message="email">` — NOT `<span x-message.from.errors="email">`.

#### clearMessages(): static

Clear all messages: `gale()->clearMessages()`.

#### errors(array $errors): static

Set validation errors displayed by `<span x-message.from.errors="fieldname">`. Each field maps to an array of strings. **Distinct from `messages()`** — this writes to the `errors` state slot, which auto-validation does NOT use. Reach for `errors()` only when you want to send detailed multi-error-per-field structure that the `.from.errors` modifier renders specially. See `best-practices.md` → Validation Error Hierarchy.

```php
gale()->errors(['email' => ['The email field is required.', 'Must be valid.']]);
```

> **`messages` vs `errors` — quick disambiguation:**
> - `messages` state: single string per field. Where `$request->validate()` writes. Display: `x-message="field"`.
> - `errors` state: array of strings per field. Where explicit `gale()->errors([...])` writes. Display: `x-message.from.errors="field"`.

#### clearErrors(): static

Clear all validation errors: `gale()->clearErrors()`.

#### forget(string|array|null $state): static

Delete state keys using RFC 7386 null semantics. `messages` and `errors` keys reset to `[]` instead of null.

```php
gale()->forget('tempData');
gale()->forget(['key1', 'key2']);
```

#### flash(string|array $key, mixed $value = null): static

Flash data to Laravel session AND deliver as `_flash` state in the current response.

```php
gale()->flash('success', 'Record saved!');
gale()->flash(['status' => 'updated', 'count' => 5]);
```

Frontend: `<div x-show="_flash.success" x-text="_flash.success"></div>` (add `_flash: {}` to x-data).

#### componentState(string $name, array $state, array $options = []): static

Patch state on a named component (registered via `x-component="name"`).

```php
gale()->componentState('cart', ['total' => 100, 'items' => 3]);
```

#### tagState(string $tag, array $state): static

Patch state on ALL components matching a tag (from `x-component` data-tags attribute).

```php
gale()->tagState('product-card', ['inStock' => true]);
```

#### componentMethod(string $name, string $method, array $args = []): static

Invoke a method on a named component's x-data object.

```php
gale()->componentMethod('cart', 'recalculate', [true]);
```

#### patchStore(string $storeName, array $data): static

Patch an Alpine.store() using RFC 7386 merge semantics.

```php
gale()->patchStore('cart', ['total' => 42]);
gale()->patchStore('notifications', ['unread' => 3]);
```

### DOM Manipulation

All DOM methods accept an `$options` array with these common keys:
- `selector` (string) — CSS selector (auto-set by named methods)
- `mode` (string) — morph mode (auto-set by named methods)
- `useViewTransition` (bool) — wrap in View Transitions API
- `settle` (int) — settle delay in ms for CSS transitions
- `limit` (int) — max number of targets to patch
- `scroll` (string) — auto-scroll: `'top'` or `'bottom'`
- `show` (string) — scroll into view: `'top'` or `'bottom'`
- `focusScroll` (bool) — restore focus scroll position

#### view(string $view, array $data = [], array $options = [], bool $web = false): static

Render a full Blade view and patch it into the DOM. Use `web: true` for pages needing direct URL access.

```php
// Gale-only (no direct URL)
return gale()->view('partials.counter', ['count' => $count]);

// Dual-mode: serves HTML for browsers, patches for Gale
return gale()->view('pages.dashboard', $data, [], web: true);
```

#### fragment(string $view, string $fragment, array $data = [], array $options = []): static

Render ONLY a `@fragment` section. The full view is NEVER rendered — only the fragment's Blade code is compiled.

```php
return gale()->fragment('products.index', 'product-list', [
    'products' => $products,
], ['selector' => '#product-list']);
```

**Critical**: Only pass data the fragment actually uses. No dummy data for other view variables.

#### fragments(array $fragments): static

Render multiple fragments in one response.

```php
return gale()->fragments([
    ['view' => 'dashboard', 'fragment' => 'stats', 'data' => ['stats' => $stats]],
    ['view' => 'dashboard', 'fragment' => 'chart', 'data' => ['chart' => $chart]],
]);
```

#### html(string $html, array $options = [], bool $web = false): static

Patch raw HTML into the DOM.

```php
gale()->html('<div id="alert">Success!</div>', ['selector' => '#alerts', 'mode' => 'append']);
```

#### outer(string $selector, string $html, array $options = []): static

Replace element entirely (DEFAULT mode). Server-driven state from x-data in response HTML.

```php
gale()->outer('#counter', '<div id="counter" x-data="{ count: 5 }">5</div>');
```

#### inner(string $selector, string $html, array $options = []): static

Replace inner content only. Wrapper element preserved.

#### outerMorph(string $selector, string $html, array $options = []): static

Smart morph using Alpine.morph(). Preserves client-side state (form inputs, counters, toggles).

#### innerMorph(string $selector, string $html, array $options = []): static

Smart morph children only. Wrapper element and its state preserved.

#### morph(string $selector, string $html, array $options = []): static

Alias for `outerMorph()`.

#### replace(string $selector, string $html, array $options = []): static

Alias for `outer()`.

#### append(string $selector, string $html, array $options = []): static

Append HTML as last child of matched elements.

#### prepend(string $selector, string $html, array $options = []): static

Prepend HTML as first child of matched elements.

#### before(string $selector, string $html, array $options = []): static

Insert HTML before matched elements (as sibling).

#### after(string $selector, string $html, array $options = []): static

Insert HTML after matched elements (as sibling).

#### remove(string $selector): static / delete(string $selector): static

Remove matched elements from DOM. `delete()` is an alias.

### Navigation

#### navigate(string|array $url, string $key = 'true', array $options = []): static

SPA navigation with browser history. Only ONE navigate() call per response.

```php
// String URL
gale()->navigate('/products?page=2', 'pagination');

// Array query params (path from current request)
gale()->navigate(['page' => 2, 'sort' => 'name'], 'filters');
```

Options: `merge` (bool), `only` (array), `except` (array), `replace` (bool).

#### navigateMerge / navigateClean / navigateOnly / navigateExcept / navigateReplace

Convenience wrappers around `navigate()` with pre-set options.

```php
gale()->navigateMerge(['page' => 2], 'pagination');  // merge: true
gale()->navigateClean('/products', 'nav');             // merge: false
gale()->navigateOnly('/products?a=1&b=2', ['a']);     // keep only 'a'
gale()->navigateExcept('/products?a=1&b=2', ['b']);   // drop 'b'
gale()->navigateReplace('/products', 'nav');           // replaceState
```

#### updateQueries(array $queries, string $key = 'filters', bool $merge = true): static

Update query parameters on current page.

```php
gale()->updateQueries(['search' => 'laptop', 'page' => 1]);
```

#### clearQueries(array $paramNames, string $key = 'clear'): static

Clear specific query parameters.

```php
gale()->clearQueries(['search', 'filter']);
```

#### reload(): static

Force full-page reload via `window.location.reload()`.

### Redirects

#### redirect(?string $url = null): GaleRedirect

Returns a fluent `GaleRedirect` builder. URL can be set here or via chained methods.

```php
return gale()->redirect('/dashboard');
return gale()->redirect()->route('dashboard');
return gale()->redirect()->back();
return gale()->redirect('/login')->with('message', 'Please log in');
```

### GaleRedirect Methods

| Method | Description |
|--------|-------------|
| `to(string $url)` | Set destination URL |
| `away(string $url)` | External URL (bypasses domain validation) |
| `back(string $fallback = '/')` | Previous URL with fallback |
| `home()` | Redirect to `/` |
| `route(string $name, array $params = [])` | Named route |
| `intended(string $default = '/')` | Auth intended URL from session |
| `refresh(bool $query = true, bool $fragment = false)` | Reload current URL |
| `backOr(string $routeName, array $params = [])` | Back with route fallback |
| `with(string\|array $key, $value = null)` | Flash data to session |
| `withInput(?array $input = null)` | Flash form input |
| `withErrors(mixed $errors)` | Flash validation errors |
| `forceReload(bool $bypass = false)` | JS window.location.reload() |

### Streaming

#### stream(Closure $callback): static

Enables SSE streaming for long-running operations. Events sent immediately as methods are called.

```php
return gale()->stream(function ($gale) {
    $gale->state('status', 'Processing...');

    foreach ($items as $i => $item) {
        processItem($item);
        $gale->state('progress', ($i + 1) / count($items) * 100);
    }

    $gale->state('status', 'Complete!');
});
```

Rules:
- `stream()` ALWAYS uses SSE regardless of config
- Session is closed before streaming begins
- `redirect()` calls inside stream() work via `GaleStreamRedirector`
- Exceptions emit `gale-error` SSE event (page layout preserved)
- `dd()`/`dump()` output captured and sent as debug overlay (when `gale.debug` is true)
- NEVER `echo` directly — use `$gale->state()` etc.

### File Downloads

#### download(string $pathOrContent, string $filename, ?string $mime = null, bool $isContent = false): static

Trigger browser download without navigation. Uses signed temporary tokens to a Gale-managed serve route.

```php
// From file path
gale()->download(storage_path('reports/q1.pdf'), 'Q1-Report.pdf');

// From dynamic content
gale()->download($csvContent, 'export.csv', 'text/csv', isContent: true);

// Chainable
gale()->download($path, 'report.pdf')->state('lastExport', now()->toIso8601String());
```

**How it works** (see `GaleResponse.php:1729-1772` and `GaleServiceProvider.php:838-849`):

1. The package auto-registers `GET /gale/download/{token}` as a named route (`gale.download.serve`).
2. `download()` writes a temporary file to the system temp dir (or leaves the original alone for path inputs), generates an HMAC-signed token, and emits a `gale-download` event containing the signed URL.
3. The frontend receives the event, creates a hidden anchor, clicks it. The browser hits `/gale/download/{token}`, which verifies the token signature, streams the bytes, and cleans up the temp file.
4. The serve route is registered with the `web` middleware group but **without `ValidateCsrfToken`** — the signed token is the auth.

The token expires after a short window. Use this for files that may live outside `public/`, behind auth gates, or computed on the fly.

### Push Channels

#### push(string $channel): GalePushChannel

Returns a `GalePushChannel` for server-initiated push to subscribed clients.

```php
// In any code (controller, job, event listener, etc.)
gale()->push('notifications')
    ->patchState(['count' => 5])
    ->send();

gale()->push('dashboard')
    ->patchElements('#stats', $html)
    ->patchComponent('chart', ['data' => $chartData])
    ->send();
```

**GalePushChannel methods**: `patchState()`, `patchElements()`, `patchComponent()`, `send()`.

Always call `->send()` to flush events to the channel queue.

### Script Execution

#### js(string $script, array $options = []): static

Execute JavaScript in the browser. Uses `gale-execute-script` event (bypasses XSS sanitizer).

```php
gale()->js('alert("Hello!")');
gale()->js('initMap()', ['nonce' => $nonce, 'autoRemove' => false]);
```

### Event Dispatching

#### dispatch(string $eventName, array $data = [], ?string $target = null): static

Dispatch a CustomEvent. Default target is `window`. With `$target`, dispatches on first matching element.

```php
gale()->dispatch('show-toast', ['message' => 'Saved!']);
gale()->dispatch('refresh', [], '#sidebar');
```

Alpine listener: `@show-toast.window="handle($event.detail)"`.

### Debug

#### debug(mixed $data): static / debug(string $label, mixed $data): static

Send debug data to browser debug panel. No-op when `APP_DEBUG=false`.

```php
gale()->debug($request->all());
gale()->debug('query result', $users->toArray());
```

### Conditional Chaining

```php
gale()
    ->when($isAdmin, fn($g) => $g->state('admin', true))
    ->unless($isGuest, fn($g) => $g->state('role', 'user'))
    ->whenGale(fn($g) => $g->state('reactive', true))
    ->whenNotGale(fn($g) => $g->web(view('static')))
    ->whenGaleNavigate('sidebar', fn($g) => $g->fragment(...));
```

### Response Configuration

#### web(mixed $response): static

Set fallback for non-Gale requests. Without this, non-Gale requests get 204 No Content.

```php
gale()->web(view('page'))->state('count', 5);
```

#### withHeaders(array $headers): static

Add HTTP headers to the response.

```php
gale()->withHeaders(['Cache-Control' => 'max-age=3600']);
```

#### etag(): static

Enable ETag conditional response. Returns 304 if content unchanged. Never applied to SSE.

#### withEventId(string $id): static

Set the SSE `id:` field on emitted events. Browsers automatically send `Last-Event-Id` on reconnection, allowing the server to resume from a known point. Use for long-running streams where exact replay matters (chat history, audit logs).

```php
return gale()->stream(function ($gale) {
    foreach ($events as $event) {
        $gale->withEventId((string) $event->id)->state('latest', $event->payload);
    }
});
```

#### withRetry(int $ms): static

Set the SSE `retry:` directive (in milliseconds). Tells the browser how long to wait before reconnecting after the connection drops. Default behavior is browser-defined (typically ~3000ms).

```php
return gale()->withRetry(5000)->stream(function ($gale) {
    // browser will retry after 5s if disconnected
});
```

Both methods affect SSE responses only.

#### forceHttp(): static

Force HTTP/JSON mode. Used internally for validation error responses.

### Lifecycle Hooks (Static)

```php
// In AppServiceProvider::boot()
GaleResponse::beforeRequest(function (Request $request) {
    logger('Gale request: ' . $request->path());
});

GaleResponse::afterResponse(function ($response, Request $request) {
    $response->headers->set('X-Debug-Time', microtime(true));
    return $response;
});
```

### Macros

```php
// Register
GaleResponse::macro('toast', function (string $msg, string $type = 'success') {
    return $this->dispatch('show-toast', ['message' => $msg, 'type' => $type]);
});

// Use
gale()->toast('Saved!');
```

Macro names cannot conflict with existing GaleResponse methods (throws `RuntimeException`).

## Mode Resolution

| Priority | Source | Example |
|----------|--------|---------|
| 1 (highest) | `stream()` | Always SSE |
| 2 | `forceHttp()` | Always HTTP (validation errors) |
| 3 | `Gale-Mode` header | `Gale-Mode: sse` |
| 4 | `config('gale.mode')` | `GALE_MODE=sse` in .env |
| 5 (lowest) | Built-in default | `'http'` |

## SSE Event Types

| Event | Purpose |
|-------|---------|
| `gale-patch-state` | Merge state into Alpine component |
| `gale-patch-elements` | DOM manipulation (9 modes) |
| `gale-patch-component` | Target named component state |
| `gale-patch-store` | Patch Alpine.store() |
| `gale-invoke-method` | Call method on named component |
| `gale-execute-script` | Execute JavaScript |
| `gale-dispatch` | Dispatch CustomEvent |
| `gale-redirect` | Full-page redirect |
| `gale-download` | Trigger file download |
| `gale-error` | Error notification |
| `gale-debug` | Debug panel data |
| `gale-debug-dump` | dd/dump output for overlay |

## HTTP JSON Response Format

```json
{
  "events": [
    { "type": "gale-patch-state", "data": { "count": 42, "_checksum": "abc123" } },
    { "type": "gale-patch-elements", "data": { "html": "<div>...</div>", "selector": "#target", "mode": "outer" } },
    { "type": "gale-dispatch", "data": { "event": "saved", "data": {}, "target": null } }
  ]
}
```
