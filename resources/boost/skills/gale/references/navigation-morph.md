# Navigation & Morph Reference

Complete reference for SPA navigation, the 9 morph modes, history cache, prefetch, and View Transitions.

## SPA Navigation

### x-navigate Directive

Intercepts link clicks and form submissions for client-side navigation.

```html
<!-- Links -->
<a href="/products" x-navigate>Products</a>
<a href="/products" x-navigate.replace>Products (replaceState)</a>
<a href="/products?page=2" x-navigate.key="pagination">Page 2</a>

<!-- Forms -->
<form action="/search" method="GET" x-navigate>
    <input name="q">
    <button>Search</button>
</form>
```

**Not intercepted**: External links (different host), `target="_blank"`, Ctrl/Cmd+click, right-click.

### $navigate Magic

Programmatic navigation from Alpine expressions.

```html
<button @click="$navigate('/dashboard')">Go</button>
<button @click="$navigate('/products', { replace: true, key: 'main' })">Products</button>
```

Options:
- `replace` (bool) — use `replaceState` instead of `pushState`
- `key` (string) — navigate key for partial updates
- `merge` (bool) — merge query params with current URL
- `only` (string[]) — keep only specified query params
- `except` (string[]) — remove specified query params

### Backend Navigation

```php
// Navigate with pushState
gale()->navigate('/products?page=2', 'pagination');

// Navigate with array query params (path from current request)
gale()->navigate(['page' => 2, 'sort' => 'name'], 'filters');

// Convenience methods
gale()->navigateMerge(['page' => 2], 'pagination');     // merge: true
gale()->navigateClean('/products', 'nav');                // merge: false
gale()->navigateOnly('/products?a=1&b=2', ['a']);        // keep only 'a'
gale()->navigateExcept('/products?a=1&b=2', ['b']);      // drop 'b'
gale()->navigateReplace('/products', 'nav');              // replaceState

// Query parameter updates (in-place, no full navigation)
gale()->updateQueries(['search' => 'laptop', 'page' => 1]);
gale()->clearQueries(['search', 'filter']);
```

**Rule**: Only ONE `navigate()` call per response.

### Navigate Key Concept

Navigate keys enable partial page updates. The server can respond differently based on which navigate key triggered the request.

```blade
<nav>
    <a href="/products" x-navigate.key="main">Products</a>
    <a href="/settings" x-navigate.key="main">Settings</a>
</nav>

<main id="main-content">
    <!-- This area updates based on navigate key -->
</main>
```

```php
public function products()
{
    return gale()
        ->whenGaleNavigate('main', fn($g) => $g->fragment('pages.products', 'main', $data))
        ->view('pages.products', $data, [], web: true);
}
```

The frontend sends `GALE-NAVIGATE: true` and `GALE-NAVIGATE-KEY: main` headers.

### Navigation Configuration

```js
Alpine.gale.configureNavigation({
    interceptLinks: true,   // Intercept <a> clicks
    interceptForms: true,   // Intercept <form> submissions
    updateHistory: true,    // Update browser history
    defaultMode: 'push',    // 'push' or 'replace'
});
```

## History Cache

Caches page snapshots in `sessionStorage` for instant back/forward navigation.

### Configuration

```js
Alpine.gale.configure({
    historyCache: { maxSize: 10 },  // Object with maxSize
    // historyCache: false,          // Disable entirely
    // historyCache: true,           // Use defaults (maxSize: 10)
});
```

### API

```js
Alpine.gale.clearHistoryCache();       // Clear all cached pages
Alpine.gale.getHistoryCacheSize();     // Number of cached entries
Alpine.gale.getHistoryCacheKeys();     // Array of cached URLs
Alpine.gale.bustHistoryCache('/url');   // Bust specific URL
Alpine.gale.bustHistoryCache();        // Bust all entries
```

### How It Works

1. Before navigating away, current page HTML is saved to `sessionStorage`
2. On popstate (back/forward), cached HTML is restored instantly
3. LRU eviction when `maxSize` is reached
4. Server can bust cache via `X-Gale-Cache-Bust` response header

