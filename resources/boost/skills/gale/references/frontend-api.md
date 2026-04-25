# Frontend API Reference

> **About alpine-gale:** alpine-gale is bundled as a **private/internal** module inside the
> `dancycodes/gale` Composer package. End users do **NOT** `npm install alpine-gale` — the JS
> ships in `vendor/gale/js/gale.js` (published by `php artisan gale:install`) and loads
> automatically via the `@gale` Blade directive. The npm version exists only as a build artifact
> for monorepo tag synchronization. This file documents the public API exposed on `Alpine.gale.*`
> and through `x-*` directives / `$` magics.

Complete reference for all Alpine.js magics, directives, and the `Alpine.gale` configuration API.

## Magics

### $action(url, options?)

Primary magic for server communication. POST with CSRF by default.

```html
<button @click="$action('/increment')">+1</button>
<button @click="$action('/save', { sse: true })">Save (SSE)</button>
```

#### Method Shorthands

```html
<div @click="$action.get('/data')">Load</div>
<div @click="$action.post('/save')">Save</div>
<div @click="$action.put('/update')">Update</div>
<div @click="$action.patch('/partial')">Patch</div>
<div @click="$action.delete('/remove')">Delete</div>
```

All shorthands accept the same options object as `$action()`.

#### Options Object

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `method` | string | `'POST'` | HTTP method (ignored on shorthands) |
| `sse` | boolean | `false` | Force SSE mode for this request |
| `http` | boolean | `false` | Force HTTP mode for this request |
| `include` | string[] | — | Whitelist of state properties to send |
| `exclude` | string[] | — | Blacklist of state properties to exclude |
| `includeFormFields` | boolean | `true` | Include bound form field values |
| `includeComponents` | string[]|object | — | Named components to include in payload |
| `includeComponentsByTag` | string[] | — | Components by tag to include |
| `headers` | object | — | Additional HTTP headers |
| `debounce` | number | — | Debounce delay in ms |
| `throttle` | number | — | Throttle period in ms |
| `leading` | boolean | `false` | Fire on first call (debounce mode) |
| `trailing` | boolean | `true` | Fire trailing call (throttle mode) |
| `retryInterval` | number | — | Initial retry interval in ms (SSE only) |
| `retryScaler` | number | — | Exponential backoff multiplier (SSE only) |
| `retryMaxWaitMs` | number | — | Max retry interval in ms (SSE only) |
| `retryMaxCount` | number | — | Max retry attempts (SSE only) |
| `requestCancellation` | string|AbortController | — | Cancellation mode |
| `onProgress` | function | — | Upload progress callback |
| `optimistic` | object | — | Optimistic state to apply immediately |
| `delta` | boolean | `true` | Send only dirty properties (when dirty tracking active) |
| `onError` | function | — | Per-request error handler; return `false` to suppress global |

#### Mode Resolution Priority

1. Per-action option: `{ sse: true }` or `{ http: true }` (if both set, SSE wins)
2. Global config: `Alpine.gale.configure({ defaultMode: 'sse' })`
3. Built-in default: `'http'`

#### Debounce & Throttle

```html
<!-- Debounce: wait 300ms after last call -->
<input @input="$action('/search', { debounce: 300 })">

<!-- Throttle: max once per 1000ms -->
<button @click="$action('/poll', { throttle: 1000 })">

<!-- Leading debounce: fire immediately, then debounce -->
<button @click="$action('/save', { debounce: 500, leading: true })">
```

Timers are keyed by URL (different URLs on the same element have independent timers). All timers are cancelled when the component unmounts.

#### Optimistic UI

```html
<button @click="$action('/like', {
    optimistic: { liked: true, count: count + 1 }
})">Like</button>
```

State is serialized BEFORE optimistic patch is applied (server receives pre-optimistic state). On success, server response replaces optimistic state. On error, state rolls back to pre-optimistic snapshot.

#### Delta Payloads

When dirty tracking is active on a component, `$action` automatically sends only dirty properties instead of the full state. Disable per-request with `{ delta: false }`.

