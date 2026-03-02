# Navigation & SPA

> **See also:** [Frontend API Reference](frontend-api.md) | [Core Concepts](core-concepts.md)

Build SPA-style navigation with `x-navigate`, `$navigate`, and POST form navigation.
Covers the PRG pattern, history cache, link prefetching, scroll restoration, and the
`gale:navigate:*` event system.

> This guide is a placeholder. Full content is added by F-100 (Navigation & SPA Guide).

---

## SPA Navigation with `x-navigate`

Add `x-navigate` to a container to enable SPA navigation for all anchor clicks inside it.
Gale intercepts the click, fetches the new page content, and morphs the DOM without a full
reload.

```html
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="/about">About</a>
    <a href="/contact">Contact</a>
</nav>
```

Or add `x-navigate` to a specific `<a>` element:

```html
<a href="/dashboard" x-navigate>Dashboard</a>
```

---

## Programmatic Navigation with `$navigate`

Navigate programmatically from Alpine component logic:

```html
<div x-data="{ goToDashboard() { $navigate('/dashboard') } }">
    <button @click="goToDashboard()">Dashboard</button>
</div>
```

---

## POST Form Navigation (PRG Pattern)

Add `x-navigate` to a `<form>` element with `method="POST"` to use the Post/Redirect/Get
pattern via SPA navigation. The form submits, the server redirects, and Gale navigates to
the redirect URL without a full page reload.

```html
<form method="POST" action="/search" x-navigate>
    @csrf
    <input name="q" type="text" placeholder="Search...">
    <button type="submit">Search</button>
</form>
```

---

## History Cache

Gale caches recently visited page states in memory. When you navigate back with the browser
back button, the cached state is restored instantly without a server request.

The cache size is configurable:

```javascript
Alpine.gale.configure({
    history: {
        cacheSize: 10,  // Number of pages to cache (default: 10)
    },
});
```

---

## Link Prefetching

Prefetch page content on hover for instant navigation:

```html
<a href="/dashboard" x-navigate x-prefetch>Dashboard</a>
```

Or configure global prefetch delay:

```javascript
Alpine.gale.configure({
    prefetch: {
        delay: 100,  // Hover delay in ms before prefetching (default: 100)
    },
});
```

---

## Scroll Restoration

Gale preserves scroll position when navigating back in history. When navigating forward,
the page scrolls to the top.

---

## Navigation Events

Listen to navigation lifecycle events on `document`:

```javascript
document.addEventListener('gale:navigate:start', (e) => {
    console.log('Navigating to:', e.detail.url);
});

document.addEventListener('gale:navigate:end', (e) => {
    console.log('Navigation complete:', e.detail.url);
});
```

---

## Server-Side Navigation Controllers

Navigation requests include the `GALE-NAVIGATE: true` header. Use `gale()->view()` to
return the full page content for navigation requests:

```php
public function show(): GaleResponse
{
    return gale()->view('dashboard', ['data' => $this->getData()]);
}
```

---

## Next Steps

- Read [Forms, Validation & Uploads](forms-validation-uploads.md) for POST form details
- Read [Frontend API Reference](frontend-api.md) for all navigation magics
