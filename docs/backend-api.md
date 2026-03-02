# Backend API Reference

> **See also:** [Core Concepts](core-concepts.md) | [Frontend API Reference](frontend-api.md)

Complete reference for the `gale()` helper and `GaleResponse` builder.

> This guide is a placeholder. Full content is added by F-098 (Backend API Reference).

---

## The `gale()` Helper

The `gale()` helper returns a `GaleResponse` singleton that provides a fluent API for
building server responses.

```php
use DancyCodes\Gale\Http\GaleResponse;

// gale() returns GaleResponse
$response = gale()->patchState(['count' => 1]);
```

---

## GaleResponse Methods

### `view(string $view, array $data = []): GaleResponse`

Render a full Blade view and return it as a `gale-patch-elements` event. Used for initial
page loads and full-page content replacements.

```php
return gale()->view('my-view', ['items' => $items]);
```

### `fragment(string $view, string $fragmentId, array $data = []): GaleResponse`

Render only a named `@fragment` block from a Blade view. Only the fragment is compiled and
rendered — the full view template is never loaded.

```php
return gale()->fragment('my-view', 'item-list', ['items' => $items]);
```

### `patchState(array $state): GaleResponse`

Merge state into the Alpine component via RFC 7386 JSON Merge Patch.

```php
return gale()->patchState(['count' => $count, 'loading' => false]);
```

### `patchElements(string $html): GaleResponse`

Morph specific DOM elements from an HTML string. The HTML must contain elements with `id`
attributes that match existing DOM elements.

```php
return gale()->patchElements(view('partials.list', ['items' => $items])->render());
```

### `patchComponent(string $name, array $state): GaleResponse`

Patch the state of a named component registered with `x-component`.

```php
return gale()->patchComponent('cart', ['total' => $total, 'count' => $count]);
```

### `redirect(string $url): GaleResponse`

Perform a full-page redirect. For Gale requests, this is converted to a script event that
sets `window.location.href`. For non-Gale requests, this is a standard HTTP redirect.

```php
return gale()->redirect(route('dashboard'));
```

### `download(string $path, string $filename = null): GaleResponse`

Trigger a file download response.

```php
return gale()->download(storage_path('exports/report.pdf'), 'report.pdf');
```

### `stream(callable $callback): Response`

Open an SSE stream and execute the callback. Inside the callback, you can call
`gale()->patchState()`, `gale()->patchElements()`, and other methods to push events
to the client in real time.

```php
return gale()->stream(function () {
    foreach ($items as $item) {
        gale()->patchState(['current' => $item->id]);
        usleep(500000);
    }
});
```

### `messages(MessageBag $messages): GaleResponse`

Send validation messages to the frontend. Automatically triggered by `ValidationException`.

```php
return gale()->messages($validator->errors());
```

### `flash(string|array $key, mixed $value = null): GaleResponse`

Deliver flash data to both the session and the frontend `_flash` state key.

```php
return gale()->flash('success', 'Record saved successfully.');
// or
return gale()->flash(['success' => 'Saved', 'count' => $count]);
```

### `debug(mixed ...$values): GaleResponse`

Output debug values to the Gale Debug Panel in development mode. No-op in production.

```php
return gale()->debug($request->all())->patchState(['count' => $count]);
```

### `forceHttp(): GaleResponse`

Force the response to use HTTP JSON mode regardless of the request mode or global config.

```php
return gale()->messages($errors)->forceHttp();
```

---

## Blade Directives

### `@gale`

Place in `<head>`. Outputs the Alpine Gale plugin script, CSRF meta tag, and configuration.
Replaces any Alpine.js CDN link.

```html
<head>
    @gale
</head>
```

### `@fragment('id') ... @endfragment`

Wrap a section of a Blade view to make it extractable via `gale()->fragment()`.

```html
@fragment('item-list')
<ul id="item-list">
    @foreach ($items as $item)
        <li>{{ $item->name }}</li>
    @endforeach
</ul>
@endfragment
```

### `@ifgale ... @endifgale`

Render content only when the current request is a Gale request.

```html
@ifgale
    {{-- Only rendered for Gale requests --}}
@endifgale
```

---

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=gale-config
```

Key configuration options in `config/gale.php`:

```php
return [
    'mode' => 'http',       // 'http' or 'sse' — default transport mode
    'debug' => false,       // Enable debug panel (set true in development)
    'checksum' => [
        'enabled' => true,  // State integrity verification
    ],
    'sanitize' => [
        'enabled' => true,  // XSS sanitization for DOM patching
    ],
];
```

---

## Next Steps

- Read [Frontend API Reference](frontend-api.md) for Alpine Gale magics
- Read [Forms, Validation & Uploads](forms-validation-uploads.md) for form patterns
- Read [Debug & Troubleshooting](debug-troubleshooting.md) for the debug tools
