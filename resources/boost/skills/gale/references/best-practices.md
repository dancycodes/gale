# Best Practices & Design Philosophy

Prescriptive rules for building performant, well-architected Gale applications. This reference
answers *when* and *why* — not just *how* — to use each Gale feature.

## Gale Design Philosophy

### The Three Principles

| Principle | Meaning | Violation Example |
|-----------|---------|-------------------|
| **Client-first** | If Alpine can do it, Alpine does it. No server round-trip. | `$action('/increment')` for `count + 1` |
| **Server-minimal** | Send only what the server needs. Not the kitchen sink. | Full `x-data` sent when only `email` matters |
| **Response-surgical** | Return only what changed. Target precisely. | `view()` when `state('count', 42)` suffices |

Gale exists to connect Alpine to Laravel's **authority** — database state, authentication,
validation that requires server context, other users' data, business rules. It does NOT exist
to replace Alpine's local reactivity. The server is for things Alpine *cannot know*.

### The Decision Tree

**Step 1 — Does this need the server?**

```
Does this operation require server authority?
├── NO: Pure client state
│   ├── Toggles, visibility       → @click="open = !open"
│   ├── Counters, arithmetic      → @click="count++"
│   ├── Tab/step switching        → @click="tab = 'settings'"
│   ├── Local filter/sort         → x-for="item in items.filter(...)"
│   ├── Input formatting          → @input="phone = formatPhone(phone)"
│   └── Conditional display       → x-show="step === 2"
│
├── MAYBE: Could be client, but needs persistence or authority
│   └── Would stale/wrong data cause harm?
│       ├── NO (cosmetic)  → Client-side, sync later with optimistic UI
│       └── YES (critical) → Server round-trip
│
└── YES: Database, auth, validation, other users, business logic
    └── Continue to Step 2...
```

**Step 2 — What to SEND?**

```
What state does the server need?
├── Nothing (read-only fetch)     → $action.get('/url')  (no body)
├── Single field                  → { include: ['field'] }
├── Few specific fields           → { include: ['f1', 'f2', 'f3'] }
├── Most fields except large ones → { exclude: ['blob', 'preview'] }
├── Only changed fields           → delta: true (default with dirty tracking)
└── File upload                   → x-files handles automatically
```

**Step 3 — What to RETURN?**

Prefer the cheapest sufficient response. Ordered from lightest to heaviest:

```
What does the client need back?
├── Just a value changed          → gale()->state('key', $value)
├── A confirmation message        → gale()->messages(['_success' => '...'])
├── A specific component's data   → gale()->componentState('name', [...])
├── A group of components         → gale()->tagState('tag', [...])
├── A piece of HTML               → gale()->fragment('view', 'name', $data)
├── Multiple HTML pieces          → gale()->fragments([...])
├── Remove an element             → gale()->remove('#selector')
└── Full page section (heavy)     → gale()->view('page', $data, [], web: true)
```

### When NOT to Use the Server

These operations MUST stay client-side. A server round-trip for any of these is an anti-pattern.

| Operation | Alpine Code | Server Round-Trip? |
|-----------|-------------|-------------------|
| Toggle visibility | `@click="open = !open"` | NEVER |
| Increment/decrement | `@click="count++"` | Only if persisting to DB |
| Tab/step switching | `@click="tab = 'settings'"` | NEVER |
| Local filter/sort | `items.filter(i => i.active)` | Only if server-side filtering needed (large datasets) |
| Input formatting | `@input="v = format(v)"` | NEVER |
| Conditional display | `x-show="role === 'admin'"` | NEVER |
| Dropdown open/close | `@click="showMenu = !showMenu"` | NEVER |
| Form field preview | `@input="preview = marked(content)"` | NEVER |
| Scroll-to-element | `@click="$el.scrollIntoView()"` | NEVER |
| Optimistic feedback | `{ optimistic: { liked: true } }` | Server confirms async |

---

## Validation & Error Display

### Validation Error Hierarchy

This is the mandatory display hierarchy. Each error type has ONE correct display method.

