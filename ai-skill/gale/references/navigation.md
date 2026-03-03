# Navigation & SPA Reference

Complete guide for SPA navigation with Gale: `x-navigate`, `$navigate`, PRG pattern, history cache, prefetching, scroll restoration, FOUC prevention, and navigation events.

---

## Table of Contents

- [How Navigation Works](#how-navigation-works)
- [x-navigate Directive](#x-navigate-directive)
- [$navigate Magic](#navigate-magic)
- [History & Caching](#history--caching)
- [FOUC Prevention](#fouc-prevention)
- [Prefetching](#prefetching)
- [POST Navigation (PRG)](#post-navigation-prg)
- [Navigation Events](#navigation-events)
- [Server-Side Controllers](#server-side-controllers)
- [Edge Cases](#edge-cases)

---

## How Navigation Works

```
User clicks x-navigate link
  ↓ Gale intercepts click, prevents browser navigation
  ↓ Saves current DOM + scroll to history cache
  ↓ GET request with Gale-Navigate: true header
  ↓ Receives full HTML from server
  ↓ Morphs <body> in-place (Alpine state preserved where possible)
  ↓ history.pushState updates URL
  ↓ Scrolls to top (or to #hash anchor)
```

---

## x-navigate Directive

### On links
```html
<a href="/dashboard" x-navigate>Dashboard</a>
```

### On containers (delegates to all child links)
```html
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="/about">About</a>
    <a href="https://external.com" x-navigate-skip>External</a>
</nav>
```

### Modifiers

| Modifier | Description |
|----------|-------------|
| `.replace` | Replace history entry (no back navigation to this page) |
| `.merge` | Keep current query params, merge new ones |
| `.only.key1.key2` | Keep only listed query params |
| `.except.key1` | Remove listed query params |
| `.key.name` | Send `Gale-Navigate-Key: name` header |
| `.debounce.300ms` | Debounce navigation |
| `.throttle.500ms` | Throttle navigation |

```html
<a href="/dashboard" x-navigate.replace>Dashboard</a>
<a href="?page=2" x-navigate.merge>Next Page</a>
<nav x-navigate.key.sidebar>
    <a href="/settings/profile">Profile</a>
    <a href="/settings/billing">Billing</a>
</nav>
```

### Auto-skipped (no interception)
- `target="_blank"`, `download` attribute
- External URLs (different origin)
- `mailto:`, `tel:`, `blob:`, `javascript:`, `data:` protocols
- File extensions: `.pdf`, `.csv`, `.zip`, `.xlsx`
- Hash-only links (`href="#"`)
- Modifier keys held (Ctrl, Cmd, Shift)

---

## $navigate Magic

```html
<button @click="$navigate('/dashboard')">Go</button>
<button @click="$navigate('/login', { replace: true })">Login</button>
```

**Options:** `replace`, `merge`, `only`, `except`, `key`, `transition`

```html
<!-- Navigate after action -->
<button @click="async () => { await $action('/save'); await $navigate('/list'); }">
    Save & Go
</button>

<!-- Conditional destination -->
<button @click="$navigate(role === 'admin' ? '/admin' : '/home')">Dashboard</button>
```

---

## History & Caching

### Back/Forward
Gale's `popstate` handler:
1. Checks history cache for DOM snapshot
2. Found → restores instantly (no server request)
3. Not found → fetches from server
4. Restores scroll position

### History Cache Config
```javascript
Alpine.gale.configure({
    history: { cacheSize: 10 },  // Default: 10 pages cached
    // history: false,            // Disable caching
});
```

Cache stored in `sessionStorage` under `gale-history:{url}` keys. LRU eviction. Max 5MB per entry.

### Server-Side Cache Busting
```php
return gale()->view('posts.index', $data)
    ->withHeaders(['Gale-Cache-Bust' => 'true']);           // Bust current page
    // ->withHeaders(['Gale-Cache-Bust' => '/posts/42']);     // Bust specific URL
    // ->withHeaders(['Gale-Cache-Bust' => '/a, /b']);        // Bust multiple
```

### Scroll Restoration
- **Forward nav:** scrolls to top (or `#hash` anchor)
- **Back/forward:** restores saved scroll position
- Approximate — dynamic content may cause minor shifts

---

## FOUC Prevention

### CSS Loading State
Gale adds `gale-navigating` class to `<html>` during navigation:

```css
html.gale-navigating main {
    opacity: 0.5;
    transition: opacity 150ms ease;
}

/* Progress bar */
html.gale-navigating::before {
    content: '';
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: #6366f1;
    animation: gale-loading 1s ease-in-out infinite;
    z-index: 9999;
}
```

### View Transitions API
```javascript
Alpine.gale.configure({ viewTransitions: true });
```

### Persistent Layout
Keep layout elements (nav, sidebar, header) in the outer structure. They survive morphs unchanged. Only the content region morphs.

---

## Prefetching

### Per-Link
```html
<a href="/dashboard" x-navigate x-prefetch>Dashboard</a>
<a href="/profile" x-navigate x-prefetch.200ms>Profile</a>
```

### Global
```javascript
Alpine.gale.configure({
    prefetch: true,
    // prefetch: { delay: 100, maxSize: 5, ttl: 30000 },
});
```

### Disable for Specific Link
```html
<a href="/large-report" x-navigate x-prefetch="false">Report</a>
```

Default: 65ms hover delay, 5 max cached, 30s TTL. Cancels if mouse leaves before delay.

---

## POST Navigation (PRG)

```html
<form method="POST" action="/contacts" x-navigate>
    @csrf
    <input type="text" name="name">
    <span x-message="name"></span>
    <button type="submit">Create</button>
</form>
```

Flow:
1. Form serialized (including files if present)
2. POST with `Gale-Request: true`
3. Validation fails → errors applied reactively (no redirect)
4. Success → server returns `redirect()` → Gale navigates to target via SPA
5. Back from POST result → browser confirmation dialog

### Method Spoofing
```html
<form method="POST" action="/contacts/5" x-navigate>
    @csrf
    @method('DELETE')
    <button type="submit">Delete</button>
</form>
```

---

## Navigation Events

| Event | Detail | When |
|-------|--------|------|
| `gale:navigate` | `{ url, method, replace }` | Navigation starts |
| `gale:navigated` | `{ url }` | Navigation completed |
| `gale:navigate-error` | `{ url, status, message }` | Navigation failed |

```javascript
// Analytics
document.addEventListener('gale:navigated', (e) => {
    gtag('event', 'page_view', { page_location: e.detail?.url });
});
```

---

## Server-Side Controllers

```php
// Basic — no special handling needed
public function show(Contact $contact): mixed
{
    return gale()->view('contacts.show', compact('contact'), web: true);
}

// Fragment response for keyed navigation
public function index(Request $request): mixed
{
    $data = ['contacts' => Contact::paginate(20)];

    if ($request->isGaleNavigate('sidebar')) {
        return gale()
            ->fragment('contacts.index', 'sidebar', $data)
            ->fragment('contacts.index', 'list', $data);
    }

    return gale()->view('contacts.index', $data, web: true);
}

// Read navigate key
$key = $request->header('Gale-Navigate-Key'); // 'sidebar'
```

---

## Edge Cases

- **Buttons:** `x-navigate` on `<button>` doesn't work — use `$navigate()` instead
- **Private browsing:** `sessionStorage` may be unavailable → caching silently disabled
- **In-flight requests:** All pending Gale requests aborted when navigation starts
- **Hash changes:** Pure `#hash` changes are browser-native, no Gale request
- **External redirects:** Configure `gale.redirect.allowed_domains` in config
