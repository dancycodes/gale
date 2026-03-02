# Components, Events & Polling

> **See also:** [Frontend API Reference](frontend-api.md) | [Backend API Reference](backend-api.md)

Use named components with `x-component` and `$components`, dispatch and listen to `gale:*`
events, implement polling with debounce/throttle options, use Alpine Store patching, and
leverage the Gale plugin/extension system.

> This guide is a placeholder. Full content is added by F-102 (Components, Events & Polling Guide).

---

## Named Components

Register a named component with `x-component`. The server can patch its state from any
controller using `gale()->patchComponent()`:

```html
<!-- Anywhere in your page -->
<div x-data="{ total: 0, count: 0 }" x-component="cart">
    Cart: <span x-text="count"></span> items — $<span x-text="total"></span>
</div>
```

```php
// In any controller
return gale()->patchComponent('cart', [
    'total' => $cart->total,
    'count' => $cart->itemCount(),
]);
```

---

## Accessing Named Components

Access registered named components from within any Alpine component:

```html
<div x-data>
    <!-- Read state of a named component -->
    <span x-text="$components.get('cart')?.state?.count ?? 0"></span>

    <!-- Patch state of a named component from the client -->
    <button @click="$components.patch('cart', { count: 0 })">Clear</button>
</div>
```

---

## Polling

Implement polling by combining `$action` with an Alpine interval:

```html
<div x-data="{ notifications: [] }" x-init="setInterval(() => $get('/notifications'), 5000)">
    <template x-for="n in notifications">
        <div x-text="n.message"></div>
    </template>
</div>
```

---

## Debounce and Throttle

Use `debounce` and `throttle` options to control request frequency:

```html
<!-- Debounce: wait 300ms after last keypress before requesting -->
<input x-model="query" @input="$action('/search', { debounce: 300 })">

<!-- Throttle: request at most once per second -->
<button @click="$action('/refresh', { throttle: 1000 })">Refresh</button>

<!-- Leading-edge debounce: fire immediately, then suppress for 500ms -->
<button @click="$action('/submit', { debounce: 500, leading: true })">Submit</button>
```

---

## Alpine Store Patching

Patch Alpine global stores from the server using `gale-patch-store` events. On the server:

```php
return gale()->patchStore('theme', ['mode' => 'dark', 'color' => 'indigo']);
```

In your Alpine setup:

```javascript
Alpine.store('theme', { mode: 'light', color: 'blue' });
```

In your Blade templates:

```html
<div :class="$store.theme.mode === 'dark' ? 'dark' : ''">
    ...
</div>
```

---

## Dispatching Custom Events from the Server

Use `gale()->dispatch()` to fire a custom Alpine event in the browser:

```php
return gale()->dispatch('toast-show', ['message' => 'Saved!', 'type' => 'success']);
```

Listen for the event in Alpine:

```html
<div x-data x-on:toast-show.window="showToast($event.detail)">
    ...
</div>
```

---

## Listening to Gale Events

Gale dispatches lifecycle events on `document`. Listen to them for analytics, logging, or
UI coordination:

```javascript
document.addEventListener('gale:request:start', (e) => {
    console.log('Request started:', e.detail.url);
});

document.addEventListener('gale:error', (e) => {
    console.error('Gale error:', e.detail.message);
});

document.addEventListener('gale:after-morph', (e) => {
    // Re-initialize third-party libraries after a DOM morph
    const el = e.detail.el;
    initMyPlugin(el);
});
```

---

## Morph Lifecycle Hooks

Register callbacks that run before or after DOM morphing:

```javascript
Alpine.gale.onMorph({
    beforeUpdate(el, toEl) {
        // Save third-party library state before morph
        el._myLibState = myLib.getState(el);
    },
    afterUpdate(el) {
        // Restore third-party library state after morph
        if (el._myLibState) {
            myLib.setState(el, el._myLibState);
        }
    },
});
```

---

## Gale Plugin/Extension System

Register custom Gale plugins to extend the framework:

```javascript
Alpine.gale.use({
    name: 'my-plugin',
    install(gale) {
        // Add custom event handlers, magics, or request interceptors
    },
});
```

---

## Real-Time SSE Channels

Subscribe to a server push channel using SSE mode:

```html
<div x-data="{ messages: [] }" x-init="$get('/notifications/stream', { sse: true })">
    <template x-for="msg in messages">
        <div x-text="msg.text"></div>
    </template>
</div>
```

Server-side, use `gale()->stream()` to push events as they occur:

```php
public function stream(): Response
{
    return gale()->stream(function () {
        foreach (getNotifications() as $notification) {
            gale()->patchState(['messages' => [...$current, $notification]]);
        }
    });
}
```

---

## Next Steps

- Read [Debug & Troubleshooting](debug-troubleshooting.md) for debugging event issues
- Read [Frontend API Reference](frontend-api.md) for the complete events reference
