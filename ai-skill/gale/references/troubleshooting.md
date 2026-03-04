# Gale Troubleshooting Reference

Common issues and their solutions when working with Gale. Covers both HTTP mode (default) and SSE mode (opt-in).

---

## State Not Updating

**Symptom:** Button click sends request but Alpine state doesn't change.

**Cause 1: No x-sync and no include option**
```html
<!-- No state sent -->
<div x-data="{ count: 0 }">
    <button @click="$action('/increment')">+</button>
</div>

<!-- Fix: Add x-sync -->
<div x-data="{ count: 0 }" x-sync>
    <button @click="$action('/increment')">+</button>
</div>

<!-- Fix: Or use include per-action -->
<div x-data="{ count: 0 }">
    <button @click="$action('/increment', { include: ['count'] })">+</button>
</div>
```

**Cause 2: Backend not returning gale() response**
```php
// Wrong
return response()->json(['count' => 1]);

// Correct
return gale()->state('count', 1);
```

**Cause 3: No Alpine context**
```html
<!-- No x-data parent -->
<button @click="$action('/save')">Save</button>

<!-- Add x-data -->
<div x-data="{ count: 0 }">
    <button @click="$action('/save')">Save</button>
</div>
```

---

## JSON Response Not Being Processed

**Symptom:** Server returns JSON but the frontend doesn't process events.

**Cause 1: Response is not in Gale event format**
```php
// Wrong -- plain JSON, not Gale events
return response()->json(['count' => 1]);

// Correct -- gale() formats events properly
return gale()->state('count', 1);
// HTTP mode returns: { "events": [{ "type": "gale-patch-state", "data": { "state": { "count": 1 } } }] }
```

**Cause 2: Missing Content-Type header**
The frontend auto-detects the response type from Content-Type. If the Content-Type is missing or unexpected, it falls back to JSON processing. This is usually fine, but verify your server isn't stripping headers.

**Cause 3: Controller returns view() instead of gale()**
```php
// Wrong -- returns HTML, not JSON events
return view('page');

// Correct
return gale()->view('page', $data, web: true);
```

---

## Mode Mismatch Issues

**Symptom:** Frontend expects JSON but server returns SSE, or vice versa.

**This is actually fine.** The frontend auto-detects the Content-Type and uses the appropriate parser. A `gale()->stream()` response will always be SSE, and the frontend handles it correctly even if the frontend didn't explicitly request SSE mode.

**If you need to force a specific mode:**
```html
<!-- Force HTTP mode for this request -->
<button @click="$action('/save', { http: true })">Save</button>

<!-- Force SSE mode for this request -->
<button @click="$action('/process', { sse: true })">Process</button>
```

```php
// Server-side: force SSE for a specific route
// stream() always uses SSE regardless of mode config
return gale()->stream(function ($gale) { ... });
```

**If you need to change the server default:**
```php
// config/gale.php
'mode' => 'sse',  // Change default from 'http' to 'sse'
```

---

## Fragment Not Replacing

**Symptom:** Fragment renders but DOM doesn't update.

**Cause 1: Missing ID on fragment root element**
```blade
<!-- No ID -- Gale can't find element to patch -->
@fragment('items')
<div>
    @foreach($items as $item) ... @endforeach
</div>
@endfragment

<!-- Add ID -->
@fragment('items')
<div id="items-list">
    @foreach($items as $item) ... @endforeach
</div>
@endfragment
```

**Cause 2: Fragment name mismatch**
```php
// Fragment name doesn't match blade
gale()->fragment('items.index', 'item-list', $data);

// Must match @fragment('items-list')
gale()->fragment('items.index', 'items-list', $data);
```

**Cause 3: View path incorrect**
```php
// Wrong view path
gale()->fragment('items', 'items-list', $data);

// Use dot notation matching file path
gale()->fragment('items.index', 'items-list', $data);
```

---

## $fetching Not Working

**Symptom:** `$fetching()` always returns false.

