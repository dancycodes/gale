# Core Concepts

> **See also:** [Getting Started](getting-started.md) | [Backend API Reference](backend-api.md) | [Frontend API Reference](frontend-api.md)

This guide explains the fundamental architecture of Gale — the mental models, design decisions, and
mechanics that make reactive Laravel development work. Read the [Getting Started](getting-started.md)
guide first if you haven't. This guide answers the *why* behind everything you typed there.

---

## Request/Response Lifecycle

Before diving into individual concepts, understand the full flow from button click to UI update:

```
┌─────────────────────────────────────────────────────────────────┐
│  BROWSER (Alpine.js + Gale plugin)                              │
│                                                                 │
│  User clicks button → @click="$action('/increment')"           │
│  Alpine serializes x-data component state                       │
│  Gale sends POST request:                                       │
│    Headers: Gale-Request: true, Gale-Mode: http (or sse)       │
│    Body:    { "count": 3, "_checksum": "sha256:..." }           │
└───────────────────────┬─────────────────────────────────────────┘
                        │ HTTP POST
                        ↓
┌─────────────────────────────────────────────────────────────────┐
│  LARAVEL CONTROLLER                                             │
│                                                                 │
│  public function increment(Request $request)                    │
│  {                                                              │
│      $count = $request->state('count') + 1;                    │
│      return gale()->state(['count' => $count]);                 │
│  }                                                              │
└───────────────────────┬─────────────────────────────────────────┘
                        │
                        ↓
┌─────────────────────────────────────────────────────────────────┐
│  RESPONSE (HTTP JSON mode, default)                             │
│                                                                 │
│  Content-Type: application/json                                 │
│  {                                                              │
│    "events": [                                                  │
│      { "type": "gale-patch-state", "data": { "count": 4 } }    │
│    ]                                                            │
│  }                                                              │
└───────────────────────┬─────────────────────────────────────────┘
                        │
                        ↓
┌─────────────────────────────────────────────────────────────────┐
│  BROWSER (Alpine.js + Gale plugin)                              │
│                                                                 │
│  Gale receives events array                                     │
│  Applies RFC 7386 JSON Merge Patch to Alpine component state    │
│  Alpine reactivity triggers → DOM updates automatically         │
│  <span x-text="count"> now shows 4                             │
└─────────────────────────────────────────────────────────────────┘
```

Every Gale interaction follows this exact flow. The variation is only in *what* events are returned
and *how* they are transported (HTTP JSON vs SSE stream).

---

## Dual-Mode Architecture

Gale supports two transport modes for sending events from server to browser. Understanding when and
why to use each is essential.

### HTTP Mode (Default)

HTTP mode is the default. The controller returns normally, and Gale serializes all events into a
single JSON response body.

```json
{
    "events": [
        { "type": "gale-patch-state", "data": { "count": 4 } },
        { "type": "gale-patch-elements", "data": { "html": "<div id='status'>...</div>", "mode": "outer" } }
    ]
}
```

**Use HTTP mode for:**
- Standard CRUD operations (create, read, update, delete)
- Form submissions
- Button actions that return immediately
- 90% of all Gale interactions

**Advantages:** Stateless, works with all hosting environments, CDNs, proxies, and load balancers. No
persistent connections. Caching-friendly.

### SSE Mode (Opt-In)

SSE mode sends events as a `text/event-stream` response, allowing the server to emit events
progressively over a single HTTP connection.

```
Content-Type: text/event-stream

event: gale-patch-state
data: state {"progress": 25}

event: gale-patch-state
data: state {"progress": 50}

event: gale-patch-state
data: state {"progress": 100, "complete": true}
```

**Use SSE mode for:**
- Long-running operations (file processing, bulk imports)
- Real-time dashboards that poll a server-push channel
- Streaming AI responses or log output
- Any operation where the user benefits from seeing progress incrementally

### Mode Selection Priority

The mode is resolved by checking in this exact order (highest priority first):

| Priority | Check | Example |
|----------|-------|---------|
| 1 (highest) | `gale()->stream()` call in controller | Always SSE — no override possible |
| 2 | `Gale-Mode` request header | Set by `{ sse: true }` or `{ http: true }` per-action option |
| 3 | `Alpine.gale.configure({ defaultMode: 'sse' })` | Frontend global override |
| 4 (lowest) | `config('gale.mode')` | Backend default, defaults to `'http'` |

