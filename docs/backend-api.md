# Backend API Reference

> **See also:** [Core Concepts](core-concepts.md) | [Frontend API Reference](frontend-api.md)

Complete PHP API reference for the Gale package. Covers the `gale()` helper, every public method on `GaleResponse`, `GaleRedirect`, the fragment system, Blade directives, request macros, route discovery attributes, and all configuration keys.

---

## Table of Contents

- [The `gale()` Helper](#the-gale-helper)
- [Response Building](#response-building)
  - [View & HTML](#view--html)
  - [State Management](#state-management)
  - [Components & Stores](#components--stores)
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
  - [Static Utilities](#static-utilities)
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

Returns the **request-scoped** `GaleResponse` instance from the Laravel container. The same instance is returned across multiple calls within a single request, so events accumulate correctly. The instance is automatically reset at the start of each new request.

**Scoped binding:** Because `gale()` uses `app()->scoped()`, calling `gale()` five times in one controller returns the exact same object every time. Events added in one call are present when you call `toResponse()`. A fresh instance is created for each new request (safe for Octane/Swoole/RoadRunner).

```php
// All three calls operate on the same instance
gale()->state(['step' => 1]);
gale()->messages(['email' => 'Invalid']);
return gale()->fragment('checkout', 'step-form', $data);
```

**Container alias:** The same instance is also available as `app('gale.response')` and via the `Response::gale()` macro.

---

## Response Building

All fluent methods return `static` (the same `GaleResponse` instance) unless otherwise noted, allowing method chaining. Methods that return a different type are marked accordingly.

### View & HTML

#### `view(string $view, array $data = [], array $options = [], bool $web = false): static`

Renders a full Blade view and sends its HTML as a `gale-patch-elements` event.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$view` | `string` | Blade view name in dot notation |
| `$data` | `array` | Variables to pass to the view |
| `$options` | `array` | DOM patching options (see [DOM Patching Options](#dom-patching-options)) |
| `$web` | `bool` | When `true`, also sets this view as the fallback for non-Gale requests |

```php
// Gale request: renders and patches the view into the DOM
// Non-Gale request: returns 204 No Content
return gale()->view('dashboard', ['stats' => $stats]);

// With web fallback for direct URL access
return gale()->view('dashboard', ['stats' => $stats], web: true);
```

> **Note:** Always use `gale()->view()` instead of returning `view()` directly from a Gale controller. Bare `view()` does not send Gale events.

---

#### `fragment(string $view, string $fragment, array $data = [], array $options = []): static`

Extracts and renders a named `@fragment` block from a Blade view. **Only the fragment is compiled -- the full view is never rendered.** This is the correct method for granular UI updates.

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

// In the controller -- only the fragment is compiled:
return gale()->fragment('tasks.list', 'task-list', ['tasks' => $tasks]);
```

> **How pre-rendering extraction works:** Gale reads the raw template file, locates the `@fragment('task-list')` ... `@endfragment` block using a parser, extracts just that text, and passes it to `Blade::render($fragmentText, $data)` for compilation. The surrounding view template is never compiled. You only pass data that the fragment actually uses -- no dummy values for variables elsewhere in the view.

---

#### `fragments(array $fragments): static`

Renders and patches multiple fragments in a single response.

```php
return gale()->fragments([
    ['view' => 'tasks.list', 'fragment' => 'task-list', 'data' => ['tasks' => $tasks]],
    ['view' => 'tasks.list', 'fragment' => 'task-count', 'data' => ['count' => $count]],
]);
```

Each entry in the array must have `view` and `fragment` keys. `data` and `options` are optional.

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

**Options:**

| Option | Type | Description |
|--------|------|-------------|
| `onlyIfMissing` | `bool` | When `true`, the state is only applied if the key does not already exist on the client |

---

#### `messages(array $messages): static`

Sets the `messages` state key -- the reactive message store read by `x-message` directives.

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

Sets the `errors` state key with Laravel-style field-error arrays. Each field maps to an array of error strings. This is the format produced by `$request->validate()` auto-conversion.

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

// Chainable with other methods
gale()->flash('success', 'Saved')->state('count', 42);
```

**Frontend display (add `_flash: {}` to `x-data`):**
```html
<div x-show="_flash.success" x-text="_flash.success"></div>
```

For non-Gale requests, `flash()` still writes to the Laravel session so server-rendered pages can display flash data via `session('key')`.

---

#### `forget(string|array|null $state = null): static`

Removes one or more state keys from the client-side Alpine component using RFC 7386 `null` deletion.

```php
gale()->forget('tempData');
gale()->forget(['tempData', 'draft']);
```

> **Note:** `messages` and `errors` are reset to `[]` instead of `null` when forgotten, because `x-message` expects an array. Calling `forget(null)` is a no-op.

---

### Components & Stores

#### `patchStore(string $storeName, array $data): static`

Patches an Alpine global store (`Alpine.store()`) using RFC 7386 Merge Patch semantics. The store must be registered on the frontend before calling this.

```php
// Patch a single store
return gale()->patchStore('cart', ['total' => 149.99, 'itemCount' => 3]);

// Patch multiple stores in one response
return gale()->patchStore('cart', ['total' => 42])->patchStore('notifications', ['unread' => 3]);
```

---

#### `componentState(string $componentName, array $state, array $options = []): static`

Patches the Alpine state of a named component registered with `x-component`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$componentName` | `string` | Name from the `x-component` attribute |
| `$state` | `array` | State updates (RFC 7386 merge patch) |
| `$options` | `array` | Options: `onlyIfMissing` (bool) |

```php
gale()->componentState('cart', ['total' => 149.99, 'itemCount' => 3]);
```

---

#### `tagState(string $tag, array $state): static`

Patches the state of **all** components sharing a tag (set via `data-tags` attribute on `x-component`).

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

### DOM Patching

All DOM patching methods use the `gale-patch-elements` event type internally.

#### DOM Patching Options

The `$options` array accepted by `view()`, `fragment()`, `html()`, and all DOM convenience methods:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `selector` | `string` | -- | CSS selector targeting elements in the DOM |
| `mode` | `string` | `'outer'` | Patching mode (see modes below) |
| `useViewTransition` | `bool` | `false` | Wrap the DOM update in a View Transition |
| `settle` | `int` | -- | Milliseconds to wait before applying classes |
| `scroll` | `string` | -- | CSS selector to scroll into view after patch |
| `show` | `string` | -- | CSS selector to show after patch |
| `focusScroll` | `bool` | `false` | Scroll focused element into view |
| `limit` | `int` | -- | Max number of elements to patch |

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
| `outerMorph` | Smart diff using `Alpine.morph()` -- preserves client state |
| `innerMorph` | Smart diff children using `Alpine.morph()` |

#### DOM Patching Convenience Methods

All methods are chainable and return `static`.

```php
// Replace the outer HTML (default mode)
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

// Smart morph -- preserves Alpine state in matched elements
gale()->outerMorph('#task-list', $newListHtml);
gale()->innerMorph('#task-list', $newChildrenHtml);

// Alias for outerMorph() (v1 compatibility)
gale()->morph('#task-list', $newListHtml);
```

#### Method Signatures

| Method | Signature |
|--------|-----------|
| `append` | `append(string $selector, string $html, array $options = []): static` |
| `prepend` | `prepend(string $selector, string $html, array $options = []): static` |
| `before` | `before(string $selector, string $html, array $options = []): static` |
| `after` | `after(string $selector, string $html, array $options = []): static` |
| `inner` | `inner(string $selector, string $html, array $options = []): static` |
| `outer` | `outer(string $selector, string $html, array $options = []): static` |
| `replace` | `replace(string $selector, string $html, array $options = []): static` |
| `outerMorph` | `outerMorph(string $selector, string $html, array $options = []): static` |
| `innerMorph` | `innerMorph(string $selector, string $html, array $options = []): static` |
| `morph` | `morph(string $selector, string $html, array $options = []): static` |
| `remove` | `remove(string $selector): static` |
| `delete` | `delete(string $selector): static` |

---

### Events & JavaScript

#### `dispatch(string $eventName, array $data = [], ?string $target = null): static`

Dispatches an Alpine-compatible `CustomEvent` on `window` (default) or on a specific element.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$eventName` | `string` | Event name (kebab-case recommended). Must not be empty. |
| `$data` | `array` | Event payload accessible via `$event.detail` |
| `$target` | `string\|null` | CSS selector for targeted dispatch; `null` = window |

```php
// Dispatch on window
gale()->dispatch('show-toast', ['message' => 'Saved!', 'type' => 'success']);

// Dispatch on a specific element
gale()->dispatch('refresh', [], '#sidebar');

// Chaining multiple dispatches
gale()->dispatch('cart-updated', ['total' => 99])->dispatch('notify', ['msg' => 'Item added']);
```

**Alpine listener:**
```html
<!-- Window event -->
<div x-on:show-toast.window="showToast($event.detail)"></div>

<!-- Element-specific (no .window modifier needed) -->
<div id="sidebar" x-on:refresh="loadItems()"></div>
```

Throws `\InvalidArgumentException` when `$eventName` is empty.

---

#### `js(string $script, array $options = []): static`

Executes arbitrary JavaScript in the browser by emitting a `gale-execute-script` event.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$script` | `string` | JavaScript code to execute |
| `$options` | `array` | Options: `autoRemove` (bool, default `true`), `nonce` (string for CSP), `attributes` (array) |

```php
gale()->js("document.title = 'Updated'");
gale()->js("console.log('debug')", ['autoRemove' => false]);
```

> **Warning:** Use `dispatch()` for Alpine communication. Use `js()` only for direct DOM operations that have no Gale equivalent.

> **CSP:** When your app uses a Content Security Policy, pass a nonce via `$options['nonce']` or configure `config('gale.csp_nonce')` globally.

---

### Navigation

#### `navigate(string|array $url, string $key = 'true', array $options = []): static`

Triggers SPA navigation via the Gale navigate system. Does NOT perform a full-page redirect -- it uses `history.pushState` and the Gale navigate machinery.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | `string\|array` | URL string, or query parameter array (applied to current path) |
| `$key` | `string` | Navigate key sent in `GALE-NAVIGATE-KEY` header |
| `$options` | `array` | Options: `merge` (bool), `only` (array), `except` (array), `replace` (bool) |

```php
// Navigate to a URL
gale()->navigate('/dashboard');

// Navigate with query parameters (applied to current path)
gale()->navigate(['page' => 2, 'sort' => 'name']);
```

> **Note:** Only one `navigate()` call is allowed per response. Calling it a second time throws `\LogicException`.

---

#### `navigateWith(string|array $url, string $key = 'true', bool $merge = false, array $options = []): static`

Navigate with explicit merge control. The `$merge` parameter controls whether new query parameters are merged with the current URL's query string.

```php
gale()->navigateWith('/products', merge: true);
gale()->navigateWith(['page' => 2], merge: true);
```

---

#### Navigate Convenience Methods

```php
// Navigate and merge with current query parameters
gale()->navigateMerge(['page' => 2]);

// Navigate without merging (clean slate)
gale()->navigateClean('/search?q=test');

// Navigate preserving only specific params
gale()->navigateOnly('/search', ['q']);

// Navigate preserving all except specific params
gale()->navigateExcept('/search', ['page']);

// Navigate using replaceState instead of pushState (no history entry)
gale()->navigateReplace('/search?q=test');

// Combine: replaceState + merge
gale()->navigateMerge(['sort' => 'name'], options: ['replace' => true]);
```

| Method | Signature |
|--------|-----------|
| `navigateMerge` | `navigateMerge(string\|array $url, string $key = 'true', array $options = []): static` |
| `navigateClean` | `navigateClean(string\|array $url, string $key = 'true', array $options = []): static` |
| `navigateOnly` | `navigateOnly(string\|array $url, array $only, string $key = 'true'): static` |
| `navigateExcept` | `navigateExcept(string\|array $url, array $except, string $key = 'true'): static` |
| `navigateReplace` | `navigateReplace(string\|array $url, string $key = 'true', array $options = []): static` |

---

#### `updateQueries(array $queries, string $key = 'filters', bool $merge = true): static`

Navigate to the current page with new query parameters. Convenience wrapper for `navigate()` that always targets the current path.

```php
// Update filters without changing the page
gale()->updateQueries(['status' => 'active', 'page' => 1]);
```

---

#### `clearQueries(array $paramNames, string $key = 'clear'): static`

Remove specific query parameters from the current URL by navigating with null values.

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

Returns a `GaleRedirect` builder for full-page browser redirects. For Gale requests, the redirect is performed via a `gale-redirect` event that triggers `window.location.href`. For non-Gale requests, a standard HTTP redirect is returned.

**Returns** `GaleRedirect` (not chainable with `GaleResponse` -- see [GaleRedirect](#galeredirect)).

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

> **Auto-conversion:** Gale registers middleware that auto-converts bare `redirect()` calls to `gale()->redirect()` for Gale requests. You can use Laravel's standard `redirect()` helper and it will work reactively.

---

#### `emitRedirect(string $url): static`

Low-level method that emits a `gale-redirect` event for both HTTP (JSON) and SSE modes. Used internally by `GaleRedirect::toResponse()`. Prefer `redirect()` for application code.

```php
// Internal use -- prefer gale()->redirect() instead
gale()->emitRedirect('/dashboard');
```

---

### Downloads

#### `download(string $pathOrContent, string $filename, ?string $mimeType = null, bool $isContent = false): static`

Triggers a file download without navigating away from the page.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$pathOrContent` | `string` | Absolute path to file on disk, or raw content string |
| `$filename` | `string` | Download filename shown to the user (**required**) |
| `$mimeType` | `string\|null` | MIME type (auto-detected from extension when `null`) |
| `$isContent` | `bool` | `true` when first argument is raw content, not a path |

```php
// File on disk
return gale()->download(storage_path('exports/report.pdf'), 'monthly-report.pdf');

// Dynamic content
return gale()->download($csvContent, 'export.csv', 'text/csv', isContent: true);

// Chainable -- download AND update state
return gale()->download($path, 'report.pdf')->state(['lastExport' => now()->toISOString()]);
```

**How it works:** Gale stores the file in the cache with an HMAC-signed token (5-minute TTL) and emits a `gale-download` event with the signed URL. The client fetches the URL via an invisible link and the file is served by `GaleDownloadServeController` at `GET /gale/download/{token}`. The page does not navigate.

Throws `\InvalidArgumentException` when a file path does not exist. Filenames are sanitized (path traversal characters stripped).

---

### Streaming (SSE)

#### `stream(Closure $callback): static`

Switches the response to SSE mode and executes the callback in streaming context. Events sent inside the callback are flushed to the client immediately. **Always uses `text/event-stream`** regardless of `config('gale.mode')` or the `Gale-Mode` header.

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

> **dd() and dump():** Inside `stream()`, when `config('gale.debug')` is true, `dump()` output is captured and sent as a `gale-debug-dump` event for the debug overlay. For `dd()` which calls `exit`, a shutdown handler captures the output. When `gale.debug` is false, dd/dump output replaces the full page as a document replacement.

> **Exceptions:** Exceptions thrown inside the `stream()` callback emit a `gale-error` SSE event without replacing the page layout.

---

#### `withEventId(string $id): static`

Sets the SSE event ID for replay support (sent in `id:` lines). The browser sends this in `Last-Event-ID` when reconnecting. Must be called **before** `state()`, `dispatch()`, etc., because events are formatted at queue time.

```php
gale()->withEventId('event-' . $latestId);
```

---

#### `withRetry(int $milliseconds): static`

Sets the SSE reconnection delay in milliseconds (default is 1000ms per the SSE spec). Must be called **before** event methods.

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

Multiple channels can be pushed to by calling `push()` multiple times.

---

### Debug

#### `debug(mixed $labelOrData = null, mixed $data = null): static`

Sends data to the Gale Debug Panel's "Server Debug" tab. **No-op in production** (`APP_DEBUG=false`).

```php
// One-argument form -- label defaults to "debug"
gale()->debug($request->all());

// Two-argument form -- custom label
gale()->debug('before validation', $request->all());
gale()->debug('user model', $user);
```

Supported data types: scalars, arrays, `Arrayable`, `JsonSerializable`, Eloquent models (via `toArray()`), Closures (as `'[Closure]'`), resources (as `'[Resource: type]'`), circular references (truncated with `'[Circular]'`).

In streaming mode, each `debug()` call emits a `gale-debug` SSE event immediately. In HTTP mode, all entries are batched into the JSON events array.

---

#### `debugDump(string $html): static`

Injects VarDumper HTML output (from `dump()` or `dd()`) as a `gale-debug-dump` event for the debug overlay. Only active when `config('gale.debug')` is `true`. Called internally by `GaleDumpInterceptMiddleware` -- not typically used in application code.

```php
// Internal use by middleware:
gale()->debugDump($capturedVarDumperHtml);
```

---

#### `forceHttp(): static`

Forces HTTP/JSON mode (`application/json`) regardless of the `Gale-Mode` header or `config('gale.mode')`. Used by the validation exception renderer to ensure validation errors return as JSON even when the request was sent in SSE mode.

```php
// Used in bootstrap/app.php renderable:
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

Inverted `when()` -- executes `$callback` when `$condition` is falsy.

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

#### `whenGaleNavigate(string|array|callable|null $key, ?callable $callback, ?callable $fallback): static`

Executes `$callback` when the request is a Gale navigate request. Optionally checks for a specific navigate key. When the first parameter is callable, treats the request as "any navigate".

```php
// Any navigate request
return gale()->whenGaleNavigate(fn($g) => $g->fragment('layout', 'content', $data));

// Specific navigate key
return gale()->whenGaleNavigate('sidebar', fn($g) => $g->fragment('layout', 'sidebar', $data));

// Array of keys
return gale()->whenGaleNavigate(['sidebar', 'content'], fn($g) => $g->view('layout', $data));
```

---

#### `web(mixed $response): static`

Sets the fallback response for non-Gale requests. When `toResponse()` is called on a non-Gale request with no web fallback, a `204 No Content` response is returned.

```php
return gale()
    ->state(['count' => $count])
    ->web(view('counter', ['count' => $count]));
```

Accepts any value that Laravel can convert to a response: Response objects, views, redirects, or closures.

---

### Hooks & Pipeline

#### `GaleResponse::beforeRequest(Closure $hook): void` (static)

Registers a before-hook that runs before every Gale controller action (via the `gale.pipeline` middleware).

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

#### `GaleResponse::macro(string $name, object|callable $macro): void` (static)

Registers a custom macro on `GaleResponse`. Throws `\RuntimeException` if the name conflicts with an existing method (unlike standard Macroable which silently shadows).

```php
GaleResponse::macro('toast', function (string $message, string $type = 'info') {
    return $this->dispatch('show-toast', ['message' => $message, 'type' => $type]);
});

// Usage:
return gale()->toast('Record saved!', 'success');
```

---

#### `withHeaders(array $headers): static`

Adds extra HTTP headers to the final response (both HTTP and SSE modes).

```php
gale()->withHeaders(['Gale-Cache-Bust' => 'true']);
gale()->withHeaders(['Cache-Control' => 'max-age=300']); // Overrides security header default
```

When `Cache-Control` is set via `withHeaders()`, the security headers middleware will not override it.

---

#### `etag(): static`

Enables ETag-based conditional responses for this endpoint. When the client sends a matching `If-None-Match` header, returns `304 Not Modified`. Never applied to SSE streaming responses.

```php
return gale()->etag()->fragment('products', 'list', $data);
```

Can also be enabled globally via `config('gale.etag', true)`.

---

### Response Finalization

#### `toResponse($request = null): JsonResponse|StreamedResponse|mixed`

Converts the accumulated events to an HTTP response. Called automatically when you `return` a `GaleResponse` from a controller (via the `Responsable` interface).

**Mode resolution priority (highest to lowest):**
1. `stream()` callback presence -- always SSE
2. `forceHttp()` -- always JSON
3. `Gale-Mode` request header -- per-request override
4. `config('gale.mode')` -- server-side default

For non-Gale requests: returns the web fallback if set, otherwise `204 No Content`.

---

#### `toJson(): array`

Returns the accumulated events as a PHP array: `{ events: [...] }`. Useful for testing or custom response building. Does not reset the response state.

---

#### `toJsonString(): string`

Returns the accumulated events as a JSON string.

---

#### `reset(): void`

Clears all accumulated state on the instance (events, callbacks, headers, flags). Called automatically in `toResponse()` to prepare the scoped instance for the next request. Safe to call multiple times (idempotent).

---

### Static Utilities

#### `GaleResponse::resolveMode(): string` (static)

Returns the configured default response mode from `config('gale.mode')`. Falls back to `'http'` for invalid or missing values.

---

#### `GaleResponse::resolveRequestMode($request = null): string` (static)

Returns the effective response mode for the current request. Checks the `Gale-Mode` header first, then falls back to `resolveMode()`.

---

#### `GaleResponse::headers(): array` (static)

Returns the standard SSE headers array for streaming responses (`Content-Type: text/event-stream`, `Cache-Control: no-store`, etc.).

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
3. Normalizes line endings (CRLF to LF) for consistent parsing
4. Uses `BladeFragmentParser` to locate the `@fragment('task-list')` ... `@endfragment` boundaries
5. Extracts the content between the directives (handles nesting correctly)
6. Passes the extracted text to `Blade::render($fragmentText, $data)` for compilation
7. Returns the rendered HTML

The outer view (header, nav, other fragments) is **never compiled**. You only pass data that the fragment actually uses.

### `BladeFragment::render(string $view, string $fragment, array $data = []): string`

The static method on `BladeFragment` that performs extraction and rendering. Includes automatic cache recovery: if a Blade compilation error occurs (e.g., directives not yet registered), clears the view cache and retries.

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
- The Gale CSS stylesheet (`<link rel="stylesheet">`)
- The Gale JS bundle (`<script type="module" src="...gale.js">`)
- Configuration globals (`GALE_DEBUG_MODE`, `GALE_SANITIZE_HTML`, `GALE_ALLOW_SCRIPTS`, `GALE_REDIRECT_CONFIG`)

**Replaces** any Alpine.js CDN `<script>` tag -- do not include both.

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

The nonce is applied to all `<script>` tags output by `@gale` and exposed as `window.GALE_CSP_NONCE` for dynamic script tags created by `gale()->js()`.

---

### `@fragment('name')` / `@endfragment`

Marks a named section for extraction by `gale()->fragment()`. These directives compile to empty strings -- they are purely markers for the fragment parser.

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

Conditionally renders content based on whether the current request is a Gale request (has `Gale-Request` header).

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

### `@galeState($data)` (deprecated)

Injects initial state as a `window.galeState` global. Deprecated -- prefer `x-data` with inline data instead.

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

Returns the resolved response mode (`'http'` or `'sse'`) for the current request. Reads the `Gale-Mode` header first (case-insensitive), then falls back to `config('gale.mode')`.

---

#### `$request->state(?string $key = null, mixed $default = null): mixed`

Reads state values from the Gale request JSON body. Call without arguments to get all state. Supports dot notation for nested keys.

```php
$allState = $request->state();
$count = $request->state('count', 0);
$nested = $request->state('user.name');
```

---

#### `$request->isGaleNavigate(string|array|null $key = null): bool`

Returns `true` when the request is a Gale navigate request (has `GALE-NAVIGATE` header). Optionally checks for a specific navigate key or array of keys.

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

Returns the raw navigate key string from the `GALE-NAVIGATE-KEY` header, or `null`.

---

#### `$request->galeNavigateKeys(): array`

Returns navigate keys as an array (splits comma-separated keys from the header).

---

#### `$request->validateState(array $rules, array $messages = [], array $attributes = []): array`

Validates Alpine state from the request body using Gale's reactive validation. On failure, throws `GaleMessageException` which auto-sends validation messages to the frontend with selective clearing (only clears message fields being validated). On success, returns validated data and clears messages for validated fields.

```php
$validated = $request->validateState([
    'email' => 'required|email',
    'name' => 'required|string|max:255',
]);
```

> **Tip:** Prefer standard `$request->validate()` which is auto-converted by Gale's `ValidationException` renderer in `bootstrap/app.php`. Use `validateState()` for manual control of message clearing behavior.

---

## GaleRedirect

`GaleRedirect` is returned by `gale()->redirect()`. It provides a fluent API for full-page browser redirects with session flash data support. Implements `Responsable`, so you can return it directly from controllers.

For Gale requests, the redirect emits a `gale-redirect` event that the frontend handles via `window.location.href`. For non-Gale requests, a standard Laravel `RedirectResponse` is returned.

> **Security:** All redirect URLs are validated server-side before being sent to the browser. Same-origin URLs are always allowed. External URLs must be in `gale.redirect.allowed_domains` or `allow_external` must be `true`. Dangerous protocols (`javascript:`, `data:`, `vbscript:`, `blob:`) are always blocked, even with `away()`.

### Methods

All methods return `static` (the `GaleRedirect` instance) unless noted.

#### `to(string $url): static`

Sets the redirect URL. Equivalent to `gale()->redirect('/path')`.

```php
return gale()->redirect()->to('/dashboard');
```

---

#### `away(string $url): static`

Sets the redirect URL for an external domain. Bypasses same-domain validation but still blocks dangerous protocols.

```php
return gale()->redirect()->away('https://stripe.com/checkout');
```

---

#### `route(string $routeName, array $parameters = [], bool $absolute = true): static`

Sets the redirect URL using a named route. Throws `\InvalidArgumentException` if the route does not exist.

```php
return gale()->redirect()->route('profile.show', ['user' => $user->id]);
```

---

#### `back(string $fallback = '/'): static`

Sets the redirect URL to the previous URL with same-domain validation. Falls back to `$fallback` if the previous URL is external, unavailable, or matches the current URL.

---

#### `home(): static`

Sets the redirect URL to the application root (`url('/')`).

---

#### `intended(string $default = '/'): static`

Sets the redirect URL to the session-stored intended URL (from auth middleware), with same-domain validation. Falls back to `$default`. Pulls and removes the `url.intended` session key.

---

#### `backOr(string $routeName, array $routeParameters = []): static`

Redirects back if a valid previous URL is available, otherwise redirects to the named route.

---

#### `refresh(bool $preserveQuery = true, bool $preserveFragment = false): static`

Sets the redirect URL to the current URL (page refresh). When `$preserveQuery` is true, includes query parameters. When `$preserveFragment` is true, attempts to preserve the URL fragment from the HTTP referer.

---

#### `with(string|array $key, mixed $value = null): static`

Adds session flash data for the next request. Multiple calls accumulate.

```php
return gale()->redirect()->route('dashboard')->with('success', 'Profile updated!');
return gale()->redirect()->back()->with(['status' => 'saved', 'count' => $count]);
```

---

#### `withInput(?array $input = null): static`

Flashes the current request input to session (for repopulating forms). Pass `null` to flash all current request input.

---

#### `withErrors(mixed $errors): static`

Flashes validation errors to session under the `errors` key.

---

#### `forceReload(bool $forceReload = false): Response`

**Returns** a final `Response` (not chainable). Executes `window.location.reload()` in the browser. When `$forceReload = true`, bypasses the browser cache. For non-Gale requests, redirects to the current URL.

---

#### `toResponse($request = null): Response`

**Returns** a final `Response`. Validates the URL, flashes session data, and emits the redirect event. Called automatically when you `return` a `GaleRedirect` from a controller (via `Responsable`). Throws `\LogicException` if no URL has been set.

---

### Auto-Conversion of `redirect()`

When Laravel's `redirect()` helper is used in a Gale request, Gale's `ConvertRedirectForGale` middleware automatically converts the `RedirectResponse` to a `gale()->redirect()->away($url)`. This means existing Laravel redirect patterns work reactively without changes:

```php
// This works in Gale requests -- auto-converted to reactive redirect:
return redirect()->route('dashboard')->with('success', 'Done!');
```

---

## Route Discovery Attributes

Gale provides PHP 8 attributes for declarative route registration. To enable discovery, set `gale.route_discovery.enabled = true` in `config/gale.php` and add directories to `discover_controllers_in_directory`.

All route discovery attributes live in `Dancycodes\Gale\Routing\Attributes`.

### `#[Route]`

Defines route parameters on a controller method (or class for defaults).

```php
use Dancycodes\Gale\Routing\Attributes\Route;

#[Route(method: 'GET', uri: '/tasks', name: 'tasks.index')]
public function index(): mixed
{
    return gale()->view('tasks.index', ['tasks' => Task::all()], web: true);
}

// POST route
#[Route(method: 'POST', name: 'tasks.store')]
public function store(Request $request): mixed { /* ... */ }

// Multiple HTTP methods
#[Route(method: ['GET', 'HEAD'], uri: '/tasks/{task}')]
public function show(Task $task): mixed { /* ... */ }
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `method` | `array\|string` | `[]` | HTTP methods (GET, POST, PUT, PATCH, DELETE, etc.) |
| `uri` | `?string` | `null` | Custom URI; `null` = auto-generated from method name |
| `fullUri` | `?string` | `null` | Complete URI override bypassing all transformers |
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

Applies middleware to a controller class (all routes) or a specific method. Repeatable -- multiple `#[Middleware]` attributes stack.

```php
use Dancycodes\Gale\Routing\Attributes\Middleware;

#[Middleware('auth')]                    // All routes
#[Middleware('verified')]                // All routes (stacked)
class ProfileController
{
    #[Middleware('can:edit,profile')]    // This method only
    public function edit(): mixed {}
}

// Multiple middleware in one attribute:
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

### `#[WithTrashed]`

Includes soft-deleted models in route model binding. Can be applied to a class (all routes) or individual methods.

```php
use Dancycodes\Gale\Routing\Attributes\WithTrashed;

#[WithTrashed]
public function show(Task $task): mixed
{
    // $task may be a soft-deleted model
}
```

---

### `#[DoNotDiscover]`

Prevents a controller class or method from being registered by route discovery. When applied to a class, all methods are excluded. When applied to a method, only that specific action is excluded.

```php
use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;

#[DoNotDiscover]
class InternalController
{
    // No routes registered for any method
}
```

---

### `#[NoAutoDiscovery]`

Disables convention-based auto-discovery for a controller class. Explicit `#[Route]` attributes on methods still work. Use this when you want granular control over which methods become routes.

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

Non-conventional public methods (e.g., `sendNotification`) are NOT registered unless they have `#[Route]`. Apply `#[NoAutoDiscovery]` to a class to disable conventions for that controller.

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
| `gale.pipeline` | `GalePipelineMiddleware` | Main Gale request pipeline -- runs before/after hooks |
| `gale.checksum` | `VerifyGaleChecksum` | Verifies HMAC state checksum on Gale requests |
| `gale.without-checksum` | `WithoutGaleChecksum` | Opt-out of state checksum verification for a specific route |
| `gale.dump-intercept` | `GaleDumpInterceptMiddleware` | Captures dd()/dump() output for the debug overlay |
| `gale.security-headers` | `AddGaleSecurityHeaders` | Adds configurable security headers to Gale responses |

> **Note:** `gale.checksum` is registered as global web middleware via `bootstrap/app.php`. The alias is for explicit re-application on API routes or groups not covered by the web group.

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

### `gale.etag`

**Type:** `bool` | **Default:** `false` | **Env:** `GALE_ETAG`

When `true`, all HTTP-mode Gale responses include an ETag header based on a content hash. If the client sends a matching `If-None-Match` header, the server returns `304 Not Modified`. Opt-in because non-idempotent endpoints with side effects should not serve 304. For granular control, use `gale()->etag()` per-endpoint instead. Never applied to SSE streaming responses.

```php
'etag' => env('GALE_ETAG', false),
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
| `'<string>'` | Static nonce value (uncommon -- nonces should rotate) |

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

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `x_content_type_options` | `string\|false` | `'nosniff'` | Prevents MIME sniffing. Set to `false` to disable. |
| `x_frame_options` | `string\|false` | `'SAMEORIGIN'` | Clickjacking protection. Values: `'SAMEORIGIN'`, `'DENY'`, or `false`. |
| `cache_control` | `string\|false` | `'no-store, no-cache, must-revalidate'` | Prevents caching of state-bearing responses. SSE always uses `'no-cache'`. Set to `false` to disable. |
| `custom` | `array` | `[]` | Additional headers added to all Gale responses. |

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

### `gale.redirect_allowed_domains` (deprecated)

**Type:** `array` | **Default:** `[]`

Deprecated. Use `gale.redirect.allowed_domains` instead. Kept for backward compatibility.

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