#### File Uploads

When the component has `x-files` inputs with files, `$action` automatically switches to `multipart/form-data` (FormData). Upload progress is tracked via `$uploading`, `$uploadProgress`, and `$uploadError`.

#### Offline Handling

If the browser is offline when `$action` is called, the action is handled according to the configured offline mode (`'queue'`, `'fail'`, or custom function). Queued actions replay automatically on reconnect.

#### Form Validation

If `$action` is triggered from inside a form with `x-validate`, HTML5 form validation runs first. If validation fails, the request is blocked. See `best-practices.md` → Validation Error Hierarchy for required display patterns and `best-practices.md` → Timing Rules for debounce/throttle prescriptive values.

### $gale

Global reactive connection state object.

```html
<div x-show="$gale.loading">Loading...</div>
<div x-show="$gale.errors">Error occurred</div>
<div x-show="!$gale.online">You are offline</div>
<div x-show="$gale.retrying">Retrying...</div>
```

| Property | Type | Description |
|----------|------|-------------|
| `loading` | boolean | Any `$action` request is in flight |
| `errors` | boolean | Last request had an error |
| `online` | boolean | Browser has network connectivity |
| `retrying` | boolean | A retry is in progress |

### $fetching

Per-element loading boolean. `true` while an `$action` triggered from this element (or its children) is in flight.

```html
<button @click="$action('/save')" :disabled="$fetching">
    <span x-show="!$fetching">Save</span>
    <span x-show="$fetching">Saving...</span>
</button>
```

### $navigate(url, options?)

Programmatic SPA navigation.

```html
<button @click="$navigate('/dashboard')">Dashboard</button>
<button @click="$navigate('/products', { replace: true })">Products</button>
```

Options: `replace` (bool), `key` (string), `merge` (bool), `only` (array), `except` (array).

### $file(name)

Get info for a single file from an `x-files` input.

```html
<div x-text="$file('avatar')?.name">No file</div>
<div x-text="$formatBytes($file('avatar')?.size)">0 B</div>
```

Returns: `{ name, size, type }` or `null`.

### $files(name)

Get array of file info from an `x-files` input (for `multiple` inputs).

```html
<template x-for="file in $files('documents')">
    <div x-text="file.name"></div>
</template>
```

### $filePreview(name, index?)

Generate a preview URL (blob URL) for an uploaded file.

```html
<img :src="$filePreview('avatar')" x-show="$file('avatar')">
```

### $clearFiles(name?)

Clear file input(s). Without argument, clears all. With name, clears specific input.

```html
<button @click="$clearFiles('avatar')">Remove Photo</button>
```

### $formatBytes(size)

Format byte count to human-readable string.

```html
<span x-text="$formatBytes(1048576)">1 MB</span>
```

### $uploading / $uploadProgress / $uploadError

Upload state tracking magics.

```html
<div x-show="$uploading">
    Uploading: <span x-text="$uploadProgress + '%'"></span>
</div>
<div x-show="$uploadError" x-text="$uploadError" class="text-red-500"></div>
```

### $dirty(prop?)

Check if component state has changed since last server sync.

```html
<!-- Any property changed? -->
<div x-show="$dirty()">Unsaved changes</div>

<!-- Specific property changed? -->
<div x-show="$dirty('name')">Name modified</div>
```

Uses deep equality (JSON.stringify comparison). Resets after successful server response. Preserved after failed response.

### $lazy(url, options?)

Programmatic lazy content loading. Same options as `$action`.

```html
<button @click="$lazy('/load-more')">Load More</button>
```

### $listen(channel)

Subscribe to a server push channel. Returns an unsubscribe function.

```html
<div x-data x-init="$listen('notifications')">
    <!-- Receives push events from gale()->push('notifications') -->
</div>
```

### $components

Component registry access with full CRUD.

```html
<button @click="$components.invoke('cart', 'recalculate')">Recalc</button>
<div x-text="$components.get('cart')?.total">0</div>
```