**The key rule:** `gale()->stream()` always forces SSE. Everything else defaults to HTTP unless
explicitly opted into SSE.

> **Note for v1 users:** Gale v1 defaulted to SSE. Gale v2 defaults to HTTP. If you are migrating
> a v1 app, set `'mode' => 'sse'` in `config/gale.php` or add `{ sse: true }` to each action.

### How to Switch Modes

**Per-action (frontend):**
```html
<!-- HTTP (default — no option needed) -->
<button @click="$action('/save')">Save</button>

<!-- SSE for this action only -->
<button @click="$action('/process', { sse: true })">Process</button>
```

**Global frontend override:**
```javascript
Alpine.gale.configure({ defaultMode: 'sse' });
```

**Backend config (`config/gale.php`):**
```php
'mode' => 'sse',  // Change global default to SSE
```

**Always SSE (streaming):**
```php
return gale()->stream(function ($gale) {
    $gale->state(['progress' => 50]);
    sleep(1);
    $gale->state(['progress' => 100, 'done' => true]);
});
```

---

## State Management

Gale's state system is the bridge between Alpine.js reactive state on the client and PHP data on
the server.

### How State Flows

Alpine component state lives in `x-data`. When a Gale action fires, the plugin serializes the
component's state and sends it in the POST body. The server reads it, modifies it, and returns a
patch. The client merges the patch back into Alpine state.

```blade
<!-- State defined in x-data -->
<div x-data="{ count: 0, name: '' }" x-sync>
    <span x-text="count"></span>
    <input x-model="name">
    <button @click="$action('/increment')">+1</button>
</div>
```

```php
// Server reads and writes state
public function increment(Request $request): GaleResponse
{
    $count = $request->state('count');   // reads from POST body
    $name  = $request->state('name');   // reads from POST body

    return gale()->state([
        'count' => $count + 1,          // updates count in Alpine
        // name is not included — unchanged (RFC 7386 semantics)
    ]);
}
```

### Controlling What State Is Sent

By default, `$action` sends **nothing** from the component's state. You must opt in:

```html
<!-- x-sync: send all state keys every request -->
<div x-data="{ count: 0, items: [] }" x-sync>

<!-- x-sync with specific keys: send only listed keys -->
<div x-data="{ count: 0, items: [], draft: '' }" x-sync="['count']">

<!-- Per-action: include specific keys for this action only -->
<button @click="$action('/save', { include: ['draft'] })">Save Draft</button>

<!-- Per-action: exclude specific keys from the full x-sync payload -->
<button @click="$action('/search', { exclude: ['draft'] })">Search</button>
```

### RFC 7386 JSON Merge Patch

Gale uses RFC 7386 JSON Merge Patch — not JSON Patch (RFC 6902). The distinction matters.

**JSON Merge Patch (RFC 7386) — what Gale uses:**
- The patch is a plain JSON object
- Keys in the patch replace matching keys in the target
- Missing keys are left unchanged
- `null` values delete the key entirely
- Objects are merged recursively — arrays are replaced, not merged

**Concrete examples:**

```javascript
// Current Alpine state
{ count: 3, user: { name: "Alice", role: "viewer" }, items: [1, 2, 3] }
```

**Example 1: Updating a value**
```javascript
// Patch from server: { count: 5 }
// Result:
{ count: 5, user: { name: "Alice", role: "viewer" }, items: [1, 2, 3] }
//                                                             ↑ unchanged
```

**Example 2: Missing key is preserved**
```javascript
// Patch from server: { user: { role: "admin" } }
// Result:
{ count: 3, user: { name: "Alice", role: "admin" }, items: [1, 2, 3] }
//                        ↑ preserved          ↑ updated
```

**Example 3: null deletes the key**
```javascript
// Patch from server: { user: { role: null } }
// Result:
{ count: 3, user: { name: "Alice" }, items: [1, 2, 3] }
//                  ↑ role deleted
```

**Example 4: Array replacement (not merge)**
```javascript
// Patch from server: { items: [4, 5] }
// Result:
{ count: 3, user: { name: "Alice", role: "viewer" }, items: [4, 5] }
//                                                             ↑ replaced entirely
```