## Prefetch

Prefetches navigation targets on hover for faster perceived navigation.

### Configuration

```js
Alpine.gale.configure({
    prefetch: true,                    // Enable globally for all x-navigate links
    // prefetch: false,                // Disable globally (default)
    // prefetch: { delay: 65, maxSize: 20, ttl: 30000 },  // Fine-grained
});
```

| Option | Default | Description |
|--------|---------|-------------|
| `delay` | 65 | ms delay before prefetch starts on hover |
| `maxSize` | 20 | Max prefetch cache entries |
| `ttl` | 30000 | Cache TTL in ms (30 seconds) |

### Per-Link Control

```html
<!-- Enable prefetch on specific link -->
<a href="/products" x-navigate x-prefetch>Products</a>

<!-- Disable prefetch on specific link (when global is enabled) -->
<a href="/heavy-page" x-navigate x-prefetch="false">Heavy</a>
```

### API

```js
Alpine.gale.clearPrefetchCache();
Alpine.gale.getPrefetchCacheSize();
Alpine.gale.getPrefetchCacheKeys();
Alpine.gale.getPrefetchConfig();
```

## View Transitions API

Wraps SPA navigation DOM updates in `document.startViewTransition()` for smooth animated page transitions.

### Configuration

```js
Alpine.gale.configure({
    viewTransitions: true,    // Enable (default)
    // viewTransitions: false, // Disable
});
```

Per-navigation override via `{ transition: false }` option.

Falls back gracefully when browser does not support `document.startViewTransition`.

### CSS Integration

```css
/* Default cross-fade */
::view-transition-old(root),
::view-transition-new(root) {
    animation-duration: 0.3s;
}

/* Named transitions */
[style*="view-transition-name: hero"] {
    view-transition-name: hero;
}
```

## FOUC Prevention

Prevents Flash of Unstyled Content during SPA navigation when new page includes external stylesheets.

```js
Alpine.gale.configure({
    foucTimeout: 3000,           // Max ms to wait for stylesheets (default: 3000)
    navigationIndicator: true,   // Show progress bar during wait (default: true)
});
```

**How it works**: After a SPA navigation inserts new HTML, Gale checks for unloaded `<link rel="stylesheet">` tags. If found, it waits up to `foucTimeout` ms for them to load before displaying the content. A progress bar is shown during the wait if `navigationIndicator` is true.

## DOM Morph Modes

### Mode Matrix

| Mode | Element | State | Alpine Integration | When to Use |
|------|---------|-------|--------------------|-------------|
| `outer` (DEFAULT) | Replace entirely | Server-driven | `Alpine.morph()` + reinit | Most common. Server controls state. |
| `inner` | Replace inner HTML | Server-driven | `morphInnerHTML()` + reinit | Update children, keep wrapper. |
| `outerMorph` | Smart morph | Client-preserved | `Alpine.morph()` | Preserve form inputs, counters. |
| `innerMorph` | Smart morph children | Client-preserved | `Alpine.morph()` | Preserve wrapper state + morph children. |
| `append` | Add as last child | New only | `Alpine.initTree()` | Add items to end of list. |
| `prepend` | Add as first child | New only | `Alpine.initTree()` | Add items to start of list. |
| `before` | Insert before | New only | `Alpine.initTree()` | Insert sibling before element. |
| `after` | Insert after | New only | `Alpine.initTree()` | Insert sibling after element. |
| `remove` | Delete element | N/A | Cleanup Alpine data | Remove elements from DOM. |

### Backend API