| Method | Returns | Description |
|--------|---------|-------------|
| `get(name)` | object|undefined | Get component by name |
| `getByTag(tag)` | array | Get components by tag |
| `all()` | object | All registered components |
| `has(name)` | boolean | Check if component exists |
| `invoke(name, method, ...args)` | any | Call method on named component |
| `when(name, timeout?)` | Promise | Resolves when component is ready |
| `onReady(names, callback)` | — | Callback when component(s) ready |
| `state(name, key?)` | any | Get reactive state (or specific key) |
| `update(name, updates)` | — | Update state (RFC 7386 merge) |
| `create(name, state, options?)` | — | Initialize state |
| `delete(name, keys)` | — | Delete state properties |
| `watch(name, keyOrCallback, callback?)` | — | Watch for state changes |

## Directives

### x-navigate

SPA navigation for links and forms. Intercepts clicks/submissions and performs client-side navigation.

```html
<!-- Basic link -->
<a href="/products" x-navigate>Products</a>

<!-- With replaceState -->
<a href="/products" x-navigate.replace>Products</a>

<!-- With navigate key (for partial updates) -->
<a href="/products?page=2" x-navigate.key="pagination">Page 2</a>

<!-- Form submission as navigation -->
<form action="/search" method="GET" x-navigate>
    <input name="q" type="text">
    <button>Search</button>
</form>
```

Modifiers: `.replace` (replaceState), `.key="name"` (navigate key).

External links (different host) are NOT intercepted — they navigate normally.

### x-message

Display messages from server state. Reads from the `messages` state object by default; the `.from.errors` modifier flips it to read from the `errors` state object instead.

```html
<!-- Read from messages state (default) — single string per field -->
<span x-message="email" class="text-red-500"></span>

<!-- Read from errors state (.from.errors modifier) — array of strings per field, displays first -->
<span x-message.from.errors="email" class="text-red-500"></span>

<!-- Form-level success message -->
<span x-message="_success" class="text-green-500"></span>
```

> **Which one for `$request->validate()`?** Use `<span x-message="email">` — auto-validation
> writes to the `messages` state slot via `GaleMessageException::render()`. The `.from.errors`
> modifier is for the `errors` state slot, which is populated only when controllers explicitly
> call `gale()->errors([...])`. **The two state slots are independent**; using the wrong
> directive means reading from an empty slot and seeing nothing.

| Server method | State slot written | Display directive |
|---|---|---|
| `$request->validate(...)` (auto) | `messages` | `<span x-message="field">` |
| `$request->validateState(...)` | `messages` | `<span x-message="field">` |
| `gale()->messages([...])` | `messages` | `<span x-message="field">` |
| `gale()->errors([...])` | `errors` | `<span x-message.from.errors="field">` |

### x-component

Register a component in the global registry by name.

```html
<div x-data="{ total: 0 }" x-component="cart">
    Cart total: <span x-text="total"></span>
</div>
```

Access from other components: `$components.get('cart')`.
Server can target: `gale()->componentState('cart', ['total' => 100])`.

Data attributes: `data-tags="product,featured"` to tag components for `tagState()`.

### x-files

Mark a file input for inclusion in the Gale upload system.

```html
<input type="file" x-files="avatar">
<input type="file" x-files="documents" multiple>
```

Client-side validation attributes: `data-max-size="5242880"` (5MB), `data-max-files="10"`.

### x-name

Auto-bind a form element to Alpine state with automatic state creation.

```html
<div x-data="{ name: '', email: '' }">
    <input x-name="name" type="text">
    <input x-name="email" type="email">
</div>
```

Creates two-way binding between the input and the corresponding `x-data` property.

### x-sync

Two-way state synchronization with the server. Automatically sends state changes to the server.

```html
<input x-sync="search" type="text">
```

When the value changes, the state is automatically sent to the server.

### x-loading

Show/hide elements during `$action` requests.

```html
<div x-loading>Loading...</div>
<div x-loading.remove>Content hidden during load</div>
```

Modifiers: `.remove` (hide during loading), `.delay` (delay showing), `.class="opacity-50"` (add class during loading).

### x-indicator

