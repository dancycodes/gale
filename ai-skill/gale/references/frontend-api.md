# Gale Frontend API Reference

Complete reference for the Alpine Gale plugin. Read this file whenever creating or editing blade files.

## Table of Contents
- [Setup](#setup)
- [Dual-Mode Request Handling](#dual-mode-request-handling)
- [HTTP Magics ($action)](#http-magics)
- [State Synchronization (x-sync)](#state-synchronization)
- [Global State ($gale)](#global-state)
- [Global Configuration](#global-configuration)
- [Element Loading ($fetching)](#element-loading)
- [Loading Directives](#loading-directives)
- [Navigation](#navigation)
- [Component Registry](#component-registry)
- [Form Binding (x-name)](#form-binding)
- [File Uploads (x-files)](#file-uploads)
- [Message Display (x-message)](#message-display)
- [Polling (x-interval)](#polling)
- [Confirmation (x-confirm)](#confirmation)
- [Directives Reference](#directives-reference)
- [Magics Reference](#magics-reference)
- [Request Options Reference](#request-options-reference)
- [Events Reference](#events-reference)
- [Configuration Reference](#configuration-reference)

---

## Setup

### @gale Directive

Place in `<head>` of your layout. Outputs CSRF meta tag + Alpine.js (v3) + Morph plugin + Alpine Gale plugin:

```blade
<head>
    @gale
</head>
```

**CRITICAL:** Remove any existing Alpine.js CDN or npm imports -- `@gale` includes Alpine.

### Alpine Context Required

ALL Gale features need `x-data` or `x-init`:

```html
<!-- Works -->
<div x-data="{ count: 0 }">
    <button @click="$action('/increment')">+</button>
</div>

<!-- Works -->
<div x-init="$action.get('/load')">Loading...</div>

<!-- Fails -- no Alpine context -->
<button @click="$action('/increment')">Broken</button>
```

---

## Dual-Mode Request Handling

Alpine Gale supports two modes for communicating with the server:

| Mode | Transport | Default | Use Case |
|------|-----------|---------|----------|
| **HTTP** | `fetch()` -> JSON response | Yes | Most actions, forms, CRUD, navigation |
| **SSE** | `EventSource`-style streaming | No (opt-in) | Long operations, real-time progress |

Both modes produce **identical results** on the frontend. The same state patches, DOM updates, component targeting, and events work regardless of which mode is used.

### Mode Resolution Priority

| Priority | Method | Example |
|----------|--------|---------|
| 1 (highest) | Per-action option | `$action('/url', { sse: true })` |
| 2 | Global config | `Alpine.gale.configure({ defaultMode: 'sse' })` |
| 3 (lowest) | Built-in default | `'http'` |

### Opting Into SSE Mode

```html
<!-- Per-action: SSE for this specific request -->
<button @click="$action('/process', { sse: true })">Process with SSE</button>

<!-- Per-action: Explicit HTTP (useful if global is SSE) -->
<button @click="$action('/save', { http: true })">Save with HTTP</button>
```

```javascript
// Global: Change default mode for all actions
Alpine.gale.configure({ defaultMode: 'sse' });

// Check current mode
const config = Alpine.gale.getConfig();
console.log(config.defaultMode); // 'http' or 'sse'
```

### Content-Type Auto-Detection

Regardless of the requested mode, the frontend auto-detects the server response format:

| Content-Type | Behavior |
|-------------|----------|
| `application/json` | Processes `{ "events": [...] }` via JSON processor |
| `text/event-stream` | Processes SSE events via SSE parser |
| `text/html` | Full-page morph (handles `dd()`, blade returns, error pages) |
| `204 No Content` | Silent success -- no action taken |

This means the frontend handles mode mismatches gracefully. If the server returns SSE when HTTP was requested (e.g., because `gale()->stream()` was used), the frontend processes it correctly.

---

## HTTP Magics

### $action(url, options?)

Default: **POST with automatic CSRF injection**. Default mode: **HTTP (JSON)**.

```html
<div x-data="{ count: 0 }" x-sync>
    <!-- Default POST (HTTP mode) -->
    <button @click="$action('/increment')">+1</button>

    <!-- Method shorthands -->
    <button @click="$action.get('/api/data')">GET</button>
    <button @click="$action.post('/api/save')">POST</button>
    <button @click="$action.put('/api/replace')">PUT</button>
    <button @click="$action.patch('/api/update')">PATCH</button>
    <button @click="$action.delete('/api/remove')">DELETE</button>

    <!-- SSE mode for this specific request -->
    <button @click="$action('/process', { sse: true })">Process (SSE)</button>
</div>
```

### CSRF Auto-Injection

| Method | CSRF |
|--------|------|
| `$action()` / `$action.post()` | Auto CSRF |
| `$action.put()` / `$action.patch()` / `$action.delete()` | Auto CSRF |
| `$action.get()` | No CSRF needed |

Reads from `<meta name="csrf-token">` (output by `@gale`).

### Request Options

```html
<button @click="$action('/save', {
    method: 'POST',            // HTTP method (default POST)
    sse: false,                // Force SSE mode (default false)
    http: false,               // Force HTTP mode (default false, implicit)
    include: ['user', 'settings'], // Only send these state keys
    exclude: ['tempData'],     // Don't send these state keys
    includeFormFields: true,   // Include form fields (default true)
    includeComponents: ['export-panel'],  // Include named component states
    includeComponentsByTag: ['filters'],  // Include by tag
    headers: { 'X-Custom': 'value' },
    retryInterval: 1000,       // Initial retry delay ms (SSE only)
    retryScaler: 2,            // Exponential backoff multiplier (SSE only)
    retryMaxWaitMs: 30000,     // Max retry delay ms (SSE only)
    retryMaxCount: 10,         // Max retry attempts (SSE only)
    requestCancellation: 'auto', // 'auto' (default) | 'disabled' | AbortController
    openWhenHidden: false,     // Keep SSE alive when tab/page hidden (default false)
    onProgress: (percent) => console.log(percent)  // Upload progress
})">Save</button>
```

### Request Cancellation

| Value | Behavior |
|-------|----------|
| `'auto'` (default) | New request cancels previous in-flight request from same element |
| `'disabled'` | Allow multiple concurrent requests (watch `$gale.activeCount`) |
| `AbortController` | Provide custom AbortController for manual cancellation |

```html
<!-- Auto (default): Search-as-you-type, each keystroke cancels previous -->
<input @input="$action.get('/search', { include: ['query'] })">

<!-- Disabled: Concurrent bulk actions -->
<button @click="$action('/process', { requestCancellation: 'disabled' })">Run</button>
```

### HTTP Mode Request Headers

In HTTP mode, these headers are sent:

| Header | Value | Purpose |
|--------|-------|---------|
| `Gale-Request` | `true` | Identifies this as a Gale request |
| `Gale-Mode` | `http` or `sse` | Tells the server which mode the frontend expects |
| `Accept` | `application/json` | HTTP mode expects JSON; SSE mode expects `text/event-stream` |
| `Content-Type` | `application/json` | Request body format (not set for FormData/file uploads) |
| `X-CSRF-TOKEN` | `<token>` | CSRF protection (non-GET methods) |

### State Sending Priority

The `include`/`exclude` options interact with `x-sync`:

| x-sync | include | exclude | Result |
|--------|---------|---------|--------|
| (empty/`*`) | - | - | All state |
| `['a','b']` | - | - | `{a, b}` |
| `['a','b']` | `['c']` | - | `{a, b, c}` (union) |
| `['a','b']` | - | `['b']` | `{a}` |
| `*` | `['a','b']` | - | `{a, b}` (include restricts) |
| (none) | - | - | Nothing |
| (none) | `['name']` | - | `{name}` |

### includeComponents

When you use `includeComponents`, component state is nested under `_components.{name}` in the request:

```javascript
// Array: include all state from named components
{ includeComponents: ['cart', 'user'] }

// Object with key arrays: include specific properties
{ includeComponents: { 'cart': ['items', 'total'] } }

// Object with full config: include/exclude/alias
{ includeComponents: {
    'cart': { include: ['items'], exclude: ['private'], as: 'shopping_cart' }
} }

// Boolean: include all state
{ includeComponents: { 'cart': true } }
```

```php
// Backend access:
$exportPanel = $request->state('_components.export-panel', []);
$format = $exportPanel['format'] ?? 'csv';

// With alias 'as: shopping_cart':
$cart = $request->state('_components.shopping_cart', []);
```

---

## State Synchronization

### x-sync Directive

Controls which Alpine state is sent with requests:

```html
<!-- Send everything -->
<div x-data="{ name: '', email: '', open: false }" x-sync>

<!-- Send specific keys only -->
<div x-data="{ name: '', email: '', open: false }" x-sync="['name', 'email']">

<!-- String shorthand -->
<div x-data="{ name: '', email: '' }" x-sync="name, email">

<!-- Explicit wildcard -->
<div x-data="{ name: '', email: '' }" x-sync="*">

<!-- No x-sync = send NOTHING (use include option per action) -->
<div x-data="{ name: '', temp: null }">
```

### Two Approaches for State Sending

**Approach A: x-sync (declare once)** -- Good when most actions need the same state:
```html
<div x-data="{ search: '', category: '' }" x-sync="['search', 'category']">
```

**Approach B: include per-action (explicit per call)** -- Good when different actions need different state:
```html
<div x-data="{ ids: [], status: '' }">
    <button @click="$action.delete('/bulk', { include: ['ids'] })">Delete</button>
    <button @click="$action.patch('/status', { include: ['ids', 'status'] })">Update</button>
</div>
```

### Form Fields

- Form fields with `name` attribute are always included by default
- Use `includeFormFields: false` to exclude
- Alpine state overrides form fields on key conflicts

---

## Global State

### $gale Magic

```html
<div x-data>
    <div x-show="$gale.loading">Loading...</div>
    <div x-show="$gale.retrying">Reconnecting...</div>
    <div x-show="$gale.retriesFailed">Connection failed</div>
    <div x-show="$gale.error">Error: <span x-text="$gale.lastError?.message"></span></div>
    <span x-text="$gale.activeCount + ' requests active'"></span>
    <ul>
        <template x-for="err in $gale.errors">
            <li x-text="err.message"></li>
        </template>
    </ul>
    <button @click="$gale.clearErrors()">Clear Errors</button>
</div>
```

| Property | Type | Description |
|----------|------|-------------|
| `$gale.loading` | bool | Any request in progress (HTTP or SSE) |
| `$gale.error` | bool | Any error occurred |
| `$gale.retrying` | bool | Currently retrying (network errors in HTTP mode, reconnection in SSE mode) |
| `$gale.retriesFailed` | bool | All retries exhausted |
| `$gale.activeCount` | number | Active request count |
| `$gale.lastError` | object\|null | Most recent error: `{ timestamp, status, message }` |
| `$gale.errors` | array | Last 10 errors, each: `{ timestamp, status, message }` |
| `$gale.state` | object | Snapshot of all state properties |
| `$gale.clearErrors()` | method | Clear error history and reset retriesFailed |

---

## Global Configuration

### Alpine.gale.configure(options)

Configure global Gale behavior. Call early, before Alpine initializes or in an `x-init`:

```javascript
Alpine.gale.configure({
    defaultMode: 'http',      // 'http' (default) or 'sse' -- global request mode
    viewTransitions: true,    // Enable View Transitions API for SPA navigation
    foucTimeout: 3000,        // Max ms to wait for stylesheets during SPA navigation
    navigationIndicator: true, // Show progress bar during navigation
    settleDuration: 20,       // Settle delay in ms for swap-settle lifecycle
    pauseOnHidden: true,      // Pause SSE connections when tab is hidden
    pauseOnHiddenDelay: 1000, // Debounce delay before pausing SSE
    csrfRefresh: 'auto',      // CSRF refresh strategy: 'auto' | 'meta' | 'sanctum'
    retry: {
        maxRetries: 3,         // Max retry attempts for HTTP network errors
        initialDelay: 1000,    // Initial delay in ms before first retry
        backoffMultiplier: 2,  // Exponential backoff multiplier
    },
});
```

**Key notes:**
- `defaultMode` only affects `$action()` calls that don't specify `{ sse: true }` or `{ http: true }`
- HTTP server errors (4xx/5xx) are NOT retried -- only network errors trigger retry
- SSE reconnection uses its own per-action retry params (`retryInterval`, `retryScaler`, etc.)
- Invalid values are silently ignored

### Alpine.gale.getConfig()

Returns a copy of the current global configuration:

```javascript
const config = Alpine.gale.getConfig();
console.log(config.defaultMode); // 'http' or 'sse'
console.log(config.viewTransitions); // true or false
```

---

## Element Loading

### $fetching() Magic

**Per-element loading state.** Returns true when THIS element triggered a request that's in progress. Works identically in both HTTP and SSE modes.

```html
<button @click="$action('/save')">
    <span x-show="!$fetching()">Save</span>
    <span x-show="$fetching()">Saving...</span>
</button>
```

**CRITICAL:** `$fetching()` is a **function call** -- always use parentheses!

### Comparison

| Pattern | Scope | Use Case |
|---------|-------|----------|
| `$gale.loading` | Global | Disable all inputs during any request |
| `$fetching()` | Element | Show spinner only for the clicked button |
| `x-loading` | Element | Auto-show/hide based on element's requests |

---

## Loading Directives

### x-loading

Auto-shows element when its ancestor triggers a request:

```html
<!-- Show while loading -->
<span x-loading>Processing...</span>

<!-- Delayed show (prevents flash for fast requests) -->
<span x-loading.delay.200ms x-cloak>Processing...</span>

<!-- Remove variant (hide instead of show) -->
<span x-loading.remove>Content hidden while loading</span>

<!-- Add class during loading -->
<button x-loading.class="opacity-50 cursor-not-allowed">Submit</button>

<!-- Add attribute during loading -->
<button x-loading.attr="disabled">Submit</button>
```

| Modifier | Description |
|----------|-------------|
| (none) | Show element during loading |
| `.remove` | Hide element during loading |
| `.class="..."` | Add class(es) during loading |
| `.attr="..."` | Add attribute during loading |
| `.delay.{ms}` | Delay before applying |

### x-indicator

Create a boolean variable for loading state:

```html
<div x-indicator="saving">
    <button @click="$action('/save')">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>
</div>
```

---

## Navigation

### x-navigate Directive

On container: delegates navigation to all child links and forms:

```html
<div x-data x-navigate>
    <a href="/page1">Intercepted by Gale</a>
    <a href="/page2" x-navigate.key.content>With navigate key</a>
    <a href="/external" x-navigate-skip>Full page load</a>
</div>
```

On individual links:

```html
<a href="/page" x-navigate>SPA navigation</a>
<a href="/page" x-navigate.merge>Preserve query params</a>
<a href="/page" x-navigate.key.filter>With navigate key</a>
<a href="/page" x-navigate.merge.key.filter>Merge + key</a>
<a href="/search?q=test" x-navigate.only.q.category>Keep only q and category</a>
<a href="/search" x-navigate.except.page>Remove page param</a>
<a href="/page?p=2" x-navigate.merge.key.pagination.replace>Combined</a>
```

### x-navigate Modifiers

| Modifier | Description |
|----------|-------------|
| `.key.{name}` | Send navigate key header |
| `.merge` | Preserve existing query params |
| `.replace` | Replace history (don't push) |
| `.only.{params}` | Keep only specified query params |
| `.except.{params}` | Remove specified query params |
| `.debounce.{time}` | Debounce navigation |
| `.throttle.{time}` | Throttle navigation |

### x-navigate-skip

Exclude specific links from navigation handling:

```html
<a href="/download.pdf" x-navigate-skip>Download (full page load)</a>
```

### $navigate Magic

Programmatic navigation:

```html
<input @input.debounce.300ms="$navigate('/search?q=' + $el.value, {
    key: 'filter',
    merge: true,
    replace: true,
    except: ['page']
})">
```

Options:
```javascript
$navigate(url, {
    key: 'filter',      // Navigate key sent to backend
    merge: true,        // Preserve existing query params
    replace: true,      // Replace history entry
    except: ['page'],   // Remove these params when merging
    only: ['q'],        // Keep only these params
})
```

---

## Component Registry

### x-component Directive

Register named components for cross-component communication:

```html
<div x-data="{ value: 0, refresh() { /* ... */ } }" x-component="stats-widget">
    <span x-text="value"></span>
</div>
```

**Use x-component ONLY when:**
- Other components need to access this component's state
- Server needs to target this component via `gale()->componentState()`
- You need `$components.watch()` or `$components.invoke()`

### $components Magic

```javascript
// Invoke method on named component
$components.invoke('activity-feed', 'addActivity', { id: 1, text: 'New' });

// Watch state changes
$components.watch('stat-users', 'value', (newVal, oldVal) => {
    console.log('Changed:', oldVal, '->', newVal);
});

// Wait for components to mount
$components.onReady(['stat-users', 'stat-orders'], () => {
    console.log('All components ready!');
});

// Get component state
$components.state('cart', 'total'); // -> returns value

// Shorthand for invoke
$invoke('cart', 'recalculate');
```

**IMPORTANT:** `$components.invoke()` passes the third argument directly to the method (does NOT spread an array). So pass the object directly:
```javascript
// Correct
$components.invoke('feed', 'addItem', { id: 1, text: 'Hello' });

// Wrong -- would pass the array itself
$components.invoke('feed', 'addItem', [{ id: 1, text: 'Hello' }]);
```

### x-component with Tags

```html
<div x-data="{ type: 'price' }" x-component="filter-price" data-tags="filters,widgets">
```

Backend can target by tag: `includeComponentsByTag: ['filters']`

### $components Full API

| Method | Description |
|--------|-------------|
| `get(name)` | Get component Alpine data object |
| `getByTag(tag)` | Get all components with tag |
| `all()` | Get all registered components |
| `tags()` | Get map of tag names to component counts |
| `has(name)` | Check if component exists |
| `invoke(name, method, ...args)` | Call method on component |
| `when(name, timeout?)` | Promise resolving when component exists |
| `onReady(name, callback)` | Callback when component ready |
| `state(name, property)` | Get reactive state value |
| `update(name, state)` | Merge state into component |
| `create(name, state, options)` | Set state (with onlyIfMissing) |
| `delete(name, keys)` | Remove state keys |
| `watch(name, property, callback)` | Watch for changes |

### Component Lifecycle Hooks

```javascript
Alpine.gale.onComponentRegistered((name, component) => {
    console.log(`${name} registered`);
});

Alpine.gale.onComponentUnregistered((name) => {
    console.log(`${name} unregistered`);
});

Alpine.gale.onComponentStateChanged(({ name, updates, oldValues }) => {
    console.log(`${name} state changed:`, updates, 'was:', oldValues);
});
```

---

## Form Binding

### x-name Directive

Two-way binding that creates/syncs state + sets `name` attribute for FormData/Laravel. Auto-creates state if not in `x-data`:

```html
<!-- Before: Verbose -->
<div x-data="{ email: '', password: '' }">
    <input x-model="email" name="email" type="email">
</div>

<!-- After: Clean with x-name -->
<div x-data="{ email: '', password: '' }">
    <input x-name="email" type="email">
    <input x-name="password" type="password">
</div>
```

#### Type-Aware Default Values (when auto-creating state)

| Input Type | Default | Notes |
|------------|---------|-------|
| text, email, password, tel, url, search | `''` | Empty string |
| number, range | `null` | Distinguishes "not entered" from 0 |
| checkbox (single) | `false` | Boolean toggle |
| checkbox (array mode) | `[]` | Multiple selections |
| radio | `null` | No selection |
| select | `''` | Empty selection |
| select[multiple] | `[]` | Array |
| textarea | `''` | Text content |
| file | -- | Delegates to x-files |

If element has `value` attribute, that becomes initial state: `<input x-name="count" type="number" value="42">` -> `{ count: 42 }`

#### Nested Paths (dot notation)

```html
<div x-data="{ user: { name: '', email: '', phone: '' } }">
    <input x-name="user.name" type="text">
    <input x-name="user.email" type="email">
</div>
```

#### Checkboxes

```html
<!-- Single (boolean) -->
<input x-name="newsletter" type="checkbox">
<!-- Toggles true/false -->

<!-- Multiple (array) -- use .array modifier -->
<div x-data="{ tags: [] }">
    <input x-name.array="tags" type="checkbox" value="alpha">
    <input x-name.array="tags" type="checkbox" value="beta">
    <!-- State: { tags: ['alpha', 'beta'] } when both checked -->
</div>
```

#### Radio Buttons

```html
<div x-data="{ gender: null }">
    <input x-name="gender" type="radio" value="male"> Male
    <input x-name="gender" type="radio" value="female"> Female
</div>
```

#### Select Elements

```html
<!-- Single -->
<select x-name="country">
    <option value="">Choose...</option>
    <option value="us">US</option>
</select>

<!-- Multiple -->
<select x-name="languages" multiple>
    <option value="js">JavaScript</option>
    <option value="php">PHP</option>
</select>
<!-- State: { languages: ['js', 'php'] } -->
```

#### Modifiers

| Modifier | Description |
|----------|-------------|
| `.lazy` | Update on blur instead of input |
| `.number` | Parse value as number |
| `.trim` | Trim whitespace |
| `.fill` | Populate input from state only if state has a value (won't override user input) |
| `.array` | Force array mode for checkboxes |
| `.debounce.{time}` | Debounce state updates (e.g. `.debounce.300ms`) |
| `.throttle.{time}` | Throttle state updates (e.g. `.throttle.500ms`) |

```html
<input x-name.lazy="search" type="text">
<input x-name.number="quantity" type="text">
<input x-name.lazy.trim="bio" type="text">
<input x-name.debounce.300ms="search" type="text">
```

#### File Inputs -- Auto-delegates to x-files

```html
<input x-name="avatar" type="file">
<!-- Equivalent to: <input x-files="avatar" name="avatar" type="file"> -->
```

#### Server Integration

```html
<div x-data="{ firstName: '', lastName: '', response: '' }">
    <input x-name="firstName" type="text">
    <input x-name="lastName" type="text">
    <button @click="$action('/api/greet')">Submit</button>
    <p x-text="response"></p>
</div>
```

```php
Route::post('/api/greet', function (Request $request) {
    return gale()->state('response', "Hello, {$request->state('firstName')} {$request->state('lastName')}!");
});
```

---

## File Uploads

### x-files Directive

Register file input for Gale tracking. `x-files` goes on the `<input>` element:

```html
<div x-data>
    <!-- Single file -->
    <input type="file" name="avatar" x-files />
    <p x-show="$file('avatar')">
        Name: <span x-text="$file('avatar')?.name"></span>
        Size: <span x-text="$formatBytes($file('avatar')?.size)"></span>
        <img :src="$filePreview('avatar')" />
    </p>
    <button @click="$action('/upload')">Upload</button>
</div>

<!-- Multiple files -->
<div x-data>
    <input type="file" name="images" x-files multiple accept="image/*">

    <template x-for="(file, i) in $files('images')" :key="i">
        <div>
            <img :src="$filePreview('images', i)" class="w-20 h-20 object-cover">
            <span x-text="file.name"></span>
            <span x-text="$formatBytes(file.size)"></span>
        </div>
    </template>

    <div x-show="$uploading">
        <div class="bg-blue-600 h-2" :style="'width: ' + $uploadProgress + '%'"></div>
    </div>

    <button @click="$action('/upload', { onProgress: p => {} })">Upload</button>
    <button @click="$clearFiles('images')">Clear</button>
</div>
```

### File Magics

| Magic | Description |
|-------|-------------|
| `$file('name')` | Get single file info `{name, size, type, lastModified}` |
| `$files('name')` | Get array of file info objects |
| `$filePreview('name', index?)` | Get blob URL for preview |
| `$clearFiles('name?')` | Clear file input and reset |
| `$formatBytes(size, decimals?)` | Format bytes to human-readable |
| `$uploading` | Boolean: upload in progress |
| `$uploadProgress` | Upload percentage 0-100 |
| `$uploadError` | Error message or null |

### Validation Modifiers

```html
<!-- Max file size (5MB) -->
<input type="file" x-files.max-size-5mb />

<!-- Max file count -->
<input type="file" x-files.max-files-3 multiple />

<!-- Combined -->
<input type="file" x-files.max-size-10mb.max-files-5 multiple />
```

### File Events

```html
<div x-data @gale:file-change="console.log($event.detail)">
    <input type="file" x-files />
</div>

<div x-data @gale:file-error="alert($event.detail.message)">
    <input type="file" x-files.max-size-1mb />
</div>
```

| Event | Detail |
|-------|--------|
| `gale:file-change` | `{ name, files }` |
| `gale:file-error` | `{ name, message, type }` |

**Key insight:** Files bypass x-sync rules. When x-files inputs have files, Gale auto-converts to multipart/form-data. Multiple files sent as `images[]` (Laravel array notation).

---

## Message Display

### x-message Directive

Display server-side messages (validation errors, success messages):

```html
<input x-name="email" type="email">
<p x-message="email" class="text-red-600 text-sm"></p>
```

### Reading from a Different State Key

By default, `x-message` reads from the `messages` state property. Use `.from.{key}` to read from a different key:

```html
<!-- Read from 'errors' instead of 'messages' -->
<p x-message.from.errors="email"></p>

<!-- Read from 'warnings' -->
<p x-message.from.warnings="email"></p>
```

### Dynamic Keys

```html
<!-- Template literals (recommended) -->
<span x-message="`items.${index}.name`"></span>

<!-- String concatenation -->
<span x-message="'items.' + index + '.name'"></span>

<!-- Nested arrays -->
<span x-message="`items.${i}.details.${j}.value`"></span>
```

### Array Validation with x-for

Use template literals to display validation errors for array items:

```html
<template x-for="(item, index) in items" :key="index">
    <div>
        <input x-model="items[index].name">
        <span x-message="`items.${index}.name`" class="text-red-500"></span>
    </div>
</template>
```

```php
$request->validateState([
    'items' => 'required|array|min:1',
    'items.*.name' => 'required|string|min:2',
]);
```

**Wildcard clearing:** When using `validateState` with `items.*.name`, all matching keys (`items.0.name`, `items.1.name`, etc.) are auto-cleared before validation.

### Message Types

Server sends type prefixes -> auto CSS classes:

```php
gale()->messages([
    'email' => '[ERROR] Invalid email',         // -> class="message-error"
    'saved' => '[SUCCESS] Changes saved',        // -> class="message-success"
    'note' => '[WARNING] Session expiring',      // -> class="message-warning"
    'info' => '[INFO] New features available',   // -> class="message-info"
]);
```

### Message Configuration

```javascript
Alpine.gale.configureMessage({
    defaultStateKey: "messages",
    autoHide: true,
    autoShow: true,
    typeClasses: {
        success: "message-success",
        error: "message-error",
        warning: "message-warning",
        info: "message-info",
    },
});
```

---

## Polling

### x-interval Directive

```html
<!-- Poll every 5 seconds -->
<div x-data="{ status: '' }" x-interval.5s="$action.get('/status')">
    <span x-text="status"></span>
</div>

<!-- Only when tab visible (saves resources) -->
<div x-interval.5s.visible="refreshStats()">

<!-- Stop on condition -->
<div x-data="{ done: false }" x-interval.1s="checkProgress()" x-interval-stop="done">
```

| Modifier | Description |
|----------|-------------|
| `.{time}` | Duration: `.5s`, `.500ms`, `.2s` |
| `.visible` | Only run when tab visible |

**x-interval-stop:** When expression is true, interval COMPLETELY stops.

**Conditional skip vs stop:**
```html
<!-- Skip pattern: interval keeps running, just skips action -->
x-interval.5s="if (!paused) refreshStats()"

<!-- Stop pattern: interval fully stops -->
x-interval-stop="paused"
```

---

## Confirmation

### x-confirm Directive

```html
<!-- Default message -->
<button @click="$action.delete('/item/1')" x-confirm>Delete</button>

<!-- Custom message -->
<button @click="$action.delete('/item/1')" x-confirm="'Are you sure?'">Delete</button>

<!-- Dynamic message -->
<button x-confirm="'Delete ' + selectedIds.length + ' item(s)?'">Bulk Delete</button>
```

---

## Directives Reference

| Directive | Description |
|-----------|-------------|
| `x-sync` / `x-sync="['a','b']"` | Sync state to server |
| `x-navigate` | Enable SPA navigation |
| `x-navigate-skip` | Skip navigation handling |
| `x-component="name"` | Register named component |
| `data-tags="tag1,tag2"` | Tag component for group targeting |
| `x-name="field"` | Form binding with state |
| `x-files="name"` | File input binding |
| `x-message="key"` | Display server message |
| `x-loading` | Loading state display |
| `x-loading.class="..."` | Add class during loading |
| `x-loading.attr="..."` | Add attribute during loading |
| `x-loading.delay.{time}` | Delayed loading display |
| `x-loading.remove` | Hide during loading |
| `x-indicator="var"` | Create loading variable |
| `x-interval.{time}` | Auto-polling |
| `x-interval.visible` | Poll only when tab visible |
| `x-interval-stop="expr"` | Stop polling condition |
| `x-confirm` | Confirmation dialog |

## Magics Reference

| Magic | Description |
|-------|-------------|
| `$action(url, options?)` | POST with auto CSRF (HTTP mode by default) |
| `$action.get/post/put/patch/delete(url, options?)` | HTTP methods |
| `$gale` | Global connection state |
| `$fetching()` | Element loading state (**function!**) |
| `$navigate(url, options?)` | Programmatic navigation |
| `$components` | Component registry API |
| `$invoke(name, method, ...args)` | Shorthand for `$components.invoke` |
| `$file(name)` | Get file info |
| `$files(name)` | Get files array |
| `$filePreview(name, index?)` | Get preview URL |
| `$clearFiles(name?)` | Clear files |
| `$formatBytes(size, decimals?)` | Format bytes |
| `$uploading` | Upload in progress |
| `$uploadProgress` | Upload progress 0-100 |
| `$uploadError` | Upload error message |

## Events Reference

Frontend events dispatched during request lifecycle (both HTTP and SSE modes):

| Event | When | Detail |
|-------|------|--------|
| `gale:started` | Request initiated | `{ el }` |
| `gale:finished` | Request completed | `{ el }` |
| `gale:error` | Request error (network, server) | `{ el, status }` |
| `gale:retrying` | Retrying after network error | `{ el, message, attempt, maxRetries, delay }` |
| `gale:retries-failed` | All retries exhausted | `{ el }` |
| `gale:html-fallback` | HTML response received (not JSON/SSE) | `{ url, contentLength }` |
| `gale:patch-complete` | DOM patch applied | `{ mode, selector, settle }` |
| `gale:patch-error` | DOM patch failed | `{ error, validModes }` |
| `gale:patch-warning` | DOM patch warning | `{ warning }` |
| `gale:swap-start` | Before DOM manipulation | `{ targets, timing, useViewTransition }` |
| `gale:settle-start` | After swap, before settle | `{ elements }` |
| `gale:settle-complete` | After settle phase | `{ elements }` |
| `gale:component-registered` | Component registered | `{ name, tags, element }` |
| `gale:component-unregistered` | Component removed | `{ name, tags }` |
| `gale:component-stateChanged` | Component state updated | `{ name, updates, oldValues }` |
| `gale:file-change` | File input changed | `{ name, files }` |
| `gale:file-error` | File validation failed | `{ name, message, type }` |
| `gale:state-created` | x-name auto-created state | `{ key, value }` |
| `gale:navigate` | Backend-triggered navigation (via `gale()->navigate()`) | `{ url, key, options }` |

```html
<div @gale:started="console.log('Loading...')"
     @gale:finished="console.log('Done!')">
    <button @click="$action('/save')">Save</button>
</div>
```

## JSON Events Reference (HTTP Mode)

In HTTP mode, the JSON response contains an events array. Each event has a `type` and `data`. The type names match their SSE counterparts:

| Event Type | Data Fields | Description |
|-----------|-------------|-------------|
| `gale-patch-state` | `state`, `onlyIfMissing` | Merge state into Alpine component |
| `gale-patch-elements` | `selector`, `mode`, `html`, `useViewTransition`, `settle`, `limit`, `scroll`, `show`, `focusScroll` | DOM manipulation |
| `gale-patch-component` | `component`, `state`, `onlyIfMissing`, `tag` | Update named component state (by name or tag) |
| `gale-invoke-method` | `component`, `method`, `args` | Invoke method on named component |
| `gale-dispatch` | `event`, `data`, `selector`, `bubbles`, `cancelable`, `composed` | Dispatch browser event |
| `gale-execute-script` | `code`, `autoRemove` | Execute JavaScript |
| `gale-redirect` | `url`, `type` | Client-side redirect |

## SSE Events Reference (SSE Mode)

In SSE mode, events are sent as standard Server-Sent Events:

| Event | Data Lines |
|-------|------------|
| `gale-patch-state` | `state {json}`, `onlyIfMissing {bool}` |
| `gale-patch-elements` | `selector`, `mode`, `elements {html}`, `useViewTransition`, `settle`, `limit`, `scroll`, `show`, `focusScroll` |
| `gale-patch-component` | `component {name}`, `state {json}`, `onlyIfMissing` |
| `gale-invoke-method` | `component {name}`, `method {name}`, `args {json}` |

---

## Configuration Reference

### CSRF

```javascript
Alpine.gale.configureCsrf({
    headerName: "X-CSRF-TOKEN",
    metaTagName: "csrf-token",
    cookieName: "XSRF-TOKEN",
    priority: ['custom', 'meta', 'cookie'],  // Source check order
    customTokenGetter: null,  // () => string -- custom token retrieval function
});
```

### Messages

```javascript
Alpine.gale.configureMessage({
    defaultStateKey: "messages",
    autoHide: true,
    autoShow: true,
    typeClasses: { success: "message-success", error: "message-error", warning: "message-warning", info: "message-info" },
});
```

### Confirmation

```javascript
Alpine.gale.configureConfirm({
    defaultMessage: "Are you sure?",
    handler: async (message) => await myModal.confirm(message),
});
```

### Navigation

```javascript
Alpine.gale.configureNavigation({
    interceptLinks: true,       // Intercept link clicks (default: true)
    interceptForms: true,       // Intercept form submissions (default: true)
    updateHistory: true,        // Update browser history (default: true)
    defaultMode: 'push',        // 'push' | 'replace' (default: 'push')
});
```

### Swap/Settle (DOM Transitions)

Controls CSS transition phases during DOM patching. HTMX-style phased updates:

```javascript
Alpine.gale.configureSwapSettle({
    timing: {
        swapDelay: 0,        // ms before DOM manipulation (exit animation time)
        settleDelay: 20,     // ms after DOM manipulation (enter animation time)
        addedDuration: 100,  // ms before removing gale-added class
    },
    classes: {
        swapping: 'gale-swapping',    // Applied to OLD elements before swap
        settling: 'gale-settling',    // Applied to NEW elements after swap
        added: 'gale-added',          // Applied to NEW elements (kept longer)
        navigating: 'gale-navigating', // Applied during full-page morphs (prevents FOUC)
    },
    useViewTransition: false,  // Global default for View Transitions API
});
```

**CSS Timeline:** `gale-swapping` -> [swapDelay] -> DOM swap -> `gale-settling` + `gale-added` -> [settleDelay] -> `gale-settling` removed -> [addedDuration] -> `gale-added` removed -> `gale:settle-complete` event.

### Error Notifications

Configure how server error responses (4xx/5xx) are displayed as toast notifications:

```javascript
Alpine.gale.configureErrors({
    autoDismissMs: 5000,    // Auto-dismiss timeout in ms (0 = no auto-dismiss, default: 5000)
    maxToasts: 5,           // Maximum visible toasts at once (default: 5)
    onError: null,          // Custom error handler function, replaces default toast display
});
```

The custom `onError` handler receives `(status, statusText)` and, if set, completely replaces the default toast notification behavior. This is useful for integrating with custom notification systems.

**Note:** 422 validation errors with `X-Gale-Response` header are NOT shown as toasts -- they are handled by `x-message` directives. Only non-422 server errors trigger toast notifications.

### Getter APIs

Every `configure*` has a matching getter:

| Method | Returns |
|--------|---------|
| `Alpine.gale.getConfig()` | Current global config (defaultMode, viewTransitions, etc.) |
| `Alpine.gale.getCsrfConfig()` | Current CSRF config |
| `Alpine.gale.getMessageConfig()` | Current message config |
| `Alpine.gale.getConfirmConfig()` | Current confirm config |
| `Alpine.gale.getNavigationConfig()` | Current navigation config |
| `Alpine.gale.getSwapSettleConfig()` | Current swap/settle config |
| `Alpine.gale.getErrorConfig()` | Current error notification config (autoDismissMs, maxToasts, onError) |

### Debug Mode

```javascript
Alpine.gale.debug = true;  // Enable verbose console logging
// or
window.GALE_DEBUG = true;  // Set before Alpine initializes
```

---

## Error Handling Behavior

### HTTP Mode Errors

| Status | Behavior |
|--------|----------|
| 419 (CSRF mismatch) | Auto-refreshes token and retries once transparently |
| 422 with `X-Gale-Response` header | Processes validation errors via JSON events (x-message shows them) |
| 4xx/5xx (non-422) | Shows dismissible toast notification, preserves component state |
| Network error | Automatic retry with exponential backoff (up to `retry.maxRetries`) |

### SSE Mode Errors

| Condition | Behavior |
|-----------|----------|
| Connection drop | Auto-reconnect with configurable retry (retryInterval, retryScaler, retryMaxCount) |
| 4xx/5xx response | Shows error, dispatches `gale:error` event |
| Element removed from DOM | In-flight request auto-cancelled via MutationObserver |

---

## Behavioral Notes

Important internal behaviors for debugging:

| Behavior | Detail |
|----------|--------|
| **State patch scope** | Patches search Alpine data stack: closest x-data scope -> parent scopes -> Alpine global stores |
| **Script execution** | `<script>` tags in patched HTML are auto-executed with deduplication (won't re-run same script) |
| **Serialization limits** | Max depth: 50, max keys: 10,000, max string length: 100,000. Exceeding these silently truncates. |
| **Global stores** | Alpine `$store` data is included in state serialization and sent to server |
| **Back/forward buttons** | Popstate (browser back/forward) triggers a Gale navigation fetch to the URL, with scroll position restoration via `history.state._galeScrollY`. No full page reload occurs. |
| **Default intervals** | `x-interval` defaults to 5000ms when no time modifier specified |
| **Default indicator name** | `x-indicator` defaults to variable name `loading` when no expression given |
| **Element removal auto-abort** | If the triggering element is removed from DOM, its in-flight request is auto-cancelled |
| **Tab visibility** | SSE connections pause when tab is hidden (unless `openWhenHidden: true` or `pauseOnHidden: false`), HTTP requests are unaffected |
| **View Transitions** | SPA navigation uses View Transitions API by default (when browser supports it) |
| **FOUC prevention** | External stylesheets are awaited (up to `foucTimeout` ms) during SPA navigation before showing content |

---

## v2 Frontend API Additions

### x-lazy — Viewport-Triggered Content Loading

Load content when the element scrolls into viewport:

```html
<!-- Load content when visible -->
<div x-data x-lazy="/api/chart-data">
    <div x-loading>Loading...</div>
</div>

<!-- Custom target selector for the HTML patch -->
<div x-data x-lazy="/api/stats" x-lazy:target="#stats-panel">
    <div id="stats-panel">Loading stats...</div>
</div>

<!-- With SSE mode -->
<div x-data x-lazy="/api/feed" x-lazy:sse>
    Loading feed...
</div>
```

---

### x-validate — HTML5 Form Validation Integration

Browser-native validation integrated with Gale form submissions:

```html
<!-- On a form with x-navigate (auto-applied when x-navigate binds to FORM) -->
<form x-data x-navigate @submit.prevent="$action('/save')">
    <input x-name="email" type="email" required>
    <input x-name="name" type="text" minlength="2">
    <button type="submit">Save</button>
</form>

<!-- Manual control -->
<form x-validate @submit.prevent="$action('/save')">
    <input name="email" type="email" required>
    <button type="submit">Save</button>
</form>
```

When Gale intercepts a form submit, it runs HTML5 `checkValidity()` before sending the request. Invalid fields show their native browser validation UI; the request is only sent when all fields are valid.

---

### x-confirm — Confirmation Dialogs

Require user confirmation before an action fires:

```html
<!-- Native browser confirm dialog -->
<button x-confirm="Are you sure you want to delete this?"
        @click="$action.delete('/items/1')">Delete</button>

<!-- Custom confirmation message per element -->
<button x-confirm="This cannot be undone. Delete permanently?"
        @click="$action.delete('/account')">Delete Account</button>

<!-- Configure global confirm behavior -->
<script>
Alpine.gale.configureConfirm({
    strategy: 'native',        // 'native' (browser dialog) | 'custom'
    message: 'Are you sure?',  // Default message
});
</script>
```

**Custom confirm element strategy:**
```html
<div x-data>
    <button x-confirm:custom="myDialog"
            @click="$action.delete('/items/1')">Delete</button>
    <dialog id="myDialog">
        <p>Are you sure?</p>
        <button data-confirm-accept>Yes, delete</button>
        <button data-confirm-reject>Cancel</button>
    </dialog>
</div>
```

---

### x-dirty — Dirty State Tracking

Track unsaved changes in form inputs:

```html
<!-- Show indicator when field has changed -->
<div x-data="{ email: '' }" x-sync="['email']">
    <input x-name="email" type="email">
    <span x-dirty="email" class="text-amber-500">Unsaved</span>

    <!-- Multiple fields -->
    <span x-dirty="email,name">Either field changed</span>

    <!-- Any dirty field -->
    <span x-dirty.all>Form has changes</span>

    <!-- Disable save until dirty -->
    <button :disabled="!$dirty()" @click="$action('/save')">Save</button>
</div>
```

```javascript
// Programmatic dirty tracking
Alpine.gale.isDirty(el, 'email')   // boolean: is field dirty?
Alpine.gale.getDirtyKeys(el)       // Set: which keys are dirty?
Alpine.gale.resetDirty(el)         // Clear all dirty flags
Alpine.gale.initDirtyTracking(el)  // Manually bootstrap tracking

// $dirty magic in templates
$dirty()           // true if any field changed
$dirty('email')    // true if 'email' changed
```

---

### x-listen — Server Push Channels

Subscribe to a server push channel for real-time updates:

```html
<!-- Subscribe to named channel -->
<div x-data="{ count: 0 }" x-listen="notifications">
    <span x-text="count"></span>
</div>

<!-- Multiple channels -->
<div x-data x-listen="orders,inventory">
    <!-- Receives events from both channels -->
</div>

<!-- Channel configuration -->
<script>
Alpine.gale.configurePushChannels({
    prefix: '/gale/push',  // Default push channel URL prefix
});
</script>
```

Push channels receive the same SSE event types as regular requests (`gale-patch-state`, `gale-patch-elements`, etc.). The server pushes to channels via `gale()->push('channel-name')->...`.

---

### $dirty Magic

```javascript
// In Alpine expressions
$dirty()           // Returns true if any tracked state has changed since last server sync
$dirty('email')    // Returns true if specific field changed

// Example usage
<button :class="$dirty() ? 'bg-blue-500' : 'bg-gray-400'"
        :disabled="!$dirty()"
        @click="$action('/save')">Save</button>
```

---

### $gale Properties (v2 Additions)

The `$gale` reactive object now includes additional properties:

```html
<div x-data>
    <!-- Existing properties -->
    <div x-show="$gale.loading">Loading...</div>
    <div x-show="$gale.error">Error occurred</div>
    <span x-text="$gale.activeCount"></span>

    <!-- v2 additions -->
    <div x-show="!$gale.online" class="offline-banner">You are offline</div>
    <span x-text="$gale.online ? 'Connected' : 'Offline'"></span>
</div>
```

| v2 Property | Type | Description |
|-------------|------|-------------|
| `$gale.online` | bool | Browser online/offline status (reactive) |

---

### Debounce and Throttle Options (v2)

Debounce and throttle are now first-class options on all `$action` calls:

```javascript
// Debounce: wait N ms after LAST call before executing
$action('/search', { debounce: 300 })

// Throttle: execute at most once per N ms
$action('/track', { throttle: 500 })

// Leading debounce: fire IMMEDIATELY on first call, suppress subsequent for N ms
$action('/save', { debounce: 200, leading: true })

// Trailing throttle: fire at start AND one trailing call at end of throttle window
$action('/autosave', { throttle: 1000, trailing: true })
```

**Rules:**
- If both `debounce` and `throttle` are set, `debounce` wins (with `console.warn`)
- Timer key is the URL — different URLs on the same element have independent timers
- Timers are auto-cleaned on component unmount via Alpine's `cleanup()`

---

### Optimistic UI (x-optimistic)

Apply an optimistic state update immediately, then roll back if the server rejects:

```html
<div x-data="{ liked: false, count: 0 }" x-sync="['liked', 'count']">
    <button @click="$action('/like', {
        optimistic: { liked: true, count: count + 1 }
    })">
        <span x-text="liked ? '♥' : '♡'"></span>
        <span x-text="count"></span>
    </button>
</div>
```

```javascript
// Programmatic optimistic check
Alpine.gale.isOptimistic(el)              // Has pending optimistic state
Alpine.gale.getPendingOptimisticCount(el) // Count of pending optimistic actions
```

On server success: optimistic state is replaced with server response.
On server error: optimistic state is rolled back to the pre-action value.

---

### Error Event System (v2)

```javascript
// Global error handler
const unregister = Alpine.gale.onError((err) => {
    // err shape: { type, status, message, url, recoverable, retry }
    // type: 'network' | 'server' | 'parse' | 'timeout' | 'abort' | 'security'

    if (err.type === 'security' && err.status === 401) {
        window.location = '/login';
        return false;  // Suppress default error handling
    }

    if (err.type === 'server' && err.status === 429) {
        // Rate limited — Gale will auto-retry; display a message
        showToast('Too many requests. Retrying...');
    }
});

// Per-action error handler (takes precedence over global)
$action('/save', {
    onError: (err) => {
        if (!err.recoverable) {
            showToast('Failed: ' + err.message);
        }
    }
});
```

**Error types:**
| Type | When |
|------|------|
| `network` | fetch() threw (offline, DNS failure) |
| `server` | 4xx/5xx HTTP response |
| `parse` | JSON/SSE parsing failed |
| `timeout` | Request exceeded timeout |
| `abort` | AbortController cancelled |
| `security` | Checksum failure (403), CSRF failure (419), auth (401) |

---

### Morph Lifecycle Hooks (v2)

```javascript
// Register lifecycle hooks for all morphs
const unregister = Alpine.gale.onMorph({
    // Called before element is updated (return false to cancel)
    beforeUpdate(el, newEl) {
        console.log('Morphing:', el.id);
    },

    // Called after element is updated
    afterUpdate(el) {
        reinitializePlugins(el);
    },

    // Called before element is removed (return false to cancel)
    beforeRemove(el) {
        if (el.id === 'protected') { return false; }
    },

    // Called after element is removed
    afterRemove(el) {
        cleanup(el);
    },

    // Called after a new element is added to DOM
    afterAdd(el) {
        initializeNewEl(el);
    },
});

// Unregister when done
unregister();
```

**IMPORTANT:** Hook callbacks run outside Alpine reactive context. Never use `$nextTick`, `$data`, `$el` or other Alpine magics inside them. To update Alpine state from a hook, capture `const self = $data` in `x-init` first.

---

### Third-Party JS Library Cleanup Registry (v2)

Register cleanup/reinit handlers for third-party libraries during morphs:

```javascript
// Generic cleanup handler
Alpine.gale.registerCleanup('[data-chart]', {
    beforeMorph: (el) => {
        // Save state + destroy before morph
        const chart = Chart.getChart(el.querySelector('canvas'));
        if (chart) { el._savedChart = chart.data; chart.destroy(); }
    },
    afterMorph: (el) => {
        // Reinitialize after morph
        if (el._savedChart) { initChart(el, el._savedChart); }
    },
    destroy: (el) => {
        // Final cleanup when element is removed
        Chart.getChart(el.querySelector('canvas'))?.destroy();
    },
});

// Remove a registered cleanup
Alpine.gale.removeCleanup('[data-chart]');

// Built-in compatibility helpers
Alpine.gale.setupAnimationCompat();  // GSAP cleanup/reinit
Alpine.gale.setupRteCompat();        // Rich text editor (TipTap, Quill, etc.)
Alpine.gale.setupSortableCompat();   // SortableJS drag-and-drop

// GSAP specific
Alpine.gale.registerGsapCleanup('[data-gsap]');  // Auto-handles GSAP context

// SortableJS specific
Alpine.gale.installSortableHandlers(el, sortable); // Install drop + order-sync listeners
```

---

### Plugin/Extension System (v2)

```javascript
// Register a Gale plugin
Alpine.gale.registerPlugin('my-plugin', {
    name: 'my-plugin',

    // Called once when plugin is registered
    install(Alpine, config) {
        // Access full Alpine instance and Gale config
        Alpine.magic('myMagic', () => () => 'plugin magic!');
    },

    // Hook into request lifecycle
    onRequest(url, options) {
        options.headers['X-Analytics-Id'] = getSessionId();
    },

    // Hook into response lifecycle
    onResponse(url, events) {
        trackInteraction(url, events.length);
    },

    // Hook into error lifecycle
    onError(err) {
        logError(err);
    },

    // Called when plugin is unregistered
    destroy() {
        // Cleanup
    },
});

Alpine.gale.unregisterPlugin('my-plugin');
Alpine.gale.getPlugin('my-plugin');    // Get plugin object
Alpine.gale.getPluginNames();          // ['my-plugin']
Alpine.gale.getPluginCount();          // 1
```

---

### Custom Alpine Directives (v2)

```javascript
// Register a Gale-aware custom directive
Alpine.gale.directive('tooltip', {
    // Called when element mounts (Alpine directive init)
    init(el, expression, galeContext) {
        // galeContext: { mode, component, config }
        el._tippy = tippy(el, { content: expression });
    },

    // Called before and after morphs
    morph(el, phase, galeContext) {
        // phase: 'before' | 'after'
        if (phase === 'before') { el._tippy?.destroy(); }
        if (phase === 'after')  { el._tippy = tippy(el, { content: el.getAttribute('x-tooltip') }); }
    },

    // Called when element is removed
    destroy(el, galeContext) {
        el._tippy?.destroy();
    },
});

// Usage: <button x-tooltip="'Click to save'">Save</button>
// (name is auto-prefixed with 'x-' if you don't include it)

Alpine.gale.getCustomDirectiveNames();  // ['tooltip']
Alpine.gale.getCustomDirectiveCount(); // 1
```

---

### Alpine.gale Complete JS API Reference

```javascript
// Configuration
Alpine.gale.configure(options)        // Set global config
Alpine.gale.getConfig()               // Get copy of current config

// CSRF
Alpine.gale.configureCsrf(options)    // Configure CSRF strategy
Alpine.gale.getCsrfConfig()           // Get CSRF config

// Navigation
Alpine.gale.configureNavigation(opts) // Configure SPA navigation
Alpine.gale.getNavigationConfig()     // Get navigation config

// History + Prefetch cache
Alpine.gale.clearHistoryCache()       // Clear all cached pages
Alpine.gale.getHistoryCacheSize()     // Number of cached entries
Alpine.gale.getHistoryCacheKeys()     // Array of cached URLs
Alpine.gale.bustHistoryCache(url)     // Invalidate specific URL
Alpine.gale.clearPrefetchCache()      // Clear link prefetch cache
Alpine.gale.getPrefetchCacheSize()    // Number of prefetched URLs
Alpine.gale.clearCache(url?)          // Clear response cache (all or specific URL)
Alpine.gale.getResponseCacheSize()    // Number of cached responses

// Components
Alpine.gale.registerComponent(name, el) // Manual registration
Alpine.gale.unregisterComponent(name)
Alpine.gale.getComponent(name)           // Get component data
Alpine.gale.getAllComponents()           // Map of all registered components
Alpine.gale.hasComponent(name)           // boolean
Alpine.gale.updateComponentState(name, state) // RFC 7386 merge
Alpine.gale.invokeComponentMethod(name, method, ...args)
Alpine.gale.whenComponentReady(name, timeout?) // Returns Promise
Alpine.gale.onComponentReady(name, callback)   // Callback on ready
Alpine.gale.onComponentRegistered(callback)    // Lifecycle hook
Alpine.gale.onComponentUnregistered(callback)  // Lifecycle hook
Alpine.gale.onComponentStateChanged(callback)  // Lifecycle hook
Alpine.gale.getComponentState(name, key?)      // Get reactive proxy
Alpine.gale.createComponentState(name, state, opts) // Init state
Alpine.gale.deleteComponentState(name, keys)   // Remove keys
Alpine.gale.watchComponentState(name, key, cb) // Watch changes

// Morph hooks
Alpine.gale.onMorph({ beforeUpdate, afterUpdate, beforeRemove, afterRemove, afterAdd })
Alpine.gale.registerCleanup(selector, handlers) // Third-party cleanup
Alpine.gale.removeCleanup(selector)
Alpine.gale.setupAnimationCompat()  // GSAP/animation compat
Alpine.gale.setupRteCompat()        // Rich text editor compat
Alpine.gale.setupSortableCompat()   // SortableJS compat

// Error handling
Alpine.gale.onError(callback)       // Global error handler — returns unregister fn

// Push channels
Alpine.gale.configurePushChannels({ prefix: '/gale/push' })
Alpine.gale.getPushChannelConfig()
Alpine.gale.getActiveChannelCount()
Alpine.gale.getActiveChannelNames()

// SSE connections
Alpine.gale.getActiveConnectionCount()  // Total SSE connections
Alpine.gale.getSharedConnectionCount()  // Shared SSE connections
Alpine.gale.getConnectionStates()       // Array of { url, state }

// Debug panel
Alpine.gale.debug.toggle()
Alpine.gale.debug.open()
Alpine.gale.debug.close()
Alpine.gale.debug.isEnabled()
Alpine.gale.debug.pushRequest(entry)
Alpine.gale.debug.pushState(entry)
Alpine.gale.debug.pushError(entry)
Alpine.gale.debug.clear()

// Console logging
Alpine.gale.setLogLevel('off' | 'info' | 'verbose')
Alpine.gale.getLogLevel()

// Security
Alpine.gale.validateRedirectUrl(url)  // Returns boolean
Alpine.gale.configureRedirect({ allowedDomains, allowExternal, logBlocked })
Alpine.gale.getRedirectConfig()
Alpine.gale.getCspNonce()             // Current CSP nonce or null
Alpine.gale.validateSSEEvent({ event, data })  // Returns { valid, reason? }

// Rate limiting
Alpine.gale.getRateLimitStatus(url, method) // { limited, retryAt, retryAfterMs }
Alpine.gale.cancelAllRateLimitRetries()

// Authentication
Alpine.gale.isAuthExpired()     // boolean
Alpine.gale.resetAuth()         // Call after re-auth

// Offline detection
Alpine.gale.isOnline()          // Synchronous boolean
Alpine.gale.getOfflineQueueSize()   // Queued action count
Alpine.gale.clearOfflineQueue()     // Discard queued actions

// Dirty tracking
Alpine.gale.isDirty(el, prop?)  // boolean
Alpine.gale.getDirtyKeys(el)    // Set of dirty field names
Alpine.gale.resetDirty(el)      // Reset dirty state
Alpine.gale.initDirtyTracking(el) // Bootstrap for element

// Optimistic UI
Alpine.gale.isOptimistic(el)              // Has pending optimistic state
Alpine.gale.getPendingOptimisticCount(el) // Count of pending actions

// Plugins
Alpine.gale.registerPlugin(name, plugin)  // Register plugin
Alpine.gale.unregisterPlugin(name)        // Remove plugin
Alpine.gale.getPlugin(name)               // Get plugin by name
Alpine.gale.getPluginNames()              // Array of names
Alpine.gale.getPluginCount()              // Number of plugins

// Custom directives
Alpine.gale.directive(name, { init, morph, destroy })
Alpine.gale.getCustomDirectiveNames()
Alpine.gale.getCustomDirectiveCount()

// Pipeline / Queue stats
Alpine.gale.getPipelineStats()   // Request pipeline statistics
Alpine.gale.getQueueStats()      // Request queue statistics

// Memory management
Alpine.gale.teardown()           // Remove all listeners + observers (for testing)
```
