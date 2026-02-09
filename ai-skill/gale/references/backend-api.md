# Gale Backend API Reference

Complete reference for the Laravel Gale PHP package. Read this file whenever implementing or editing controller methods.

## Table of Contents
- [gale() Helper](#gale-helper)
- [State Management](#state-management)
- [DOM Manipulation](#dom-manipulation)
- [Fragments](#fragments)
- [Redirects](#redirects)
- [Streaming](#streaming)
- [Navigation](#navigation)
- [Events and JavaScript](#events-and-javascript)
- [Component Targeting](#component-targeting)
- [Request Macros](#request-macros)
- [Blade Directives](#blade-directives)
- [Validation](#validation)
- [Conditional Execution](#conditional-execution)
- [Route Discovery](#route-discovery)
- [GaleResponse Complete API](#galeresponse-complete-api)

---

## gale() Helper

Returns a singleton `GaleResponse` instance with fluent API. All methods chain:

```php
return gale()
    ->state('count', 42)
    ->state('updated', now()->toISOString())
    ->messages(['success' => 'Saved!']);
```

The instance accumulates SSE events throughout the request, then streams them.

---

## State Management

### state($key, $value, $options)

Merge state into Alpine component via RFC 7386:

```php
// Single key-value
gale()->state('count', 42);

// Multiple values
gale()->state([
    'count' => 42,
    'user' => ['name' => 'John', 'email' => 'john@example.com'],
]);

// Nested update (merges with existing)
gale()->state('user.email', 'new@example.com');

// Only set if key doesn't exist in component state
gale()->state('defaults', ['theme' => 'dark'], ['onlyIfMissing' => true]);
```

**RFC 7386 Merge Behavior:**
| Server Sends | Current State | Result |
|---|---|---|
| `{ count: 5 }` | `{ count: 0, name: "John" }` | `{ count: 5, name: "John" }` |
| `{ name: null }` | `{ count: 0, name: "John" }` | `{ count: 0 }` |
| `{ user: { email: "new" } }` | `{ user: { name: "John", email: "old" } }` | `{ user: { name: "John", email: "new" } }` |

### forget($keys)

Remove state properties (sends `null` per RFC 7386):

```php
gale()->forget('tempData');
gale()->forget(['tempData', 'cache', 'draft']);
```

### messages($messages)

Set the `messages` state object (commonly for validation):

```php
gale()->messages([
    'email' => 'Invalid email address',
    'password' => 'Password too short',
]);
gale()->messages(['_success' => 'Profile saved!']);
```

**Message type prefixes for auto-styling:**
```php
gale()->messages([
    'email' => '[ERROR] Invalid email',
    'saved' => '[SUCCESS] Changes saved',
    'note' => '[WARNING] Session expiring',
    'info' => '[INFO] New features available',
]);
```

### clearMessages()

```php
gale()->clearMessages();
```

---

## DOM Manipulation

### view($view, $data, $options, $web)

Render Blade view and patch into DOM:

```php
// Basic: morphs by matching element IDs
gale()->view('partials.user-card', ['user' => $user]);

// With selector and mode
gale()->view('partials.item', ['item' => $item], [
    'selector' => '#items-list',
    'mode' => 'append',
]);

// As web fallback for non-Gale requests (CRITICAL for page loads)
gale()->view('dashboard', $data, web: true);
```

### html($html, $options, $web)

Patch raw HTML:

```php
gale()->html('<div id="content">New content</div>');
gale()->html('<li>New item</li>', ['selector' => '#list', 'mode' => 'append']);
```

### DOM Convenience Methods

All accept: `($selector, $html, $options = [])`

```php
// Server-driven state (replacement via initTree — re-initializes Alpine)
gale()->outer('#element', '<div id="element">Replaced</div>');
gale()->inner('#container', '<p>Inner content</p>');

// Client-preserved state (smart morphing via Alpine.morph — preserves state + focus)
gale()->outerMorph('#element', '<div id="element">Updated</div>');
gale()->innerMorph('#container', '<p>Morphed content</p>');

// Insertion modes (new elements get Alpine initialized)
gale()->append('#list', '<li>Last</li>');
gale()->prepend('#list', '<li>First</li>');
gale()->before('#target', '<div>Before</div>');
gale()->after('#target', '<div>After</div>');

// Removal
gale()->remove('.deprecated');
gale()->remove("#item-{$id}");
```

### Viewport Options (third parameter on all DOM methods)

```php
gale()->append('#chat', $html, ['scroll' => 'bottom']);  // Auto-scroll container
gale()->outer('#form', $html, ['show' => 'top']);         // Scroll element into viewport
gale()->outerMorph('#list', $html, ['focusScroll' => true]); // Maintain focus position
gale()->append('#list', $html, ['show' => 'bottom']);     // Show element at bottom of viewport
```

### Complete DOM Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `selector` | string | `null` | CSS selector for target element |
| `mode` | string | `'outer'` | DOM patching mode |
| `useViewTransition` | bool | `false` | Enable View Transitions API |
| `settle` | int | `0` | Delay (ms) before patching (for CSS transitions) |
| `limit` | int | `null` | Max elements to patch |
| `scroll` | string | `null` | Auto-scroll: `'top'` or `'bottom'` |
| `show` | string | `null` | Scroll into viewport: `'top'` or `'bottom'` |
| `focusScroll` | bool | `false` | Maintain focus scroll position |

### When to Use Which Mode

| Method | Mode | Use When |
|--------|------|----------|
| `outer()` | Replace entire element | Full refresh, new state from server |
| `inner()` | Replace children only | Update content, keep wrapper |
| `outerMorph()` | Smart-patch element | User interacting (typing, focus, editing) |
| `innerMorph()` | Smart-patch children | Children have their own Alpine state |
| `append()` | Add to end | New item in list |
| `prepend()` | Add to start | New item at top |
| `before()` / `after()` | Insert adjacent | Precise positioning |
| `remove()` | Delete element | Item deletion |

**Backward Compatibility Aliases:**
| Alias | Resolves To |
|-------|-------------|
| `replace()` | `outer()` |
| `morph()` | `outerMorph()` |
| `delete()` | `remove()` |

Frontend also accepts mode string aliases: `outerHTML`→`outer`, `innerHTML`→`inner`, `beforebegin`→`before`, `afterbegin`→`prepend`, `beforeend`→`append`, `afterend`→`after`, `morph_inner`→`innerMorph`

---

## Fragments

### Defining Fragments in Blade

```blade
@fragment('stats')
<div id="stats">
    <span>Users: {{ $userCount }}</span>
</div>
@endfragment
```

**CRITICAL: Fragment root elements MUST have IDs** for Gale to match and patch them.

### fragment($view, $fragment, $data, $options)

```php
gale()->fragment('dashboard', 'stats', ['userCount' => User::count()]);

// With explicit selector/mode
gale()->fragment('dashboard', 'stats', $data, [
    'selector' => '#stats-panel',
    'mode' => 'outerMorph',
]);
```

### fragments($array)

Multiple fragments in one response:

```php
gale()->fragments([
    ['view' => 'dashboard', 'fragment' => 'stats', 'data' => $statsData],
    ['view' => 'dashboard', 'fragment' => 'orders', 'data' => $ordersData],
]);
```

### Chained Fragments

```php
return gale()
    ->fragment('catalog.index', 'sidebar', $data)
    ->fragment('catalog.index', 'products', $data);
```

### Fragment with Static Name + Dynamic Selector

For reusable partials (e.g., Kanban columns using same partial for different columns):

```php
gale()->fragment('board.partials.cards-list', 'cards.list', [
    'cards' => $cards,
    'status' => $data['status'],
], [
    'selector' => "#cards-{$data['status']}",
    'mode' => 'outer',
    'settle' => 100,
]);
```

Fragment names are STATIC (identify the part), content is DYNAMIC ($data), selector specifies WHERE.

---

## Redirects

### redirect($url)

Full-page browser redirects with session flash support:

```php
return gale()->redirect('/dashboard');
return gale()->redirect('/dashboard')->with('message', 'Welcome!');
return gale()->redirect('/register')->withErrors($validator)->withInput();
```

### Redirect Chain Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `with($key, $value)` | `with(string\|array $key, mixed $value = null)` | Flash data |
| `withInput($input)` | `withInput(?array $input = null)` | Flash form input |
| `withErrors($errors)` | `withErrors(mixed $errors)` | Flash validation errors |
| `away($url)` | `away(string $url)` | Redirect to external URL (different domain) |
| `back($fallback)` | `back(string $fallback = '/')` | Go back |
| `backOr($route, $params)` | `backOr(string $route, array $params = [])` | Back with route fallback |
| `refresh($query, $fragment)` | `refresh(bool $query = true, bool $fragment = false)` | Refresh page |
| `home()` | `home()` | Go to root |
| `route($name, $params)` | `route(string $name, array $params = [], bool $absolute = true)` | Named route |
| `intended($default)` | `intended(string $default = '/')` | Auth intended |
| `forceReload($bypass)` | `forceReload(bool $bypass = false)` | Hard reload |

**IMPORTANT:** `redirect()` requires an initial URL argument. Chain methods override it:
```php
return gale()->redirect('/')->back('/fallback');
return gale()->redirect('/')->route('dashboard');
```

### reload()

Simple page reload:
```php
return gale()->reload(); // triggers window.location.reload()
```

---

## Streaming

For long-running operations — events sent immediately as they're added:

```php
return gale()->stream(function ($gale) {
    $users = User::cursor();
    $total = User::count();
    $processed = 0;

    foreach ($users as $user) {
        $user->processExpensiveOperation();
        $processed++;

        // Sent immediately to browser
        $gale->state('progress', [
            'current' => $processed,
            'total' => $total,
            'percent' => round(($processed / $total) * 100),
        ]);
    }

    $gale->state('complete', true);
    $gale->messages(['_success' => "Processed {$total} users"]);
});
```

**Key difference:** In normal mode, events batch and send together. In streaming mode, each `$gale->method()` call sends an SSE event immediately.

**Streaming features:**
- `dd()` and `dump()` output captured and displayed
- Exceptions rendered with stack traces
- **Redirects auto-intercepted:** Standard Laravel `redirect()` calls inside `stream()` are automatically converted to SSE redirect events by `GaleStreamRedirector`. You can use `redirect('/dashboard')` normally inside the closure — it will trigger a client-side navigation instead of an HTTP redirect.

---

## Navigation

### Backend-Triggered Navigation

```php
gale()->navigate('/users');                          // Push to history
gale()->navigate('/users', 'main-content');          // With navigate key
gale()->navigateWith('/users', 'main', merge: true); // Explicit merge control
gale()->navigateMerge(['page' => 2]);                // Merge query params
gale()->navigateMerge(['sort' => 'name'], 'table');   // Merge with key
gale()->navigateClean('/users');                      // No param merging
gale()->navigateOnly('/search', ['q', 'category']);   // Keep only these params
gale()->navigateExcept('/search', ['page', 'cursor']); // Remove these params
gale()->navigateReplace('/users');                    // Replace history entry
gale()->updateQueries(['sort' => 'name']);            // Update query params in place
gale()->clearQueries(['filter', 'search']);           // Remove query params
gale()->reload();                                     // Full page reload
```

---

## Events and JavaScript

### dispatch($event, $data, $options)

```php
gale()->dispatch('user-updated', ['id' => $user->id]);

// Targeted to specific elements
gale()->dispatch('refresh', ['section' => 'cart'], ['selector' => '.shopping-cart']);

// With event options
gale()->dispatch('notification', $data, [
    'bubbles' => true,
    'cancelable' => true,
    'composed' => false,
]);
```

Listen in Alpine: `@user-updated.window="handleUpdate($event.detail)"`

### js($code, $options)

```php
gale()->js('console.log("Hello from server")');
gale()->js('myApp.showNotification("Saved!")', ['autoRemove' => true]);
```

---

## Component Targeting

Target specific named Alpine components (registered with `x-component="name"`):

### componentState($name, $state, $options)

```php
gale()->componentState('cart', ['items' => $items, 'total' => $total]);
gale()->componentState('cart', ['currency' => 'USD'], ['onlyIfMissing' => true]);
```

### componentMethod($name, $method, $args)

```php
gale()->componentMethod('cart', 'recalculate');
gale()->componentMethod('calculator', 'setValues', [10, 20, 30]);
```

**Key insight:** `componentState()` and `componentMethod()` target ANY named component on the page, not just the one that made the request. Perfect for dashboards where one polling request updates multiple widgets.

---

## Request Macros

| Macro | Signature | Description |
|-------|-----------|-------------|
| `isGale()` | `isGale()` | Check if Gale request |
| `state($key, $default)` | `state(?string $key = null, mixed $default = null)` | Get Alpine state |
| `isGaleNavigate($key)` | `isGaleNavigate(string\|array\|null $key = null)` | Check navigate request |
| `galeNavigateKey()` | `galeNavigateKey()` | Get navigate key string |
| `galeNavigateKeys()` | `galeNavigateKeys()` | Get navigate keys array |
| `validateState($rules, $messages, $attrs)` | `validateState(array $rules, ...)` | Validate state with SSE error response |

### state() Usage

```php
$state = $request->state();                    // All state
$count = $request->state('count', 0);          // Specific key with default
$email = $request->state('user.email');         // Nested dot notation
```

### isGaleNavigate() Usage

```php
if ($request->isGaleNavigate()) { /* any navigate */ }
if ($request->isGaleNavigate('sidebar')) { /* specific key */ }
if ($request->isGaleNavigate(['main', 'sidebar'])) { /* any of these keys */ }
```

---

## Blade Directives

| Directive | Purpose |
|-----------|---------|
| `@gale` | Include CSS + JS bundle + CSRF meta (in `<head>`) |
| `@galeState($data)` | Inject initial state as `window.galeState` for Alpine hydration |
| `@fragment('name')` / `@endfragment` | Define extractable fragment |
| `@ifgale` / `@else` / `@endifgale` | Conditional rendering by request type |

### @ifgale Usage

Render different content for Gale requests vs full page loads:

```blade
@ifgale
    {{-- Only rendered during Gale SSE requests --}}
    <div class="partial-update">Updated content</div>
@else
    {{-- Only rendered during full page loads --}}
    <div class="full-page">
        @include('layouts.header')
        <div class="partial-update">Initial content</div>
        @include('layouts.footer')
    </div>
@endifgale
```

### @galeState Usage

Inject server data as initial Alpine state via a global JS variable:

```blade
<head>
    @gale
    @galeState(['user' => $user->toArray(), 'settings' => $settings])
</head>
<body>
    <div x-data="{ user: window.galeState?.user, settings: window.galeState?.settings }">
        <span x-text="user.name"></span>
    </div>
</body>
```

Outputs: `<script>window.galeState = {"user":{...},"settings":{...}};</script>`

---

## Validation

### validateState() (Recommended)

```php
$validated = $request->validateState([
    'name' => 'required|min:2|max:255',
    'email' => 'required|email|unique:users',
]);
// On failure: throws GaleMessageException → SSE error response with messages
// On success: returns validated data, clears messages for validated fields
```

### GaleMessageException (Custom Flows)

```php
use Dancycodes\Gale\Exceptions\GaleMessageException;

$validator = Validator::make($request->state(), ['email' => 'required|email']);
if ($validator->fails()) {
    throw new GaleMessageException($validator);
}
```

### Manual Message Handling

```php
if ($validator->fails()) {
    return gale()->messages($validator->errors()->toArray());
}
return gale()->clearMessages();
```

**Note on file uploads:** Files come through FormData, not Alpine state. Use standard `$request->validate()` for file validation, not `validateState()`.

---

## Conditional Execution

```php
gale()->when($condition, fn($g) => $g->state('visible', true));
gale()->when($user->isAdmin(), fn($g) => $g->state('role', 'admin'), fn($g) => $g->state('role', 'user'));
gale()->unless($user->isGuest(), fn($g) => $g->state('user', $user->toArray()));
gale()->whenGale(fn($g) => $g->state('partial', true), fn($g) => $g->web(view('full')));
gale()->whenNotGale(fn() => view('full-page'));
gale()->whenGaleNavigate('sidebar', fn($g) => $g->fragment('layout', 'sidebar', $data));
```

### web($response)

Fallback for non-Gale requests:
```php
return gale()->state('data', $data)->web(view('page', compact('data')));
```

---

## Route Discovery

### Enable

```php
// config/gale.php
'route_discovery' => [
    'enabled' => true,
    'discover_controllers_in_directory' => [app_path('Http/Controllers')],
    'discover_views_in_directory' => ['docs' => resource_path('views/docs')],
    'pending_route_transformers' => [
        ...Dancycodes\Gale\Routing\Config::defaultRouteTransformers(),
    ],
],
```

### Attributes

```php
use Dancycodes\Gale\Routing\Attributes\{Route, Prefix, Where, DoNotDiscover, WithTrashed};

#[Prefix('/admin')]
#[Route(middleware: 'auth')]
class UserController extends Controller
{
    #[Route('GET', '/users', name: 'admin.users.index')]
    public function index() { }

    #[Route('GET', '/users/{id}', name: 'admin.users.show')]
    #[Where('id', Where::NUMERIC)]
    #[WithTrashed]
    public function show($id) { }

    #[Route(['GET', 'POST'], '/users/search')]
    public function search() { }

    #[DoNotDiscover]
    public function internalMethod() { }
}
```

| Attribute | Parameters | Description |
|-----------|------------|-------------|
| `#[Route]` | `methods, uri, name, middleware, domain, withTrashed` | Define route |
| `#[Prefix]` | `prefix` | URL prefix for class |
| `#[Where]` | `param, pattern` | Parameter constraint |
| `#[DoNotDiscover]` | — | Exclude from discovery |
| `#[WithTrashed]` | — | Include soft-deleted models |

Where constants: `Where::ALPHA`, `Where::NUMERIC`, `Where::ALPHANUMERIC`, `Where::UUID`

---

## GaleResponse Complete API

| Method | Signature | Description |
|--------|-----------|-------------|
| `state` | `state(string\|array $key, mixed $value = null, array $options = [])` | Set state |
| `forget` | `forget(string\|array $keys)` | Remove state keys |
| `messages` | `messages(array $messages)` | Set messages |
| `clearMessages` | `clearMessages()` | Clear messages |
| `view` | `view(string $view, array $data = [], array $options = [], bool $web = false)` | Render view |
| `fragment` | `fragment(string $view, string $fragment, array $data = [], array $options = [])` | Render fragment |
| `fragments` | `fragments(array $fragments)` | Render multiple fragments |
| `html` | `html(string $html, array $options = [], bool $web = false)` | Patch raw HTML |
| `outer` | `outer(string $selector, string $html, array $options = [])` | Replace element |
| `inner` | `inner(string $selector, string $html, array $options = [])` | Replace inner |
| `outerMorph` | `outerMorph(string $selector, string $html, array $options = [])` | Smart-patch element |
| `innerMorph` | `innerMorph(string $selector, string $html, array $options = [])` | Smart-patch children |
| `append` | `append(string $selector, string $html, array $options = [])` | Append |
| `prepend` | `prepend(string $selector, string $html, array $options = [])` | Prepend |
| `before` | `before(string $selector, string $html, array $options = [])` | Insert before |
| `after` | `after(string $selector, string $html, array $options = [])` | Insert after |
| `remove` | `remove(string $selector)` | Remove element |
| `morph` | alias for `outerMorph` | Backward compat |
| `replace` | alias for `outer` | Backward compat |
| `delete` | alias for `remove` | Backward compat |
| `redirect` | `redirect(string $url)` | Client redirect |
| `reload` | `reload()` | Page reload |
| `stream` | `stream(Closure $callback)` | Streaming mode |
| `navigate` | `navigate(string\|array $url, string $key = 'true', array $options = [])` | Navigate |
| `navigateWith` | `navigateWith(string\|array $url, string $key = 'true', bool $merge = false, array $options = [])` | Navigate with merge |
| `navigateMerge` | `navigateMerge(string\|array $url, string $key = 'true', array $options = [])` | Merge query params |
| `navigateClean` | `navigateClean(string\|array $url, string $key = 'true', array $options = [])` | No merge navigate |
| `navigateOnly` | `navigateOnly(string\|array $url, array $only, string $key = 'true')` | Keep only params |
| `navigateExcept` | `navigateExcept(string\|array $url, array $except, string $key = 'true')` | Remove params |
| `navigateReplace` | `navigateReplace(string\|array $url, string $key = 'true', array $options = [])` | Replace history |
| `updateQueries` | `updateQueries(array $queries, string $key = 'filters', bool $merge = true)` | Update queries |
| `clearQueries` | `clearQueries(array $paramNames, string $key = 'clear')` | Clear queries |
| `dispatch` | `dispatch(string $event, array $data = [], array $options = [])` | Dispatch event |
| `js` | `js(string $code, array $options = [])` | Execute JS |
| `componentState` | `componentState(string $name, array $state, array $options = [])` | Update component state |
| `componentMethod` | `componentMethod(string $name, string $method, array $args = [])` | Invoke component method |
| `when` | `when(mixed $condition, callable $true, ?callable $false = null)` | Conditional |
| `unless` | `unless(mixed $condition, callable $callback)` | Inverse conditional |
| `whenGale` | `whenGale(callable $gale, ?callable $web = null)` | Gale request check |
| `whenNotGale` | `whenNotGale(callable $callback)` | Non-Gale check |
| `whenGaleNavigate` | `whenGaleNavigate(?string $key, callable $callback)` | Navigate check |
| `web` | `web(mixed $response)` | Set web fallback |
| `withEventId` | `withEventId(string $id)` | Set SSE event ID for client replay support |
| `withRetry` | `withRetry(int $ms)` | Set SSE retry interval (ms) for auto-reconnection |
| `reset` | `reset()` | Clear all accumulated events |

### withEventId / withRetry

SSE-level configuration for connection resilience:

```php
// Set event ID so clients can resume from last received event
gale()->withEventId('evt-' . time())->state('data', $data);

// Tell the client to retry after 5 seconds if connection drops
gale()->withRetry(5000)->state('data', $data);
```