**Element + children scoped** loading boolean. When any `$action` request is in flight from the element or any of its descendants, `x-indicator` toggles a state variable on the host `x-data` to `true`. When the request finishes (or errors), it toggles back to `false`.

```html
<!-- Default state name is 'loading' -->
<div x-data="{ loading: false }" x-indicator>
    <button @click="$action('/save')">Save</button>
    <span x-show="loading">Saving...</span>
</div>

<!-- Custom state name -->
<div x-data="{ saving: false }" x-indicator="saving">
    <button @click="$action('/save')" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>
</div>
```

The state name in the expression must already exist on the parent `x-data` — `x-indicator` updates it but does not create it. The directive uses DOM containment (the request must originate from this element or a descendant), so siblings in the same `x-data` scope do NOT trigger the indicator. This is the right tool for **per-button loading state** in multi-action components.

> **NOT a top-of-page progress bar.** Earlier versions of this documentation incorrectly described `x-indicator` as a global page bar. It's element-scoped — a local boolean that mirrors `$fetching` but only for actions originating from this element/subtree. For a global progress bar, use `Alpine.gale.configureNavigation({ navigationIndicator: true })` for SPA navigation, or read `$gale.loading` for a global "any request in flight" boolean.

### x-confirm

Confirmation dialog before action execution.

```html
<button @click="$action('/delete')" x-confirm="Are you sure?">Delete</button>
```

Blocks the action until the user confirms. Uses a styled modal dialog (customizable via `Alpine.gale.configure({ confirmTemplate: '...' })`).

### x-interval

Polling at a fixed interval.

```html
<!-- Poll every 5 seconds via HTTP -->
<div x-interval="5000" data-url="/status"></div>

<!-- Poll every 5 seconds via SSE -->
<div x-interval.sse="5000" data-url="/status"></div>
```

### x-dirty

Show/hide elements based on dirty state.

```html
<div x-dirty>You have unsaved changes</div>
```

Visible when any `x-data` property has changed since last server sync.

### x-lazy

Load content when element enters the viewport.

```html
<!-- Load via HTTP when visible -->
<div x-lazy="/comments"></div>

<!-- Load via SSE -->
<div x-lazy.sse="/comments"></div>

<!-- Repeat loading (not just once) -->
<div x-lazy.repeat="/live-data"></div>
```

Uses IntersectionObserver with 200px rootMargin (pre-fetches before visible). Shows a shimmer placeholder during loading. Each element loads only once unless `.repeat` modifier is used.

Custom placeholder: add a child element with `data-lazy-placeholder` attribute.

### x-listen

Subscribe to a server push channel (declarative version of `$listen`).

```html
<div x-listen="notifications">
    <!-- Receives push events -->
</div>
```

Auto-reconnects with exponential backoff (1s, 2s, 4s, 8s, max 30s). Auto-unsubscribes on component destruction.

### x-validate

HTML5 form validation integration with `$action`.

```html
<form x-validate>
    <input type="email" required>
    <button @click="$action('/save')">Save</button>
</form>
```

When `$action` is triggered inside a form with `x-validate`, native browser validation runs first. Request is blocked if validation fails.

### x-prefetch

Prefetch link content on hover.

```html
<a href="/products" x-navigate x-prefetch>Products</a>

<!-- Disable prefetch on specific link -->
<a href="/heavy-page" x-navigate x-prefetch="false">Heavy Page</a>
```

Global prefetch can be enabled via `Alpine.gale.configure({ prefetch: true })`, making all `x-navigate` links prefetch automatically.

## Alpine.gale API

The `Alpine.gale` namespace exposes configuration and utility APIs.

### Configuration

#### configure(options)

Set global configuration. Validates all keys; invalid values are rejected with console.error.