**Cause: Missing parentheses**
```html
<!-- Wrong -- property access, not function call -->
<span x-show="$fetching">Loading...</span>

<!-- Correct -- function call -->
<span x-show="$fetching()">Loading...</span>
```

---

## CSRF Token Mismatch (419)

**Symptom:** POST requests return 419 error.

**Cause 1: Missing @gale directive**
```blade
<!-- No CSRF meta tag -->
<head></head>

<!-- @gale outputs CSRF meta + Alpine + Gale -->
<head>@gale</head>
```

**Cause 2: Expired session**
The CSRF token is read from `<meta name="csrf-token">`. If the session expires, the token becomes stale. Gale automatically attempts to refresh the token on 419 errors:
- **Auto mode (default):** Tries re-reading the meta tag first, then fetches `/sanctum/csrf-cookie`
- **Meta mode:** Only re-reads the meta tag
- **Sanctum mode:** Only fetches from `/sanctum/csrf-cookie`

Configure the strategy:
```javascript
Alpine.gale.configure({ csrfRefresh: 'auto' }); // 'auto' | 'meta' | 'sanctum'
```

If the token refresh succeeds, the original request is transparently retried once. If it fails, a "Session expired" error is shown.

---

## Duplicate Alpine.js Errors

**Symptom:** Console errors about Alpine being initialized twice.

**Cause: @gale + separate Alpine script**
```blade
<!-- Duplicate Alpine -->
<head>
    @gale
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
</head>

<!-- Remove separate Alpine -- @gale includes it -->
<head>
    @gale
</head>
```

Also check `resources/js/app.js` for `import Alpine from 'alpinejs'` -- remove it.

---

## Navigation Not Working

**Symptom:** Links cause full page reload instead of SPA navigation.

**Cause 1: Missing x-navigate on container or link**
```html
<!-- No navigation handling -->
<a href="/page">Link</a>

<!-- Add x-navigate -->
<div x-data x-navigate>
    <a href="/page">Now it works</a>
</div>
```

**Cause 2: Missing x-data context**
```html
<!-- x-navigate without Alpine context -->
<div x-navigate>
    <a href="/page">Won't work</a>
</div>

<!-- Add x-data -->
<div x-data x-navigate>
    <a href="/page">Works</a>
</div>
```

**Cause 3: Backend not handling navigate request**
```php
// Returns full page for navigate requests too
public function index()
{
    return view('page');
}

// Check for navigate and return fragments
public function index(Request $request)
{
    $data = $this->getData();

    if ($request->isGaleNavigate('content')) {
        return gale()->fragment('page', 'content', $data);
    }
    return gale()->view('page', $data, web: true);
}
```

---

## Validation Errors Not Showing

**Symptom:** Server validates but errors don't appear in UI.

**Cause 1: Missing x-message directive**
```html
<!-- No error display -->
<input x-name="email">

<!-- Add x-message -->
<input x-name="email">
<p x-message="email" class="text-red-600 text-sm"></p>
```

**Cause 2: Message key mismatch**
```php
// Server validates 'email_address'
$request->validateState(['email_address' => 'required|email']);
```
```html
<!-- Key doesn't match -->
<p x-message="email"></p>

<!-- Must match validation key -->
<p x-message="email_address"></p>
```

**Cause 3: Using validate() for Alpine state instead of validateState()**
```php
// For Alpine state sent via $action, use validateState()
$request->validateState(['email' => 'required|email']);

// Standard validate() also works -- it auto-converts for Gale requests
// BUT: it reads from request input, not from Alpine state
// Use when state is sent via x-sync or include (which puts it in request body)
$request->validate(['email' => 'required|email']);
```

---

## outerMorph Not Preserving State

**Symptom:** Using outerMorph but Alpine state still resets.

**Cause: ID mismatch between old and new HTML**
```php
// New HTML has different structure/ID
$html = '<div id="item-new">...</div>';
gale()->outerMorph('#item-1', $html);

// IDs must match for morph to work
$html = '<div id="item-1">...updated...</div>';
gale()->outerMorph('#item-1', $html);
```

---

## Streaming Not Working