| Error Type | Server Method | State Slot | Display Directive | Location |
|------------|---------------|------------|-------------------|----------|
| Per-field auto-validation | `$request->validate()` (auto-renders to `gale()->state('messages', [...])`) | `messages` | `x-message="field"` | **Adjacent to field** |
| Per-field auto-validation (Gale-native) | `$request->validateState()` | `messages` | `x-message="field"` | Adjacent to field |
| Per-field custom message | `gale()->messages(['field' => 'msg'])` | `messages` | `x-message="field"` | Adjacent to field |
| Per-field manual errors (multi-string) | `gale()->errors(['field' => ['msg1','msg2']])` | `errors` | `x-message.from.errors="field"` | Adjacent to field |
| Form-level success | `gale()->messages(['_success' => '...'])` | `messages` | `x-message="_success"` | Banner or toast area |
| Custom event notification | `gale()->dispatch('show-toast', $data)` | — | `@show-toast.window` handler | Toast overlay |
| System error (500, network) | Automatic | — | `Alpine.gale.onError()` handler | Toast or error overlay |
| Auth expiry (401) | Automatic | — | `Alpine.gale.configure({ auth: ... })` | Login redirect or message |
| Rate limit (429) | Automatic | — | Built-in retry message | Inline message |

**Critical distinction**: `configureErrors({ showToast: true })` handles **system errors** (network failures, 500s, checksum mismatches). It does NOT handle validation errors. Validation errors come from auto-converted `ValidationException` (which writes to the `messages` state slot, displayed via `x-message`) or explicit `gale()->errors([...])` (writing to the `errors` slot, displayed via `x-message.from.errors`). Use whichever slot you wrote to — the two directives read from different state slots.

### The x-message Contract

**RULE: Every form that uses `$request->validate()` (or `$request->validateState()`) MUST have:**

1. One `<span x-message="fieldname">` per validated field — reads from the `messages` state slot, where auto-validation writes.
2. Placed immediately after the corresponding input element.
3. Styled with error appearance (e.g., `class="text-red-500 text-sm mt-1"`).
4. Success/completion feedback via `x-message="_success"` OR `dispatch('show-toast', ...)`.

> **Use `x-message`, NOT `x-message.from.errors`, for `$request->validate()`.** Auto-validation writes to the `messages` state via `GaleMessageException::render()`. `x-message="field"` reads from that slot. `x-message.from.errors="field"` reads from a different slot (`errors`) populated only by explicit `gale()->errors([...])` calls.

#### WRONG — Single error container

```blade
{{-- Anti-pattern: errors in a single block away from fields --}}
<form x-data="{ name: '', email: '' }" @submit.prevent="$action('/users')">
    <div class="errors" x-show="Object.keys($data.errors || {}).length">
        <template x-for="(msgs, field) in ($data.errors || {})">
            <p x-text="msgs[0]" class="text-red-500"></p>
        </template>
    </div>

    <input x-name="name" type="text">
    <input x-name="email" type="email">
    <button type="submit">Save</button>
</form>
```

#### CORRECT — Inline per-field

```blade
<form x-data="{ name: '', email: '' }" @submit.prevent="$action('/users')">
    <div>
        <input x-name="name" type="text" required>
        <span x-message="name" class="text-red-500 text-sm mt-1"></span>
    </div>

    <div>
        <input x-name="email" type="email" required>
        <span x-message="email" class="text-red-500 text-sm mt-1"></span>
    </div>

    <span x-message="_success" class="text-green-600"></span>
    <button type="submit" x-indicator="saving" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>
</form>
```

If you specifically want array-of-strings per field (multiple errors per field shown stacked), use `gale()->errors([...])` server-side AND `<span x-message.from.errors="field">` client-side. Most apps use the simpler `messages` flow above.

Server-side — standard Laravel validation. No manual error handling needed:

```php
public function store(Request $request): mixed
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
    ]);

    User::create($validated);

    return gale()
        ->state(['name' => '', 'email' => ''])
        ->messages(['_success' => 'User created!']);
}
```