> **Common mistake:** Sending `{ items: null }` when you mean "clear the array". That *deletes* the
> `items` key from Alpine state entirely. To clear an array, send `{ items: [] }`.

### The `$gale` Reactive Proxy

The `$gale` magic provides reactive access to the Gale plugin's internal state from Alpine
templates:

```html
<div x-data>
    <!-- Shows while any Gale action is in flight -->
    <div x-show="$gale.loading">Loading...</div>

    <!-- Shows when an error occurred -->
    <div x-show="$gale.error" x-text="$gale.errorMessage"></div>
</div>
```

`$fetching()` is the per-element equivalent — returns `true` when the element that triggered the
action is waiting for a response:

```html
<button @click="$action('/save')">
    <span x-show="!$fetching()">Save</span>
    <span x-show="$fetching()">Saving...</span>
</button>
```

### State Integrity (Checksum Verification)

Every state payload sent from the client includes a `_checksum` field — an HMAC-SHA256 signature
of the state content signed with the application key. The Gale middleware verifies this signature
on every request. Tampered or replayed state is rejected with HTTP 403, and the frontend dispatches
a `gale:security-error` event.

This means Alpine state cannot be forged on the client side. The server can trust `$request->state()`
values as coming from a legitimate Gale session.

Routes that do not involve sensitive state (public forms, anonymous actions) can opt out:

```php
Route::post('/newsletter', [NewsletterController::class, 'subscribe'])
    ->middleware('gale.without-checksum');
```

---

## DOM Morphing

When the server returns `gale-patch-elements` events, the frontend must update the DOM without a
full page reload. This is DOM morphing — intelligently diffing old and new HTML.

### Nine Patch Modes

Gale provides nine distinct modes for DOM manipulation, each with specific Alpine.js integration
behavior:

| Mode | State Model | Best For |
|------|-------------|---------|
| `outer` (default) | Server-driven | Replace entire element, re-init Alpine from server HTML |
| `inner` | Server-driven | Replace inner content, keep wrapper, re-init children |
| `outerMorph` | Client-preserved | Smart diff entire element, preserve Alpine state |
| `innerMorph` | Client-preserved | Smart diff children, preserve wrapper Alpine state |
| `prepend` | New only | Insert new items at list start |
| `append` | New only | Insert new items at list end |
| `before` | New only | Insert element before target |
| `after` | New only | Insert element after target |
| `remove` | N/A | Delete element from DOM |

**Server API:**
```php
gale()->html('<div id="counter">4</div>');                       // outer (default, ID-matched)
gale()->append('#list', '<li>New Item</li>');                    // append to list
gale()->remove('#notification-5');                               // remove
gale()->outerMorph('#product-form', view('products.form', $data)->render()); // client-state preserved
```

### Choosing the Right Mode

**Use `outer` (default) when:**
- The server controls the component's state (most Gale interactions)
- You are replacing a counter, badge, status indicator, or any element whose truth is on the server
- Maximum performance is needed — destroy/replace/reinit is the fastest DOM operation

**Use `outerMorph` when:**
- The user may be interacting with the element (typing in a form, mid-transition)
- Client-side state (input values, scroll position, unsaved changes) must survive the update
- Example: Updating a product card while preserving an unsaved quantity input

**Use `append`/`prepend` when:**
- Adding new items to an existing list
- The new items contain Alpine components that need initialization
- Example: Appending a new chat message, prepending a notification

### Alpine State Preservation

Alpine state is preserved based on the chosen mode:

**`outer` / `inner` — server wins:**
```
destroyTree(old element)
↓ cleanly removes Alpine effects, listeners, watchers
replaceWith(new element)
↓ new element is now in DOM
initTree(new element)
↓ Alpine evaluates x-data from server-rendered HTML
```

The client state is discarded. The server's rendered `x-data` values become the new state. No FOUC
(Flash of Unstyled Content) occurs because all three operations are synchronous.

**`outerMorph` / `innerMorph` — client is preserved:**
```
Alpine.morph(old, new)
↓ diffs old and new DOM trees
↓ mutates only what changed (text content, attributes)
↓ Alpine reactive state (x-data) is preserved throughout
↓ Input values, focus, scroll position are maintained
```