**Symptom:** All events arrive at once instead of progressively.

**Cause 1: Not using stream()**
```php
// Events batch and send together (normal mode -- HTTP or SSE)
$gale = gale();
foreach ($items as $item) {
    $gale->state('progress', $i);
}
return $gale;

// Use stream() for immediate delivery (always SSE)
return gale()->stream(function ($gale) use ($items) {
    foreach ($items as $i => $item) {
        $gale->state('progress', $i);
    }
});
```

**Cause 2: Output buffering**
Check for `ob_start()` or middleware that buffers output. Streaming requires unbuffered output.

---

## File Upload Issues

**Symptom:** Files not received by server.

**Cause 1: Missing x-files directive on the input**
```html
<!-- Files not tracked -->
<input type="file" name="images">

<!-- Add x-files on the input element -->
<div x-data>
    <input type="file" name="images" x-files multiple>
</div>
```

**Cause 2: Using validateState() for files**
```php
// Files come via FormData, not Alpine state
$request->validateState(['images' => 'required']);

// Use standard Laravel validation for files
$request->validate(['images.*' => 'required|image|max:5120']);
```

---

## Component State Not Updating

**Symptom:** `componentState()` called but component doesn't update.

**Cause 1: Component not registered**
```html
<!-- No x-component -->
<div x-data="{ value: 0 }">
    <span x-text="value"></span>
</div>

<!-- Register with x-component -->
<div x-data="{ value: 0 }" x-component="my-widget">
    <span x-text="value"></span>
</div>
```

**Cause 2: Name mismatch**
```php
// Name doesn't match
gale()->componentState('myWidget', ['value' => 42]);

// Must match x-component attribute exactly
gale()->componentState('my-widget', ['value' => 42]);
```

---

## Polling Issues

**Symptom:** Polling doesn't start or stops unexpectedly.

**Cause 1: Missing time modifier**
```html
<!-- No interval specified (defaults to 5s, which may be too long to notice) -->
<div x-interval="refresh()">

<!-- Specify explicit interval -->
<div x-interval.5s="refresh()">
```

**Cause 2: x-interval-stop evaluating true immediately**
```html
<!-- Starts stopped -->
<div x-data="{ done: true }" x-interval.5s="check()" x-interval-stop="done">

<!-- Start with false -->
<div x-data="{ done: false }" x-interval.5s="check()" x-interval-stop="done">
```

---

## Non-Gale Request Errors (LogicException)

**Symptom:** First page load throws error.

**Cause: No web fallback**
```php
// No fallback for initial page load
return gale()->state('data', $data);

// Provide web fallback
return gale()->view('page', $data, web: true);

// Or use web() method
return gale()->state('data', $data)->web(view('page', compact('data')));
```

---

## Redirect Not Working in Gale Requests

**Symptom:** Standard Laravel redirect() doesn't work with Gale requests.

**Resolution:** Gale automatically intercepts standard `redirect()` calls for Gale requests and converts them. However, for explicit control, use `gale()->redirect()`:

```php
// Standard redirect() -- auto-converts for Gale requests
return redirect('/dashboard');

// Explicit Gale redirect (recommended for clarity)
return gale()->redirect('/dashboard');

// With flash data
return gale()->redirect('/dashboard')->with('message', 'Saved!');
```

**Inside stream() closures:** Standard `redirect()` calls are automatically converted to SSE redirect events by `GaleStreamRedirector`.

---

## Error Pages Replacing SPA State

**Symptom:** A 500 error replaces the entire page, destroying Alpine state.

**This is by design for SSE mode.** In SSE mode, error page HTML replaces the document.

**In HTTP mode (default):** Server errors (4xx/5xx) show a dismissible toast notification instead of replacing the page. Component state is preserved. This is the recommended behavior for production applications.

If you're seeing page replacement, check if your requests are using SSE mode (`{ sse: true }` or global `defaultMode: 'sse'`).

---

## Back/Forward Navigation Issues

**Symptom:** Clicking browser back/forward after Gale navigation does not update content correctly.

