# JavaScript Compatibility Reference

Complete reference for third-party library integration, the cleanup registry, plugin system, and custom directives.

## Third-Party Cleanup Registry

The cleanup registry provides lifecycle hooks for third-party JavaScript libraries during Gale's DOM morph operations. Without these hooks, libraries that modify the DOM (GSAP animations, rich text editors, drag-and-drop) can break when Gale morphs elements they control.

### API

```js
Alpine.gale.registerCleanup(selector, {
    beforeMorph(el) { /* Save state, pause animations */ },
    afterMorph(el)  { /* Re-attach, resume (element survived) */ },
    destroy(el)     { /* Free resources (element removed) */ },
});

Alpine.gale.removeCleanup(selector);
```

### Lifecycle

| Hook | When | Purpose |
|------|------|---------|
| `beforeMorph(el)` | Before morph touches a matched element | Save state, kill animations, detach listeners |
| `afterMorph(el)` | After morph completes (element survived) | Re-initialize, resume animations |
| `destroy(el)` | Element permanently removed from DOM | Free resources, prevent memory leaks |

### Rules

- Multiple handlers for different selectors invoke independently
- Empty registry has zero performance overhead
- Handlers called in registration order
- Handler errors are caught and logged (do not prevent morph or other handlers)
- Same selector registered twice replaces the first registration

### Custom Library Example

```js
// Chart.js cleanup
Alpine.gale.registerCleanup('canvas[data-chart]', {
    beforeMorph(el) {
        // Destroy chart instance before morph
        if (el._chartInstance) {
            el._chartInstance.destroy();
        }
    },
    afterMorph(el) {
        // Re-create chart after morph (element survived)
        if (el.dataset.chart) {
            el._chartInstance = new Chart(el, JSON.parse(el.dataset.chart));
        }
    },
    destroy(el) {
        // Cleanup on permanent removal
        if (el._chartInstance) {
            el._chartInstance.destroy();
            el._chartInstance = null;
        }
    },
});
```

## GSAP Integration

Built-in compatibility for GSAP (GreenSock Animation Platform).

### Setup

```js
// Auto-register all built-in animation handlers
document.addEventListener('alpine:initialized', () => {
    Alpine.gale.setupAnimationCompat();
});

// Or register for custom selector
Alpine.gale.registerGsapCleanup('[data-gsap]');
```

### How It Works

Elements matched by the selector (default: `[data-gsap]`) are automatically tracked:

1. **beforeMorph**: Active tweens are killed via `gsap.getTweensOf(el)` to prevent visual corruption
2. **afterMorph**: Dispatches `gale:animation-morph-complete` event so `x-init` can restart animations
3. **destroy**: Kills tweens and frees memory

### Usage Pattern

```html
<div data-gsap x-data x-init="gsap.to($el, { x: 100, duration: 1 })"
     @gale:animation-morph-complete="gsap.to($el, { x: 100, duration: 1 })">
    Animated element
</div>
```

### CSS Transition Deferral

When a morph targets an element with an active CSS transition, the morph is deferred until `transitionend` fires (500ms safety timeout).

```js
// Wait for a specific element's transition to complete
await Alpine.gale.waitForElementTransition(el);  // 500ms timeout
```

### x-morph-ignore

Elements with `x-morph-ignore` are completely excluded from morph operations.

```html
<div x-morph-ignore>
    <!-- GSAP timeline running here is never interrupted -->
</div>
```

## Rich Text Editor Integration

Built-in compatibility for TipTap, Trix, CKEditor, and Quill.

### Setup

```js
document.addEventListener('alpine:initialized', () => {
    Alpine.gale.setupRteCompat();
});

// Or register for custom selector
Alpine.gale.registerRteCleanup('[data-my-editor]');
```

### Default Selectors

`setupRteCompat()` registers handlers for:
- `[data-rte]` — generic RTE container attribute
- `.tiptap` — TipTap editor (ProseMirror-based)
- `.ql-container` — Quill editor
- `trix-editor` — Trix editor element

### How It Works

1. **beforeMorph**: Sets `x-morph-ignore` on the editor root so morph completely skips it. Preserves cursor position, undo history, and all editor state.
2. **afterMorph**: Removes the `x-morph-ignore` mark (element was untouched).
3. **destroy**: Calls the editor's native destroy method to free resources.

### Content Sync Pattern

Bind editor content to Alpine state via the editor's update/input event:

```html
<div x-data="{ content: '' }" x-init="
    const editor = new TipTap.Editor({
        element: $refs.editor,
        onUpdate: ({ editor }) => { content = editor.getHTML() }
    })
">
    <div x-ref="editor" data-rte></div>
    <button @click="$action('/save', { include: ['content'] })">Save</button>
</div>
```

## SortableJS Integration

Built-in compatibility for SortableJS drag-and-drop.

### Setup

```js
document.addEventListener('alpine:initialized', () => {
    Alpine.gale.setupSortableCompat();
});

// Or register for custom selector
Alpine.gale.registerSortableCleanup('[data-drag-list]');
```

Default selector: `[data-sortable]`.

### How It Works