The morph algorithm walks both trees simultaneously, making surgical changes. If a `<div id="card">`
is in both the old and new tree, only its changed attributes or text content are updated. Its Alpine
`x-data` state is never touched.

### Morph Lifecycle Hooks

You can hook into the morph lifecycle to integrate third-party JavaScript libraries (Chart.js,
GSAP, CodeMirror, SortableJS, etc.):

```javascript
// Register hooks once (e.g., in a script block or Alpine.start() callback)
const unregister = Alpine.gale.onMorph({
    beforeUpdate(ctx) {
        // ctx.el — the existing DOM element about to be morphed
        // ctx.newEl — the incoming element from the server
        // ctx.component — the nearest Alpine component element
        if (ctx.el.id === 'chart-container') {
            myChart.destroy(); // clean up before morph
        }
        // return false to cancel morphing this element entirely
    },
    afterUpdate(ctx) {
        if (ctx.el.id === 'chart-container') {
            myChart = new Chart(ctx.el, config); // reinit after morph
        }
    },
    beforeRemove(ctx) {
        // return false to prevent removal of this element
    },
    afterRemove(ctx) { /* element was removed */ },
    afterAdd(ctx)    { /* new element was added */ },
});

// Later: remove hooks when no longer needed
unregister();
```

**DOM events (alternative to hook API):**
```javascript
document.addEventListener('gale:before-morph', (e) => {
    const { el, newHtml } = e.detail;
});

document.addEventListener('gale:after-morph', (e) => {
    const { el } = e.detail;
});
```

### The `x-morph-ignore` Attribute

Mark elements that should never be touched during morphing:

```html
<div id="canvas-wrapper" x-morph-ignore>
    <canvas id="my-chart"></canvas>
</div>
```

Any element with `x-morph-ignore` and all its descendants are completely skipped by the morph
algorithm. The server's version of those elements is ignored.

### What IS and Is NOT Preserved During Morphs

**Alpine state IS preserved (`outerMorph`/`innerMorph`):**
- All `x-data` properties and their current values
- Active focus (element that has keyboard focus)
- Input values (text, checkboxes, selects)
- Scroll position within the morphed element

**NOT preserved (always reset):**
- Vanilla JavaScript event listeners attached with `addEventListener` directly
- Third-party library state (Chart.js instances, map instances, etc.) — use morph hooks
- `outer`/`inner` mode: all Alpine state (server wins)

---

## The Main Law

> **Every controller action that responds to a Gale request MUST return `gale()->...`**

This is the single most important rule in Gale. Breaking it causes silent failures that are hard
to debug.

### Correct Usage

```php
// CORRECT: Returns a GaleResponse
public function update(Request $request): GaleResponse
{
    $user = User::find($request->state('userId'));
    $user->update(['name' => $request->state('name')]);

    return gale()->state(['saved' => true, 'name' => $user->name]);
}

// CORRECT: Works for both Gale and non-Gale (direct URL) requests
public function index(Request $request): mixed
{
    $items = Item::all();
    return gale()->view('items.index', compact('items'), web: true);
}
//                                                       ↑ web: true makes this
//                                                         the fallback for page loads
```

### What Goes Wrong Without It

```php
// WRONG: Returns a Blade view directly
public function update(Request $request)
{
    User::find($request->state('userId'))
        ->update(['name' => $request->state('name')]);

    return view('users.show'); // ← This is the violation
}
```

When Gale sends a POST request to this action and receives a `text/html` response instead of
`application/json` or `text/event-stream`, the Gale plugin does not know what to do with it. One
of these will occur:

1. The HTML is silently ignored — no state update, no DOM change, confused user
2. The response body is misinterpreted as an event payload — parse error, broken UI
3. An exception propagates and the error handler tries to convert it — inconsistent behavior

**The pattern for routes accessed both via Gale and direct browser URL:**

```php
return gale()->view('items.index', compact('items'), web: true);
//                                                    ↑ This sets view() as the
//                                                      fallback for non-Gale requests
```

`web: true` means: if this request is NOT a Gale request (first page load, browser navigation),
return the view directly. If it IS a Gale request, return the Gale events that include the
rendered view.

**The pattern for navigation requests (SPA):**