```js
Alpine.gale.configure({
    defaultMode: 'http',           // 'http' or 'sse'
    viewTransitions: true,         // View Transitions API for SPA navigation
    foucTimeout: 3000,             // Max ms to wait for stylesheets during navigation
    navigationIndicator: true,     // Show progress bar during navigation
    maxConcurrent: 6,              // Max concurrent HTTP requests
    pauseOnHidden: true,           // Pause SSE when tab hidden
    pauseOnHiddenDelay: 1000,      // Debounce delay before pausing SSE (ms)
    csrfRefresh: 'auto',           // 'auto' | 'meta' | 'sanctum'
    morphTransitions: true,        // Defer morph during CSS transitions
    sseShared: false,              // Share SSE connections per URL
    sseHeartbeatTimeout: 60000,    // Heartbeat timeout (ms, 0 = disabled)
    sanitizeHtml: true,            // XSS sanitize HTML patches
    allowScripts: false,           // Preserve <script> tags in patches
    cspNonce: null,                // CSP nonce for dynamic scripts
    warnPayloadSize: 102400,       // Response size warning threshold (bytes)
    prefetch: false,               // Global link prefetching (false/true/object)
    queue: 'parallel',             // 'parallel' | 'sequential' | 'cancel-previous'
    settleDuration: 0,             // Settle delay for DOM transitions (ms, bridged)
    confirmTemplate: null,         // Custom confirm dialog HTML (null = built-in)

    // Nested config objects
    retry: {
        maxRetries: 3,
        initialDelay: 1000,
        backoffMultiplier: 2,
    },
    // Shorthand aliases:
    retries: 3,                    // Alias for retry.maxRetries
    retryBaseDelay: 1000,          // Alias for retry.initialDelay

    historyCache: { maxSize: 10 }, // false to disable, true for defaults
    rateLimiting: {
        autoRetry: true,
        maxRetries: 3,
        showMessage: true,
        messageText: 'Too many requests. Please wait.',
    },
    auth: {
        loginUrl: '/login',
        autoRedirect: false,
        showMessage: true,
        messageText: 'Your session has expired. Please log in again.',
        messageTimeout: 5000,
    },
    offline: {
        mode: 'queue',             // 'queue' | 'fail' | function
        queueMaxSize: 50,
        offlineIndicator: true,
    },
    redirect: {
        allowedDomains: [],        // e.g. ['*.stripe.com']
        allowExternal: false,
        logBlocked: true,
    },
    debug: {
        thresholds: { response: 500, domMorph: 100, total: 1000 },
        logLevel: 'off',           // 'off' | 'info' | 'verbose'
    },
});
```

Dispatches `gale:config-changed` event on `document` with `{ detail: { changes } }` when values actually change.

#### getConfig()

Returns a shallow copy of the current global configuration.

#### getMaxConcurrent()

Returns the current max concurrent HTTP requests (default 6).

### CSRF

```js
Alpine.gale.configureCsrf({ tokenSelector: 'meta[name="csrf-token"]' });
Alpine.gale.getCsrfConfig();
```

### Navigation

```js
Alpine.gale.configureNavigation({ interceptLinks: true, interceptForms: true });
Alpine.gale.getNavigationConfig();
```

### Messages

```js
Alpine.gale.configureMessage({ clearOnSuccess: true });
Alpine.gale.getMessageConfig();
```

### Confirm Dialog

```js
Alpine.gale.configureConfirm({ okText: 'Yes', cancelText: 'No' });
Alpine.gale.getConfirmConfig();
```

### Swap/Settle

```js
Alpine.gale.configureSwapSettle({ timing: { settleDelay: 100 } });
Alpine.gale.getSwapSettleConfig();
```

### Component Registry

```js
// Manual registration
Alpine.gale.registerComponent(name, el);
Alpine.gale.unregisterComponent(name);
Alpine.gale.getComponent(name);
Alpine.gale.getComponentsByTag(tag);
Alpine.gale.updateComponentState(name, state);
Alpine.gale.invokeComponentMethod(name, method, ...args);
Alpine.gale.hasComponent(name);
Alpine.gale.getAllComponents();

// Lifecycle hooks — each returns an unregister function
Alpine.gale.onComponentRegistered(callback);   // callback({ name, el })
Alpine.gale.onComponentUnregistered(callback); // callback({ name })
Alpine.gale.onComponentStateChanged(callback); // callback({ name, changes })

// Timing solutions
Alpine.gale.whenComponentReady(name, timeout?); // Promise
Alpine.gale.onComponentReady(names, callback);

// Reactive CRUD
Alpine.gale.getComponentState(name, key?);
Alpine.gale.createComponentState(name, state, options?);
Alpine.gale.deleteComponentState(name, keys);
Alpine.gale.watchComponentState(name, keyOrCallback, callback?);
```

