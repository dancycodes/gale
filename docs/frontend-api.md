# Frontend API Reference

> **See also:** [Core Concepts](core-concepts.md) | [Backend API Reference](backend-api.md) | [Navigation & SPA](navigation.md) | [Forms, Validation & Uploads](forms-validation-uploads.md) | [Components, Events & Polling](components-events-polling.md)

Complete JavaScript API reference for the Alpine Gale plugin. Covers every Alpine magic, directive, configuration option, and event dispatched by the plugin.

> **Prerequisite:** All magics and directives require an Alpine component (`x-data`) on the element or an ancestor. They cannot be used outside a component context.

---

## Table of Contents

- [Setup](#setup)
- [HTTP Magics](#http-magics)
  - [$action](#action)
  - [Method Shorthands](#method-shorthands)
  - [Options Object](#options-object)
- [State Magics](#state-magics)
  - [$gale](#gale)
  - [$fetching](#fetching)
  - [$dirty](#dirty)
- [File Upload Magics](#file-upload-magics)
  - [$file / $files](#file--files)
  - [$filePreview](#filepreview)
  - [$clearFiles](#clearfiles)
  - [$formatBytes](#formatbytes)
  - [$uploading / $uploadProgress / $uploadError](#uploading--uploadprogress--uploaderror)
  - [$lazy](#lazy)
  - [$listen](#listen)
  - [$invoke](#invoke)
- [Navigation](#navigation)
  - [$navigate](#navigate)
- [Component Registry](#component-registry)
  - [$components](#components)
- [Directives](#directives)
  - [x-sync](#x-sync)
  - [x-navigate](#x-navigate)
  - [x-component](#x-component)
  - [x-message](#x-message)
  - [x-files](#x-files)
  - [x-name](#x-name)
  - [x-interval](#x-interval)
  - [x-confirm](#x-confirm)
  - [x-lazy](#x-lazy)
  - [x-validate](#x-validate)
  - [x-dirty](#x-dirty)
  - [x-listen](#x-listen)
  - [x-loading](#x-loading)
  - [x-indicator](#x-indicator)
  - [x-prefetch](#x-prefetch)
- [Global Configuration](#global-configuration)
- [Events Reference](#events-reference)
- [Alpine.gale API](#alpinegale-api)

---

## Setup

Add `@gale` to your layout `<head>`. This outputs the Alpine.js CDN script plus the Alpine Gale plugin:

```html
<head>
    @gale
</head>
```

The `@gale` directive also injects several `window.*` globals used to bridge PHP config values into the plugin (`GALE_SANITIZE_HTML`, `GALE_ALLOW_SCRIPTS`, `GALE_CSP_NONCE`, `GALE_REDIRECT_CONFIG`, `GALE_DEBUG_MODE`).

If you manage Alpine yourself (npm / Vite), register the plugin manually:

```javascript
import Alpine from 'alpinejs';
import gale from 'alpine-gale';

Alpine.plugin(gale);
Alpine.start();
```

---

## HTTP Magics

### `$action`

**Signature:** `$action(url, options = {}): Promise<void>`

The primary HTTP magic. Performs an HTTP POST by default, serializes the component's `x-sync` state as the request body, and applies the server response back to the component.

```html
<div x-data="{ count: 0 }" x-sync>
    <button @click="$action('/increment')">+1</button>
    <span x-text="count"></span>
</div>
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | `string` | Request URL (absolute or relative) |
| `options` | `object` | Optional request options — see [Options Object](#options-object) |

**Return type:** `Promise<void>` — resolves when the HTTP response is received; patch application happens asynchronously via `requestAnimationFrame`.

**Default method:** POST. Override with `{ method: 'GET' }` or use a [method shorthand](#method-shorthands).

**Mode selection:**

1. Per-action option `{ sse: true }` or `{ http: true }` (highest priority)
2. Global config: `Alpine.gale.configure({ defaultMode: 'sse' })`
3. Built-in default: `'http'`

---

### Method Shorthands

`$action` exposes method-specific shorthands. All support the same [Options Object](#options-object).

```html
<!-- GET -->
<button @click="$action.get('/items')">Refresh</button>

<!-- POST (default, same as $action) -->
<button @click="$action.post('/save')">Save</button>

<!-- PUT -->
<button @click="$action.put('/users/1')">Update</button>

<!-- PATCH -->
<button @click="$action.patch('/users/1/status')">Toggle</button>

<!-- DELETE -->
<button @click="$action.delete('/users/1')">Delete</button>
```

CSRF tokens are automatically injected for all non-GET methods.

---

### Options Object

Every HTTP magic accepts a second `options` argument:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `method` | `string` | `'POST'` | HTTP method override (`'GET'`, `'POST'`, `'PUT'`, `'PATCH'`, `'DELETE'`) |
| `sse` | `boolean` | `false` | Force SSE mode for this request |
| `http` | `boolean` | `false` | Force HTTP JSON mode for this request |
| `include` | `string[]` | all x-sync keys | State keys to whitelist in the request body |
| `exclude` | `string[]` | none | State keys to blacklist from the request body |
| `includeFormFields` | `boolean` | `true` | Include form fields in the serialized state |
| `includeComponents` | `string[]` \| `object` | — | Named components (by name) to include in the request |
| `includeComponentsByTag` | `string[]` | — | Named components (by tag) to include in the request |
| `headers` | `object` | — | Additional HTTP headers to merge |
| `debounce` | `number` | — | Trailing-edge debounce in milliseconds |
| `leading` | `boolean` | `false` | Leading-edge debounce (fire on first call, suppress within window) |
| `throttle` | `number` | — | Throttle in milliseconds (at most once per window) |
| `trailing` | `boolean` | `false` | Emit one trailing call after throttle window ends |
| `confirm` | `string` | — | Show a confirmation dialog with this message before the request |
| `optimistic` | `object` | — | Apply this state patch immediately before the request (rollback on error) |
| `delta` | `boolean` | `true` | When `false`, send full state instead of dirty-key delta |
| `queue` | `string` | global config | Per-action queue mode: `'parallel'`, `'sequential'`, `'cancel-previous'` |
| `retryInterval` | `number` | — | Initial SSE retry interval in ms (SSE mode only) |
| `retryScaler` | `number` | — | Exponential backoff multiplier (SSE mode only) |
| `retryMaxWaitMs` | `number` | — | Maximum retry interval cap in ms (SSE mode only) |
| `retryMaxCount` | `number` | — | Maximum retry attempts (SSE mode only) |
| `requestCancellation` | `string` \| `AbortController` | — | Cancellation mode or a custom `AbortController` |
| `onProgress` | `function` | — | Upload progress callback `(percent: number) => void` (file uploads only) |

**Debounce and throttle notes:**

- If both `debounce` and `throttle` are provided, `debounce` wins and a `console.warn` is emitted.
- Timer key is the URL — different URLs on the same element have independent timers.
- Timers are automatically cancelled when the component unmounts.

**Examples:**

```javascript
// Debounce search input
$action('/search', { data: { q: query }, debounce: 300 })

// Leading-edge debounce (fires immediately, then suppresses)
$action('/suggest', { debounce: 200, leading: true })

// Throttle rapid clicks
$action('/like', { throttle: 1000 })

// Confirm before destructive action
$action('/delete', { confirm: 'Are you sure you want to delete this item?' })

// Optimistic UI: immediately mark as saved, rollback on error
$action('/save', { optimistic: { saved: true, saving: false } })

// SSE mode for a long-running action
$action('/generate', { sse: true })

// Only send specific state keys
$action('/update-email', { include: ['email'] })

// Send current component + the cart component state
$action('/checkout', { includeComponents: ['cart'] })
```

---

## State Magics

### `$gale`

**Type:** `object` (reactive)

Global connection state proxy. Provides reactive access to the plugin's runtime state across all components. All properties are reactive — changes automatically update Alpine bindings.

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `loading` | `boolean` | `true` when any Gale request is in flight (globally) |
| `activeCount` | `number` | Number of active in-flight requests |
| `retrying` | `boolean` | `true` when a request is being retried |
| `retriesFailed` | `boolean` | `true` when all retry attempts have been exhausted |
| `error` | `boolean` | `true` when `lastError` is set |
| `lastError` | `object \| null` | Most recent error object — see shape below |
| `errors` | `object[]` | Array of last 10 errors |
| `rateLimited` | `object \| null` | `{ until: Date, retryAfter: number }` when a 429 is active, else `null` |
| `rateLimitMessage` | `string \| null` | Human-readable message when rate-limited |
| `authExpired` | `boolean` | `true` when a 401 or 419 session expiry is detected |
| `online` | `boolean` | `true` when the browser has network connectivity |

**Error object shape:**

```javascript
{
    timestamp: number,      // Unix ms timestamp
    type: string,           // 'network' | 'server' | 'parse' | 'timeout' | 'abort' | 'security'
    status: number,         // HTTP status code (0 for network errors)
    message: string,        // Human-readable description
    context: object,        // Additional context (url, method, etc.)
    recoverable: boolean,   // Whether a retry is possible
}
```

**Methods:**

| Method | Description |
|--------|-------------|
| `clearErrors()` | Clear `lastError`, `errors`, and reset `retriesFailed` |

**Examples:**

```html
<!-- Global loading indicator -->
<div x-show="$gale.loading" class="spinner"></div>

<!-- Offline banner -->
<div x-show="!$gale.online">You are offline. Changes will sync when reconnected.</div>

<!-- Rate limit message -->
<div x-show="$gale.rateLimited" x-text="$gale.rateLimitMessage"></div>

<!-- Auth expiry -->
<div x-show="$gale.authExpired">
    Session expired. <a href="/login">Log in again</a>
</div>

<!-- Last error display -->
<div x-show="$gale.error">
    Error: <span x-text="$gale.lastError?.message"></span>
</div>
```

---

### `$fetching`

**Signature:** `$fetching(): boolean`

Per-component loading state. Returns `true` when a Gale request is in flight for the **current Alpine component** (the closest `x-data` ancestor). This is scoped — it only reflects requests originating from within the component.

```html
<div x-data="{ name: '' }" x-sync>
    <input x-model="name" @input="$action('/save', { debounce: 500 })">
    <span x-show="$fetching()">Saving...</span>
    <button :disabled="$fetching()" @click="$action('/submit')">Submit</button>
</div>
```

> **Note:** `$fetching` is a function — call it as `$fetching()`. It is scoped to the component, unlike `$gale.loading` which is global.

---

### `$dirty`

**Signature:** `$dirty(key?: string): boolean`

Returns whether any component state (or a specific key) has been modified since the last server response. Relies on dirty tracking being active — it is automatically enabled for components using `x-sync`.

```html
<div x-data="{ title: '', body: '' }" x-sync>
    <input x-model="title">
    <textarea x-model="body"></textarea>
    <button :disabled="!$dirty()" @click="$action('/save')">Save</button>
    <span x-show="$dirty('title')">Title has unsaved changes</span>
</div>
```

| Parameter | Description |
|-----------|-------------|
| no argument | `true` if any tracked key is dirty |
| `key` (string) | `true` if that specific key is dirty |

---

## File Upload Magics

These magics are available inside components with `x-files` bindings.

### `$file` / `$files`

```javascript
$file(name)           // Get info for a single-file input
$files(name)          // Get info for a multi-file input (returns array)
```

Returns file info objects with shape:

```javascript
{
    name: string,       // File name
    size: number,       // File size in bytes
    type: string,       // MIME type
    lastModified: number,
    previewUrl: string, // Blob URL for image preview (revoked on component destroy)
}
```

```html
<div x-data="{ photo: null }" x-sync>
    <input type="file" x-files="photo">
    <img :src="$file('photo')?.previewUrl" x-show="$file('photo')">
    <span x-text="$file('photo')?.name"></span>
</div>
```

---

### `$filePreview`

**Signature:** `$filePreview(name, index = 0): string | null`

Returns the blob preview URL for a file at the given index. Convenience wrapper around `$files(name)[index]?.previewUrl`.

```html
<img :src="$filePreview('avatar')">
```

---

### `$clearFiles`

**Signature:** `$clearFiles(name?: string): void`

Clears file input state. Without an argument, clears all file inputs in the component. With a `name` argument, clears only that input.

```html
<button @click="$clearFiles('photo')">Remove photo</button>
<button @click="$clearFiles()">Clear all</button>
```

---

### `$formatBytes`

**Signature:** `$formatBytes(bytes: number, decimals?: number): string`

Formats a byte count as a human-readable string (e.g. `1.2 MB`). Default is 2 decimal places.

```html
<span x-text="$formatBytes($file('doc')?.size)"></span>
```

---

### `$uploading` / `$uploadProgress` / `$uploadError`

Upload state magics available inside any component with `x-files` bindings.

| Magic | Type | Description |
|-------|------|-------------|
| `$uploading` | `boolean` | `true` while a file upload is in progress |
| `$uploadProgress` | `number` | Upload progress percentage (0–100) |
| `$uploadError` | `string \| null` | Error message if upload failed, else `null` |

```html
<div x-data x-sync>
    <input type="file" x-files="photo">
    <button @click="$action('/upload')" :disabled="$uploading">
        <span x-show="!$uploading">Upload</span>
        <span x-show="$uploading">Uploading <span x-text="$uploadProgress + '%'"></span></span>
    </button>
    <span x-show="$uploadError" x-text="$uploadError" class="text-red-500"></span>
</div>
```

---

### `$lazy`

**Signature:** `$lazy(url: string, options = {}): Promise<void>`

Programmatically fetch a URL and morph the response into the current element. Useful for on-demand content loading triggered by user interaction (complementing the `x-lazy` directive which loads on viewport entry).

```html
<div x-data>
    <button @click="$lazy('/modal/content')">Load Content</button>
    <div id="modal-body"><!-- content morphed here --></div>
</div>
```

Accepts the same `options` object as `$action`.

---

### `$listen`

**Signature:** `$listen(channel: string): object`

Returns a subscription controller for a server-push channel. Useful for programmatic subscription management (complement to `x-listen`).

```html
<div x-data x-init="$listen('notifications').subscribe()">
```

---

### `$invoke`

**Signature:** `$invoke(componentName: string, method: string, ...args): *`

Invoke a method on a named component from another component. The named component must be registered with `x-component`.

```html
<div x-data>
    <button @click="$invoke('modal', 'open', { title: 'Hello' })">Open Modal</button>
</div>
```

This is equivalent to `$components.invoke(name, method, ...args)`.

---

## Navigation

### `$navigate`

**Signature:** `$navigate(url: string, options = {}): Promise<void>`

Programmatically trigger SPA navigation to a URL. Uses the same full-page morph mechanism as `x-navigate` link clicks.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | `string` | Target URL (relative or absolute) |
| `options.replace` | `boolean` | Use `replaceState` instead of `pushState` |

```html
<div x-data>
    <button @click="$navigate('/dashboard')">Dashboard</button>
    <button @click="$navigate('/login', { replace: true })">Log in (replace history)</button>
</div>
```

> For query-parameter control and other navigation options, use the `x-navigate` directive instead. The `$navigate` magic is best for programmatic navigation triggered by non-anchor elements.

---

## Component Registry

### `$components`

**Type:** `object`

Access and control named Alpine components from any other component. Named components are registered with `x-component="name"`.

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `$components.get(name)` | `object \| null` | Get the component's data object by name |
| `$components.has(name)` | `boolean` | Check if a component with this name is registered |
| `$components.all()` | `object[]` | Get all registered component data objects |
| `$components.getByTag(tag)` | `object[]` | Get all components with a given `data-tags` tag |
| `$components.state(name, key?)` | `*` | Get the full reactive state proxy (or a specific key) |
| `$components.update(name, updates)` | `void` | Apply a JSON Merge Patch to the component state |
| `$components.create(name, state, options?)` | `void` | Initialize a state namespace inside the component |
| `$components.delete(name, keys)` | `void` | Delete one or more state properties |
| `$components.watch(name, keyOrCallback, callback?)` | `function` | Watch a key (or all state) for changes — returns unwatch function |
| `$components.when(name, timeout?)` | `Promise<void>` | Promise that resolves when the component registers |
| `$components.onReady(names, callback)` | `function` | Callback when one or more components are ready — returns cleanup function |
| `$components.invoke(name, method, ...args)` | `*` | Call a method on the named component |

**Examples:**

```html
<!-- Cart icon that reads from a named cart component -->
<div x-data>
    <span x-text="$components.state('cart', 'count')">0</span>
</div>

<!-- Product card that refreshes the cart after an add-to-cart action -->
<div x-data="{ productId: 1 }">
    <button @click="$action('/cart/add', { include: ['productId'] })">
        Add to Cart
    </button>
</div>

<!-- Wait for a lazily-mounted component before acting -->
<div x-data x-init="
    $components.when('modal').then(() => {
        $components.update('modal', { open: true });
    })
">
</div>
```

**Backend targeting:** The server can patch any named component by name using `gale()->patchComponent('cart', $data)`. The component does not need to be in the same page region as the requesting component.

---

## Directives

### `x-sync`

Declares which state keys to synchronize with the server on each request.

```html
<!-- Wildcard: send all component state -->
<div x-data="{ name: '', email: '' }" x-sync>

<!-- Array syntax: send only these keys -->
<div x-data="{ name: '', email: '', uiOnly: false }" x-sync="['name', 'email']">

<!-- String syntax (convenience) -->
<div x-data="{ name: '', email: '' }" x-sync="name, email">

<!-- No x-sync: send no state automatically -->
<div x-data="{ count: 0 }">
```

Without `x-sync`, no state is serialized into the request body. This is the default for components that only need to receive server patches without sending local state.

> When `x-sync` is present on a component, dirty tracking is automatically activated, enabling delta payloads (only changed keys are sent).

---

### `x-navigate`

Enables SPA navigation on links, containers, and forms. Clicking an anchor (or submitting a form) inside an `x-navigate` element performs a full-page morph instead of a standard browser navigation.

**On an anchor element:**

```html
<a href="/about" x-navigate>About</a>
```

**On a container (all links inside become SPA links):**

```html
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="/about">About</a>
    <a href="/contact">Contact</a>
</nav>
```

**On a form (POST with PRG pattern):**

```html
<form method="POST" action="/search" x-navigate>
    <input name="q" type="text">
    <button>Search</button>
</form>
```

**Modifiers:**

| Modifier | Description |
|----------|-------------|
| `.replace` | Use `replaceState` instead of `pushState` |
| `.merge` | Merge current query parameters with new ones |
| `.only.key1.key2` | Keep only the listed query parameters |
| `.except.key` | Keep all query parameters except the listed ones |
| `.key.name` | Send a navigate key header for backend filtering (e.g. pagination within a region) |
| `.debounce.300ms` | Debounce navigation (useful on inputs) |
| `.throttle.500ms` | Throttle navigation |
| `.preserveEmpty` | Preserve empty string query parameters (default: stripped) |

```html
<!-- Replace history instead of push -->
<a href="/login" x-navigate.replace>Log in</a>

<!-- Merge current params (e.g. keep active filters when changing page) -->
<a href="?page=2" x-navigate.merge>Next page</a>

<!-- Scoped navigation key for a sidebar region -->
<nav x-navigate.key.sidebar>
    <a href="/docs/installation">Installation</a>
</nav>
```

**Opting out:** Add `x-navigate-skip` to a link or its ancestor to prevent `x-navigate` delegation from intercepting it:

```html
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="/external" x-navigate-skip>External link (full reload)</a>
</nav>
```

---

### `x-component`

Registers the Alpine component under a name in the global component registry. Enables the server to target it with `gale()->patchComponent('name', $data)` and other components to read/update its state via `$components`.

```html
<div x-data="{ total: 0, count: 0 }" x-component="cart" x-sync>
    <span x-text="count"></span> items — $<span x-text="total"></span>
</div>
```

**With tags** (for group operations):

```html
<div x-data="{ visible: true }" x-component="sidebar" data-tags="layout panels">
</div>
```

The name must be unique per page. Re-registering the same name replaces the previous registration.

---

### `x-message`

Binds a field name to the server validation message system. The element's text content is automatically updated to display the validation error from the server `messages` state key, and the element is shown/hidden based on whether an error exists.

```html
<input name="email" type="email" x-model="email">
<span x-message="email" class="text-red-500"></span>
```

The directive reads from the `messages` key in the component state (populated by the server via `gale()->messages($errors)`).

**Configuration:** Custom message bag key and CSS classes can be set via `Alpine.gale.configureMessage({ stateKey, errorClass })`.

---

### `x-files`

Binds a file input element to the component state for reactive file handling. The bound name becomes accessible via the `$file`, `$files`, `$filePreview`, and `$clearFiles` magics.

```html
<div x-data="{ documents: [] }" x-sync>
    <!-- Single file -->
    <input type="file" x-files="avatar">
    <img :src="$filePreview('avatar')" x-show="$file('avatar')">

    <!-- Multiple files -->
    <input type="file" multiple x-files="attachments">
    <template x-for="f in $files('attachments')">
        <div x-text="f.name + ' (' + $formatBytes(f.size) + ')'"></div>
    </template>

    <button @click="$action('/upload')">Upload</button>
</div>
```

When file inputs are present in the component, `$action` automatically uses `multipart/form-data` encoding.

**Events dispatched on the input element:**

| Event | Detail | When |
|-------|--------|------|
| `gale:file-change` | `{ name, files }` | Files selected/changed |
| `gale:file-error` | `{ name, error }` | Validation error (type/size) |

---

### `x-name`

Auto-binds a form input to the component state. Creates the state property if it does not exist. Acts as a two-way binding shortcut that also registers the field name for form serialization.

```html
<div x-data="{}">
    <!-- Creates state.username = '' automatically -->
    <input type="text" x-name="username">
    <input type="email" x-name="email">
    <button @click="$action('/register')">Register</button>
</div>
```

`x-name` differs from `x-model` in that it also registers the field name for state serialization and Gale's form system.

---

### `x-interval`

Runs an Alpine expression on a repeating timer. Useful for polling.

**Syntax:** `x-interval.{duration}="{expression}"`

Duration is specified as a modifier: `.2s` (2 seconds), `.500ms` (500 milliseconds).

```html
<!-- Poll every 5 seconds -->
<div x-data="{ status: 'idle' }" x-sync x-interval.5s="$action('/status')">
    <span x-text="status"></span>
</div>

<!-- Increment counter every second -->
<div x-data="{ tick: 0 }" x-interval.1s="tick++">
    <span x-text="tick"></span>
</div>
```

**Modifiers:**

| Modifier | Description |
|----------|-------------|
| `.Xs` | Duration in seconds (e.g. `.2s`, `.30s`) |
| `.Xms` | Duration in milliseconds (e.g. `.500ms`) |
| `.visible` | Only run when the tab is visible AND the element is in the viewport |

**Stop mechanisms:**

1. **Client-side condition:** `x-interval-stop="expression"` — stops permanently when expression is truthy (evaluated after each tick)
2. **Server-side stop:** Server dispatches `gale()->dispatch('gale-interval-stop')` — stops all polling elements
3. **Manual stop/restart:** Dispatch `gale-interval-stop` or `gale-interval-restart` events on the element

```html
<!-- Stop when isDone becomes true -->
<div x-data="{ progress: 0, isDone: false }" x-sync
     x-interval.2s="$action('/progress')"
     x-interval-stop="isDone">
    <progress :value="progress" max="100"></progress>
</div>
```

---

### `x-confirm`

Intercepts a click or submit event and shows a confirmation dialog before allowing the action to proceed. The event is cancelled if the user declines.

```html
<button x-confirm="Delete this item permanently?" @click="$action('/delete')">
    Delete
</button>

<form x-confirm="Submit your application?" @submit.prevent="$action('/apply')">
    <button type="submit">Apply</button>
</form>
```

The dialog text is the directive expression value. The built-in dialog is styled by default; replace it globally with `Alpine.gale.configure({ confirmTemplate: '<div>...</div>' })`.

---

### `x-lazy`

Defers loading of content until the element enters the viewport (using Intersection Observer). The URL is fetched once when visible; the response morphs into the element.

```html
<div x-lazy="/widgets/chart" class="min-h-48">
    <div class="spinner">Loading...</div>
</div>
```

The content at the URL must be a full Gale response (`gale()->view(...)`). The existing element content is replaced by the server response on first visibility.

---

### `x-validate`

Integrates HTML5 form validation with Gale actions. When `$action` is triggered from within a form that has `x-validate`, native browser constraint validation runs first. The request is blocked if any field is invalid.

```html
<form x-validate>
    <input type="email" name="email" required>
    <input type="text" name="name" required minlength="2">
    <button @click="$action('/submit')">Submit</button>
</form>
```

Custom validation expression:

```html
<form x-validate="customValidate()">
    ...
</form>
```

When `x-validate` is present, the browser's native validation UI (tooltips) is shown for invalid fields before any request is made.

---

### `x-dirty`

Applies a CSS class to an element when the component has unsaved (dirty) state. Useful for showing "unsaved changes" indicators.

```html
<div x-data="{ title: '' }" x-sync>
    <input x-model="title">
    <span x-dirty class="text-amber-500 hidden">Unsaved changes</span>
    <!-- Or on the input itself: -->
    <input x-model="body" x-dirty="ring-2 ring-amber-400">
</div>
```

Without an expression, `x-dirty` toggles the element's visibility. With an expression, it adds/removes those CSS classes.

---

### `x-listen`

Subscribes to a server-push channel (SSE). The expression is evaluated whenever the server pushes a message to the channel.

```html
<div x-data="{ notifications: [] }" x-sync x-listen="notifications">
    <template x-for="n in notifications">
        <div x-text="n.message"></div>
    </template>
</div>
```

The channel name maps to a server-side push channel endpoint. See the backend docs for `gale()->pushChannel()`.

---

### `x-loading`

Shows, hides, or modifies elements based on the loading state of the closest ancestor Gale request. Useful as an alternative to `$fetching()` when you need class or attribute toggling instead of conditional visibility.

**Modifiers:**

| Modifier | Behavior |
|----------|----------|
| _(none)_ | Show element during loading, hide when idle |
| `.remove` | Show when idle, hide during loading (inverse) |
| `.class` | Add CSS classes (from expression) during loading |
| `.attr` | Set attribute (from expression) during loading |
| `.delay.Xms` | Delay showing loading state to avoid flicker |

```html
<!-- Show spinner during any request from this component's region -->
<span x-loading>Loading...</span>

<!-- Disable button during loading -->
<button x-loading.attr="disabled" @click="$action('/save')">Save</button>

<!-- Add CSS classes during loading -->
<button x-loading.class="opacity-50 cursor-wait" @click="$action('/save')">Save</button>

<!-- Delay 150ms before showing loading state (avoids flicker for fast requests) -->
<span x-loading.delay.150ms>Working...</span>

<!-- Show only when NOT loading -->
<span x-loading.remove>Saved</span>
```

---

### `x-indicator`

Binds a boolean component state variable to the loading state of a region. Sets the variable to `true` while any Gale request originates from within the element, and `false` when idle.

```html
<div x-data="{ saving: false }" x-indicator="saving">
    <span x-show="saving">Saving...</span>
    <button @click="$action('/save')">Save</button>
</div>
```

The expression defaults to `'loading'` if omitted:

```html
<div x-data="{ loading: false }" x-indicator>
    <span x-show="loading">Loading...</span>
    <button @click="$action('/fetch')">Fetch</button>
</div>
```

> **Difference from `$fetching()`:** `x-indicator` stores loading state in a named component variable (explicitly visible in `x-data`), while `$fetching()` reads from a reactive scope tracker. Both are per-component scoped.

---

### `x-prefetch`

Pre-fetches a navigation URL on hover/focus, so the page loads instantly on click.

```html
<!-- Prefetch this link on hover -->
<a href="/dashboard" x-navigate x-prefetch>Dashboard</a>

<!-- Prefetch with custom hover delay (default: 80ms) -->
<a href="/profile" x-navigate x-prefetch.100ms>Profile</a>

<!-- Opt out when global prefetch is enabled -->
<a href="/large-page" x-navigate x-prefetch="false">Large Page</a>
```

Enable global prefetch for all `x-navigate` links:

```javascript
Alpine.gale.configure({ prefetch: true });
// Or fine-grained:
Alpine.gale.configure({ prefetch: { delay: 80, maxSize: 20, ttl: 60000 } });
```

---

## Global Configuration

Configure Alpine Gale before `Alpine.start()` (or at any time at runtime):

```javascript
Alpine.gale.configure({
    // ...options
});
```

**All configuration options:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `defaultMode` | `'http' \| 'sse'` | `'http'` | Default request mode for all `$action` calls |
| `viewTransitions` | `boolean` | `true` | Use View Transitions API during SPA navigation |
| `foucTimeout` | `number` | `3000` | Max ms to wait for external stylesheets to load before proceeding with SPA navigation |
| `navigationIndicator` | `boolean` | `true` | Show a top-of-page progress bar during SPA navigation |
| `settleDuration` | `number` | `0` | Settle delay in ms after DOM patch (for CSS transitions) — bridged to swap-settle |
| `morphTransitions` | `boolean` | `true` | Detect active CSS transitions on morph targets and defer morph until complete |
| `maxConcurrent` | `number` | `6` | Maximum concurrent HTTP requests (values ≤ 0 are clamped to 1) |
| `warnPayloadSize` | `number` | `102400` | Response byte size above which a `console.warn` fires in dev mode (0 = always warn) |
| `queue` | `'parallel' \| 'sequential' \| 'cancel-previous'` | `'parallel'` | Global default request queue mode |
| `historyCache` | `boolean \| { maxSize: number }` | `{ maxSize: 10 }` | SPA history cache — `false` to disable |
| `prefetch` | `boolean \| { delay?, maxSize?, ttl? }` | `false` | Global link prefetch — `true` for defaults, `false` to disable |
| `sseShared` | `boolean` | `false` | Share one SSE connection per URL across multiple components |
| `sseHeartbeatTimeout` | `number` | `60000` | Ms of silence before proactive SSE reconnection (0 = disabled) |
| `pauseOnHidden` | `boolean` | `true` | Pause SSE connections when the browser tab is hidden |
| `pauseOnHiddenDelay` | `number` | `1000` | Debounce delay in ms before pausing SSE on tab hide |
| `sanitizeHtml` | `boolean` | `true` | Sanitize HTML from server before patching into DOM |
| `allowScripts` | `boolean` | `false` | Allow `<script>` tags in server HTML patches (use only with fully trusted content) |
| `cspNonce` | `string \| null` | `null` | CSP nonce for dynamically inserted `<script>` tags |
| `csrfRefresh` | `'auto' \| 'meta' \| 'sanctum'` | `'auto'` | CSRF token refresh strategy on 419 |
| `confirmTemplate` | `string \| null` | `null` | Custom HTML template for confirmation dialogs (replaces built-in modal) |
| `retries` | `number` | `3` | Shorthand for `retry.maxRetries` |
| `retryBaseDelay` | `number` | `1000` | Shorthand for `retry.initialDelay` (ms) |
| `retry` | `object` | — | Retry sub-object: `{ maxRetries, initialDelay, backoffMultiplier }` |
| `retry.maxRetries` | `number` | `3` | Max retry attempts for HTTP network errors |
| `retry.initialDelay` | `number` | `1000` | Initial retry delay in ms |
| `retry.backoffMultiplier` | `number` | `2` | Exponential backoff multiplier |
| `rateLimiting` | `object` | — | Rate limiting sub-object |
| `rateLimiting.autoRetry` | `boolean` | `true` | Automatically retry after a 429 response |
| `rateLimiting.maxRetries` | `number` | `3` | Max 429 auto-retries |
| `rateLimiting.showMessage` | `boolean` | `true` | Show rate-limit message toast |
| `rateLimiting.messageText` | `string` | `'Too many requests...'` | Message text shown when rate-limited |
| `auth` | `object` | — | Auth state detection sub-object |
| `auth.loginUrl` | `string` | `'/login'` | URL to redirect to after 401/419 (when `autoRedirect: true`) |
| `auth.autoRedirect` | `boolean` | `false` | Automatically redirect to `loginUrl` on session expiry |
| `auth.showMessage` | `boolean` | `true` | Show session-expired message toast |
| `auth.messageText` | `string` | `'Your session has expired...'` | Message text shown on session expiry |
| `auth.messageTimeout` | `number` | `5000` | Duration (ms) before the message disappears |
| `offline` | `object \| false` | — | Offline degradation sub-object (`false` to disable entirely) |
| `offline.mode` | `'queue' \| 'fail' \| function` | `'queue'` | How to handle actions while offline |
| `offline.queueMaxSize` | `number` | `50` | Max queued actions before oldest is dropped |
| `offline.offlineIndicator` | `boolean` | `true` | Show built-in offline toast |
| `redirect` | `object` | — | Redirect security sub-object |
| `redirect.allowedDomains` | `string[]` | `[]` | Allowed external redirect hostnames (supports `*.wildcard`) |
| `redirect.allowExternal` | `boolean` | `false` | Allow all external redirects (disables domain whitelist) |
| `redirect.logBlocked` | `boolean` | `false` | Log blocked redirects to console and debug panel |
| `debug` | `object` | — | Debug panel sub-object |
| `debug.thresholds.response` | `number` | `500` | TTFB > this ms shows yellow warning in debug panel |
| `debug.thresholds.domMorph` | `number` | `100` | DOM morph > this ms shows orange warning |
| `debug.thresholds.total` | `number` | `1000` | Total operation > this ms shows red warning |
| `debug.logLevel` | `'off' \| 'info' \| 'verbose'` | auto | Console logging verbosity |

**Configuration changes dispatch `gale:config-changed` on `document`.** Unknown keys emit `console.warn`. Invalid values emit `console.error` and retain the previous value.

**Example:**

```javascript
Alpine.gale.configure({
    defaultMode: 'http',
    viewTransitions: true,
    rateLimiting: { autoRetry: true, maxRetries: 5 },
    auth: { loginUrl: '/auth/login', autoRedirect: true },
    offline: { mode: 'queue', queueMaxSize: 20 },
    redirect: { allowedDomains: ['*.stripe.com', 'paypal.com'] },
    debug: {
        logLevel: 'verbose',
        thresholds: { response: 300, total: 800 },
    },
});
```

---

## Events Reference

Alpine Gale dispatches `CustomEvent`s on `document` (or on specific elements) throughout its lifecycle. Listen with `document.addEventListener('gale:event-name', handler)` or use Alpine's `@gale:event-name` shorthand on elements.

### Request Lifecycle

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:started` | `document` | `{ el }` | Request begins (any Gale request) |
| `gale:finished` | `document` | `{ el }` | Request completes successfully |
| `gale:retrying` | `document` | `{ message }` | Request is being retried |
| `gale:retries-failed` | `document` | — | All retry attempts exhausted |

### Error Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:error` | `document` | `{ type, status, message, context, recoverable }` | Any error (all types) |
| `gale:network-error` | `document` | `{ type, status, message, context, recoverable }` | Network/connectivity failure |
| `gale:server-error` | `document` | `{ type, status, message, context, recoverable }` | HTTP 4xx/5xx response |
| `gale:parse-error` | `document` | `{ type, status, message, context, recoverable }` | Malformed JSON or SSE data |
| `gale:timeout` | `document` | `{ type, status, message, context, recoverable }` | Request timed out |
| `gale:security-error` | `document` | `{ reason }` | Security error (checksum failure, etc.) |
| `gale:patch-error` | `document` | `{ selector, mode, html }` | DOM patch failed (element not found) |
| `gale:patch-warning` | `document` | `{ selector, mode, html }` | DOM patch warning (multiple matches) |

### Navigation Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:navigate` | `document` | `{ url, method, replace, _isPostForm }` | Navigation starts (before fetch) |
| `gale:patch-complete` | `document` | `{ el, url }` | Full-page DOM patch after SPA navigation |

### DOM Morph Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:before-morph` | `document` | `{ el, newHtml }` | Before any morphing begins for an element |
| `gale:after-morph` | `document` | `{ el }` | After all morphing completes for an element |
| `gale:before-remove` | element | `{ el, component }` | Element is about to be removed during morph |
| `gale:after-add` | element | `{ el, component }` | Element was newly added to DOM during morph |
| `gale:swap-start` | `document` | `{ el }` | DOM swap begins (swap-settle lifecycle) |
| `gale:settle-start` | `document` | `{ el }` | Settle phase begins |
| `gale:settle-complete` | `document` | `{ el }` | Settle phase complete |

### CSRF Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:csrf-rotated` | `document` | `{ token }` | CSRF token was refreshed |
| `gale:csrf-missing` | `document` | — | No CSRF token source was found |
| `gale:csrf-exhausted` | `document` | — | CSRF refresh failed after all retries |

### Rate Limiting Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:rate-limited` | `document` | `{ retryAfter, url, retryCount }` | 429 response received, retry scheduled |
| `gale:rate-limit-exhausted` | `document` | `{ url, maxRetries }` | Max 429 retries exhausted |

### Authentication Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:auth-expired` | `document` | `{ status, loginUrl, url, autoRedirect }` | 401 or 419 session expiry detected |

### Connectivity Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:offline` | `document` | `{ previouslyOnline }` | Browser went offline |
| `gale:online` | `document` | `{ queueSize }` | Browser came back online |

### Component Registry Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:component-registered` | `document` | `{ name, el, tags }` | Component registered via `x-component` |
| `gale:component-unregistered` | `document` | `{ name, el }` | Component removed from registry |
| `gale:state-created` | element | `{ name, state }` | State namespace created via `x-name` |

### Redirect Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:redirect-blocked` | `document` | `{ url, reason }` | Redirect blocked by security policy |

### Confirmation Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:confirm` | `document` | `{ message, el }` | User confirmed a dialog |
| `gale:cancel` | `document` | `{ message, el }` | User cancelled a dialog |

### Optimistic UI Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:rollback` | element | `{ rolledBack, snapshot }` | Optimistic state rolled back on error |

### Validation Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:validation-failed` | `document` | `{ fields }` | HTML5 form validation blocked a request |

### File Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:file-change` | input element | `{ name, files }` | Files selected or changed |
| `gale:file-error` | input element | `{ name, error }` | File validation error (type/size) |

### Configuration Events

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:config-changed` | `document` | `{ changes }` | `Alpine.gale.configure()` applied a change |

### Debug Events (dev mode only)

| Event | Dispatched on | Detail | When |
|-------|---------------|--------|------|
| `gale:error-detail` | `document` | Full error context with stack | Non-recoverable error (dev mode overlay) |

**Listening example:**

```javascript
// Listen for any Gale error
document.addEventListener('gale:error', (event) => {
    const { type, status, message, recoverable } = event.detail;
    console.error(`Gale ${type} error [${status}]: ${message}`);
});

// Listen for auth expiry and show a login modal
document.addEventListener('gale:auth-expired', () => {
    Alpine.store('ui').showLoginModal = true;
});

// Listen for offline event in an Alpine component
```

```html
<div x-data x-init="
    document.addEventListener('gale:offline', () => { offline = true });
    document.addEventListener('gale:online', () => { offline = false });
" :data-offline="offline">
```

---

## Alpine.gale API

The `Alpine.gale` namespace exposes utility methods beyond configuration.

### Component Registry

```javascript
Alpine.gale.registerComponent(name, el)           // Manually register an element
Alpine.gale.unregisterComponent(name)             // Unregister by name
Alpine.gale.getComponent(name)                    // Get component data by name
Alpine.gale.getComponentsByTag(tag)               // Get all components with a tag
Alpine.gale.updateComponentState(name, updates)   // Apply JSON Merge Patch to state
Alpine.gale.invokeComponentMethod(name, method)   // Call a method on a component
Alpine.gale.hasComponent(name)                    // Boolean existence check
Alpine.gale.getAllComponents()                    // Array of all component data objects
Alpine.gale.getComponentState(name, key?)         // Get reactive state (or a key)
Alpine.gale.createComponentState(name, state)     // Initialize a state namespace
Alpine.gale.deleteComponentState(name, keys)      // Delete state properties
Alpine.gale.watchComponentState(name, cb)         // Watch all state changes
Alpine.gale.onComponentRegistered(cb)             // Lifecycle: on registration
Alpine.gale.onComponentUnregistered(cb)           // Lifecycle: on unregistration
Alpine.gale.onComponentStateChanged(cb)           // Lifecycle: on state change
Alpine.gale.whenComponentReady(name, timeout?)    // Promise resolving when ready
Alpine.gale.onComponentReady(names, cb)           // Callback when ready
```

### Morph Lifecycle Hooks

```javascript
// Register hooks that fire around each DOM morph operation
const unregister = Alpine.gale.onMorph({
    beforeUpdate(el, toEl)  { /* return false to cancel morph */ },
    afterUpdate(el, toEl)   { /* cleanup, reinit third-party */ },
    beforeRemove(el)        { /* return false to prevent removal */ },
    afterRemove(el)         { /* post-removal cleanup */ },
    afterAdd(el)            { /* initialize newly added element */ },
});

// Unregister when done
unregister();
```

> Hook callbacks run outside Alpine reactive context. Never use `$nextTick`, `$el`, or other Alpine magics inside them. Capture `const self = $data` in `x-init` before registering if you need to update component state from a hook.

### Error Handling

```javascript
// Register a global error handler
const unregister = Alpine.gale.onError((error) => {
    // error: { type, status, message, url, recoverable, retry }
    // Return false to suppress the default global gale:error dispatch
    myMonitoring.captureError(error);
});
```

### Plugin System

```javascript
Alpine.gale.registerPlugin('my-plugin', {
    init(Alpine, config)         { /* called once on register */ },
    beforeRequest(context)       { /* { url, method, data, options } */ },
    afterResponse(context)       { /* { url, method, status, data } */ },
    beforeMorph(el, html)        { /* called before DOM morph */ },
    afterMorph(el)               { /* called after DOM morph */ },
    destroy()                   { /* cleanup on unregister */ },
});

Alpine.gale.unregisterPlugin('my-plugin');
Alpine.gale.getPlugin('my-plugin');
Alpine.gale.getPluginNames();  // string[]
```

### Custom Directives

```javascript
// Register a Gale-aware Alpine directive
Alpine.gale.directive('tooltip', {
    init(el, expression, { mode, component, config }) {
        // el: the element, expression: directive value
        // Called on element mount
    },
    morph(el, phase, galeContext) {
        // phase: 'before' | 'after'
        // Called before/after each morph of this element
    },
    destroy(el, galeContext) {
        // Called on element removal
    },
});
// Usage: <div x-tooltip="'Click me'">
```

> The `x-` prefix is added automatically. The directive name must not include it.

### Third-Party Library Cleanup

```javascript
// Register cleanup for elements matching a CSS selector
Alpine.gale.registerCleanup('[data-chart]', {
    beforeMorph(el)  { chart.destroy(); },
    afterMorph(el)   { chart.init(el); },
    destroy(el)      { chart.destroy(); },
});

// Remove a cleanup registration
Alpine.gale.removeCleanup('[data-chart]');

// GSAP convenience registration
Alpine.gale.registerGsapCleanup('[data-gsap]');
Alpine.gale.setupAnimationCompat();

// Rich text editor convenience registration
Alpine.gale.registerRteCleanup('[data-editor]');
Alpine.gale.setupRteCompat();

// SortableJS convenience registration
Alpine.gale.registerSortableCleanup('[data-sortable]');
Alpine.gale.setupSortableCompat();
```

### Cache Management

```javascript
// History cache (SPA back/forward)
Alpine.gale.clearHistoryCache();          // Clear all cached pages
Alpine.gale.bustHistoryCache('/url');     // Bust cache for a specific URL
Alpine.gale.getHistoryCacheSize();        // Number of cached pages
Alpine.gale.getHistoryCacheKeys();        // Array of cached URLs

// Prefetch cache
Alpine.gale.clearPrefetchCache();         // Clear all prefetched pages
Alpine.gale.getPrefetchCacheSize();       // Number of prefetched pages
Alpine.gale.getPrefetchCacheKeys();       // Array of prefetched URLs

// HTTP response cache
Alpine.gale.clearCache();                 // Clear all cached responses
Alpine.gale.clearCache('/url');           // Clear cache for a specific URL
Alpine.gale.getResponseCacheSize();       // Number of cached responses
Alpine.gale.getResponseCacheKeys();       // Array of cached URLs
```

### Dirty State API

```javascript
Alpine.gale.isDirty(el, key?)        // boolean — is el (or key) dirty?
Alpine.gale.getDirtyKeys(el)         // Set<string> — dirty key names
Alpine.gale.resetDirty(el)           // Manually reset dirty tracking
Alpine.gale.initDirtyTracking(el)    // Bootstrap dirty tracking for an element
```

### Offline API

```javascript
Alpine.gale.isOnline()              // boolean — current connectivity state
Alpine.gale.getOfflineQueueSize()   // number — queued actions count
Alpine.gale.clearOfflineQueue()     // Discard queued actions without replay
```

### Authentication API

```javascript
Alpine.gale.isAuthExpired()   // boolean — has session expiry been detected?
Alpine.gale.resetAuth()       // Reset auth state after re-authentication
```

### Rate Limiting API

```javascript
Alpine.gale.cancelAllRateLimitRetries()           // Cancel all pending 429 retry timers
Alpine.gale.parseRetryAfter(headerValue)          // Parse a Retry-After header (returns seconds)
Alpine.gale.getRateLimitStatus(url, method)       // Get retry status for a request
```

### SSE Connection API

```javascript
Alpine.gale.getActiveConnectionCount()    // Total active SSE connections
Alpine.gale.getSharedConnectionCount()   // Number of shared connections
Alpine.gale.getConnectionStates()        // [{ url, state }] for all connections
```

### Debug Panel API (dev mode)

```javascript
Alpine.gale.debug.toggle()              // Toggle panel open/closed
Alpine.gale.debug.open()               // Open panel
Alpine.gale.debug.close()              // Close panel
Alpine.gale.debug.pushRequest(entry)   // Add to Requests tab
Alpine.gale.debug.pushState(entry)     // Add to State tab
Alpine.gale.debug.pushError(entry)     // Add to Errors tab
Alpine.gale.debug.registerTab(id, label)  // Register a custom tab
Alpine.gale.debug.pushTab(id, entry)   // Push entry to custom tab
Alpine.gale.debug.clear()              // Clear all entries
Alpine.gale.debug.isEnabled()          // Whether debug mode is active
```

### Teardown

```javascript
// Remove all MutationObservers and global event listeners registered by Gale.
// Useful for SSR, plugin re-initialization, and test isolation.
Alpine.gale.teardown();
```

---

## Next Steps

- Read [Navigation & SPA](navigation.md) for full SPA navigation details, history management, and prefetching
- Read [Forms, Validation & Uploads](forms-validation-uploads.md) for form patterns, validation, and file uploads
- Read [Components, Events & Polling](components-events-polling.md) for cross-component communication and polling
- Read [Backend API Reference](backend-api.md) for the server-side PHP API
- Read [Debug & Troubleshooting](debug-troubleshooting.md) for debug panel and common issues