```php
// DEFAULT: Replace element entirely (server-driven state)
gale()->outer('#counter', '<div id="counter" x-data="{ count: 5 }">5</div>');

// Replace inner content only
gale()->inner('#list', '<li>Item 1</li><li>Item 2</li>');

// Smart morph — preserves client state (form inputs, etc.)
gale()->outerMorph('#form', $html);
gale()->innerMorph('#form', $html);

// Aliases
gale()->morph('#el', $html);    // Alias for outerMorph
gale()->replace('#el', $html);  // Alias for outer

// Insertion modes
gale()->append('#list', '<li>New item</li>');
gale()->prepend('#list', '<li>First item</li>');
gale()->before('#target', '<div>Above</div>');
gale()->after('#target', '<div>Below</div>');

// Removal
gale()->remove('#item-42');
gale()->delete('#item-42');  // Alias
```

### Common Options

All DOM methods accept an `$options` array:

| Option | Type | Description |
|--------|------|-------------|
| `selector` | string | CSS selector (auto-set by named methods) |
| `mode` | string | Morph mode (auto-set by named methods) |
| `useViewTransition` | bool | Wrap in View Transitions API |
| `settle` | int | Settle delay in ms for CSS transitions |
| `limit` | int | Max number of targets to patch |
| `scroll` | string | Auto-scroll: `'top'` or `'bottom'` |
| `show` | string | Scroll into view: `'top'` or `'bottom'` |
| `focusScroll` | bool | Restore focus scroll position |

### Using view() and fragment()

```php
// Full view morph (with web: true for dual-mode)
gale()->view('products.index', $data, [], web: true);

// Fragment-only morph (partial rendering)
gale()->fragment('products.index', 'product-list', ['products' => $products]);

// Fragment with custom selector
gale()->fragment('products.index', 'product-list', $data, ['selector' => '#product-list']);

// Multiple fragments in one response
gale()->fragments([
    ['view' => 'dashboard', 'fragment' => 'stats', 'data' => ['stats' => $stats]],
    ['view' => 'dashboard', 'fragment' => 'chart', 'data' => ['chart' => $chart]],
]);
```

### Raw HTML

```php
gale()->html('<div id="alert">Success!</div>', ['selector' => '#alerts', 'mode' => 'append']);
```

### Morph Lifecycle Hooks

Frontend hooks for custom behavior during morph operations.

```js
const unregister = Alpine.gale.onMorph({
    beforeUpdate(el, newEl) {
        // Save state before morph
    },
    afterUpdate(el, newEl) {
        // Re-initialize after morph
    },
    beforeRemove(el) {
        // Cleanup before removal
        return false; // Return false to prevent removal
    },
    afterRemove() {
        // Post-removal cleanup
    },
    afterAdd(el) {
        // Initialize newly added element
    },
});

// Later: unregister();
```

### Transition-Aware Morphing

When `morphTransitions: true` (default), morph operations check if target elements have active CSS transitions. If a transition is in progress, the morph is deferred until `transitionend` fires (with a 500ms safety timeout).

```js
Alpine.gale.configure({ morphTransitions: true });  // Default
Alpine.gale.configure({ morphTransitions: false }); // Disable deferral
```

### x-morph-ignore

Elements with `x-morph-ignore` attribute are completely skipped during morph operations.

```html
<div x-morph-ignore>
    <!-- This content is never touched by morph -->
    <div class="tiptap-editor">...</div>
</div>
```

Used by RTE compatibility (`setupRteCompat()`) to protect editor instances.

### Morph Markers

When `config('gale.morph_markers') = true` (default), Gale injects HTML comment markers around rendered content. These markers help the morph algorithm identify stable boundaries for more accurate patching.

## Swap/Settle Lifecycle

DOM patching follows a swap-settle lifecycle for smooth transitions:

1. **Swap**: New content is inserted into the DOM
2. **Settle**: After a configurable delay, CSS classes are applied/removed

```js
Alpine.gale.configureSwapSettle({
    timing: { settleDelay: 100 }  // ms between swap and settle
});
```

This enables CSS transition patterns:

```css
.gale-settling {
    opacity: 0;
    transition: opacity 0.2s;
}
.gale-settled {
    opacity: 1;
}
```

## Full Page Reload

```php
gale()->reload();  // Forces window.location.reload()
```

Use sparingly — defeats the purpose of SPA navigation.
