# Frontend API Reference

> **See also:** [Core Concepts](core-concepts.md) | [Backend API Reference](backend-api.md)

Complete reference for Alpine Gale magics, directives, and configuration.

> This guide is a placeholder. Full content is added by F-099 (Frontend API Reference).

---

## Setup

Add `@gale` to your layout `<head>`. This provides Alpine.js plus the Alpine Gale plugin:

```html
<head>
    @gale
</head>
```

---

## HTTP Magics

All magics are available inside `x-data` components.

### `$get(url, options = {})`

Perform a GET request and apply the server response to the current component.

```html
<button @click="$get('/items')">Refresh</button>
```

### `$post(url, options = {})`

Perform a POST request with the current component state as the request body.

```html
<button @click="$post('/increment')">+1</button>
```

### `$postx(url, options = {})`

Perform a POST request with file upload support (`multipart/form-data`). Use with `x-files`
for reactive file input binding.

```html
<button @click="$postx('/upload')">Upload</button>
```

### `$action(url, options = {})`

General-purpose action magic. Detects the HTTP method from the server response and applies
debounce/throttle options.

```html
<input @input="$action('/search', { debounce: 300 })">
```

### Options

All HTTP magics accept an options object:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `sse` | boolean | `false` | Use SSE mode for this request |
| `include` | string[] | all | State keys to include in the request body |
| `exclude` | string[] | none | State keys to exclude from the request body |
| `debounce` | number | — | Debounce in milliseconds (trailing edge) |
| `debounce` + `leading: true` | — | — | Leading-edge debounce |
| `throttle` | number | — | Throttle in milliseconds |
| `confirm` | string | — | Show a confirm dialog before the request |

---

## Navigation Magics

### `$navigate(url, options = {})`

Programmatically navigate to a URL using SPA navigation.

```html
<button @click="$navigate('/dashboard')">Go to Dashboard</button>
```

---

## State Magics

### `$gale`

Reactive object with Gale's global state:

```html
<span x-text="$gale.online ? 'Online' : 'Offline'"></span>
```

Available properties: `online`, `fetching`, `errors`.

### `$fetching`

`true` when a Gale request is in flight for the current component.

```html
<button :disabled="$fetching" @click="$post('/save')">Save</button>
```

---

## Directives

### `x-navigate`

Add to a container or specific link to enable SPA navigation for anchor clicks.

```html
<!-- Enable SPA navigation for all links inside a container -->
<nav x-navigate>
    <a href="/home">Home</a>
    <a href="/about">About</a>
</nav>

<!-- Enable SPA navigation for a specific link -->
<a href="/dashboard" x-navigate>Dashboard</a>

<!-- Enable SPA navigation for a form (POST with PRG) -->
<form method="POST" action="/search" x-navigate>
    <input name="q" type="text">
    <button>Search</button>
</form>
```

### `x-component`

Register a named component that can be patched by the server via `gale()->patchComponent()`.

```html
<div x-data="{ total: 0, count: 0 }" x-component="cart">
    Cart: <span x-text="count"></span> items, $<span x-text="total"></span>
</div>
```

### `x-message`

Bind a field to the server validation message system. Automatically shows/hides error messages.

```html
<input name="email" type="email">
<span x-message="email"></span>
```

### `x-files`

Bind a file input to the Alpine component for reactive file uploads.

```html
<input type="file" x-files="profilePhoto">
<img :src="profilePhoto?.previewUrl">
```

---

## Component Registry

### `$components`

Access registered named components.

```javascript
// Get state of a named component
$components.get('cart').state

// Patch state of a named component
$components.patch('cart', { total: 99.99 })
```

---

## Configuration

Configure Alpine Gale globally via `Alpine.gale.configure()`:

```javascript
Alpine.gale.configure({
    defaultMode: 'http',        // 'http' or 'sse'
    debug: false,               // Enable debug panel
    redirect: {
        allowExternal: false,   // Allow cross-origin redirects
        allowedDomains: [],     // Whitelist of allowed external domains
    },
});
```

> **Note:** Place this call before `Alpine.start()`.

---

## Global Events

Alpine Gale dispatches these events on `document`:

| Event | When | Detail |
|-------|------|--------|
| `gale:request:start` | Request begins | `{ url, method, el }` |
| `gale:request:end` | Request completes | `{ url, method, el, status }` |
| `gale:error` | Request fails | `{ type, status, message }` |
| `gale:navigate:start` | Navigation begins | `{ url, el }` |
| `gale:navigate:end` | Navigation completes | `{ url }` |
| `gale:before-morph` | Before DOM morph | `{ el }` |
| `gale:after-morph` | After DOM morph | `{ el }` |

---

## Next Steps

- Read [Navigation & SPA](navigation.md) for SPA navigation details
- Read [Forms, Validation & Uploads](forms-validation-uploads.md) for form patterns
- Read [Components, Events & Polling](components-events-polling.md) for advanced patterns