**How it works:** Gale intercepts `popstate` events and performs a Gale navigation fetch to the restored URL instead of triggering a full page reload. This provides smooth SPA-style back/forward navigation with:
- Scroll position restoration via `history.state._galeScrollY`
- Server-fresh content (no client-side cache)
- No duplicate history entries (pushState is skipped during popstate)
- Browser-native scroll restoration disabled in favor of Gale's manual restoration

**If back/forward seems broken**, check:
1. Your controller handles navigate requests correctly:
```php
if ($request->isGaleNavigate()) {
    return gale()->fragment('page', 'content', $data);
}
return gale()->view('page', $data, web: true);
```
2. Your `x-navigate` container wraps all navigable content
3. Fragment root elements have stable IDs that persist across navigations

---

## FOUC (Flash of Unstyled Content) During Navigation

**Symptom:** Brief flash of unstyled content when navigating between pages.

**Resolution:** Gale has built-in FOUC prevention. It waits for external stylesheets to load before showing new content. Configure the timeout:

```javascript
Alpine.gale.configure({
    foucTimeout: 5000,         // Wait up to 5s for stylesheets (default: 3000)
    navigationIndicator: true,  // Show progress bar during navigation (default: true)
});
```

If FOUC still occurs, ensure your stylesheets are loaded via `<link>` tags in the `<head>`, not dynamically injected.

---

## View Transitions Not Working

**Symptom:** SPA navigation doesn't show smooth page transitions.

**Cause 1: Browser doesn't support View Transitions API**
View Transitions require Chrome 111+, Edge 111+, or other Chromium-based browsers. Safari and Firefox do not currently support this API.

**Cause 2: View Transitions disabled**
```javascript
// Check if enabled
const config = Alpine.gale.getConfig();
console.log(config.viewTransitions); // should be true

// Enable if disabled
Alpine.gale.configure({ viewTransitions: true });
```

**Cause 3: Per-element transition needs explicit opt-in**
```php
// Enable View Transitions for specific DOM patches
gale()->outerMorph('#element', $html, ['useViewTransition' => true]);
```

---

## Common Performance Tips

1. **Use fragments over full views** -- Only re-render what changed
2. **Use outerMorph for interactive elements** -- Preserves user input
3. **Use x-interval.visible** -- Stop polling when tab hidden
4. **Use { include: [...] }** -- Send only needed state, not everything
5. **Use settle for animations** -- `['settle' => 200]` gives CSS time to animate
6. **Use componentState for multi-widget updates** -- One request, many updates
7. **Use streaming for long operations** -- Progressive feedback via `gale()->stream()`
8. **Default to HTTP mode** -- Better for most actions, lower overhead than SSE
9. **Use SSE only when needed** -- Long-running operations, real-time progress
10. **Use $action.get() for read-only operations** -- Lighter, cacheable, no CSRF needed

---

## v2 Common Issues

### "Security Forbidden" (403) on First Request with x-sync

**Cause:** Component uses `x-sync` but `_checksum` is null on first request. Server validates checksum and rejects.

**Fix:** Use the bootstrap pattern to exclude `_checksum` on first request:

```blade
<div x-data="{ email: '', _checksum: null }" x-sync="['email', '_checksum']">
    <button @click="_checksum
        ? $action('/save')
        : $action('/save', { exclude: ['_checksum'] })">Save</button>
</div>
```

Or opt out of checksum validation for that route:

```php
Route::post('/save', ...)->middleware(\Dancycodes\Gale\Http\Middleware\WithoutGaleChecksum::class);
```

---

### "CSRF Token Mismatch" (419) After Long Idle

**Cause:** CSRF token expired due to session timeout.

**Fix:** Gale v2 has enhanced CSRF retry. Configure the strategy:

```javascript
Alpine.gale.configure({
    csrfRefresh: 'auto',  // 'auto' | 'meta' | 'sanctum' (default: 'auto')
});
```

`'auto'` mode: On 419, Gale fetches a fresh CSRF token and retries the request automatically. The user never sees the error.

---

### Response Validation Error in Debug Mode

