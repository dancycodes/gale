# Navigation & SPA Guide

> **See also:** [Core Concepts](core-concepts.md) | [Frontend API Reference](frontend-api.md) | [Backend API Reference](backend-api.md) | [Forms, Validation & Uploads](forms-validation-uploads.md)

Gale turns any multi-page Laravel application into a single-page app (SPA) with a single
directive. Navigation fetches the new page from the server and morphs the `<body>` content
in-place -- no full page reload, no lost Alpine state in persistent layout regions, no
white flash.

This guide covers everything from basic link navigation to advanced patterns like
prefetching, POST form navigation, scroll management, and FOUC prevention.

> **Prerequisite:** Your layout must include `@gale` in the `<head>`. This loads Alpine.js
> and the Gale plugin, which powers all navigation features.

---

## Table of Contents

- [How Navigation Works](#how-navigation-works)
- [x-navigate Directive](#x-navigate-directive)
  - [Basic Usage](#basic-usage)
  - [Container Navigation](#container-navigation)
  - [Modifiers Reference](#modifiers-reference)
  - [Opting Out](#opting-out)
  - [Supported Elements](#supported-elements)
- [$navigate Magic](#navigate-magic)
- [History Management](#history-management)
  - [Back/Forward Behavior](#backforward-behavior)
  - [History Cache](#history-cache)
  - [Scroll Restoration](#scroll-restoration)
- [FOUC Prevention](#fouc-prevention)
  - [GALE-NAVIGATE-KEY Mechanism](#gale-navigate-key-mechanism)
  - [Persistent Layout Strategy](#persistent-layout-strategy)
  - [Transition Strategies](#transition-strategies)
- [Prefetching](#prefetching)
  - [Per-Link Prefetch](#per-link-prefetch)
  - [Global Prefetch](#global-prefetch)
  - [Cache Behavior](#cache-behavior)
  - [Disabling Prefetch](#disabling-prefetch)
- [POST Navigation (PRG Pattern)](#post-navigation-prg-pattern)
  - [Basic POST Form](#basic-post-form)
  - [Complete Flow](#complete-flow)
  - [Back Navigation After POST](#back-navigation-after-post)
- [Hash Handling](#hash-handling)
- [Navigation Events](#navigation-events)
  - [Event Reference](#event-reference)
  - [Loading Indicator Example](#loading-indicator-example)
  - [Analytics Example](#analytics-example)
- [Alpine Component Lifecycle During Navigation](#alpine-component-lifecycle-during-navigation)
- [Server-Side Navigation Controllers](#server-side-navigation-controllers)
- [Common Patterns](#common-patterns)
  - [Sidebar Navigation](#sidebar-navigation)
  - [Tabbed Interface](#tabbed-interface)
  - [Multi-Step Wizard](#multi-step-wizard)
- [Edge Cases & Limitations](#edge-cases--limitations)

---

## How Navigation Works

When a user clicks an `x-navigate` link, Gale:

1. Intercepts the click and prevents browser navigation.
2. Dispatches a `gale:navigate` event on `document`.
3. Saves the current page DOM and scroll position to the history cache.
4. Sends a `GET` request with the `Gale-Navigate: true` header.
5. Receives the full HTML response from the server.
6. Morphs the new `<body>` content into the existing DOM, preserving Alpine component state where possible.
7. Updates the browser URL via `history.pushState`.
8. Scrolls to the top (or to the hash anchor if the URL contains a fragment).

The `Gale-Navigate: true` header signals to the controller that a SPA navigation is in progress.
Controllers always return `gale()->view()` — no special handling is required.

```
User clicks link
    ↓ Gale intercepts click
    ↓ GET /page (Gale-Navigate: true)
Server returns full HTML
    ↓ Body morphed in-place (Alpine state preserved where possible)
    ↓ history.pushState('/page')
Browser shows new content without reload
```

---

## x-navigate Directive

### Basic Usage

Add `x-navigate` to an anchor tag to enable SPA navigation for that link:

```html
<a href="/dashboard" x-navigate>Dashboard</a>
```

Gale intercepts the click, fetches `/dashboard`, and morphs the page content. The URL updates
to `/dashboard` in the browser address bar without a full page reload.

### Container Navigation

Add `x-navigate` to a container element to enable SPA navigation for all links inside it:

```html
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="/about">About</a>
    <a href="/contact">Contact</a>
</nav>
```

This is the recommended pattern for navigation menus. Every anchor click inside the container
is intercepted. If a child link also has its own `x-navigate`, the child's handler takes
priority and the container handler is skipped — exactly one navigation fires per click.

### Modifiers Reference

| Modifier | Description |
|----------|-------------|
| `.replace` | Replace the current history entry instead of pushing a new one |
| `.merge` | Keep current query params and merge new ones on top |
| `.only.key1.key2` | Keep only the listed query params from the current URL |
| `.except.key1` | Keep all current query params except the listed ones |
| `.key.name` | Send a `Gale-Navigate-Key: name` header for backend filtering |
| `.debounce.300ms` | Debounce navigation — useful for keyboard-driven navigation |
| `.throttle.500ms` | Throttle navigation to at most once per interval |
| `.preserveEmpty` | When merging, preserve empty-string query params instead of stripping them |

**Examples:**

```html
<!-- Replace history (Login → Dashboard should not allow back to Login) -->
<a href="/dashboard" x-navigate.replace>Dashboard</a>

<!-- Merge: keep ?tab= when paginating -->
<a href="?page=2" x-navigate.merge>Next Page</a>

<!-- Only keep the page param when paginating -->
<a href="?page=3" x-navigate.only.page>Page 3</a>

<!-- Sidebar navigation with a key for backend to filter content -->
<nav x-navigate.key.sidebar>
    <a href="/settings/profile">Profile</a>
    <a href="/settings/billing">Billing</a>
</nav>
```

### Opting Out

Add `x-navigate-skip` to a link (or any ancestor) to prevent the container from intercepting it:

```html
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="https://external.com" x-navigate-skip>External (full reload)</a>
    <a href="/download/report.pdf" x-navigate-skip>Download PDF</a>
</nav>
```

### Supported Elements

`x-navigate` works on:

| Element | Behavior |
|---------|----------|
| `<a>` | Intercepts click, navigates to `href` |
| `<form>` | Intercepts submit event (see [POST Navigation](#post-navigation-prg-pattern)) |
| Any container | Delegates to child `<a>` and `<form>` elements |

**Automatically skipped** (browser handles normally, no interception):

- Links with `target="_blank"` (opens in new tab)
- Links with the `download` attribute
- External URLs (different origin)
- `mailto:`, `tel:`, `blob:`, `javascript:`, `data:` protocols
- File extension links (`.pdf`, `.csv`, `.zip`, `.xlsx`, etc.)
- Hash-only links (`href="#"`)
- Links with modifier keys held (Ctrl, Cmd, Shift — opens in new tab)

> **Note:** `x-navigate` only works for same-origin URLs. Clicking an external link with
> `x-navigate` falls through to normal browser navigation.

> **Note:** For buttons and other non-anchor elements, use `$navigate` instead.

---

## $navigate Magic

`$navigate` triggers programmatic SPA navigation from JavaScript:

**Signature:** `$navigate(url: string, options = {}): Promise<void>`

```html
<div x-data>
    <button @click="$navigate('/dashboard')">Go to Dashboard</button>
    <button @click="$navigate('/login', { replace: true })">Log in (replace history)</button>
</div>
```

**Options:**

| Option | Type | Description |
|--------|------|-------------|
| `replace` | `boolean` | Replace current history entry instead of pushing |
| `merge` | `boolean` | Merge current query params with new URL params |
| `only` | `string[]` | Keep only these query params from the current URL |
| `except` | `string[]` | Keep all query params except these |
| `key` | `string` | Navigation key sent as `Gale-Navigate-Key` header |
| `transition` | `boolean` | Set `false` to disable View Transitions for this navigation |

**Common use cases:**

```html
<!-- Navigate after a successful action -->
<div x-data="{ async save() { await $action('/save'); await $navigate('/list'); } }">
    <button @click="save()">Save and go to list</button>
</div>

<!-- Conditional navigation -->
<div x-data="{ role: 'admin' }">
    <button @click="$navigate(role === 'admin' ? '/admin' : '/home')">
        Go to dashboard
    </button>
</div>

<!-- Replace history (prevent back navigation to this page) -->
<div x-data>
    <button @click="$navigate('/dashboard', { replace: true })">
        Continue
    </button>
</div>
```

> **Tip:** `$navigate` is the right choice for buttons, select dropdowns, and other non-anchor
> elements. For standard links, prefer `x-navigate` on the `<a>` element.

---

## History Management

### Back/Forward Behavior

Gale integrates with the browser History API. Every SPA navigation calls `history.pushState`,
creating a new history entry. Pressing the browser back or forward button triggers Gale's
`popstate` handler, which:

1. Checks the history cache for a snapshot of the target URL.
2. If found: restores the DOM snapshot instantly (no server request).
3. If not found (cache miss or cache disabled): fetches the page from the server.
4. Restores the saved scroll position.

### History Cache

Gale stores DOM snapshots in `sessionStorage` for instant back-navigation without a server
round-trip.

**Storage details:**

- Key format: `gale-history:{url}` (fragment stripped — hash changes do not create new entries)
- Entry format: `{ html, scrollX, scrollY, timestamp }`
- LRU eviction: oldest entry removed when the cache is full
- Maximum snapshot size: 5 MB (larger pages are skipped silently)
- Scoped to the browser tab (sessionStorage — not shared across tabs)

**Configuration:**

```javascript
Alpine.gale.configure({
    historyCache: {
        maxSize: 10,  // Number of pages to cache (default: 10)
    },
});

// Disable caching entirely
Alpine.gale.configure({
    historyCache: false,
});
```

**Server-side cache busting:**

The server can invalidate one or more cache entries by sending the `Gale-Cache-Bust` response
header:

```php
// Bust the current page's cache entry
return gale()->view('posts.index', $data)
    ->withHeaders(['Gale-Cache-Bust' => 'true']);

// Bust a specific URL
return gale()->view('posts.index', $data)
    ->withHeaders(['Gale-Cache-Bust' => '/posts/42']);

// Bust multiple URLs
return gale()->view('posts.index', $data)
    ->withHeaders(['Gale-Cache-Bust' => '/posts/42, /posts']);
```

**Cache limits and eviction:**

When the cache reaches `maxSize`, the least recently used entry is evicted. If
`sessionStorage` is unavailable (e.g., private browsing mode, quota exceeded), caching is
silently skipped — navigation still works, just without the instant back-navigation benefit.

### Scroll Restoration

Gale saves the current scroll position before each navigation and restores it when the user
navigates back:

- **Forward navigation:** scrolls to the top (or to the hash anchor if the URL has a `#fragment`).
- **Back/forward navigation:** restores the scroll position saved at the time of the original navigation.
- **Cache restore:** scroll position is restored from the snapshot entry.
- **Server fetch fallback:** scroll position is restored from `history.state._galeScrollY`.

> **Note:** Scroll restoration is approximate. Alpine re-rendering and dynamic content loading
> may cause minor differences from the exact position at the time of navigation. For precise
> restoration, ensure page content is server-rendered (not loaded dynamically after init).

---

## FOUC Prevention

Flash of Unstyled Content (FOUC) occurs when new page content appears briefly without styles
before Alpine re-initializes. Gale prevents FOUC through two mechanisms.

### GALE-NAVIGATE-KEY Mechanism

The `Gale-Navigate-Key` header tells the server which part of the page content to return.
When the server receives a navigation request with this header, it can return only the content
relevant to the navigation region — avoiding a full-page re-render.

This is particularly useful for **partial navigation** (updating only a content region while
keeping the surrounding layout intact):

```html
<!-- Sidebar navigation — key tells server which panel is active -->
<nav x-navigate.key.sidebar>
    <a href="/settings/profile">Profile</a>
    <a href="/settings/billing">Billing</a>
</nav>
```

```php
// Server reads the key and returns appropriately
public function profile(Request $request): GaleResponse
{
    $key = $request->header('Gale-Navigate-Key'); // 'sidebar'

    return gale()->view('settings.profile', [
        'key' => $key,
    ]);
}
```

### Persistent Layout Strategy

The most reliable FOUC prevention is to keep layout elements (navigation, sidebar, header) in
the outer HTML structure and only morph the content region. Because Gale morphs the body
in-place, elements that remain structurally identical across pages survive the morph without
re-rendering.

**Recommended layout structure:**

```html
<!-- layouts/app.blade.php -->
<html>
<head>
    @gale
</head>
<body>
    <!-- Layout elements (survive morphs unchanged) -->
    <nav x-navigate>
        <a href="/dashboard">Dashboard</a>
        <a href="/contacts">Contacts</a>
    </nav>

    <!-- Content region (morphed on each navigation) -->
    <main>
        @yield('content')
    </main>
</body>
</html>
```

Elements with `x-data` that are identical in structure across pages will have their Alpine
state preserved during the morph (Alpine.js morphing is identity-preserving for matching
elements).

### Transition Strategies

**CSS transitions with `gale-navigating`:**

Gale adds the `gale-navigating` class to `<html>` during navigation. Use it to show a loading
state and dim the current content:

```css
/* Fade content during navigation */
html.gale-navigating main {
    opacity: 0.5;
    transition: opacity 150ms ease;
}
```

**View Transitions API (modern browsers):**

Gale supports the [View Transitions API](https://developer.mozilla.org/en-US/docs/Web/API/View_Transitions_API)
for smooth cross-fade animations. Enable globally:

```javascript
Alpine.gale.configure({
    viewTransitions: true,
});
```

With View Transitions enabled, the browser captures a screenshot of the current page, performs
the navigation, then cross-fades to the new content. The entire transition is handled by the
browser — no custom CSS required.

Disable for a specific navigation via `$navigate`:

```html
<div x-data>
    <button @click="$navigate('/page', { transition: false })">
        Navigate without transition
    </button>
</div>
```

> **Tip:** Always set `@gale` in your layout's `<head>`. The directive ensures Alpine and the
> Gale plugin are loaded before any component renders, preventing flicker on initial page load.

---

## Prefetching

Prefetching fetches page content in the background on hover, so the navigation appears instant
when the user clicks.

### Per-Link Prefetch

Add `x-prefetch` to any `x-navigate` link to enable prefetching for that link:

```html
<!-- Prefetch this link on hover (65ms default delay) -->
<a href="/dashboard" x-navigate x-prefetch>Dashboard</a>
```

The hover delay is 65ms by default (configurable globally via `prefetch.delay`). Gale waits
this long before firing the background fetch -- long enough that brief hover events (moving
the mouse across the link) do not trigger unnecessary requests.

### Global Prefetch

Enable prefetching for all `x-navigate` links globally:

```javascript
Alpine.gale.configure({
    prefetch: true,
});

// Or configure with options
Alpine.gale.configure({
    prefetch: {
        delay: 100,      // Hover delay in ms before fetching (default: 65)
        maxSize: 5,      // Maximum cached responses (default: 5)
        ttl: 30000,      // Cache TTL in milliseconds (default: 30000 = 30s)
    },
});
```

### Cache Behavior

The prefetch cache is in-memory (not `sessionStorage`) and is ephemeral — it lives for the
duration of the page session and is cleared on full page reload.

| Property | Default | Description |
|----------|---------|-------------|
| `delay` | 65ms | Hover time before fetching starts |
| `maxSize` | 5 | Maximum cached responses (LRU eviction) |
| `ttl` | 30s | How long a cached response stays fresh |

**Cancellation:**

- If the user moves the mouse off the link before the delay expires, the scheduled fetch is cancelled.
- If the user moves the mouse off after the fetch has started, the in-flight request is aborted.
- If the user clicks the link while it is being fetched, Gale waits for the fetch to complete and then uses the result instantly.

**Stale content:**

Prefetch cache entries expire after the configured TTL (default: 30 seconds). Expired entries
are transparently discarded — the navigation falls back to a fresh server request. To ensure
fresh content for a page that changes frequently, set a lower TTL or disable prefetching for
that link.

### Disabling Prefetch

Disable prefetching for a specific link when global prefetch is enabled:

```html
<!-- Global prefetch is on, but this link skips it -->
<a href="/large-report" x-navigate x-prefetch="false">Download Report</a>
```

> **Bandwidth consideration:** Only enable prefetching for pages users are likely to navigate to.
> Prefetch sends real network requests — on metered connections, unnecessary prefetches consume
> data. Use per-link `x-prefetch` for high-traffic navigation patterns rather than enabling
> global prefetch indiscriminately.

---

## POST Navigation (PRG Pattern)

Gale implements the Post/Redirect/Get (PRG) pattern for form submissions via SPA navigation.
The form submits, the server redirects, and Gale navigates to the redirect URL as a SPA
navigation — no full page reload required.

### Basic POST Form

Add `x-navigate` to a `<form>` element with `method="POST"`:

```html
<form method="POST" action="/contacts" x-navigate>
    @csrf
    <input type="text" name="name" placeholder="Name">
    <input type="email" name="email" placeholder="Email">
    <button type="submit">Create Contact</button>
</form>
```

When the form submits:

1. Gale serializes the form data (including files if any).
2. Sends a `POST` request with `Gale-Request: true` header.
3. The server processes the form and calls `redirect()`.
4. Gale intercepts the redirect and navigates to the redirect target as a SPA navigation.
5. The browser URL updates to the redirect target (e.g., `/contacts/42`).

### Complete Flow

```php
// ContactController.php
public function store(Request $request): mixed
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:contacts',
    ]);

    $contact = Contact::create($validated);

    // Gale converts this redirect to a SPA navigation
    return redirect()->route('contacts.show', $contact);
}
```

```php
// show() — the redirect target
public function show(Contact $contact): GaleResponse
{
    return gale()->view('contacts.show', ['contact' => $contact]);
}
```

**Validation errors:**

When validation fails, Gale applies the error messages reactively to the current page — the
URL does not change. No redirect is performed. Use `x-message` to display field errors:

```html
<form method="POST" action="/contacts" x-navigate>
    @csrf
    <input type="text" name="name" x-model="name">
    <span x-message="name"></span>

    <input type="email" name="email" x-model="email">
    <span x-message="email"></span>

    <button type="submit">Create Contact</button>
</form>
```

See [Forms, Validation & Uploads](forms-validation-uploads.md) for complete form handling docs.

**File uploads:**

`x-navigate` forms support file uploads — Gale automatically uses `multipart/form-data` when
file inputs are present:

```html
<form method="POST" action="/upload" x-navigate enctype="multipart/form-data">
    @csrf
    <input type="file" name="avatar">
    <button type="submit">Upload</button>
</form>
```

**PUT/DELETE via method spoofing:**

Use Laravel's `@method` directive for `PUT`, `PATCH`, and `DELETE` form submissions:

```html
<form method="POST" action="/contacts/{{ $contact->id }}" x-navigate>
    @csrf
    @method('DELETE')
    <button type="submit">Delete Contact</button>
</form>
```

### Back Navigation After POST

When the user navigates back from a POST result page (e.g., the contact show page reached via
PRG redirect), Gale shows a browser confirmation dialog:

> "You may need to re-submit the form to view this page. Continue?"

If the user confirms, Gale navigates back. If they cancel, they remain on the POST result page.
This prevents accidental form re-submission from back navigation — a standard web pattern.

> **Note:** POST pages are never saved to the history cache (BR-043.9). Only the redirect
> target (GET page) is cached.

---

## Hash Handling

Gale handles URL hash fragments (`#section-id`) during navigation:

- **Navigating to a URL with a hash:** Gale performs the SPA navigation (fetching the page content), then the browser scrolls to the element with the matching `id` attribute.
- **Hash-only changes (`#another-section`):** A pure hash change on the same page does not trigger a Gale navigation — the browser handles it natively.
- **History cache:** Hash fragments are stripped from cache keys. Navigating to `/page#section-a` and `/page#section-b` use the same cache entry for `/page`.

```html
<!-- Navigate to a section on another page -->
<a href="/docs#installation" x-navigate>Installation</a>

<!-- In-page hash navigation — handled by browser, no Gale request -->
<a href="#next-section">Next section</a>
```

---

## Navigation Events

Gale dispatches custom events on `document` throughout the navigation lifecycle. Listen to
these events to implement loading indicators, analytics, cleanup, and other navigation hooks.

### Event Reference

| Event | Dispatched on | Detail payload | Description |
|-------|--------------|----------------|-------------|
| `gale:navigate` | `document` | `{ url, method, replace, _isPostForm }` | Navigation starts (before fetch). Fired for both GET navigations and POST form submissions. |
| `gale:patch-complete` | `document` | `{ el, url }` | Full-page morph completed and Alpine has re-initialized on the new content. This is the "navigation finished" signal. |

> **Note:** The `_isPostForm` flag in `gale:navigate` is `true` for client-initiated POST
> form submissions (informational only). The backend navigation listener ignores events with
> this flag to avoid triggering duplicate GET requests.

> **Note:** There is no separate "navigation error" event. Navigation errors are handled via
> the general Gale error event system (`gale:error`, `gale:network-error`, `gale:server-error`).
> Additionally, a persistent error banner is shown on the page when navigation fails, and the
> URL bar is reverted to the original page URL.

### Loading Indicator Example

Implement a progress bar using `gale:navigate` and `gale:patch-complete`:

```html
<!-- Progress bar element -->
<div id="progress-bar" style="display:none; position:fixed; top:0; left:0; right:0; height:2px; background:#6366f1; z-index:9999;"></div>

<script>
const bar = document.getElementById('progress-bar');

document.addEventListener('gale:navigate', () => {
    bar.style.display = 'block';
    bar.style.width = '30%';
    bar.style.transition = 'width 300ms ease';
    // Animate to 80% while loading
    setTimeout(() => { bar.style.width = '80%'; }, 100);
});

document.addEventListener('gale:patch-complete', () => {
    bar.style.width = '100%';
    setTimeout(() => {
        bar.style.display = 'none';
        bar.style.width = '0';
    }, 200);
});
</script>
```

**CSS-only loading indicator (simpler approach):**

Gale adds `gale-navigating` to `<html>` while a navigation is in progress:

```css
/* Top progress bar using CSS animation */
html.gale-navigating::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: #6366f1;
    animation: gale-loading 1s ease-in-out infinite;
    z-index: 9999;
}

@keyframes gale-loading {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
```

### Analytics Example

Track page views for each SPA navigation:

```javascript
document.addEventListener('gale:patch-complete', (event) => {
    // event.detail.url is the URL after navigation
    const url = event.detail?.url ?? window.location.href;

    // Google Analytics 4
    gtag('event', 'page_view', {
        page_location: url,
    });
});
```

**Error tracking:**

```javascript
document.addEventListener('gale:error', (event) => {
    console.error('[Gale Error]', event.detail);

    // Send to your error tracking service
    Sentry.captureMessage('Gale request failed', {
        extra: event.detail,
    });
});
```

---

## Alpine Component Lifecycle During Navigation

When Gale morphs the page body during navigation, Alpine component lifecycle behaves as follows:

**Components that survive the morph (same structure):**

When a component's root element (`x-data`) is structurally identical in the old and new page,
the morph engine matches the elements and preserves Alpine's reactive data. The component
continues running — it does not re-initialize.

**Components that are removed:**

When a component does not exist on the new page, Alpine's `destroyTree` is called on it.
This runs all registered `cleanup()` callbacks — event listeners, watchers, and intervals are
cleaned up automatically.

**New components on the new page:**

Components that appear for the first time on the new page are initialized by `Alpine.initTree`
after the morph completes. They run `x-init` and initialize normally.

**Practical implications:**

- **Global state** (Alpine.store) persists across navigations — it is not mounted to the DOM.
- **Component-local state** (x-data) is preserved if the component root element survives the morph.
- **Event listeners** added inside Alpine directives are cleaned up automatically when the component is destroyed.
- **External JS libraries** (charts, editors) attached to DOM elements may need explicit cleanup — see [JS Compatibility](components-events-polling.md#morph-lifecycle-hooks) for `Alpine.gale.onMorph` hooks.

**Resetting state on navigation:**

If a component should reset its state on every navigation (e.g., a search form that should clear on page change), use `x-init` to respond to the `gale:patch-complete` event:

```html
<div
    x-data="{ query: '', results: [] }"
    x-init="document.addEventListener('gale:patch-complete', () => { query = ''; results = []; })"
>
    <input x-model="query" type="search" placeholder="Search...">
</div>
```

---

## Server-Side Navigation Controllers

Navigation requests include the `Gale-Navigate: true` header. Controllers do not need to check
this header for basic navigation — simply return `gale()->view()`:

```php
public function show(Contact $contact): GaleResponse
{
    return gale()->view('contacts.show', ['contact' => $contact]);
}
```

**Reading the navigate key:**

When using `x-navigate.key.name`, read the key from the request header:

```php
public function index(Request $request): GaleResponse
{
    $navigateKey = $request->header('Gale-Navigate-Key');

    // Use the key to filter or scope the response
    return gale()->view('contacts.index', [
        'key' => $navigateKey,
    ]);
}
```

**Detecting navigation requests:**

```php
use Illuminate\Http\Request;

public function show(Request $request, Contact $contact): GaleResponse
{
    // Navigation requests always have Gale-Navigate: true
    $isNavigation = $request->header('Gale-Navigate') === 'true';

    // Regular Gale reactive requests have Gale-Request: true
    $isGaleRequest = $request->isGale();

    return gale()->view('contacts.show', ['contact' => $contact]);
}
```

**Server-side redirect:**

To redirect to another page as a SPA navigation, use `gale()->redirect()`:

```php
public function store(Request $request): mixed
{
    $contact = Contact::create($request->validated());

    // Gale converts this to a SPA navigation on the client
    return gale()->redirect()->route('contacts.show', $contact);
}
```

---

## Common Patterns

### Sidebar Navigation

A sidebar that navigates to different sections while keeping the sidebar itself intact:

```html
<!-- layouts/app.blade.php -->
<body>
    <div class="flex h-screen">
        <!-- Sidebar — persists across navigations because it is in the shared layout -->
        <nav class="w-64 bg-gray-800 text-white p-4" x-navigate>
            <ul class="space-y-1">
                <li>
                    <a
                        href="/dashboard"
                        class="{{ request()->is('dashboard') ? 'bg-white/10' : '' }} block px-3 py-2 rounded hover:bg-white/10"
                    >
                        Dashboard
                    </a>
                </li>
                <li>
                    <a
                        href="/contacts"
                        class="{{ request()->is('contacts*') ? 'bg-white/10' : '' }} block px-3 py-2 rounded hover:bg-white/10"
                    >
                        Contacts
                    </a>
                </li>
                <li>
                    <a
                        href="/settings"
                        class="{{ request()->is('settings*') ? 'bg-white/10' : '' }} block px-3 py-2 rounded hover:bg-white/10"
                    >
                        Settings
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Content area — morphed on each navigation -->
        <main class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </main>
    </div>
</body>
```

The sidebar survives morphs because it is structurally identical on every page. The active
state is server-rendered via `request()->is()` — it updates correctly on each navigation
because the server returns the full page HTML.

### Tabbed Interface

A tabbed interface where each tab is a separate URL:

```html
<div x-data="{}">
    <!-- Tab bar -->
    <nav class="flex border-b" x-navigate>
        <a
            href="/settings/profile"
            class="px-4 py-2 text-sm font-medium
                {{ request()->is('settings/profile') ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700' }}"
        >
            Profile
        </a>
        <a
            href="/settings/billing"
            class="px-4 py-2 text-sm font-medium
                {{ request()->is('settings/billing') ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700' }}"
        >
            Billing
        </a>
        <a
            href="/settings/security"
            class="px-4 py-2 text-sm font-medium
                {{ request()->is('settings/security') ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700' }}"
        >
            Security
        </a>
    </nav>

    <!-- Tab content — changes with each navigation -->
    <div class="mt-6">
        @yield('tab-content')
    </div>
</div>
```

Each tab link navigates to a different URL. The active tab state is server-rendered. Back and
forward browser navigation works correctly because each tab has its own history entry.

### Multi-Step Wizard

A wizard where each step is a separate page, navigating forward via PRG:

```html
<!-- Step 1: Basic Info -->
<form method="POST" action="/wizard/step-1" x-navigate>
    @csrf
    <h2 class="text-xl font-bold mb-4">Step 1: Basic Information</h2>

    <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Name</label>
        <input type="text" name="name" class="w-full border rounded px-3 py-2">
        <span x-message="name" class="text-red-500 text-sm"></span>
    </div>

    <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" class="w-full border rounded px-3 py-2">
        <span x-message="email" class="text-red-500 text-sm"></span>
    </div>

    <!-- Progress indicator -->
    <p class="text-sm text-gray-500 mb-4">Step 1 of 3</p>

    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
        Next Step
    </button>
</form>
```

```php
// WizardController.php
public function storeStep1(Request $request): mixed
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email',
    ]);

    // Store wizard state in session
    session(['wizard' => array_merge(session('wizard', []), $validated)]);

    // PRG: navigate to step 2
    return redirect()->route('wizard.step2');
}
```

Each step is a separate URL. Validation errors are applied reactively on the current step
without navigation. Successful submission redirects to the next step via SPA navigation.

---

## Edge Cases & Limitations

**`x-navigate` on buttons:**

`x-navigate` on a `<button>` element is not supported — buttons do not have an `href`. Use
`$navigate` instead:

```html
<!-- Wrong: x-navigate on a button doesn't work -->
<button x-navigate href="/page">Go</button>

<!-- Correct: use $navigate for buttons -->
<div x-data>
    <button @click="$navigate('/page')">Go</button>
</div>
```

**External URL navigation:**

`x-navigate` automatically falls through for external URLs (different origin). If you need to
validate external redirects, configure `gale.redirect.allowed_domains` in `config/gale.php`:

```php
// config/gale.php
'redirect' => [
    'allow_external' => false,
    'allowed_domains' => [
        'partner.example.com',
        '*.trusted-domain.com',
    ],
],
```

**Scroll position precision:**

Scroll position after back navigation is approximate. Dynamic content that loads asynchronously
after the DOM is restored may shift the layout and make the restored position inaccurate. For
content-heavy pages, consider disabling the history cache for specific routes by returning
`Gale-Cache-Bust: true` from the server so back navigation always fetches fresh content.

**History cache in private browsing:**

`sessionStorage` is unavailable in some private browsing modes. When unavailable, history
caching is silently disabled. Back navigation falls back to a server request — navigation
still works, just without the instant restore.

**Navigation during in-flight requests:**

When a navigation starts, all pending Gale requests from the current page are aborted. This
prevents race conditions where a background response arrives after the navigation has replaced
the DOM.

---

## Next Steps

- Read [Forms, Validation & Uploads](forms-validation-uploads.md) for POST form details
- Read [Frontend API Reference](frontend-api.md) for all navigation magics and directives
- Read [Components, Events & Polling](components-events-polling.md) for morph hooks and event handling
- Read [Debug & Troubleshooting](debug-troubleshooting.md) if navigation is not working as expected