See `patterns.md` → Create with Validation for the full reference implementation.

### System vs Validation Feedback

| Category | Trigger | Display Method | Clears When |
|----------|---------|----------------|-------------|
| Field validation error (auto) | `ValidationException` → `messages` state | `x-message` inline | Next successful submit (auto-cleared by `validateState`) |
| Field message | `gale()->messages(['field' => ...])` → `messages` state | `x-message` inline | `clearMessages()` or next response |
| Field error (manual, multi-string) | `gale()->errors(['field' => [...]])` → `errors` state | `x-message.from.errors` inline | `clearErrors()` or next response |
| Success notification | `gale()->messages(['_success' => ...])` | `x-message="_success"` or toast macro | `clearMessages()` |
| System/network error | HTTP 500, network failure | `Alpine.gale.onError()` handler | Manual dismiss |
| Auth expiry | HTTP 401 | Auth config handler | Re-authentication |
| Rate limit | HTTP 429 | Built-in retry mechanism | Auto-retry success |

### Anti-Patterns: Error Display

| Wrong | Why | Correct |
|-------|-----|---------|
| Single `<div>` showing all errors | Users cannot locate which field failed | Per-field `x-message="field"` adjacent to each input (auto-validation flow) |
| Using `x-message.from.errors="field"` for `$request->validate()` | Auto-validation writes to `messages` state — `.from.errors` reads from `errors` state, which is empty here. Result: nothing displays. | Use plain `x-message="field"` for `$request->validate()` / `$request->validateState()` |
| `configureErrors({ showToast: true })` for validation | That config handles system errors (500/network/checksum), not field validation | `x-message` (or `x-message.from.errors`) for validation; toast config for system errors |
| No error display elements in the form | Validation errors exist but are invisible to the user | Always include `x-message="field"` for every validated field |
| Manual error parsing from state | Reinventing the built-in directive | Use `x-message` (default — reads `messages` state) which renders automatically |
| Errors from form A leaking to form B | Shared state pollution | Each `x-data` scope has isolated state; keep forms in separate components |

---

## Loading State Decision Guide

Gale provides four loading mechanisms. Choosing the wrong one causes non-local loading
(clicking filter disables the Add button) or missing feedback (button grays out with no spinner).

### Decision Table

| Mechanism | Scope | Use When |
|-----------|-------|----------|
| `x-indicator="varName"` | **Element + children only** | Button-specific loading in multi-action components (the common case) |
| `$fetching` | Per-`x-data` scope | Single-action components ONLY (e.g., a form with one submit button) |
| `x-loading` / `x-loading.remove` | Per-`x-data` scope | Show/hide loading UI for the entire component |
| `$gale.loading` | Global (entire page) | Global loading bar or overlay |

### The Common Case: Multi-Action Components

Most real pages have MULTIPLE `$action` triggers in the same `x-data` scope (Add button,
Edit button, Delete button, Filter buttons). `$fetching` is the WRONG choice here because
it returns `true` when ANY action in the scope fires — clicking a filter button would
disable the Add button.

**Use `x-indicator` for local, button-specific loading:**

```html
<div x-data="{ adding: false, saving: false }">
    {{-- Add button — only shows spinner when THIS button's action fires --}}
    <button x-indicator="adding" @click="$action('/store', { include: [...] })" :disabled="adding">
        <span x-show="!adding">Add</span>
        <span x-show="adding"><svg class="animate-spin h-4 w-4">...</svg></span>
    </button>

    {{-- Filter button — no loading state needed, instant UI update --}}
    <button @click="activeFilter = 'all'; $action('/filter', { include: ['activeFilter'] })">All</button>

    {{-- Save button in edit mode — independent loading --}}
    <button x-indicator="saving" @click="$action.put('/items/1', { include: [...] })" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving"><svg class="animate-spin h-4 w-4">...</svg></span>
    </button>
</div>
```

`x-indicator` only reacts to `$action` calls triggered from the element itself or its
children (DOM containment check) — NOT from sibling elements in the same `x-data` scope.