1. **beforeMorph**: If a drag is in progress, the morph is deferred until `onEnd` fires. Multiple deferred morphs collapse to the last one (last-wins). A 5-second safety timeout forces the morph regardless.
2. **afterMorph**: SortableJS is re-initialized on the updated container if needed.
3. **destroy**: The SortableJS instance is destroyed to free resources.

### Post-Drop Order Sync

After a drop, the new order is written to `el._sortableOrder` and a `gale:sortable-drop` event is dispatched:

```html
<div data-sortable x-data="{ items: [...] }"
     @gale:sortable-drop="items = $event.detail.order; $action('/reorder')">
    <div data-id="1">Item 1</div>
    <div data-id="2">Item 2</div>
    <div data-id="3">Item 3</div>
</div>
```

### Install Handlers Manually

```js
const sortable = new Sortable(el, { /* config */ });
Alpine.gale.installSortableHandlers(el, sortable);
```

Installs drop listeners and order-sync behavior on an existing Sortable instance.

## Plugin System

Formal plugin architecture for extending Gale.

### Plugin Object Shape

```js
{
    init({ addMagic, addDirective, config }) {
        // Called on registration
        addMagic('myMagic', (el) => (value) => { /* ... */ });
        addDirective('my-directive', (el, { expression }, { evaluate }) => { /* ... */ });
    },
    beforeRequest({ url, method, data, options }) {
        // Modify request before sending
    },
    afterResponse({ url, status, data, response }) {
        // Process response
    },
    beforeMorph({ el, newEl, component }) {
        // Return false to prevent morph
    },
    afterMorph({ el, newEl, component }) {
        // Post-morph processing
    },
    destroy() {
        // Cleanup on unregistration
    },
}
```

All hooks are optional.

### Registration

```js
Alpine.gale.registerPlugin('analytics', {
    init({ config }) {
        console.log('Analytics plugin initialized');
    },
    afterResponse({ url, status }) {
        trackPageView(url, status);
    },
    destroy() {
        console.log('Analytics plugin destroyed');
    },
});
```

### Rules

- Plugin names must be non-empty strings
- Registering with an existing name calls `destroy()` on the old plugin first, then replaces
- `init` context provides `addMagic`, `addDirective`, and `config` (current Gale config)
- `beforeMorph` can return `false` to prevent the morph operation

### API

```js
Alpine.gale.registerPlugin(name, pluginObject);
Alpine.gale.unregisterPlugin(name);      // Calls destroy()
Alpine.gale.getPlugin(name);             // Returns plugin object or undefined
Alpine.gale.getPluginNames();            // string[]
Alpine.gale.getPluginCount();            // number
```

## Custom Directives

Register Gale-aware Alpine directives with morph lifecycle integration.

### API

```js
Alpine.gale.directive('tooltip', {
    init(el, expression, galeContext) {
        // Called when element mounts
        // expression: the directive value string
        // galeContext: { mode, component, config }
        tippy(el, { content: expression });
    },
    morph(el, phase, galeContext) {
        // phase: 'before' or 'after'
        if (phase === 'before') {
            el._tippy?.destroy();
        }
        if (phase === 'after') {
            tippy(el, { content: el.getAttribute('x-tooltip') });
        }
    },
    destroy(el, galeContext) {
        // Called when element is removed
        el._tippy?.destroy();
    },
});
```

### Rules

- Directive names are auto-prefixed with `x-` if not already present
- `galeContext` provides `{ mode, component, config }` for access to current request mode, component reference, and Gale configuration
- All hooks are optional

### Query API

```js
Alpine.gale.getCustomDirectiveNames();   // string[]
Alpine.gale.getCustomDirectiveCount();   // number
```

## Memory Management

### The teardown() Method

```js
Alpine.gale.teardown();
```

Removes ALL resources registered by the Gale plugin:

- Navigation listeners and observers
- Component registry
- File system tracking
- State tracking
- SSE connections (all active connections closed)
- ETag cache
- Request pipeline and queue manager
- Morph hooks and transition queue
- Drag morph queue (SortableJS)
- Cleanup registry
- Response cache
- Prefetch cache (aborts in-flight prefetches)
- History cache (releases sessionStorage)
- Push channel subscriptions (EventSource connections closed)
- Debug panel
- Request logger entries
- Performance timing contexts
- State diff captures
- Error overlay
- Rate limit retry timers
- Auth expiration timers
- Offline detection listeners
- Console logger state
- Registered plugins (calls destroy on each)
- Custom directives
- CSRF token state

### When to Use

- **Testing**: Call in `afterEach` for test isolation
- **SSR**: Clean up server-side Alpine instances
- **Plugin re-initialization**: Full reset before re-registering the Gale plugin

## Production Builds

Debug modules (`debug.js`, `error-overlay.js`, `console-logger.js`, `request-logger.js`, `performance-timing.js`, `state-diff.js`) are replaced with no-op stubs in production builds by the `strip-debug` esbuild plugin. This eliminates ~200KB of debug source from production bundles.

In production:
- `Alpine.gale.debug.*` methods are no-ops
- `Alpine.gale.showErrorOverlay` is a no-op
- `Alpine.gale.getLogLevel()` returns `'off'`
- `Alpine.gale.setLogLevel()` is a no-op
- No debug DOM is injected