**Symptom:** `InvalidArgumentException: Stream output is not valid SSE`

**Cause:** Controller returned HTML or non-SSE text inside `stream()` callback.

**Fix:** Only call `gale()` methods inside the stream callback. Never `echo`, `print`, or `return html`:

```php
// Wrong
return gale()->stream(function ($gale) {
    echo "Status: processing";  // Not SSE format
    $gale->state('done', true);
});

// Correct
return gale()->stream(function ($gale) {
    $gale->debug('Status', 'processing');
    $gale->state('done', true);
});
```

---

### "dd() or dump() Output in JSON Response"

**Cause:** Debug output corrupting the Gale response.

**Fix:** Enable debug mode — Gale intercepts `dd()`/`dump()` and redirects output to the debug panel:

```env
GALE_DEBUG=true
APP_DEBUG=true
```

In dev: `@gale` in `<head>` injects `window.GALE_DEBUG_MODE=true`, activating the debug panel.

---

### Alpine SyntaxError: "Unexpected token" in x-data with Complex Logic

**Cause:** Multi-line JS or Blade template syntax inside `x-data` attribute breaks Alpine's expression parser.

**Fix:** Use window global functions:

```blade
@push('head-scripts')
<script>
window._myComponent = function() {
    return {
        messages: {},
        async save() {
            await this.$action('/save');
        }
    };
};
</script>
@endpush

<div x-data="window._myComponent()">
    ...
</div>
```

---

### Redirect Blocked: "Cross-origin redirect rejected"

**Cause:** v2 redirect security is blocking an external redirect.

**Fix:** Whitelist the domain in `config/gale.php`:

```php
'redirect' => [
    'allowed_domains' => ['payment.stripe.com', '*.myapp.com'],
    'allow_external' => false,
],
```

Or disable checking for trusted environments:

```php
'redirect' => [
    'allow_external' => true,  // Disables domain checking entirely
],
```

---

### x-sync State With Multi-step Form (Step Jumps Back on Validation)

**Cause:** Validation errors update `messages` state; the form needs to watch `messages` to jump back to the step with the first error.

**Pattern:**

```javascript
// In x-data component or window global function
init() {
    this.$watch('messages', (newMessages) => {
        const errorFields = Object.keys(newMessages);
        if (errorFields.length > 0) {
            this.step = this.findFirstErrorStep(errorFields);
        }
    });
},
findFirstErrorStep(errorFields) {
    // Map field names to step numbers
    const fieldSteps = { name: 1, email: 1, address: 2, payment: 3 };
    return Math.min(...errorFields.map(f => fieldSteps[f] ?? 1));
},
```

---

### Alpine $event in Custom Events Has Colon

**Cause:** Events with colons (`gale:file-change`) cannot be bound via `@event:name` Alpine syntax.

**Fix:** Use `addEventListener` in `init()`:

```javascript
init() {
    this.$nextTick(() => {
        this.$el.addEventListener('gale:file-change', (e) => {
            this.files = e.detail.files;
        });
        this.$el.addEventListener('gale:file-error', (e) => {
            this.fileError = e.detail.message;
        });
    });
}
```

---

### Controller Produces Multiple x-navigate Navigations

**Cause:** Both direct `x-navigate` handler AND delegated container handler fire for same click.

**Fix (v2):** This is fixed in v2 via `stopPropagation()` in the direct handler. If you're seeing this, ensure you've updated to the latest `gale.js` build:

```bash
npm run gale:build && php artisan vendor:publish --tag=gale-assets --force
```

---

### Push Channel Not Receiving Events

**Cause:** Frontend subscribing with `x-listen` but server using wrong channel name or prefix.

**Check:**
1. Channel name in `x-listen` matches `gale()->push('channel-name')` exactly
2. Channel prefix is `/gale/push` by default — verify `GaleServiceProvider` registers the route
3. The subscription is SSE — it requires `text/event-stream` support in the hosting environment

```bash
# Check the push route exists
php artisan gale:routes
# Should show: GET /gale/push/{channel}
```
