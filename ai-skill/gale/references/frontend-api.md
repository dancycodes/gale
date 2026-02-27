# Gale Frontend API Reference

Complete reference for the Alpine Gale plugin. Read this file whenever creating or editing blade files.

## Table of Contents
- [Setup](#setup)
- [HTTP Magics ($action)](#http-magics)
- [State Synchronization (x-sync)](#state-synchronization)
- [Global State ($gale)](#global-state)
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
- [SSE Events Reference](#sse-events-reference)
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

**CRITICAL:** Remove any existing Alpine.js CDN or npm imports — `@gale` includes Alpine.

### Alpine Context Required

ALL Gale features need `x-data` or `x-init`:

```html
<!-- ✅ Works -->
<div x-data="{ count: 0 }">
    <button @click="$action('/increment')">+</button>
</div>

<!-- ✅ Works -->
<div x-init="$action.get('/load')">Loading...</div>

<!-- ❌ Fails — no Alpine context -->
<button @click="$action('/increment')">Broken</button>
```

---

## HTTP Magics

### $action(url, options?)

Default: **POST with automatic CSRF injection**.

```html
<div x-data="{ count: 0 }" x-sync>
    <!-- Default POST -->
    <button @click="$action('/increment')">+1</button>

    <!-- Method shorthands -->
    <button @click="$action.get('/api/data')">GET</button>
    <button @click="$action.post('/api/save')">POST</button>
    <button @click="$action.put('/api/replace')">PUT</button>
    <button @click="$action.patch('/api/update')">PATCH</button>
    <button @click="$action.delete('/api/remove')">DELETE</button>
</div>
```

### CSRF Auto-Injection

| Method | CSRF |
|--------|------|
| `$action()` / `$action.post()` | ✅ Auto CSRF |
| `$action.put()` / `$action.patch()` / `$action.delete()` | ✅ Auto CSRF |
| `$action.get()` | ❌ No CSRF needed |

Reads from `<meta name="csrf-token">` (output by `@gale`).

### Request Options

```html
<button @click="$action('/save', {
    method: 'POST',            // HTTP method (default POST)
    include: ['user', 'settings'], // Only send these state keys
    exclude: ['tempData'],     // Don't send these state keys
    includeFormFields: true,   // Include form fields (default true)
    includeComponents: ['export-panel'],  // Include named component states
    includeComponentsByTag: ['filters'],  // Include by tag
    headers: { 'X-Custom': 'value' },
    retryInterval: 1000,       // Initial retry delay ms
    retryScaler: 2,            // Exponential backoff multiplier
    retryMaxWaitMs: 30000,     // Max retry delay ms
    retryMaxCount: 10,         // Max retry attempts
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

**Approach A: x-sync (declare once)** — Good when most actions need the same state:
```html
<div x-data="{ search: '', category: '' }" x-sync="['search', 'category']">
```

**Approach B: include per-action (explicit per call)** — Good when different actions need different state:
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
| `$gale.loading` | bool | Any request in progress |
| `$gale.error` | bool | Any error occurred |
| `$gale.retrying` | bool | Currently retrying |
| `$gale.retriesFailed` | bool | All retries exhausted |
| `$gale.activeCount` | number | Active request count |
| `$gale.lastError` | object\|null | Most recent error: `{ timestamp, status, message }` |
| `$gale.errors` | array | Last 10 errors, each: `{ timestamp, status, message }` |
| `$gale.state` | object | Snapshot of all state properties |
| `$gale.clearErrors()` | method | Clear error history and reset retriesFailed |

---

## Element Loading

### $fetching() Magic

**Per-element loading state.** Returns true when THIS element triggered a request that's in progress.

```html
<button @click="$action('/save')">
    <span x-show="!$fetching()">Save</span>
    <span x-show="$fetching()">Saving...</span>
</button>
```

**CRITICAL:** `$fetching()` is a **function call** — always use parentheses!

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
    console.log('Changed:', oldVal, '→', newVal);
});

// Wait for components to mount
$components.onReady(['stat-users', 'stat-orders'], () => {
    console.log('All components ready!');
});

// Get component state
$components.state('cart', 'total'); // → returns value

// Shorthand for invoke
$invoke('cart', 'recalculate');
```

**IMPORTANT:** `$components.invoke()` passes the third argument directly to the method (does NOT spread an array). So pass the object directly:
```javascript
// ✅ Correct
$components.invoke('feed', 'addItem', { id: 1, text: 'Hello' });

// ❌ Wrong — would pass the array itself
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
| file | — | Delegates to x-files |

If element has `value` attribute, that becomes initial state: `<input x-name="count" type="number" value="42">` → `{ count: 42 }`

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

<!-- Multiple (array) — use .array modifier -->
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

#### File Inputs — Auto-delegates to x-files

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

Server sends type prefixes → auto CSS classes:

```php
gale()->messages([
    'email' => '[ERROR] Invalid email',         // → class="message-error"
    'saved' => '[SUCCESS] Changes saved',        // → class="message-success"
    'note' => '[WARNING] Session expiring',      // → class="message-warning"
    'info' => '[INFO] New features available',   // → class="message-info"
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
| `$action(url, options?)` | POST with auto CSRF |
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

## SSE Events Reference

| Event | Data Lines |
|-------|------------|
| `gale-patch-state` | `state {json}`, `onlyIfMissing {bool}` |
| `gale-patch-elements` | `selector`, `mode`, `elements {html}`, `useViewTransition`, `settle`, `limit`, `scroll`, `show`, `focusScroll` |
| `gale-patch-component` | `component {name}`, `state {json}`, `onlyIfMissing` |
| `gale-invoke-method` | `component {name}`, `method {name}`, `args {json}` |

## Configuration Reference

### CSRF

```javascript
Alpine.gale.configureCsrf({
    headerName: "X-CSRF-TOKEN",
    metaTagName: "csrf-token",
    cookieName: "XSRF-TOKEN",
    priority: ['custom', 'meta', 'cookie'],  // Source check order
    customTokenGetter: null,  // () => string — custom token retrieval function
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
    },
    useViewTransition: false,  // Global default for View Transitions API
});
```

**CSS Timeline:** `gale-swapping` → [swapDelay] → DOM swap → `gale-settling` + `gale-added` → [settleDelay] → `gale-settling` removed → [addedDuration] → `gale-added` removed → `gale:settle-complete` event.

### Getter APIs

Every `configure*` has a matching getter:

| Method | Returns |
|--------|---------|
| `Alpine.gale.getCsrfConfig()` | Current CSRF config |
| `Alpine.gale.getMessageConfig()` | Current message config |
| `Alpine.gale.getConfirmConfig()` | Current confirm config |
| `Alpine.gale.getNavigationConfig()` | Current navigation config |
| `Alpine.gale.getSwapSettleConfig()` | Current swap/settle config |

### Debug Mode

```javascript
Alpine.gale.debug = true;  // Enable verbose console logging
// or
window.GALE_DEBUG = true;  // Set before Alpine initializes
```

---

## SSE Lifecycle Events

Frontend events dispatched during request lifecycle:

| Event | When | Detail |
|-------|------|--------|
| `gale:started` | SSE connection opened | `{ el }` |
| `gale:finished` | SSE connection closed | `{ el }` |
| `gale:error` | SSE connection error | `{ el, status }` |
| `gale:retrying` | Retrying after error | `{ el, message }` |
| `gale:retries-failed` | All retries exhausted | `{ el }` |
| `gale:html-fallback` | HTML response (not SSE) | `{ url, contentLength }` |
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

```html
<div @gale:started="console.log('Loading...')"
     @gale:finished="console.log('Done!')">
    <button @click="$action('/save')">Save</button>
</div>
```

---

## Content-Type Handling

The frontend handles multiple response types gracefully:

| Content-Type | Behavior |
|-------------|----------|
| `text/event-stream` | Standard SSE processing (state patches, DOM morphing) |
| `text/html` | Full-page morph fallback — morphs HTML into DOM (handles `dd()`, blade returns) |
| `application/json` | Patches JSON data directly into Alpine state |
| `204 No Content` | Silent success — no action taken |

---

## DOM Patching Categories

| Category | Modes | State Behavior |
|----------|-------|---------------|
| **Server-driven** | `outer` (default), `inner` | State comes from `x-data` in response HTML via `Alpine.initTree()` |
| **Client-preserved** | `outerMorph`, `innerMorph` | Existing Alpine state preserved via `Alpine.morph()` smart diffing |
| **Insertion/Deletion** | `append`, `prepend`, `before`, `after`, `remove` | New elements initialized, existing elements unchanged |

**Event Processing Order (atomic batch):** State patches → Component patches → Element patches → Method invocations. This ensures state is ready before DOM updates reference it.

---

## SSE Behavioral Notes

Important internal behaviors to be aware of when debugging:

| Behavior | Detail |
|----------|--------|
| **Error page rendering** | 4xx/5xx HTML responses replace the entire document (destroys SPA state) |
| **Redirect handling** | Browser-followed redirects navigate via `window.location.href` (full reload) |
| **Element removal auto-abort** | If the triggering element is removed from DOM, its in-flight request is auto-cancelled via MutationObserver |
| **Visibility changes** | SSE connections drop when page/tab is hidden, auto-recreate on visible (unless `openWhenHidden: true`) |
| **State patch scope** | Patches search Alpine data stack: closest x-data scope → parent scopes → Alpine global stores |
| **Script execution** | `<script>` tags in patched HTML are auto-executed with deduplication (won't re-run same script) |
| **Serialization limits** | Max depth: 50, max keys: 10,000, max string length: 100,000. Exceeding these silently truncates. |
| **Global stores** | Alpine `$store` data is included in state serialization and sent to server |
| **Back/forward buttons** | Popstate (browser back/forward) triggers a full page reload, not SPA navigation |
| **Default intervals** | `x-interval` defaults to 5000ms when no time modifier specified |
| **Default indicator name** | `x-indicator` defaults to variable name `loading` when no expression given |