### Error Handling

```js
// Global error handler — callback receives { type, status, message, url, recoverable, retry }
const unregister = Alpine.gale.onError(callback);

// Error notification config
Alpine.gale.configureErrors({ showToast: true });
Alpine.gale.getErrorConfig();
```

### Morph Lifecycle Hooks

```js
const unregister = Alpine.gale.onMorph({
    beforeUpdate(el, newEl) { /* save state */ },
    afterUpdate(el, newEl) { /* re-init */ },
    beforeRemove(el) { /* cleanup */ return false; /* to prevent */ },
    afterRemove() { /* post-cleanup */ },
    afterAdd(el) { /* init new element */ },
});
```

### History Cache

```js
Alpine.gale.clearHistoryCache();
Alpine.gale.getHistoryCacheSize();       // number
Alpine.gale.getHistoryCacheKeys();       // string[]
Alpine.gale.bustHistoryCache(url?);      // bust specific or all
```

### Prefetch Cache

```js
Alpine.gale.clearPrefetchCache();
Alpine.gale.getPrefetchCacheSize();
Alpine.gale.getPrefetchCacheKeys();
Alpine.gale.getPrefetchConfig();
```

### Response Cache

```js
Alpine.gale.clearCache();              // Clear all
Alpine.gale.clearCache('/url');        // Clear specific URL
Alpine.gale.getResponseCacheSize();
Alpine.gale.getResponseCacheKeys();
```

### Request Pipeline

```js
Alpine.gale.getPipelineStats();        // { pending, active, completed } — pipeline scheduler stats
Alpine.gale.PIPELINE_PRIORITY;         // Priority enum: { VIP, HIGH, NORMAL, LOW }.
                                       // Plugins can pass these to the scheduler to influence ordering.
                                       // Default is NORMAL; navigations/prefetches use lower; user-initiated
                                       // actions stay at NORMAL/HIGH.
Alpine.gale.getQueueStats();           // Queue manager stats — for the queue: 'parallel' | 'sequential'
                                       // | 'cancel-previous' grouping mode (configurable via configure({queue: ...}))
```

### SSE Connection Manager

```js
Alpine.gale.getActiveConnectionCount();   // Total open SSE connections (number)
Alpine.gale.getSharedConnectionCount();   // Subset that are shared per-URL when sseShared: true (number)
Alpine.gale.getConnectionStates();        // Array of { url, state } for all active connections.
                                          // state ∈ 'connecting' | 'open' | 'reconnecting' | 'closed'.
```

### Push Channels

```js
Alpine.gale.configurePushChannels({ prefix: '/gale/channel' });
Alpine.gale.getPushChannelConfig();
Alpine.gale.getActiveChannelCount();
Alpine.gale.getActiveChannelNames();   // string[]
```

### Rate Limiting

```js
Alpine.gale.cancelAllRateLimitRetries();
Alpine.gale.parseRetryAfter(headerValue);  // seconds
Alpine.gale.getRateLimitStatus(url, method);
```

### Authentication

```js
Alpine.gale.resetAuth();         // Reset after re-authentication
Alpine.gale.isAuthExpired();     // boolean
```

### Offline Detection

```js
Alpine.gale.isOnline();              // boolean (imperative, not reactive)
Alpine.gale.getOfflineQueueSize();   // number
Alpine.gale.clearOfflineQueue();
```

### Dirty State Tracking

```js
Alpine.gale.isDirty(el, prop?);      // boolean
Alpine.gale.getDirtyKeys(el);        // Set<string>
Alpine.gale.resetDirty(el);          // Manual reset
Alpine.gale.initDirtyTracking(el);   // Manual bootstrap
```