### Anti-Pattern: Global $fetching in Multi-Action Components

```html
<!-- WRONG: $fetching is per-x-data-scope, not per-button -->
<div x-data="{ ... }">
    <button @click="$action('/filter')">Filter</button>
    <button @click="$action('/store')" :disabled="$fetching">
        <span x-show="!$fetching">Add</span>
        <span x-show="$fetching">spinner</span>
    </button>
</div>
<!-- Clicking Filter disables the Add button! -->
```

### Anti-Pattern: Disabled Button With No Spinner

```html
<!-- WRONG: No visual feedback that something is happening -->
<button :disabled="$fetching">Add</button>

<!-- CORRECT: Always swap text for spinner -->
<button x-indicator="adding" :disabled="adding">
    <span x-show="!adding">Add</span>
    <span x-show="adding"><svg class="animate-spin">...</svg></span>
</button>
```

---

## Performance Rules

### Payload Rules

Every `$action` call should justify what it sends. Default to sending nothing; add only what the server requires.

| Rule | Threshold | Implementation |
|------|-----------|---------------|
| Use `include` for forms with 3+ fields | Always list relevant fields | `{ include: ['name', 'email'] }` |
| Use `exclude` when most state is needed | Exclude known-large or UI-only keys | `{ exclude: ['preview', 'showModal'] }` |
| Use `delta` for dirty-tracked components | Enabled by default with dirty tracking | `{ delta: true }` (or omit — it is the default) |
| Use GET for read-only fetches | No state payload needed | `$action.get('/url')` |
| Never send computed/derived state | Wastes bandwidth, can cause conflicts | Use `include` to skip derived values |
| Never send UI-only state to server | Toggles, loading flags, modal states | Use `include` to whitelist server-relevant fields |

#### WRONG — Sends everything

```html
<div x-data="{ query: '', results: [], showFilters: false, sortBy: 'name', page: 1 }">
    <input @input="$action.get('/search')">
</div>
```

Sends `query`, `results` (potentially large array), `showFilters`, `sortBy`, `page` — the server
only needs `query`.

#### CORRECT — Sends only what the server needs

```html
<div x-data="{ query: '', results: [], showFilters: false, sortBy: 'name', page: 1 }">
    <input x-name="query" @input="$action.get('/search', {
        debounce: 300,
        include: ['query']
    })">
</div>
```

### Timing Rules

Prescriptive timing minimums. These are floors, not ceilings — adjust upward based on your use case.

| Feature | Minimum | Recommended | Notes |
|---------|---------|-------------|-------|
| `debounce` on text input | 200ms | 300ms | **Mandatory** for search, filter, and autocomplete inputs |
| `debounce` on autocomplete API | 300ms | 500ms | External APIs need more breathing room |
| `throttle` on scroll/resize handlers | 100ms | 200ms | Prevents flooding during continuous events |
| `throttle` on button clicks | — | 1000ms | Use `leading: true` for instant feedback |
| `x-interval` non-critical (dashboards) | 5000ms | 10000-30000ms | Status checks, analytics, non-urgent metrics |
| `x-interval` time-sensitive (auctions) | 2000ms | 3000ms | Only when push channels are impractical |
| Sub-2s real-time requirement | — | Push channels | `x-listen` with `gale()->push()` — not polling |

#### WRONG — No debounce, floods server

```html
<input @input="$action.get('/search')">
```

Every keystroke fires a request. Typing "laptop" sends 6 requests in ~500ms.

#### CORRECT — Debounced with include

```html
<input x-name="query" @input="$action.get('/search', {
    debounce: 300,
    include: ['query']
})">
```

One request fires 300ms after the user stops typing.

#### WRONG — 1-second polling for dashboard

```html
<div x-interval="1000" data-url="/dashboard/stats">
```

1 request/second/tab/user. 100 users = 100 requests/second for non-critical data.

#### CORRECT — Reasonable polling or push channel