```php
public function index(Request $request): mixed
{
    $products = Product::paginate(20);

    // SPA navigation: return only changed fragments
    if ($request->isGaleNavigate('catalog')) {
        return gale()
            ->fragment('catalog.index', 'sidebar', compact('products'))
            ->fragment('catalog.index', 'main', compact('products'));
    }

    // Page load: return full view
    return gale()->view('catalog.index', compact('products'), web: true);
}
```

---

## Auto-Conversion Rules

Gale intercepts standard Laravel responses during Gale requests and converts them to reactive
equivalents. This means your controllers can use familiar Laravel idioms and they "just work"
reactively.

### ValidationException → Reactive Error Messages

When `$request->validate()` or `$request->validateState()` throws a `ValidationException` during
a Gale request, Gale automatically converts it to a `gale()->messages()` response:

```php
// This standard Laravel validation...
public function store(Request $request): GaleResponse
{
    $validated = $request->validate([
        'email' => 'required|email',
        'name'  => 'required|min:2',
    ]);
    // ^ If validation fails, a ValidationException is thrown

    User::create($validated);
    return gale()->state(['saved' => true]);
}

// ...is automatically converted to the equivalent of:
// return gale()->messages(['email' => 'The email field is required.'])->forceHttp();
// Status: 422
```

The converted response patches the Alpine component's `messages` state with an object where keys
are field names and values are the first error message for that field. Fields that now pass
validation have their message set to `null` (clearing any previous error).

**Display validation errors in Blade with `x-message`:**

```blade
<form x-data="{ email: '', name: '', messages: {} }" x-sync="['email', 'name']"
      @submit.prevent="$action('/users')">
    <div>
        <input type="email" x-model="email" x-name="email">
        <p x-message="email" class="text-red-600 text-sm"></p>
    </div>
    <div>
        <input type="text" x-model="name" x-name="name">
        <p x-message="name" class="text-red-600 text-sm"></p>
    </div>
    <button type="submit">Create User</button>
</form>
```

`x-message="field"` automatically shows/hides error text from the `messages` state object. No
conditional logic needed in your template.

### redirect() → Reactive Navigation

When a controller returns a `redirect()` during a Gale request, the `ConvertRedirectForGale`
middleware automatically converts it to a `gale-redirect` event that triggers client-side
navigation:

```php
// Standard redirect in a controller that handles both Gale and non-Gale:
public function store(Request $request): mixed
{
    $item = Item::create($request->validated());

    return redirect()->route('items.show', $item)
        ->with('message', 'Item created successfully!');
}
// In a Gale request: converted to reactive navigation automatically
// In a regular request: normal Laravel redirect
```

The flash data (`with(...)`) is committed to the session before the middleware intercepts the
response, so it is available on the next page load.

**When to use `gale()->redirect()` directly:**

Use `gale()->redirect()` explicitly when you need to redirect only in the Gale context, or when
you need precise control over the redirect with a web fallback:

```php
return gale()->redirect('/dashboard')
    ->with('status', 'Profile updated!')
    ->web(redirect()->back());
//  ↑ non-Gale fallback
```

### abort() → Reactive Error State

When `abort()` is called during a Gale request, the `GaleErrorHandler` converts it to a structured
error response instead of returning an HTML error page:

```php
public function show(Request $request, Item $item): GaleResponse
{
    abort_unless($request->user()->can('view', $item), 403);

    return gale()->view('items.show', compact('item'));
}
```

For a Gale request, the 403 abort becomes:
- `_error` state patch: `{ status: 403, message: "Forbidden" }`
- `gale:error` DOM event dispatched on document

Special cases:
- **401 Unauthorized**: redirects to the login route via `gale()->redirect(route('login'))`
- **419 CSRF Mismatch**: dispatches `gale:csrf-expired` event (triggers CSRF token refresh)
- **422 Validation**: handled by the ValidationException renderable (above)

### dd() and dump() → Debug Overlay

When `config('gale.debug')` is `true` (automatically set when `APP_DEBUG=true`), the
`GaleDumpInterceptMiddleware` wraps Gale request handling in an output buffer. VarDumper output
from `dd()` and `dump()` is captured and sent as `gale-debug-dump` events to the frontend debug
overlay panel — it does NOT corrupt the JSON or SSE response:

