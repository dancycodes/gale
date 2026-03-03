# Backend API Reference

Complete PHP API for `dancycodes/gale`. All methods on `GaleResponse`, the fragment system, request macros, Blade directives, redirect builder, and configuration.

---

## Table of Contents

- [The gale() Helper](#the-gale-helper)
- [View & HTML](#view--html)
- [State Management](#state-management)
- [DOM Patching](#dom-patching)
- [Events & JavaScript](#events--javascript)
- [Navigation & Redirects](#navigation--redirects)
- [Downloads](#downloads)
- [Streaming (SSE)](#streaming-sse)
- [Debug](#debug)
- [Conditionals & Flow Control](#conditionals--flow-control)
- [Hooks & Pipeline](#hooks--pipeline)
- [Fragment System](#fragment-system)
- [Blade Directives](#blade-directives)
- [Request Macros](#request-macros)
- [GaleRedirect](#galeredirect)
- [Route Discovery Attributes](#route-discovery-attributes)
- [Configuration Reference](#configuration-reference)

---

## The gale() Helper

```php
gale(): \Dancycodes\Gale\Http\GaleResponse
```

Returns the **request-scoped** `GaleResponse` singleton. Same instance returned across multiple calls within a single request, so events accumulate correctly. Reset automatically per request.

```php
// Chaining multiple events — all accumulate on the same instance
return gale()
    ->patchState(['count' => 5])
    ->patchElements('<div id="status">Updated</div>')
    ->dispatch('toast', ['message' => 'Saved!']);
```

---

## View & HTML

### view()

```php
gale()->view(string $view, array $data = [], bool $web = false): static
```

Renders a Blade view and emits it as a `gale-patch-elements` event (outer mode, targeting `<body>`).

- `$web` — when `true`, non-Gale requests get the raw Blade view as a standard response (first page load, direct URL)
- Gale requests get the HTML wrapped in the events array

```php
return gale()->view('products.index', compact('products'), web: true);
```

### html()

```php
gale()->html(string $rawHtml): static
```

Emits raw HTML as a `gale-patch-elements` event. Does NOT render Blade.

```php
return gale()->html('<div id="banner">Sale ends today!</div>');
```

### web()

```php
gale()->web(mixed $fallback): static
```

Sets the fallback response for non-Gale requests. Useful when combined with other methods:

```php
return gale()
    ->patchState(['updated' => true])
    ->web(view('page', $data));
```

---

## State Management

### patchState()

```php
gale()->patchState(array $data): static
```

Emits a `gale-patch-state` event. The data is merged into the Alpine component's `x-data` using RFC 7386 JSON Merge Patch.

```php
// Update specific keys — other keys are untouched
return gale()->patchState([
    'count' => 5,
    'user'  => ['name' => 'Alice'],  // nested merge
]);

// Delete a key — set to null
return gale()->patchState(['tempData' => null]);

// Replace an array entirely
return gale()->patchState(['items' => [4, 5, 6]]);
```

### messages()

```php
gale()->messages(array $messages): static
```

Patches the `messages` key in Alpine state. Used for validation error display with `x-message`.

```php
return gale()->messages([
    'email' => 'The email field is required.',
    'name'  => null,  // clears previous error for name
]);
```

### flash()

```php
gale()->flash(string|array $key, mixed $value = null): static
```

Delivers flash data to both Laravel session AND Alpine `_flash` reactive state.

```php
gale()->flash('success', 'Item saved!');
gale()->flash(['success' => 'Saved!', 'type' => 'toast']);
```

---

## DOM Patching

### patchElements()

```php
gale()->patchElements(
    string $html,
    ?string $selector = null,
    string $mode = 'outer'
): static
```

Emits a `gale-patch-elements` event. The HTML is applied to the DOM using the specified mode.

**Modes:** `outer` (default), `inner`, `outerMorph`, `innerMorph`, `prepend`, `append`, `before`, `after`

```php
// Replace element by ID (default outer mode — ID extracted from HTML)
gale()->patchElements('<div id="counter">5</div>');

// Replace inner content of a specific selector
gale()->patchElements('<li>New</li>', selector: '#list', mode: 'inner');

// Append to a list
gale()->patchElements('<li>Item 4</li>', selector: '#list', mode: 'append');

// Morph (preserve Alpine state)
gale()->patchElements('<div id="form">...</div>', mode: 'outerMorph');
```

### remove()

```php
gale()->remove(string $selector): static
```

Removes an element from the DOM.

```php
gale()->remove('#notification-5');
```

---

## Events & JavaScript

### dispatch()

```php
gale()->dispatch(string $eventName, array $detail = []): static
```

Triggers an Alpine `$dispatch` event on the client. Listeners use `@event-name.window`.

```php
gale()->dispatch('toast', ['message' => 'Saved!', 'type' => 'success']);
gale()->dispatch('cart-updated', ['total' => 99.99]);
```

```html
<div @toast.window="showToast($event.detail)">...</div>
```

### patchStore()

```php
gale()->patchStore(string $storeName, array $data): static
```

Patches a named Alpine store (`Alpine.store('name')`) from the server.

```php
gale()->patchStore('cart', ['total' => 50, 'count' => 3]);
```

### js()

```php
gale()->js(string $script): static
```

Executes JavaScript on the client. Use sparingly — prefer `dispatch()` for communication.

```php
gale()->js('document.title = "Updated"');
```

---

## Navigation & Redirects

### redirect()

```php
gale()->redirect(string $url = '/'): GaleRedirect
```

Returns a `GaleRedirect` builder. See [GaleRedirect](#galeredirect) section.

```php
return gale()->redirect('/dashboard')->with('status', 'Saved!');
return gale()->redirect('/')->route('users.show', $user);
return gale()->redirect('/')->back('/fallback');
```

### navigate()

```php
gale()->navigate(string $url): static
```

Triggers SPA navigation on the client (same as clicking an `x-navigate` link).

```php
return gale()->navigate('/products?page=2');
```

### reload()

```php
gale()->reload(): static
```

Triggers `window.location.reload()` on the client.

---

## Downloads

### download()

```php
gale()->download(string $path, ?string $filename = null, array $headers = []): static
```

Triggers a client-side file download without leaving the current page.

```php
return gale()->download(storage_path('exports/report.csv'), 'report.csv');
```

---

## Streaming (SSE)

### stream()

```php
gale()->stream(Closure $callback): StreamedResponse
```

Forces SSE mode. The callback receives a fresh `GaleResponse` instance and can emit events progressively.

```php
return gale()->stream(function ($gale) {
    $gale->patchState(['status' => 'Processing...']);
    // ... long operation ...
    $gale->patchState(['status' => 'Complete', 'done' => true]);
});
```

### forceHttp()

```php
gale()->forceHttp(): static
```

Forces HTTP JSON mode regardless of client-requested mode. Used internally by auto-conversion (e.g., validation errors always respond via HTTP).

---

## Debug

### debug()

```php
gale()->debug(mixed $value, string $label = 'debug'): static
```

Sends a debug value to the browser debug panel during development.

```php
gale()->debug($user->toArray(), 'Current User');
gale()->debug($query->toSql(), 'SQL Query');
```

---

## Conditionals & Flow Control

```php
gale()->when(bool $condition, Closure $callback): static
gale()->unless(bool $condition, Closure $callback): static
gale()->whenGale(Closure $galeCallback, ?Closure $webCallback = null): static
gale()->whenGaleNavigate(string $key, Closure $callback): static
```

```php
gale()->when($user->isAdmin(), fn($g) => $g->patchState(['role' => 'admin']));

gale()->whenGale(
    fn($g) => $g->patchState(['partial' => true]),
    fn($g) => $g->web(view('full-page'))
);

gale()->whenGaleNavigate('sidebar', fn($g) => $g->fragment('layout', 'sidebar', $data));
```

---

## Hooks & Pipeline

### before() / after()

```php
gale()->before(Closure $callback): static
gale()->after(Closure $callback): static
```

Register callbacks that run before/after the response is finalized.

### tap()

```php
gale()->tap(Closure $callback): static
```

Execute a callback with the current GaleResponse for side effects.

### macro()

```php
GaleResponse::macro(string $name, Closure $callback): void
```

Register custom methods on GaleResponse.

```php
// In a service provider
GaleResponse::macro('toast', function (string $message, string $type = 'info') {
    return $this->dispatch('toast', compact('message', 'type'));
});

// In any controller
return gale()->toast('Item saved!', 'success');
```

---

## Fragment System

Fragments allow rendering only a portion of a Blade template without executing the full view.

### Blade Syntax

```blade
@fragment('sidebar')
<div id="sidebar">
    @foreach($items as $item)
        <div>{{ $item->name }}</div>
    @endforeach
</div>
@endfragment
```

### Controller Usage

```php
gale()->fragment(string $view, string $fragmentName, array $data = []): static
```

```php
// Render single fragment
return gale()->fragment('products.index', 'results', compact('products'));

// Chain multiple fragments
return gale()
    ->fragment('products.index', 'sidebar', $data)
    ->fragment('products.index', 'results', $data);
```

**Important:** Only pass data the fragment actually needs. The full view is NEVER rendered — Gale extracts and compiles only the fragment text.

---

## Blade Directives

| Directive | Purpose |
|-----------|---------|
| `@gale` | Outputs Alpine.js, Gale plugin, CSRF meta, debug script. MUST be in `<head>` |
| `@fragment('name')` / `@endfragment` | Define a named fragment region |
| `@ifgale` / `@endifgale` | Conditional block rendered only for Gale requests |
| `@morphKey($id)` | Emits `data-morph-key` for stable list item identity during morphs |

---

## Request Macros

Gale adds these macros to `Illuminate\Http\Request`:

```php
$request->isGale(): bool                     // Is this a Gale request?
$request->isGaleNavigate(?string $key): bool // Is this a Gale navigation request (with optional key)?
$request->state(string $key, $default): mixed // Read a state value from the POST body
$request->allState(): array                   // Read all state values
```

---

## GaleRedirect

`gale()->redirect()` returns a `GaleRedirect` builder:

```php
->with(string $key, mixed $value): static      // Flash data
->withErrors($errors, string $bag): static      // Flash validation errors
->withInput(?array $input = null): static        // Flash input
->back(?string $fallback = null): static         // Redirect back
->backOr(string $routeName): static              // Back with named route fallback
->route(string $name, array $params): static     // Named route redirect
->refresh(): static                               // Refresh current page
->web(mixed $fallback): static                    // Non-Gale fallback response
```

---

## Route Discovery Attributes

```php
use Dancycodes\Gale\Routing\Attributes\{Route, Prefix, Where, Middleware, RateLimit, Group, NoAutoDiscovery};

#[Prefix('/admin')]
#[Group(prefix: '/api', middleware: ['auth:api'])]
class Controller {
    #[Route('GET', '/users', name: 'admin.users')]
    #[Middleware('auth', 'verified')]
    #[RateLimit(maxAttempts: 60, decayMinutes: 1)]
    #[Where('id', '[0-9]+')]
    public function index() {}
}

// Convention-based: index, show, create, store, edit, update, destroy auto-register
// Disable with #[NoAutoDiscovery]
```

Enable in `config/gale.php`:
```php
'route_discovery' => [
    'enabled' => true,
    'discover_controllers_in_directory' => [app_path('Http/Controllers')],
],
```

---

## Configuration Reference

Key `config/gale.php` settings:

```php
'mode'  => env('GALE_MODE', 'http'),     // Default transport: 'http' or 'sse'
'debug' => env('GALE_DEBUG', false),      // Enable debug panel + logging

'security' => [
    'checksum' => ['enabled' => false],    // HMAC state verification
    'csp_nonce' => null,                   // CSP nonce for script tags
    'xss' => [/* sanitizer allowlist */],
],

'redirect' => [
    'allow_external' => false,
    'allowed_domains' => [],
],

'route_discovery' => [
    'enabled' => false,
    'discover_controllers_in_directory' => [],
],
```