```html
<!-- 10-second polling for non-critical -->
<div x-interval="10000" data-url="/dashboard/stats">

<!-- OR: push channel for real-time (preferred) -->
<div x-listen="dashboard-stats">
```

### Response Size Rules

Prefer the lightest sufficient response type. Ordered from cheapest to heaviest:

| What Changed? | Return Method | Cost | When to Use |
|---------------|---------------|------|-------------|
| A single value | `state('key', $val)` | Minimal | Data-only update, no HTML rendering |
| Multiple values | `state(['a' => 1, 'b' => 2])` | Minimal | Data-only, multiple properties |
| Named component data | `componentState('name', [...])` | Low | Cross-component update |
| Tagged components | `tagState('tag', [...])` | Low | Broadcast to component group |
| Confirmation only | `messages(['_success' => '...'])` | Minimal | No state change, just feedback |
| Single HTML fragment | `fragment('view', 'name', $data)` | Medium | List, table, or section re-render |
| Multiple fragments | `fragments([...])` | Medium | Multiple independent sections |
| Remove element | `remove('#selector')` | Minimal | Delete from DOM |
| Raw HTML patch | `html($html, $opts)` / `outer()` / `inner()` | Medium | Computed HTML, no Blade template |
| Full page section | `view('page', $data, [], web: true)` | **Heavy** | Use sparingly — direct URL + Gale dual mode |

**Rule of thumb**: If you are reaching for `view()` when `fragment()` would suffice, or `fragment()`
when `state()` would suffice — you are being too heavy. Descend the table until you find the
lightest option that fully satisfies the client's needs.

### Caching Rules

| Feature | When to Enable | When to Skip |
|---------|----------------|--------------|
| `gale()->etag()` | Read-heavy endpoints: lists, dashboards, reference data | Write endpoints, real-time data, user-specific views |
| `historyCache` | Multi-page SPA with back/forward navigation | Single-page apps, forms that should not cache |
| `prefetch` | Navigation-heavy apps (menus, pagination links) | Action-heavy workflows, form submissions |
| `Alpine.gale.clearCache('/url')` | After write operations that invalidate cached reads | Read-only flows |

```php
// ETag on a read-heavy endpoint — returns 304 if unchanged
return gale()
    ->etag()
    ->fragment('products.index', 'product-list', ['products' => $products]);
```

### Polling & Real-Time Decision Guide

| Update Frequency | Solution | Implementation |
|------------------|----------|----------------|
| Every 10s+ | `x-interval` | `<div x-interval="10000" data-url="/stats">` |
| Every 2-10s | `x-interval` (with care) | `<div x-interval="3000" data-url="/auction">` — monitor server load |
| Sub-2 seconds | Push channels | `<div x-listen="live-feed">` with `gale()->push('live-feed')` |
| Long-running operation | `gale()->stream()` | SSE streaming with progress updates |
| Multiple users seeing changes | Push channels | Only solution that delivers cross-user updates |

**Transition rule**: If you start with polling and find yourself wanting `x-interval < 2000`,
switch to push channels. Polling below 2 seconds is almost always worse than a push channel in
both latency and server cost.

### Anti-Patterns: Performance

| Wrong | Why | Correct |
|-------|-----|---------|
| No `debounce` on text input | Fires per keystroke, floods server | `{ debounce: 300 }` minimum |
| Sending full `x-data` for single-field action | Wastes bandwidth, may leak UI state | `{ include: ['field'] }` |
| `x-interval="1000"` for dashboard stats | 1 req/s/tab/user, scales terribly | `x-interval="10000"` or push channel |
| `gale()->view()` for a partial update | Renders entire Blade template, heavy response | `gale()->fragment()` or `gale()->state()` |
| Polling when push channels are available | Higher latency AND higher server cost | `x-listen` with `gale()->push()` |
| Missing `gale()->etag()` on read endpoints | Full response sent even when data is unchanged | Add `->etag()` for 304 responses |
| Large `x-data` objects with blobs/arrays | Every `$action` serializes and sends all of it | Audit state, use `include`, split components |