```php
public function index(Request $request): GaleResponse
{
    $data = $this->buildData();
    dump($data);  // captured and shown in debug panel, not in response body
    dd($data);    // same — stops execution and shows in overlay

    return gale()->view('page', compact('data'));
}
```

In production (`APP_DEBUG=false`), `GaleDumpInterceptMiddleware` is a no-op — zero overhead.

---

## Putting It All Together

Here is a complete example demonstrating dual-mode selection, state management, DOM morphing, The
Main Law, and auto-conversion working together in a single feature — a product search page with
instant filtering.

**Controller:**
```php
class ProductController extends Controller
{
    // The Main Law: always return gale()
    public function index(Request $request): mixed
    {
        // SPA navigation returns only changed fragments
        if ($request->isGaleNavigate('products')) {
            return $this->buildFragmentResponse($request);
        }

        // Page load: full view (web: true is the fallback)
        return gale()->view('products.index', $this->getData($request), web: true);
    }

    public function search(Request $request): GaleResponse
    {
        // Auto-conversion: if this throws, ValidationException → reactive errors
        $request->validate([
            'query'    => 'nullable|string|max:100',
            'category' => 'nullable|exists:categories,id',
        ]);

        $query    = $request->state('query');      // from x-sync state
        $category = $request->state('category');   // from x-sync state

        $products = Product::query()
            ->when($query, fn($q) => $q->where('name', 'like', "%{$query}%"))
            ->when($category, fn($q) => $q->where('category_id', $category))
            ->paginate(20);

        // Update the results list in the DOM
        return gale()
            ->fragment('products.index', 'results', compact('products'))
            ->state('total', $products->total());
    }

    private function buildFragmentResponse(Request $request): GaleResponse
    {
        $data = $this->getData($request);
        return gale()
            ->fragment('products.index', 'sidebar', $data)
            ->fragment('products.index', 'results', $data);
    }

    private function getData(Request $request): array
    {
        return ['products' => Product::paginate(20)];
    }
}
```

**Blade view:**
```blade
<div x-data="{
    query: '',
    category: null,
    total: {{ $products->total() }},
    messages: {}
}" x-sync="['query', 'category']">

    @fragment('sidebar')
    <aside id="sidebar">
        <!-- x-navigate for SPA navigation — see Navigation guide -->
        <nav x-navigate.key.products>
            <a href="/products">All Products</a>
            @foreach($categories as $cat)
                <a href="/products?category={{ $cat->id }}">{{ $cat->name }}</a>
            @endforeach
        </nav>
    </aside>
    @endfragment

    <main>
        <!-- Search form — uses HTTP mode (default) -->
        <form @submit.prevent="$action('/products/search')">
            <input type="text" x-model="query" placeholder="Search products...">
            <p x-message="query" class="text-red-600 text-sm"></p>

            <select x-model.number="category">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>

            <button type="submit">
                <span x-show="!$fetching()">Search</span>
                <span x-show="$fetching()">Searching...</span>
            </button>
        </form>

        <p>Found <span x-text="total"></span> products</p>

        @fragment('results')
        <div id="results">
            @foreach($products as $product)
                <div id="product-{{ $product->id }}">
                    {{ $product->name }} — ${{ $product->price }}
                </div>
            @endforeach
        </div>
        @endfragment
    </main>
</div>
```

This example demonstrates:
- **The Main Law**: every return is `gale()->...`
- **Dual-mode**: HTTP mode (default) for the search action; SPA navigation uses fragment responses
- **State management**: `x-sync` sends `query` and `category` on each request
- **Auto-conversion**: `validate()` failures become reactive `x-message` errors automatically
- **DOM morphing**: `fragment()` targets the `#results` element by ID using `outer` mode
- **Loading states**: `$fetching()` shows a spinner while the request is in flight
- **RFC 7386 state**: `total` is patched independently — other state keys are untouched

---

## Next Steps

- Read [Backend API Reference](backend-api.md) for the complete `gale()` method catalog
- Read [Frontend API Reference](frontend-api.md) for all Alpine Gale magics and directives
- Read [Navigation & SPA Guide](navigation.md) for SPA navigation patterns and history management
- Read [Forms, Validation & Uploads Guide](forms-validation-uploads.md) for complex form patterns
- Read [Debug & Troubleshooting Guide](debug-troubleshooting.md) when something doesn't work as expected
