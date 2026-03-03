# Backend API Reference

> **See also:** [Core Concepts](core-concepts.md) | [Frontend API Reference](frontend-api.md)

Complete PHP API reference for the Gale package. Covers the `gale()` helper, every public method on `GaleResponse`, `GaleRedirect`, the fragment system, Blade directives, request macros, route discovery attributes, and all configuration keys.

---

## Table of Contents

- [The `gale()` Helper](#the-gale-helper)
- [Response Building](#response-building)
  - [View & HTML](#view--html)
  - [State Management](#state-management)
  - [DOM Patching](#dom-patching)
  - [Events & JavaScript](#events--javascript)
  - [Navigation](#navigation)
  - [Redirects](#redirects)
  - [Downloads](#downloads)
  - [Streaming (SSE)](#streaming-sse)
  - [Debug](#debug)
  - [Conditionals & Flow Control](#conditionals--flow-control)
  - [Hooks & Pipeline](#hooks--pipeline)
  - [Response Finalization](#response-finalization)
- [Fragment System](#fragment-system)
- [Blade Directives](#blade-directives)
- [Request Macros](#request-macros)
- [GaleRedirect](#galeredirect)
- [Route Discovery Attributes](#route-discovery-attributes)
- [Middleware Aliases](#middleware-aliases)
- [Configuration Reference](#configuration-reference)
- [Artisan Commands](#artisan-commands)

---

## The `gale()` Helper

```php
gale(): \Dancycodes\Gale\Http\GaleResponse
```

Returns the **request-scoped** `GaleResponse` singleton from the Laravel container. The same instance is returned across multiple calls within a single request, so events accumulate correctly. The instance is automatically reset at the start of each new request.

**Singleton behavior:** Because `gale()` uses `app()->scoped()`, calling `gale()` five times in one controller returns the exact same object every time. Events added in one call are present when you call `toResponse()`.

```php
// All three calls operate on the same instance
gale()->state(['step' => 1]);
gale()->messages(['email' => 'Invalid']);
return gale()->fragment('checkout', 'step-form', $data);
```

**Container alias:** The same instance is also available as `app('gale.response')` and via the `Response::gale()` macro.

---

## Response Building

All fluent methods return `static` (the same `GaleResponse` instance) unless otherwise noted, allowing method chaining. Methods that return a non-`GaleResponse` value are marked accordingly.

### View & HTML

#### `view(string $view, array $data = [], array $options = [], bool $web = false): static`

Renders a full Blade view and sends its HTML as a `gale-patch-elements` event.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$view` | `string` | Blade view name in dot notation |
| `$data` | `array` | Variables to pass to the view |
| `$options` | `array` | DOM patching options (see [DOM Patching](#dom-patching)) |
| `$web` | `bool` | When `true`, also sets this view as the fallback for non-Gale requests |

```php
// Gale request: renders and patches the view into the DOM
// Non-Gale request: returns a 204 (no content)
return gale()->view('dashboard', ['stats' => $stats]);

// With web fallback for direct URL access
return gale()->view('dashboard', ['stats' => $stats], web: true);
```

> **Note:** Always use `gale()->view()` instead of returning `view()` directly from a Gale controller. Bare `view()` does not send Gale events.

---

#### `fragment(string $view, string $fragment, array $data = [], array $options = []): static`

Extracts and renders a named `@fragment` block from a Blade view. **Only the fragment is compiled — the full view is never rendered.** This is the correct method for granular UI updates.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$view` | `string` | Blade view name in dot notation |
| `$fragment` | `string` | Fragment name (the ID passed to `@fragment`) |
| `$data` | `array` | Variables needed by the fragment only |
| `$options` | `array` | DOM patching options |

```php
// In the Blade view (resources/views/tasks/list.blade.php):
// @fragment('task-list')
// <ul id="task-list"> ... </ul>
// @endfragment

// In the controller — only the fragment is compiled:
return gale()->fragment('tasks.list', 'task-list', ['tasks' => $tasks]);
```

> **How pre-rendering extraction works:** Gale reads the raw template file, uses a regex to locate the `@fragment('task-list')` ... `@endfragment` block, extracts just that text, and passes it to `Blade::render()`. The surrounding view template is never compiled. This means you only pass data that the fragment actually needs — no dummy values for variables used elsewhere in the view.

---

#### `fragments(array $fragments): static`

Renders and patches multiple fragments in a single response.

```php
return gale()->fragments([
    ['view' => 'tasks.list', 'fragment' => 'task-list', 'data' => ['tasks' => $tasks]],
    ['view' => 'tasks.list', 'fragment' => 'task-count', 'data' => ['count' => $count]],
]);
```

---

#### `html(string $html, array $options = [], bool $web = false): static`

Patches raw HTML string into the DOM without view compilation.

```php
return gale()->html('<li id="new-item">New task</li>', ['selector' => '#task-list', 'mode' => 'append']);
```

---

### State Management

#### `state(string|array $key, mixed $value = null, array $options = []): static`

Updates Alpine component state via RFC 7386 JSON Merge Patch. Accepts a key-value pair or an associative array.

```php
// Single key
gale()->state('count', 42);

// Multiple keys at once
gale()->state(['count' => 42, 'loading' => false, 'name' => 'Alice']);

// Chainable
return gale()->state('step', 2)->state('error', null);
```

**RFC 7386 semantics:** `null` values delete the key on the client. Missing keys are left unchanged. Nested objects are merged shallowly (top-level keys only).

---

#### `messages(array $messages): static`

Sets the `messages` state key — the reactive message store read by `x-message` directives.

```php
gale()->messages(['email' => 'This email is already taken.', 'name' => 'Name is required.']);
```

---

#### `clearMessages(): static`

Resets `messages` to an empty array.

```php
return gale()->clearMessages()->state('success', true);
```

---

#### `errors(array $errors): static`

Sets the `errors` state key with Laravel-style field-error arrays. Each field maps to an array of error strings.

```php
gale()->errors(['email' => ['The email field is required.', 'Must be a valid email.']]);
```

**Frontend display:**
```html
<p x-message.from.errors="email" class="text-red-600 text-sm"></p>
```

---

#### `clearErrors(): static`

Resets `errors` to an empty array.

---

#### `flash(string|array $key, mixed $value = null): static`

Delivers flash data to **both** the Laravel session (for the next request) and the frontend `_flash` state key (for the current response). Multiple calls accumulate into one `_flash` state patch.

```php
// Key-value form
gale()->flash('success', 'Record saved!');

// Array form
gale()->flash(['success' => 'Saved', 'count' => 5]);
```

**Frontend display (add `_flash: {}` to `x-data`):**
```html
<div x-show="_flash.success" x-text="_flash.success"></div>
```

---

#### `forget(string|array $state): static`

Removes one or more state keys from the client-side Alpine component.

```php
gale()->forget('tempData');
gale()->forget(['tempData', 'draft']);
```

> **Note:** `messages` and `errors` are reset to `[]` instead of `null` when forgotten, because `x-message` expects an array.

---

#### `patchStore(string $storeName, array $data): static`

Patches an Alpine global store (`Alpine.store()`) using RFC 7386 Merge Patch semantics. The store must be registered on the frontend before calling this.

```php
return gale()->patchStore('cart', ['total' => 149.99, 'itemCount' => 3]);
```

---

### DOM Patching

All DOM patching methods use the `gale-patch-elements` SSE event. The `$options` array supports:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `selector` | `string` | — | CSS selector targeting elements in the DOM |
| `mode` | `string` | `'outer'` | Patching mode (see modes below) |
| `useViewTransition` | `bool` | `false` | Wrap the DOM update in a View Transition |
| `settle` | `int` | — | Milliseconds to wait before applying classes |
| `scroll` | `string` | — | CSS selector to scroll into view after patch |
| `show` | `string` | — | CSS selector to show after patch |
| `focusScroll` | `bool` | `false` | Scroll focused element into view |
| `limit` | `int` | — | Max number of elements to patch |

**Patching modes:**

| Mode | Description |
|------|-------------|
| `outer` | Replace the entire matched element (default) |
| `inner` | Replace only the children of the matched element |
| `append` | Insert HTML as the last child |
| `prepend` | Insert HTML as the first child |
| `before` | Insert HTML as a sibling before the element |
| `after` | Insert HTML as a sibling after the element |
| `remove` | Remove the matched element |
| `outerMorph` | Smart diff using `Alpine.morph()` — preserves client state |
| `innerMorph` | Smart diff children using `Alpine.morph()` |

#### DOM Patching Convenience Methods

All methods are chainable and return `static`.

```php
// Replace the outer HTML (default)
gale()->outer('#task-42', '<li id="task-42">Updated</li>');

// Alias for outer()
gale()->replace('#task-42', $html);

// Replace inner HTML only
gale()->inner('#task-list', $listItemsHtml);

// Insert as last child
gale()->append('#task-list', '<li>New item</li>');

// Insert as first child
gale()->prepend('#task-list', '<li>First item</li>');

// Insert before the element
gale()->before('#task-42', '<li>Inserted before</li>');

// Insert after the element
gale()->after('#task-42', '<li>Inserted after</li>');

// Remove the element
gale()->remove('#task-42');

// Alias for remove()
gale()->delete('#task-42');

// Smart morph — preserves Alpine state in matched elements
gale()->outerMorph('#task-list', $newListHtml);
gale()->innerMorph('#task-list', $newChildrenHtml);

// Alias for outerMorph() (v1 compatibility)
gale()->morph('#task-list', $newListHtml);
```

---

### Events & JavaScript

#### `dispatch(string $eventName, array $data = [], ?string $target = null): static`

Dispatches an Alpine-compatible `CustomEvent` on `window` (default) or on a specific element.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$eventName` | `string` | Event name (kebab-case recommended) |
| `$data` | `array` | Event payload accessible via `$event.detail` |
| `$target` | `string\|null` | CSS selector for targeted dispatch; `null` = window |

```php
// Dispatch on window
gale()->dispatch('show-toast', ['message' => 'Saved!', 'type' => 'success']);

// Dispatch on a specific element
gale()->dispatch('refresh', [], '#sidebar');
```

**Alpine listener:**
```html
<!-- Window event -->
<div x-on:show-toast.window="showToast($event.detail)"></div>

<!-- Element-specific -->
<div id="sidebar" x-on:refresh="loadItems()"></div>
```

---

#### `js(string $script, array $options = []): static`

Executes arbitrary JavaScript in the browser.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$script` | `string` | JavaScript code to execute |
| `$options` | `array` | Options: `autoRemove` (bool, default `true`), `nonce` (string for CSP) |

```php
gale()->js("document.title = 'Updated'");
gale()->js("console.log('debug')", ['autoRemove' => false]);
```

> **Warning:** Use `dispatch()` for Alpine communication. Use `js()` only for direct DOM operations that have no Gale equivalent.

---

#### `componentState(string $componentName, array $state, array $options = []): static`

Patches the Alpine state of a named component registered with `x-component`.

```php
gale()->componentState('cart', ['total' => 149.99, 'itemCount' => 3]);
```

---

#### `tagState(string $tag, array $state): static`

Patches the state of all components sharing a tag.

```php
gale()->tagState('product-card', ['inStock' => false]);
```

---

#### `componentMethod(string $componentName, string $method, array $args = []): static`

Invokes a method on a named component's Alpine `x-data` object.

```php
gale()->componentMethod('modal', 'open', ['title' => 'Confirm Delete']);
```

---

### Navigation

#### `navigate(string|array $url, string $key = 'true', array $options = []): static`

Triggers SPA navigation via the Gale navigate system. Does NOT perform a full-page redirect.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | `string\|array` | URL string, or query parameter array (applied to current path) |
| `$key` | `string` | Navigate key sent in `GALE-NAVIGATE-KEY` header |
| `$options` | `array` | Options: `merge` (bool), `only` (array), `except` (array), `replace` (bool) |

```php
// Navigate to a URL
gale()->navigate('/dashboard');

// Navigate with query parameters
gale()->navigate(['page' => 2, 'sort' => 'name']);
```

#### Navigate Convenience Methods

```php
// Navigate and merge with current query parameters
gale()->navigateMerge(['page' => 2]);

// Navigate without merging (clean slate)
gale()->navigateClean('/search?q=test');

// Navigate with merging but preserve only specific params
gale()->navigateOnly('/search', ['q']);

// Navigate with merging but exclude specific params
gale()->navigateExcept('/search', ['page']);

// Navigate using replaceState instead of pushState (no history entry)
gale()->navigateReplace('/search');

// Navigate using replaceState and merge with current params
gale()->navigateMerge(['sort' => 'name'], options: ['replace' => true]);
```

#### `navigateReplace(string|array $url, string $key = 'true', array $options = []): static`

Navigate using `replaceState` instead of `pushState` (no history entry). The current URL in the browser's history is replaced without adding a new entry.

```php
gale()->navigateReplace('/search?q=test');
```

---

#### `updateQueries(array $queries, string $key = 'filters', bool $merge = true): static`

Navigate to the current page with new query parameters. Convenience wrapper for `navigate()` that always targets the current path.

```php
// Update filters without changing the page
gale()->updateQueries(['status' => 'active', 'page' => 1]);
```

---

#### `clearQueries(array $paramNames, string $key = 'clear'): static`

Remove specific query parameters from the current URL by navigating with null values for those params.

```php
// Remove the 'search' and 'filter' params from the URL
gale()->clearQueries(['search', 'filter']);
```

---

#### `reload(): static`

Forces a full-page reload via `window.location.reload()`.

```php
return gale()->reload();
```

---

### Redirects

#### `redirect(?string $url = null): GaleRedirect`

Returns a `GaleRedirect` builder for full-page browser redirects. For Gale requests, the redirect is performed via `window.location.href`. For non-Gale requests, a standard HTTP redirect is returned.

**Returns** `GaleRedirect` (not chainable with `GaleResponse` — see [GaleRedirect](#galeredirect)).

```php
// Direct URL
return gale()->redirect('/dashboard');

// Using builder methods
return gale()->redirect()->to('/dashboard');
return gale()->redirect()->route('home');
return gale()->redirect()->back();
return gale()->redirect()->intended('/fallback');
return gale()->redirect()->home();

// With session flash
return gale()->redirect('/dashboard')->with('success', 'Profile updated!');
```

> **Auto-conversion:** In `bootstrap/app.php`, Gale registers a `renderable` for `Illuminate\Http\RedirectResponse` that auto-converts bare `redirect()` calls to `gale()->redirect()` for Gale requests. You can use Laravel's standard `redirect()` helper and it will work reactively.

---

### Downloads

#### `download(string $pathOrContent, string $filename, ?string $mimeType = null, bool $isContent = false): static`

Triggers a file download without navigating away from the page.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$pathOrContent` | `string` | Absolute path to file on disk, or raw content string |
| `$filename` | `string` | Download filename shown to the user |
| `$mimeType` | `string\|null` | MIME type (auto-detected from extension when `null`) |
| `$isContent` | `bool` | `true` when first argument is raw content, not a path |

```php
// File on disk
return gale()->download(storage_path('exports/report.pdf'), 'monthly-report.pdf');

// Dynamic content
return gale()->download($csvContent, 'export.csv', 'text/csv', isContent: true);

// Chainable — download AND update state
return gale()->download($path, 'report.pdf')->state(['lastExport' => now()->toISOString()]);
```

> **How it works:** Gale stores the file in the cache with a signed token and returns a signed URL. The client fetches the URL via an invisible link and the file is served by `GaleDownloadServeController`. The page does not navigate.

---

### Streaming (SSE)

#### `stream(Closure $callback): static`

Switches the response to SSE mode and executes the callback in streaming context. Events sent inside the callback are flushed to the client immediately. Always uses `text/event-stream` regardless of `config('gale.mode')`.

```php
return gale()->stream(function ($gale) {
    foreach ($items as $item) {
        $gale->state(['current' => $item->id, 'progress' => $item->progress]);
        usleep(200_000); // 200ms between events
    }
    $gale->state(['done' => true]);
});
```

> **Always SSE:** `stream()` ignores `config('gale.mode')` and the `Gale-Mode` header. It always returns `text/event-stream`.

> **dd() and dump():** Inside `stream()`, `dd()` and `dump()` output is captured and rendered as a full-page document replacement in the browser, matching the behavior of standard Gale requests.

> **Exceptions:** Exceptions thrown inside the `stream()` callback emit a `gale-error` SSE event without replacing the page layout.

---

#### `withEventId(string $id): static`

Sets the SSE event ID for replay support (sent in `id:` lines). The browser sends this in `Last-Event-ID` when reconnecting.

```php
gale()->withEventId('event-' . $latestId);
```

---

#### `withRetry(int $milliseconds): static`

Sets the SSE reconnection delay in milliseconds (default is 1000ms per the SSE spec).

```php
gale()->withRetry(3000); // Reconnect after 3 seconds
```

---

#### `push(string $channel): GalePushChannel`

Returns a `GalePushChannel` broadcaster for server push to a named channel. **Returns** `GalePushChannel` (not chainable with `GaleResponse`).

```php
gale()->push('notifications')->patchState(['count' => 5])->send();
gale()->push('dashboard')->patchElements('#stats', $html)->send();
```

---

### Debug

#### `debug(mixed $labelOrData = null, mixed $data = null): static`

Sends data to the Gale Debug Panel's "Server Debug" tab. **No-op in production** (`APP_DEBUG=false`).

```php
// One-argument form — label defaults to "debug"
gale()->debug($request->all());

// Two-argument form — custom label
gale()->debug('before validation', $request->all());
gale()->debug('user model', $user);
```

Supported data types: scalars, arrays, `Arrayable`, `JsonSerializable`, Eloquent models (via `toArray()`), Closures (as `'[Closure]'`), resources (as `'[Resource: type]'`), circular references (as `'[Circular]'`).

---

#### `forceHttp(): static`

Forces HTTP/JSON mode (`application/json`) regardless of the `Gale-Mode` header or `config('gale.mode')`. Used by the validation exception renderer to ensure validation errors return as JSON even when the request was sent in SSE mode.

```php
// In bootstrap/app.php renderable:
return gale()->messages($errors)->errors($allErrors)->forceHttp();
```

---

### Conditionals & Flow Control

#### `when(mixed $condition, callable $callback, ?callable $fallback = null): static`

Executes `$callback` when `$condition` is truthy. `$condition` may be a boolean or a callable receiving `$this`.

```php
return gale()
    ->when($user->isAdmin(), fn($g) => $g->state(['adminPanel' => true]))
    ->when(fn($g) => $count > 0, fn($g) => $g->state(['hasItems' => true]));
```

If the callback returns a `GaleRedirect`, it is stored and executed when `toResponse()` is called.

---

#### `unless(mixed $condition, callable $callback, ?callable $fallback = null): static`

Inverted `when()` — executes `$callback` when `$condition` is falsy.

---

#### `whenGale(callable $callback, ?callable $fallback = null): static`

Executes `$callback` only for Gale requests (requests with `Gale-Request` header).

```php
return gale()->whenGale(
    fn($g) => $g->state(['reactive' => true]),
    fn($g) => $g->web(view('dashboard'))
);
```

---

#### `whenNotGale(callable $callback, ?callable $fallback = null): static`

Executes `$callback` only for non-Gale requests.

---

#### `whenGaleNavigate(string|array|callable|null $key = null, ?callable $callback = null, ?callable $fallback = null): static`

Executes `$callback` when the request is a Gale navigate request. Optionally checks for a specific navigate key.

```php
return gale()->whenGaleNavigate('sidebar', fn($g) => $g->fragment('layout', 'sidebar', $data));
```

---

#### `web(mixed $response): static`

Sets the fallback response for non-Gale requests. This is only used when `toResponse()` is called on a non-Gale request and no web fallback was set via `view($view, $data, web: true)`.

```php
return gale()
    ->state(['count' => $count])
    ->web(view('counter', ['count' => $count]));
```

---

### Hooks & Pipeline

#### `GaleResponse::beforeRequest(Closure $hook): void` (static)

Registers a before-hook that runs before every Gale controller action.

```php
// In AppServiceProvider::boot():
GaleResponse::beforeRequest(function (\Illuminate\Http\Request $request) {
    Log::info('Gale request: ' . $request->path());
});
```

---

#### `GaleResponse::afterResponse(Closure $hook): void` (static)

Registers an after-hook that runs after the `GaleResponse` is built but before it is sent. The hook may return a replacement response or `null` to keep the original.

```php
GaleResponse::afterResponse(function (mixed $response, \Illuminate\Http\Request $request) {
    $response->headers->set('X-Gale-Timing', microtime(true));
    return $response;
});
```

---

#### `GaleResponse::clearHooks(): void` (static)

Clears all registered before and after hooks. Primarily used in tests.

---

#### `withHeaders(array $headers): static`

Adds extra HTTP headers to the final response (both HTTP and SSE modes).

```php
gale()->withHeaders(['Gale-Cache-Bust' => 'true']);
```

---

#### `etag(): static`

Enables ETag-based conditional responses for this endpoint. When the client sends a matching `If-None-Match` header, returns `304 Not Modified`. Not applied to SSE streaming responses.

```php
return gale()->etag()->fragment('products', 'list', $data);
```

---

### Response Finalization

#### `toResponse($request = null): JsonResponse|StreamedResponse`

Converts the accumulated events to an HTTP response. Called automatically when you `return` a `GaleResponse` from a controller (via the `Responsable` interface).

**Mode resolution priority (highest to lowest):**
1. `stream()` callback presence — always SSE
2. `Gale-Mode` request header — per-request override
3. `config('gale.mode')` — server-side default

For non-Gale requests: returns the web fallback if set, otherwise `204 No Content`.

---

#### `toJson(): array`

Returns the accumulated events as a PHP array (`{ events: [...] }`). Useful for testing or custom response building.

---

#### `toJsonString(): string`

Returns the accumulated events as a JSON string.

---

#### `reset(): void`

Clears all accumulated state on the instance. Called automatically in `toResponse()` to prepare the scoped singleton for the next request.

---

## Fragment System

Fragments allow you to mark sections of a Blade view for individual extraction and re-rendering.

### Defining Fragments in Blade

```blade
{{-- resources/views/tasks/list.blade.php --}}

{{-- Non-fragment content (header, nav) is never compiled when fetching a fragment --}}
<div class="page-header">
    <h1>Tasks</h1>
</div>

@fragment('task-list')
<ul id="task-list">
    @foreach ($tasks as $task)
        <li id="task-{{ $task->id }}">{{ $task->name }}</li>
    @endforeach
</ul>
@endfragment

@fragment('task-count')
<span id="task-count">{{ $tasks->count() }} tasks</span>
@endfragment
```

### Using Fragments in Controllers

```php
// Re-render only the task list
return gale()->fragment('tasks.list', 'task-list', ['tasks' => $tasks]);

// Re-render two fragments in one response
return gale()->fragments([
    ['view' => 'tasks.list', 'fragment' => 'task-list', 'data' => ['tasks' => $tasks]],
    ['view' => 'tasks.list', 'fragment' => 'task-count', 'data' => ['tasks' => $tasks]],
]);
```

### Pre-Rendering Extraction

When you call `gale()->fragment('tasks.list', 'task-list', $data)`, Gale:

1. Resolves the absolute path to `resources/views/tasks/list.blade.php`
2. Reads the raw template text from disk (no PHP execution)
3. Uses a regex to locate `@fragment('task-list')` ... `@endfragment`
4. Extracts only that block of text
5. Passes it to `Blade::render($fragmentText, $data)` for compilation
6. Returns the rendered HTML

The outer view (header, nav, other fragments) is **never compiled**. You only pass data that the fragment actually uses.

### `View::renderFragment(string $view, string $fragment, array $data = []): string`

The fragment system is also available as a View facade macro for use outside of Gale responses:

```php
$html = View::renderFragment('tasks.list', 'task-list', ['tasks' => $tasks]);
```

---

## Blade Directives

### `@gale`

Place in the `<head>` section. Outputs:
- `<meta name="csrf-token">` for CSRF protection
- The Gale JS bundle (`<script type="module" src="...gale.js">`)
- The Gale CSS stylesheet
- Configuration globals (`GALE_DEBUG_MODE`, `GALE_SANITIZE_HTML`, `GALE_ALLOW_SCRIPTS`, `GALE_REDIRECT_CONFIG`)

**Replaces** any Alpine.js CDN `<script>` tag — do not include both.

```blade
<head>
    <title>My App</title>
    @gale
</head>
```

**With CSP nonce:**
```blade
@gale(['nonce' => $nonce])
```

The nonce is applied to all `<script>` tags output by `@gale` and to any `<script>` tags created by `gale()->js()` calls.

---

### `@fragment('name')` / `@endfragment`

Marks a named section for extraction by `gale()->fragment()`. These directives compile to empty strings — they are purely markers for the fragment parser.

```blade
@fragment('user-card')
<div id="user-card">
    {{ $user->name }}
</div>
@endfragment
```

Fragments can be nested, but each fragment name must be unique within a view.

---

### `@ifgale` / `@elsegale` / `@endifgale`

Conditionally renders content based on whether the current request is a Gale request.

```blade
@ifgale
    {{-- Only rendered for Gale requests --}}
    <div x-data="{ reactive: true }">...</div>
@elsegale
    {{-- Only rendered for standard HTTP requests --}}
    <noscript>Enable JavaScript for the best experience.</noscript>
@endifgale
```

---

### `@galeState($data)`

Injects initial state as a `window.galeState` global. Deprecated — prefer `x-data` with inline data instead.

---

## Request Macros

Gale extends Laravel's `Request` object with the following macros:

#### `$request->isGale(): bool`

Returns `true` when the request has the `Gale-Request` header. This is the canonical check for whether a request is a reactive Gale request.

```php
if ($request->isGale()) {
    return gale()->state(['result' => $result]);
}
return view('result', ['result' => $result]);
```

---

#### `$request->galeMode(): string`

Returns the resolved response mode (`'http'` or `'sse'`) for the current request. Reads the `Gale-Mode` header first, then falls back to `config('gale.mode')`.

---

#### `$request->state(?string $key = null, mixed $default = null): mixed`

Reads state values from the Gale request JSON body. Call without arguments to get all state.

```php
$allState = $request->state();
$count = $request->state('count', 0);
$nested = $request->state('user.name');
```

---

#### `$request->isGaleNavigate(string|array|null $key = null): bool`

Returns `true` when the request is a Gale navigate request (has `GALE-NAVIGATE` header). Optionally checks for a specific navigate key.

```php
if ($request->isGaleNavigate()) {
    // Any navigate request
}
if ($request->isGaleNavigate('sidebar')) {
    // Navigate request with key 'sidebar'
}
if ($request->isGaleNavigate(['sidebar', 'content'])) {
    // Navigate request with either key
}
```

---

#### `$request->galeNavigateKey(): ?string`

Returns the navigate key string from the `GALE-NAVIGATE-KEY` header, or `null`.

---

#### `$request->galeNavigateKeys(): array`

Returns navigate keys as an array (comma-separated keys from the header).

---

#### `$request->validateState(array $rules, array $messages = [], array $attributes = []): array`

Validates Alpine state from the request body using Gale's reactive validation. On failure, throws `GaleMessageException` which auto-sends validation messages to the frontend. On success, clears message fields that were validated.

```php
$validated = $request->validateState([
    'email' => 'required|email',
    'name' => 'required|string|max:255',
]);
```

> **Tip:** Prefer standard `$request->validate()` which is auto-converted by Gale's `ValidationException` renderer in `bootstrap/app.php`. Use `validateState()` for manual control of message clearing.

---

## GaleRedirect

`GaleRedirect` is returned by `gale()->redirect()`. It provides a fluent API for full-page browser redirects. For Gale requests, the redirect is performed by executing `window.location.href` in the browser. For non-Gale requests, a standard Laravel redirect response is returned.

> **Security:** All redirect URLs are validated server-side against the `gale.redirect` config before being sent to the browser.

### Methods

All methods return `static` (the `GaleRedirect` instance) unless noted.

#### `to(string $url): static`

Sets the redirect URL. Equivalent to `gale()->redirect('/path')`.

```php
return gale()->redirect()->to('/dashboard');
```

---

#### `away(string $url): static`

Sets the redirect URL for an external domain (bypasses domain-check logic of `back()`/`intended()`).

```php
return gale()->redirect()->away('https://stripe.com/checkout');
```

---

#### `route(string $routeName, array $parameters = [], bool $absolute = true): static`

Sets the redirect URL using a named route.

```php
return gale()->redirect()->route('profile.show', ['user' => $user->id]);
```

---

#### `back(string $fallback = '/'): static`

Sets the redirect URL to the previous URL with same-domain validation. Falls back to `$fallback` if the previous URL is external or unavailable.

---

#### `home(): static`

Sets the redirect URL to the application root (`url('/')`).

---

#### `intended(string $default = '/'): static`

Sets the redirect URL to the session-stored intended URL (from auth middleware), with same-domain validation. Falls back to `$default`.

---

#### `backOr(string $routeName, array $routeParameters = []): static`

Redirects back if a previous URL is available, otherwise redirects to the named route.

---

#### `refresh(bool $preserveQuery = true, bool $preserveFragment = false): static`

Sets the redirect URL to the current URL (page refresh).

---

#### `with(string|array $key, mixed $value = null): static`

Adds session flash data for the next request.

```php
return gale()->redirect()->route('dashboard')->with('success', 'Profile updated!');
return gale()->redirect()->back()->with(['status' => 'saved', 'count' => $count]);
```

---

#### `withInput(?array $input = null): static`

Flashes the current request input to session (for repopulating forms).

---

#### `withErrors(mixed $errors): static`

Flashes validation errors to session under the `errors` key.

---

#### `forceReload(bool $forceReload = false): Response`

**Returns** a final `Response` (not chainable). Executes `window.location.reload()` in the browser. When `$forceReload = true`, bypasses the browser cache.

---

#### `toResponse($request = null): Response`

**Returns** a final `Response`. Validates the URL, flashes session data, and emits the redirect. Called automatically when you `return` a `GaleRedirect` from a controller.

---

### Auto-Conversion of `redirect()`

When Laravel's `redirect()` helper is used in a Gale request, Gale's middleware in `bootstrap/app.php` automatically converts the `RedirectResponse` to a `gale()->redirect()`. This means existing Laravel redirect patterns work reactively without changes:

```php
// This works in Gale requests — auto-converted to reactive redirect:
return redirect()->route('dashboard')->with('success', 'Done!');
```

---

## Route Discovery Attributes

Gale provides PHP 8 attributes for declarative route registration. To enable discovery, set `gale.route_discovery.enabled = true` in `config/gale.php` and add directories to `discover_controllers_in_directory`.

### `#[Route]`

Defines route parameters on a controller class or method.

```php
use Dancycodes\Gale\Routing\Attributes\Route;

#[Route(method: 'GET', uri: '/tasks', name: 'tasks.index')]
public function index(): mixed
{
    return gale()->view('tasks.index', ['tasks' => Task::all()], web: true);
}

// POST route
#[Route(method: 'POST', name: 'tasks.store')]
public function store(Request $request): mixed
{
    // ...
}

// Multiple HTTP methods
#[Route(method: ['GET', 'HEAD'], uri: '/tasks/{task}')]
public function show(Task $task): mixed
{
    // ...
}
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `method` | `array\|string` | `[]` | HTTP methods (GET, POST, PUT, PATCH, DELETE, etc.) |
| `uri` | `?string` | `null` | Custom URI; `null` = auto-generated |
| `fullUri` | `?string` | `null` | Complete URI override bypassing transformers |
| `name` | `?string` | `null` | Route name |
| `middleware` | `array\|string` | `[]` | Middleware to apply |
| `domain` | `?string` | `null` | Domain constraint |
| `withTrashed` | `bool` | `false` | Include soft-deleted models in route model binding |

---

### `#[Prefix]`

Applies a URI prefix to all routes in a controller class. Cannot be combined with `#[Group]`.

```php
use Dancycodes\Gale\Routing\Attributes\Prefix;

#[Prefix('/admin/users')]
class UserController
{
    public function index(): mixed { /* GET /admin/users */ }
    public function show(User $user): mixed { /* GET /admin/users/{user} */ }
}
```

---

### `#[Group]`

Combines prefix, middleware, name prefix, and domain settings in a single attribute. Cannot be combined with `#[Prefix]`.

```php
use Dancycodes\Gale\Routing\Attributes\Group;

#[Group(prefix: '/admin', middleware: ['auth'], as: 'admin.')]
class AdminController
{
    public function index(): mixed { /* GET /admin, name: admin.index */ }
}

// All parameters are optional:
#[Group(prefix: '/api/v1', middleware: ['auth:sanctum', 'throttle:60,1'], as: 'api.v1.')]
class ApiController {}
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `prefix` | `?string` | `null` | URI prefix |
| `middleware` | `array\|string` | `[]` | Middleware for all routes |
| `as` | `?string` | `null` | Route name prefix |
| `domain` | `?string` | `null` | Domain constraint |

---

### `#[Middleware]`

Applies middleware to a controller class (all routes) or a specific method. Repeatable — multiple `#[Middleware]` attributes stack.

```php
use Dancycodes\Gale\Routing\Attributes\Middleware;

#[Middleware('auth')]                    // All routes
#[Middleware('verified')]                // All routes (stacked)
class ProfileController
{
    #[Middleware('can:edit,profile')]    // This method only
    public function edit(): mixed {}
}
```

Multiple middleware in one attribute:
```php
#[Middleware('auth', 'verified')]
```

---

### `#[RateLimit]`

Applies Laravel's `throttle` middleware via attribute.

```php
use Dancycodes\Gale\Routing\Attributes\RateLimit;

// throttle:60,1 (60 requests per minute)
#[RateLimit(60)]

// throttle:10,5 (10 requests per 5 minutes)
#[RateLimit(10, decayMinutes: 5)]

// Named rate limiter (defined in AppServiceProvider)
#[RateLimit(limiter: 'api')]
```

---

### `#[Where]`

Adds route parameter constraints.

```php
use Dancycodes\Gale\Routing\Attributes\Where;

#[Where('id', '[0-9]+')]
public function show(int $id): mixed {}
```

---

### `#[NoAutoDiscovery]`

Disables convention-based auto-discovery for a controller class. Explicit `#[Route]` attributes on methods still work.

```php
use Dancycodes\Gale\Routing\Attributes\NoAutoDiscovery;

#[NoAutoDiscovery]
class SpecialController
{
    // Only methods with #[Route] are registered
    #[Route('GET', '/special')]
    public function index(): mixed {}

    // Not registered (no #[Route] and auto-discovery disabled)
    public function helper(): void {}
}
```

---

### Convention-Based Discovery

When `gale.route_discovery.conventions = true`, controllers with standard CRUD method names have routes registered automatically without `#[Route]`:

| Method Name | HTTP Verb | URI |
|-------------|-----------|-----|
| `index()` | GET | `/{prefix}` |
| `create()` | GET | `/{prefix}/create` |
| `store()` | POST | `/{prefix}` |
| `show($model)` | GET | `/{prefix}/{model}` |
| `edit($model)` | GET | `/{prefix}/{model}/edit` |
| `update($model)` | PUT/PATCH | `/{prefix}/{model}` |
| `destroy($model)` | DELETE | `/{prefix}/{model}` |

Non-conventional public methods (e.g., `sendNotification`) are NOT registered unless they have `#[Route]`.

---

### Using `Discover` in `web.php`

For route cache compatibility, you can call `Discover` directly in `routes/web.php`:

```php
use Dancycodes\Gale\Routing\Discovery\Discover;

Discover::controllers()
    ->useBasePath(base_path())
    ->in(app_path('Http/Controllers'));
```

---

## Middleware Aliases

Gale registers the following middleware aliases. Apply them using `Route::middleware()` or the `#[Middleware]` attribute.

| Alias | Class | Description |
|-------|-------|-------------|
| `gale.pipeline` | `GalePipelineMiddleware` | Main Gale request pipeline — runs before/after hooks |
| `gale.without-checksum` | `WithoutGaleChecksum` | Opt-out of state checksum verification for a specific route |

---

## Configuration Reference

Publish the config file:

```bash
php artisan vendor:publish --tag=gale-config
```

All keys in `config/gale.php`:

---

### `gale.mode`

**Type:** `string` | **Default:** `'http'` | **Env:** `GALE_MODE`

Controls the default transport mode for `GaleResponse::toResponse()`.

| Value | Description |
|-------|-------------|
| `'http'` | JSON response (`application/json`). Recommended for most deployments. |
| `'sse'` | Server-Sent Events (`text/event-stream`). Use when the entire app relies on SSE. |

Individual requests can override this via the `Gale-Mode` request header. `gale()->stream()` always uses SSE regardless of this setting.

```php
'mode' => env('GALE_MODE', 'http'),
```

---

### `gale.debug`

**Type:** `bool` | **Default:** `false` | **Env:** `GALE_DEBUG`

When `true`, Gale intercepts `dd()` and `dump()` output during Gale requests and renders it in a debug overlay instead of corrupting the JSON/SSE response. Set to `true` in development.

```php
'debug' => env('GALE_DEBUG', false),
```

---

### `gale.morph_markers`

**Type:** `bool` | **Default:** `true` | **Env:** `GALE_MORPH_MARKERS`

When `true`, Gale injects HTML comment markers around conditional/loop Blade blocks (`@if`, `@foreach`, `@switch`, `@forelse`). These markers improve Alpine.js morph accuracy when conditional content changes. Disable in production to reduce HTML payload if morphing accuracy is not a concern.

```php
'morph_markers' => env('GALE_MORPH_MARKERS', true),
```

---

### `gale.sanitize_html`

**Type:** `bool` | **Default:** `true` | **Env:** `GALE_SANITIZE_HTML`

When `true`, HTML received in `gale-patch-elements` events is sanitized before DOM insertion. Strips `<script>` tags (unless `allow_scripts` is true), removes `on*` event handler attributes, and neutralizes `javascript:` URLs.

```php
'sanitize_html' => env('GALE_SANITIZE_HTML', true),
```

> **Warning:** Setting `sanitize_html = false` disables all XSS protection. Only disable if you fully trust all HTML returned by your server.

---

### `gale.allow_scripts`

**Type:** `bool` | **Default:** `false` | **Env:** `GALE_ALLOW_SCRIPTS`

When `false` (default), `<script>` tags in patched HTML are stripped by the sanitizer. Set to `true` only in fully trusted environments where you control all HTML content and need inline scripts.

```php
'allow_scripts' => env('GALE_ALLOW_SCRIPTS', false),
```

---

### `gale.csp_nonce`

**Type:** `string|null` | **Default:** `null` | **Env:** `GALE_CSP_NONCE`

CSP nonce for `@gale` script tags and dynamic `gale()->js()` script tags.

| Value | Description |
|-------|-------------|
| `null` | No nonce (default) |
| `'auto'` | Read `window.GALE_CSP_NONCE` at JS init time |
| `'<string>'` | Static nonce value (uncommon — nonces should rotate) |

```php
'csp_nonce' => env('GALE_CSP_NONCE', null),
```

For per-request nonces, inject via the `@gale` directive:
```blade
@gale(['nonce' => config('gale.csp_nonce')])
```

---

### `gale.redirect`

Redirect security configuration. Prevents open-redirect vulnerabilities.

```php
'redirect' => [
    'allowed_domains' => [
        // Empty = same-origin only
        // 'payment.stripe.com',
        // '*.myapp.com',  // wildcard subdomain
    ],
    'allow_external' => false,
    'log_blocked'    => true,
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `allowed_domains` | `array` | `[]` | Whitelist of external domains. Supports `*.example.com` wildcards. |
| `allow_external` | `bool` | `false` | When `true`, all external redirects are allowed (disables domain checking). |
| `log_blocked` | `bool` | `true` | Log blocked redirect attempts with `console.warn` and in the debug panel. |

> **Security:** Dangerous protocols (`javascript:`, `data:`, `vbscript:`, `blob:`) are always blocked regardless of `allow_external`. Relative URLs (`/path`) and same-origin absolute URLs are always allowed.

---

### `gale.headers`

Security headers added automatically to all Gale responses.

```php
'headers' => [
    'x_content_type_options' => 'nosniff',
    'x_frame_options'        => 'SAMEORIGIN',
    'cache_control'          => 'no-store, no-cache, must-revalidate',
    'custom' => [
        // 'X-Custom-Header' => 'value',
    ],
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `x_content_type_options` | `'nosniff'` | Prevents MIME sniffing. Set to `false` to disable. |
| `x_frame_options` | `'SAMEORIGIN'` | Clickjacking protection. Values: `'SAMEORIGIN'`, `'DENY'`, or `false`. |
| `cache_control` | `'no-store, no-cache, must-revalidate'` | Prevents caching of state-bearing responses. SSE always uses `'no-cache'`. |
| `custom` | `[]` | Additional headers added to all Gale responses. |

---

### `gale.route_discovery`

```php
'route_discovery' => [
    'enabled' => false,
    'conventions' => true,
    'discover_controllers_in_directory' => [
        // app_path('Http/Controllers'),
    ],
    'discover_views_in_directory' => [
        // 'docs' => resource_path('views/docs'),
    ],
    'pending_route_transformers' => [
        ...Dancycodes\Gale\Routing\Config::defaultRouteTransformers(),
    ],
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `false` | Opt-in: set to `true` to enable automatic route discovery |
| `conventions` | `bool` | `true` | Auto-register CRUD convention method names (`index`, `store`, `show`, etc.) |
| `discover_controllers_in_directory` | `array` | `[]` | Directories to scan for controller classes |
| `discover_views_in_directory` | `array` | `[]` | Directories to scan for Blade view routes (key = URI prefix) |
| `pending_route_transformers` | `array` | defaults | Pipeline of transformers that process discovered routes before registration |

---

### `gale.redirect_allowed_domains`

**Deprecated.** Use `gale.redirect.allowed_domains` instead. Kept for backward compatibility with Gale v1.

---

## Artisan Commands

### `php artisan gale:install`

Publishes Gale assets and config:

```bash
php artisan gale:install
```

### `php artisan vendor:publish --tag=gale-assets`

Publishes the compiled Gale JS and CSS files to `public/vendor/gale/`.

```bash
php artisan vendor:publish --tag=gale-assets --force
```

### `php artisan vendor:publish --tag=gale-config`

Publishes `config/gale.php` to your application config directory.

### `php artisan gale:routes`

Lists all routes discovered and registered by Gale's route discovery system.

```bash
php artisan gale:routes
php artisan gale:routes --json
```

---

> **See also:** [Core Concepts](core-concepts.md) for architecture explanations | [Frontend API Reference](frontend-api.md) for Alpine Gale magics and directives
