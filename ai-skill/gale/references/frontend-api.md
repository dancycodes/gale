# Frontend API Reference

Complete JavaScript API for the Alpine Gale plugin. All Alpine magics, directives, configuration options, and events.

> All magics and directives require `x-data` on the element or an ancestor.

---

## Table of Contents

- [Setup](#setup)
- [HTTP Magics](#http-magics)
- [State Magics](#state-magics)
- [File Upload Magics](#file-upload-magics)
- [Navigation](#navigation)
- [Component Registry](#component-registry)
- [Directives](#directives)
- [Configuration](#configuration)
- [Events Reference](#events-reference)

---

## Setup

```html
<head>
    @gale  <!-- Outputs Alpine.js, Gale plugin, CSRF meta, debug assets -->
</head>
```

- `@gale` MUST be in `<head>`, NOT `<body>`
- Do NOT load Alpine.js separately — `@gale` bundles Alpine + Morph + Intersect

---

## HTTP Magics

### $action(url, options?)

The primary way to call the server. Sends a POST request with the component's state.

```html
<button @click="$action('/increment')">+1</button>
<button @click="$action('/save', { sse: true })">Save (SSE)</button>
```

**Options object:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `sse` | `boolean` | `false` | Force SSE mode for this request |
| `http` | `boolean` | `true` | Force HTTP mode |
| `include` | `string[]` | `[]` | State keys to include (adds to x-sync) |
| `exclude` | `string[]` | `[]` | State keys to exclude from x-sync |
| `debounce` | `number` | — | Debounce delay in ms |
| `throttle` | `number` | — | Throttle interval in ms |
| `confirm` | `string` | — | Show confirm dialog before executing |
| `optimistic` | `object` | — | Apply state immediately, revert on error |
| `cache` | `number` | — | Cache response TTL in ms |
| `onProgress` | `function` | — | Upload progress callback `(percent) => {}` |

### Method Shorthands

```html
$get(url, options?)     <!-- GET request -->
$post(url, options?)    <!-- POST request -->
$postx(url, options?)   <!-- POST with CSRF token -->
$patch(url, options?)   <!-- PATCH request -->
$patchx(url, options?)  <!-- PATCH with CSRF token -->
$put(url, options?)     <!-- PUT request -->
$putx(url, options?)    <!-- PUT with CSRF token -->
$delete(url, options?)  <!-- DELETE request -->
$deletex(url, options?) <!-- DELETE with CSRF token -->
```

`$action` is equivalent to `$postx` — POST with CSRF protection.

### Advanced Options

```html
<!-- Debounce: wait 300ms after last trigger -->
<input @input="$action('/search', { debounce: 300, include: ['query'] })">

<!-- Throttle: at most once per 500ms -->
<button @click="$action('/refresh', { throttle: 500 })">Refresh</button>

<!-- Confirm dialog -->
<button @click="$action('/delete', { confirm: 'Are you sure?' })">Delete</button>

<!-- Optimistic update (revert on error) -->
<button @click="$action('/like', { optimistic: { liked: true, count: count + 1 } })">Like</button>

<!-- Cache response for 30 seconds -->
<button @click="$action('/stats', { cache: 30000 })">Load Stats</button>
```

---

## State Magics

### $gale

Reactive proxy for the Gale plugin's internal state:

```html
<div x-show="$gale.loading">Loading...</div>
<div x-show="$gale.error" x-text="$gale.errorMessage"></div>
```

| Property | Type | Description |
|----------|------|-------------|
| `$gale.loading` | `boolean` | Any Gale request in flight |
| `$gale.error` | `boolean` | An error occurred |
| `$gale.errorMessage` | `string` | The error message |
| `$gale._flash` | `object` | Flash data from `gale()->flash()` |

### $fetching()

Per-element loading state — returns `true` when the element that triggered the action is waiting for a response. **MUST use parentheses — it's a function call.**

```html
<button @click="$action('/save')">
    <span x-show="!$fetching()">Save</span>
    <span x-show="$fetching()">Saving...</span>
</button>
```

### $dirty

Returns `true` if the form/element has been modified since initial load.

```html
<div x-data x-dirty>
    <input x-name="email">
    <button :disabled="!$dirty">Save Changes</button>
</div>
```

---

## File Upload Magics

### $files(name) / $file(name)

Access selected files for a named file input.

```html
<input type="file" x-files="avatar" accept="image/*">
<span x-text="$file('avatar')?.name"></span>

<input type="file" x-files="photos" multiple>
<template x-for="(f, i) in $files('photos')" :key="i">
    <span x-text="f.name"></span>
</template>
```

### $filePreview(name, index?)

Generate a preview URL for images.

```html
<img :src="$filePreview('avatar')" class="w-20 h-20 object-cover">
<img :src="$filePreview('photos', 0)" class="w-20 h-20">
```

### $clearFiles(name)

Clear selected files.

```html
<button @click="$clearFiles('avatar')">Remove</button>
```

### $uploading / $uploadProgress / $uploadError

Upload state magics:

```html
<div x-show="$uploading">Uploading... <span x-text="$uploadProgress"></span>%</div>
<div x-show="$uploadError" x-text="$uploadError" class="text-red-600"></div>
```

---

## Navigation

### $navigate(url, options?)

Programmatic SPA navigation.

```html
<button @click="$navigate('/dashboard')">Go</button>
<button @click="$navigate('/login', { replace: true })">Login</button>
```

| Option | Type | Description |
|--------|------|-------------|
| `replace` | `boolean` | Replace current history entry |
| `merge` | `boolean` | Merge current query params |
| `only` | `string[]` | Keep only these query params |
| `except` | `string[]` | Remove these query params |
| `key` | `string` | Navigation key (`Gale-Navigate-Key` header) |
| `transition` | `boolean` | Enable/disable View Transitions |

---

## Component Registry

### $components

Access named components registered with `x-component`.

```html
<span x-text="$components('cart')?.total ?? 0"></span>
<button @click="$components('cart').refresh()">Refresh Cart</button>
```

### $invoke(componentName, methodName, ...args)

Invoke a method on a named component.

```html
<button @click="$invoke('cart', 'clear')">Clear Cart</button>
```

### $listen(event, callback)

Listen for custom events (auto-cleaned up on component destroy).

```html
<div x-data x-init="$listen('cart-updated', (detail) => count = detail.count)">
```

---

## Directives

### x-sync

Controls which state keys are sent with each request.

```html
<!-- Send ALL state keys -->
<div x-data="{ count: 0, name: '' }" x-sync>

<!-- Send specific keys only -->
<div x-data="{ count: 0, name: '', draft: '' }" x-sync="['count', 'name']">
```

### x-navigate

SPA navigation on links and containers.

```html
<a href="/page" x-navigate>SPA Link</a>
<nav x-navigate><!-- All child links become SPA --></nav>
```

**Modifiers:** `.replace`, `.merge`, `.only.key`, `.except.key`, `.key.name`, `.debounce.300ms`, `.throttle.500ms`

### x-component

Register a named component in the Gale component registry.

```html
<div x-data="{ count: 0 }" x-component="counter">...</div>
```

### x-message

Display validation error messages from the `messages` state object.

```html
<p x-message="email" class="text-red-600 text-sm"></p>
```

Auto-shows/hides text from `messages.email`. Requires `messages: {}` in `x-data`.

### x-name

Auto-creates state, binds two-way, sets name attribute.

```html
<input x-name="email" type="email">
<input x-name.lazy.trim="username" type="text">
<input x-name.number="age" type="number">
<input x-name="user.address.city" type="text"> <!-- dot-notation nesting -->
```

**Modifiers:** `.lazy`, `.number`, `.trim`, `.array`, `.debounce.Nms`

### x-files

Bind file inputs to the Gale file upload system.

```html
<input type="file" x-files="photos" multiple accept="image/*">
```

### x-interval

Polling at a fixed interval.

```html
<div x-interval.5s="$action('/refresh')">...</div>
<div x-interval.10s.visible="$action('/stats')">...</div> <!-- Only when tab visible -->
```

**Formats:** `.5s`, `.500ms`, `.1m`

### x-confirm

Show confirm dialog before executing the bound expression.

```html
<button x-confirm="Delete this item?" @click="$action('/delete')">Delete</button>
```

### x-lazy

Lazy-load content when the element enters the viewport.

```html
<div x-lazy="/api/comments">Loading comments...</div>
```

### x-dirty

Track dirty (modified) state for forms/inputs.

```html
<form x-data x-dirty>
    <input x-name="email">
    <button :disabled="!$dirty">Save</button>
</form>
```

### x-validate

HTML5 form validation integration.

```html
<form x-validate @submit.prevent="$action('/save')">
    <input type="email" required x-name="email">
    <button type="submit">Save</button>
</form>
```

### x-navigate-skip

Opt out of container x-navigate delegation.

```html
<nav x-navigate>
    <a href="/page">SPA</a>
    <a href="https://external.com" x-navigate-skip>Full Reload</a>
</nav>
```

### x-morph-ignore

Skip element during DOM morphing.

```html
<div x-morph-ignore>
    <canvas id="chart"></canvas> <!-- Never touched by morph -->
</div>
```

### x-loading

Loading state directive.

```html
<span x-loading>Processing...</span>
<span x-loading.delay.200ms>Still processing...</span>
```

### x-indicator

Show/hide loading indicator elements.

```html
<div x-indicator>
    <svg class="animate-spin">...</svg>
</div>
```

---

## Configuration

```javascript
Alpine.gale.configure({
    defaultMode: 'http',        // 'http' or 'sse'
    logLevel: 'warn',           // 'debug', 'info', 'warn', 'error', 'none'

    history: {
        cacheSize: 10,           // Pages cached for instant back-nav
    },
    // history: false,           // Disable history caching

    prefetch: true,              // Enable hover-prefetch for all x-navigate links
    // prefetch: { delay: 100, maxSize: 5, ttl: 30000 },

    viewTransitions: true,       // Enable View Transitions API for SPA nav

    sanitize: { /* XSS allowlist */ },
});
```

---

## Events Reference

### Gale Lifecycle Events (on `document`)

| Event | Detail | Description |
|-------|--------|-------------|
| `gale:request` | `{ url, method }` | Request starts |
| `gale:response` | `{ url, events }` | Response received |
| `gale:error` | `{ type, status, message, context, recoverable }` | Error occurred |
| `gale:navigate` | `{ url, method, replace }` | SPA navigation starts |
| `gale:navigated` | `{ url }` | SPA navigation completed |
| `gale:navigate-error` | `{ url, status, message }` | Navigation failed |
| `gale:before-morph` | `{ el, newHtml }` | Before DOM morph |
| `gale:after-morph` | `{ el }` | After DOM morph |
| `gale:rate-limited` | `{ retryAfter }` | 429 response received |
| `gale:unauthenticated` | `{ url }` | 401 response received |
| `gale:csrf-expired` | — | 419 CSRF mismatch |
| `gale:online` | — | Network restored |
| `gale:offline` | — | Network lost |

### Morph Lifecycle Hooks (programmatic)

```javascript
const unregister = Alpine.gale.onMorph({
    beforeUpdate(ctx) {
        // ctx.el, ctx.newEl, ctx.component
        // return false to cancel morph for this element
    },
    afterUpdate(ctx) { },
    beforeRemove(ctx) { /* return false to prevent removal */ },
    afterRemove(ctx) { },
    afterAdd(ctx) { },
});

unregister(); // Remove hooks when done
```

### Third-Party Library Cleanup

```javascript
Alpine.gale.registerCleanup('canvas[data-chart]', {
    beforeMorph(el) { el._chart?.destroy(); },
    afterMorph(el) { el._chart = new Chart(el, config); },
    destroy(el) { el._chart?.destroy(); },
});
```

### Plugin/Extension System

```javascript
Alpine.gale.extend({
    name: 'my-plugin',
    init(gale) { /* setup */ },
    beforeRequest(ctx) { /* modify request */ },
    afterResponse(ctx) { /* process response */ },
    destroy() { /* cleanup */ },
});
```