### Optimistic UI

```js
Alpine.gale.isOptimistic(el);             // boolean
Alpine.gale.getPendingOptimisticCount(el); // number
```

### Security

```js
// CSP nonce
Alpine.gale.getCspNonce();  // string|null

// SSE event validation
Alpine.gale.validateSSEEvent({ event, data });  // { valid, reason? }

// Redirect security
Alpine.gale.validateRedirectUrl(url);  // boolean
Alpine.gale.configureRedirect({ allowedDomains, allowExternal, logBlocked });
Alpine.gale.getRedirectConfig();
```

### Plugin System

```js
Alpine.gale.registerPlugin('analytics', {
    init({ addMagic, addDirective, config }) {
        addMagic('track', (el) => (event) => { /* ... */ });
    },
    beforeRequest({ url, method, data, options }) { /* modify */ },
    afterResponse({ url, status, data, response }) { /* log */ },
    beforeMorph({ el, newEl, component }) { return false; /* prevent */ },
    afterMorph({ el, newEl, component }) { /* re-init */ },
    destroy() { /* cleanup */ },
});

Alpine.gale.unregisterPlugin('analytics');
Alpine.gale.getPlugin('analytics');
Alpine.gale.getPluginNames();    // string[]
Alpine.gale.getPluginCount();    // number
```

### Custom Directives

```js
Alpine.gale.directive('tooltip', {
    init(el, expression, galeContext) { /* mount */ },
    morph(el, phase, galeContext) { /* 'before' | 'after' */ },
    destroy(el, galeContext) { /* cleanup */ },
});

Alpine.gale.getCustomDirectiveNames();   // string[]
Alpine.gale.getCustomDirectiveCount();   // number
```

`galeContext`: `{ mode, component, config }`.

### Third-Party Cleanup Registry

```js
Alpine.gale.registerCleanup('canvas[data-chart]', {
    beforeMorph(el) { /* save state, pause animations */ },
    afterMorph(el) { /* re-attach, resume */ },
    destroy(el) { /* free resources */ },
});
Alpine.gale.removeCleanup('canvas[data-chart]');
```

### Animation Compatibility (GSAP)

```js
Alpine.gale.registerGsapCleanup('[data-gsap]');
Alpine.gale.setupAnimationCompat();          // Register all built-in handlers
Alpine.gale.waitForElementTransition(el);    // Promise, 500ms timeout
```

### RTE Compatibility

```js
Alpine.gale.registerRteCleanup('[data-rte]');
Alpine.gale.setupRteCompat();  // Registers: [data-rte], .tiptap, .ql-container, trix-editor
```

### SortableJS Compatibility

```js
Alpine.gale.registerSortableCleanup('[data-sortable]');
Alpine.gale.setupSortableCompat();
Alpine.gale.installSortableHandlers(el, sortableInstance);
```

### Debug Panel

```js
Alpine.gale.debug.toggle();
Alpine.gale.debug.open();
Alpine.gale.debug.close();
Alpine.gale.debug.pushRequest(entry);
Alpine.gale.debug.pushState(entry);
Alpine.gale.debug.pushError(entry);
Alpine.gale.debug.registerTab(id, label);
Alpine.gale.debug.pushTab(tabId, entry);
Alpine.gale.debug.clear();
Alpine.gale.debug.isEnabled();

// Console logging
Alpine.gale.getLogLevel();         // 'off' | 'info' | 'verbose'
Alpine.gale.setLogLevel('info');

// Error overlay (dev only)
Alpine.gale.showErrorOverlay(error);
```

### Memory Management

```js
Alpine.gale.teardown();  // Remove all listeners, observers, connections
```

Cleans up: navigation, component registry, file system, state tracking, SSE connections, ETag cache, pipeline, queue manager, morph hooks, cleanup registry, response cache, prefetch cache, history cache, push channels, debug panel, request logger, performance timing, state diffs, error overlay, rate limit timers, auth timers, offline detection, console logger, plugins, custom directives, CSRF state.
