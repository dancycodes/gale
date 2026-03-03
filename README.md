# Laravel Gale

[![CI](https://github.com/dancycodes/gale/actions/workflows/ci.yml/badge.svg)](https://github.com/dancycodes/gale/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/dancycodes/gale?style=flat-square&label=packagist)](https://packagist.org/packages/dancycodes/gale)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-11%2B%20%7C%2012%2B-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Alpine.js](https://img.shields.io/badge/Alpine.js-3.x-8BC0D0?style=flat-square&logo=alpine.js)](https://alpinejs.dev)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

**Laravel Gale** is a server-driven reactive framework for Laravel. It uses standard HTTP responses (JSON) by default to deliver real-time UI updates to Alpine.js components directly from your Blade templates -- no JavaScript framework, no build complexity, no API layer. For long-running operations or real-time streaming, Server-Sent Events (SSE) is available as an explicit opt-in.

**GALE** = **G**ouater + **A**nais + **L**oic + **E**unice (Founders' initials)

This README documents both:

- **Laravel Gale** -- The PHP backend package (`dancycodes/gale`)
- **Alpine Gale** -- The Alpine.js frontend plugin (bundled with Laravel Gale)

**Full documentation:** [`docs/README.md`](docs/README.md) | [Getting Started](docs/getting-started.md) | [Backend API](docs/backend-api.md) | [Frontend API](docs/frontend-api.md)

---

## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Installation](#installation)
- [How It Works](#how-it-works)
  - [Dual-Mode Architecture](#dual-mode-architecture)
  - [Request/Response Flow](#requestresponse-flow)
  - [RFC 7386 JSON Merge Patch](#rfc-7386-json-merge-patch)
- [Mode Configuration](#mode-configuration)
  - [HTTP vs SSE Comparison](#http-vs-sse-comparison)
  - [Choosing a Mode](#choosing-a-mode)
  - [Configuring the Default Mode](#configuring-the-default-mode)
  - [Per-Request Mode Override](#per-request-mode-override)
- [Backend: Laravel Gale](#backend-laravel-gale)
  - [The gale() Helper](#the-gale-helper)
  - [State Management](#state-management)
  - [DOM Manipulation](#dom-manipulation)
  - [Blade Fragments](#blade-fragments)
  - [Redirects](#redirects)
  - [Navigation](#navigation)
  - [Events and JavaScript](#events-and-javascript)
  - [Component Targeting](#component-targeting)
  - [Streaming Mode (SSE)](#streaming-mode-sse)
  - [Request Macros](#request-macros)
  - [Blade Directives](#blade-directives)
  - [Validation](#validation)
  - [Conditional Execution](#conditional-execution)
  - [Route Discovery](#route-discovery)
- [Frontend: Alpine Gale](#frontend-alpine-gale)
  - [The $action Magic](#the-action-magic)
  - [State Synchronization (x-sync)](#state-synchronization-x-sync)
  - [CSRF Protection](#csrf-protection)
  - [Global State ($gale)](#global-state-gale)
  - [Element State ($fetching)](#element-state-fetching)
  - [Loading Directives](#loading-directives)
  - [Navigation](#navigation-1)
  - [Component Registry](#component-registry)
  - [Form Binding (x-name)](#form-binding-x-name)
  - [File Uploads](#file-uploads)
  - [Message Display](#message-display)
  - [Polling (x-interval)](#polling-x-interval)
  - [Confirmation Dialogs](#confirmation-dialogs)
- [Configuration Reference](#configuration-reference)
- [Advanced Topics](#advanced-topics)
  - [DOM Patching Modes](#dom-patching-modes)
  - [View Transitions API](#view-transitions-api)
  - [SSE Protocol Specification](#sse-protocol-specification)
  - [State Serialization](#state-serialization)
- [API Reference](#api-reference)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

---

## Requirements

- **PHP** 8.2 or higher
- **Laravel** 11 or 12
- **Alpine.js** 3.x (bundled -- no separate install needed)

No Node.js or npm required for basic usage. `@gale` serves the pre-built JS bundle from `public/vendor/gale/`.

---

## Quick Start

A complete reactive counter in under 20 lines:

**routes/web.php:**

```php
Route::get('/counter', fn() => gale()->view('counter', ['count' => 0], web: true));

Route::post('/increment', function () {
    return gale()->state('count', request()->state('count', 0) + 1);
});
```

**resources/views/counter.blade.php:**

```html
<!DOCTYPE html>
<html>
    <head>
        @gale
    </head>
    <body>
        <div x-data="{ count: {{ $count }} }" x-sync>
            <span x-text="count"></span>
            <button @click="$action('/increment')">+</button>
        </div>
    </body>
</html>
```

Click the button. The count updates via HTTP. No page reload, no JavaScript written.

---

## Installation

```bash
composer require dancycodes/gale
php artisan gale:install
```

Add `@gale` to your layout's `<head>`:

```html
<head>
    @gale
</head>
```

**That's it.** The `@gale` directive outputs:

- CSRF meta tag
- Alpine.js (v3) with the Morph plugin
- The Alpine Gale plugin
- Debug panel (when `APP_DEBUG=true`)

### Existing Alpine.js Projects

Gale bundles Alpine.js (v3) with the Morph plugin. If you already have Alpine.js installed, **remove it** to prevent conflicts:

```html
<!-- Remove any CDN script -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
```

```javascript
// Remove these lines from resources/js/app.js:
import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();
```

Then use `@gale` instead -- it handles everything.

### Using Additional Alpine Plugins

Gale exposes `window.Alpine`, so other plugins work normally:

```html
<head>
    @gale
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.plugin(yourPlugin);
        });
    </script>
</head>
```

### Optional: Publish Configuration

```bash
php artisan vendor:publish --tag=gale-config
```

---

## How It Works

### Dual-Mode Architecture

Gale operates in two modes with an identical developer API:

- **HTTP mode (default)**: Responses are standard JSON payloads (`Content-Type: application/json`). Simple, works with all hosting environments, CDNs, and load balancers. Suitable for the vast majority of interactions.

- **SSE mode (opt-in)**: Responses are streamed as Server-Sent Events (`Content-Type: text/event-stream`). Required for long-running operations, real-time progress, or live streaming. Activated per-request with `{ sse: true }` or globally via configuration.

The backend API is identical in both modes -- the same `gale()->state()`, `gale()->view()`, and all other methods work regardless of transport. The frontend automatically detects the response type and processes accordingly.

### Request/Response Flow

```
                           BROWSER
    +----------------------------------------------------+
    | Alpine.js Component (x-data)                       |
    |   State: { count: 0, user: {...} }                 |
    +----------------------------------------------------+
                           |
                           | @click="$action('/increment')"
                           v
    +----------------------------------------------------+
    | HTTP Request                                       |
    |   Headers: Gale-Request: true, X-CSRF-TOKEN        |
    |   Body: { count: 0, user: {...} }                  |
    +----------------------------------------------------+
                           |
                           v
                      LARAVEL SERVER
    +----------------------------------------------------+
    | Controller                                         |
    |   $count = request()->state('count');               |
    |   return gale()->state('count', $count + 1);       |
    +----------------------------------------------------+
                           |
              +------------+------------+
              |                         |
         HTTP Mode                 SSE Mode
    +------------------+    +--------------------+
    | application/json |    | text/event-stream  |
    | { events: [...] }|    | event: gale-patch  |
    +------------------+    +--------------------+
              |                         |
              +------------+------------+
                           |
                           v
    +----------------------------------------------------+
    | Alpine.js merges state via RFC 7386                 |
    |   State: { count: 1, user: {...} }                 |
    |   UI reactively updates                            |
    +----------------------------------------------------+
```

### RFC 7386 JSON Merge Patch

State updates follow [RFC 7386](https://tools.ietf.org/html/rfc7386):

| Server Sends | Current State | Result |
|---|---|---|
| `{ count: 5 }` | `{ count: 0, name: "John" }` | `{ count: 5, name: "John" }` |
| `{ name: null }` | `{ count: 0, name: "John" }` | `{ count: 0 }` |
| `{ user: { email: "new" } }` | `{ user: { name: "John", email: "old" } }` | `{ user: { name: "John", email: "new" } }` |

- **Values merge**: Sent values replace existing values
- **Null deletes**: Sending `null` removes the property
- **Deep merge**: Nested objects merge recursively

---

## Mode Configuration

### HTTP vs SSE Comparison

| Feature | HTTP Mode (Default) | SSE Mode (Opt-in) |
|---|---|---|
| **Transport** | Standard JSON over HTTP | Server-Sent Events stream |
| **Response type** | `application/json` | `text/event-stream` |
| **Hosting** | Works everywhere | Requires SSE-compatible hosting |
| **CDN / Load Balancer** | Fully compatible | May require configuration |
| **Serverless** | Fully compatible | Not recommended |
| **Latency** | Single response | Streaming (events sent as they occur) |
| **Progress updates** | Not supported | Real-time progress |
| **Long-running ops** | Subject to timeout | Stream indefinitely |
| **Connection overhead** | New connection per request | Held open during stream |
| **Error handling** | Standard HTTP status codes | Inline error events |
| **Retry** | Automatic with backoff | Built-in SSE reconnection |
| **Best for** | Forms, CRUD, navigation, most interactions | Dashboards, progress bars, chat, AI streaming |

### Choosing a Mode

**Use HTTP mode (default) when:**
- Building forms, CRUD operations, or standard interactions
- Deploying to serverless, CDN-fronted, or shared hosting
- You want the simplest possible setup
- Response times are fast (< 1 second)

**Use SSE mode when:**
- You need real-time progress updates (file processing, imports)
- Building live dashboards or chat interfaces
- Streaming AI-generated content
- Operations take more than a few seconds

### Configuring the Default Mode

The default mode can be set at three levels (highest priority first):

**1. Request header** (per-request, set automatically by frontend):

```
Gale-Mode: sse
```

**2. Environment variable** (application-wide):

```env
GALE_MODE=http
```

**3. Config file** (`config/gale.php`):

```php
return [
    'mode' => env('GALE_MODE', 'http'),
    // ...
];
```

### Per-Request Mode Override

On the frontend, override per request:

```html
<!-- Force SSE for this action -->
<button @click="$action('/process', { sse: true })">Process</button>

<!-- Force HTTP for this action -->
<button @click="$action('/save', { http: true })">Save</button>
```

Or use `gale()->stream()` on the backend, which always uses SSE regardless of configuration:

```php
return gale()->stream(function ($gale) {
    // This always streams via SSE
    $gale->state('progress', 50);
});
```

---

## Backend: Laravel Gale

### The gale() Helper

Returns a request-scoped `GaleResponse` instance with a fluent API:

```php
return gale()
    ->state('count', 42)
    ->state('updated', now()->toISOString())
    ->messages(['_success' => 'Saved!']);
```

The same instance accumulates events throughout the request. In HTTP mode, they are serialized as a single JSON response. In SSE mode, they are streamed as individual events.

### State Management

#### state()

Set state values to merge into the Alpine component:

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

#### patchState()

Alias for `state()` when passing an array -- preferred for explicit multi-key patches:

```php
gale()->patchState(['count' => 1, 'updated' => true]);
```

#### forget()

Remove state properties (sends `null` per RFC 7386):

```php
gale()->forget('tempData');
gale()->forget(['tempData', 'cache', 'draft']);
```

#### messages()

Set the `messages` state object (used for validation errors and notifications):

```php
gale()->messages([
    'email' => 'Invalid email address',
    'password' => 'Password too short',
]);

// Success pattern
gale()->messages(['_success' => 'Profile saved!']);
```

#### clearMessages()

Clear all messages:

```php
gale()->clearMessages();
```

#### flash()

Deliver flash data to both the session and the `_flash` Alpine state key in one call:

```php
gale()->flash('status', 'Your account has been updated.');
gale()->flash(['status' => 'ok', 'message' => 'Saved!']);
```

In the view, display flash reactively:

```html
<div x-data="{ _flash: {} }" x-sync="['_flash']">
    <div x-show="_flash.status" x-text="_flash.status" class="alert"></div>
</div>
```

### DOM Manipulation

#### view()

Render a Blade view and patch it into the DOM:

```php
// Morph by matching element IDs
gale()->view('partials.user-card', ['user' => $user]);

// With selector and mode
gale()->view('partials.item', ['item' => $item], [
    'selector' => '#items-list',
    'mode' => 'append',
]);

// As web fallback for non-Gale requests
gale()->view('dashboard', $data, web: true);
```

#### html()

Patch raw HTML into the DOM:

```php
gale()->html('<div id="content">New content</div>');

gale()->html('<li>New item</li>', [
    'selector' => '#list',
    'mode' => 'append',
]);
```

#### DOM Convenience Methods

```php
// Server-driven state (replacement via initTree)
gale()->outer('#element', '<div id="element">Replaced</div>');
gale()->inner('#container', '<p>Inner content</p>');

// Client-preserved state (smart morphing via Alpine.morph)
gale()->outerMorph('#element', '<div id="element">Updated</div>');
gale()->innerMorph('#container', '<p>Morphed content</p>');

// Insertion modes
gale()->append('#list', '<li>Last</li>');
gale()->prepend('#list', '<li>First</li>');
gale()->before('#target', '<div>Before</div>');
gale()->after('#target', '<div>After</div>');

// Removal
gale()->remove('.deprecated');

// Viewport modifiers (optional third parameter)
gale()->append('#chat', $html, ['scroll' => 'bottom']);
gale()->outer('#form', $html, ['show' => 'top']);
```

| Method | Mode | State Handling |
|---|---|---|
| `outer($selector, $html, $opts)` | `outer` | Server-driven |
| `inner($selector, $html, $opts)` | `inner` | Server-driven |
| `outerMorph($selector, $html, $opts)` | `outerMorph` | Client-preserved |
| `innerMorph($selector, $html, $opts)` | `innerMorph` | Client-preserved |
| `append($selector, $html, $opts)` | `append` | New elements init |
| `prepend($selector, $html, $opts)` | `prepend` | New elements init |
| `before($selector, $html, $opts)` | `before` | New elements init |
| `after($selector, $html, $opts)` | `after` | New elements init |
| `remove($selector)` | `remove` | Cleanup |

**View options:**

| Option | Type | Default | Description |
|---|---|---|---|
| `selector` | string | `null` | CSS selector for target element |
| `mode` | string | `'outer'` | DOM patching mode |
| `useViewTransition` | bool | `false` | Enable View Transitions API |
| `settle` | int | `0` | Delay (ms) before patching |
| `scroll` | string | `null` | Auto-scroll: `'top'` or `'bottom'` |
| `show` | string | `null` | Scroll into viewport: `'top'` or `'bottom'` |
| `focusScroll` | bool | `false` | Maintain focus scroll position |

### Blade Fragments

Extract and render specific sections from Blade views without rendering the entire template.

**Define fragments in Blade:**

```blade
<div id="todo-list">
    @fragment('todo-items')
    @foreach($todos as $todo)
        <div id="todo-{{ $todo->id }}">{{ $todo->title }}</div>
    @endforeach
    @endfragment
</div>
```

**Render fragments:**

```php
// Single fragment
gale()->fragment('todos', 'todo-items', ['todos' => $todos]);

// With options
gale()->fragment('todos', 'todo-items', ['todos' => $todos], [
    'selector' => '#todo-list',
    'mode' => 'morph',
]);

// Multiple fragments at once
gale()->fragments([
    ['view' => 'dashboard', 'fragment' => 'stats', 'data' => $statsData],
    ['view' => 'dashboard', 'fragment' => 'recent-orders', 'data' => $ordersData],
]);
```

### Redirects

Full-page browser redirects with session flash support:

```php
return gale()->redirect('/dashboard');

return gale()->redirect('/dashboard')
    ->with('message', 'Welcome back!')
    ->with(['key' => 'value']);

return gale()->redirect('/register')
    ->withErrors($validator)
    ->withInput();
```

| Method | Description |
|---|---|
| `with($key, $value)` | Flash data to session |
| `withInput($input)` | Flash form input for repopulation |
| `withErrors($errors)` | Flash validation errors |
| `back($fallback)` | Redirect to previous URL with fallback |
| `backOr($route, $params)` | Back with named route fallback |
| `refresh($query, $fragment)` | Reload current page |
| `home()` | Redirect to root URL |
| `route($name, $params)` | Redirect to named route |
| `intended($default)` | Redirect to auth intended URL |
| `forceReload($bypass)` | Hard reload via JavaScript |

### Navigation

Trigger SPA navigation from the backend:

```php
gale()->navigate('/users');
gale()->navigate('/users', 'main-content');

// Merge query params
gale()->navigateMerge(['page' => 2]);

// Replace history instead of push
gale()->navigateReplace('/users');

// Update query parameters in place
gale()->updateQueries(['sort' => 'name', 'order' => 'asc']);

// Clear specific query parameters
gale()->clearQueries(['filter', 'search']);

// Full page reload
gale()->reload();
```

### Events and JavaScript

#### dispatch()

Dispatch custom DOM events from the server:

```php
gale()->dispatch('user-updated', ['id' => $user->id]);

// Targeted to a specific element
gale()->dispatch('refresh', ['section' => 'cart'], [
    'selector' => '.shopping-cart',
]);
```

Listen in Alpine:

```html
<div x-data @user-updated.window="handleUpdate($event.detail)"></div>
```

#### js()

Execute JavaScript in the browser:

```php
gale()->js('console.log("Hello from server")');
gale()->js('myApp.showNotification("Saved!")');
```

#### debug()

Send debug data to the Gale debug panel (dev mode only):

```php
gale()->debug('payload', $request->all());
gale()->debug(['user' => $user, 'state' => $state]);
```

### Component Targeting

Target specific named Alpine components from the backend:

```php
// Update a component's state
gale()->componentState('cart', [
    'items' => $cartItems,
    'total' => $total,
]);

// Invoke a method on a named component
gale()->componentMethod('cart', 'recalculate');
gale()->componentMethod('calculator', 'setValues', [10, 20, 30]);
```

### Streaming Mode (SSE)

For long-running operations, stream events in real-time. `gale()->stream()` always uses SSE regardless of the global mode setting:

```php
return gale()->stream(function ($gale) {
    $users = User::cursor();
    $total = User::count();
    $processed = 0;

    foreach ($users as $user) {
        $user->processExpensiveOperation();
        $processed++;

        // Sent immediately to the browser
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

### Request Macros

Gale registers these macros on the Laravel `Request` object:

```php
// Check if the request is a Gale request
if (request()->isGale()) {
    return gale()->state('data', $data);
}
return view('page', compact('data'));

// Access state sent from the Alpine component
$count = request()->state('count', 0);
$email = request()->state('user.email');

// Check if it's a navigation request
if (request()->isGaleNavigate()) {
    return gale()->fragment('page', 'content', $data);
}

// Validate state with automatic error response
$validated = request()->validateState([
    'email' => 'required|email',
    'name' => 'required|min:2',
]);
```

### Blade Directives

#### @gale

Include the JavaScript bundle and CSRF meta tag:

```blade
<head>
    @gale
</head>
```

Accepts optional options:

```blade
@gale(['nonce' => config('gale.csp_nonce')])
```

#### @fragment / @endfragment

Define extractable fragments:

```blade
@fragment('header')
<header>{{ $title }}</header>
@endfragment
```

#### @ifgale / @else / @endifgale

Conditional rendering based on request type:

```blade
@ifgale
    <div id="content">{{ $content }}</div>
@else
    @include('layouts.app')
@endifgale
```

### Validation

Standard Laravel validation works reactively for Gale requests. `ValidationException` is automatically converted to a `gale()->messages()` response:

```php
// Standard validate() -- auto-converts for Gale requests
public function store(Request $request)
{
    $request->validate([
        'state.name' => 'required|min:2|max:255',
        'state.email' => 'required|email|unique:users',
    ]);

    // Process...
}

// validateState() -- validates against component state directly
public function store(Request $request)
{
    $validated = $request->validateState([
        'name' => 'required|min:2|max:255',
        'email' => 'required|email|unique:users',
    ]);

    User::create($validated);
    return gale()->messages(['_success' => 'Account created!']);
}
```

Form Request classes also work out of the box:

```php
// app/Http/Requests/StoreUserRequest.php
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'state.name' => 'required|min:2',
            'state.email' => 'required|email',
        ];
    }
}

// Controller -- validation errors auto-converted for Gale
public function store(StoreUserRequest $request)
{
    User::create($request->validated());
    return gale()->messages(['_success' => 'Created!']);
}
```

### Conditional Execution

```php
gale()->when($condition, function ($gale) {
    $gale->state('visible', true);
});

gale()->whenGale(
    fn($g) => $g->state('partial', true),
    fn($g) => $g->web(view('full'))
);

gale()->whenGaleNavigate('sidebar', function ($gale) {
    $gale->fragment('layout', 'sidebar', $data);
});

// Web fallback for non-Gale requests
return gale()
    ->state('data', $data)
    ->web(view('page', compact('data')));
```

### Route Discovery

Optional attribute-based route discovery:

```php
// config/gale.php
'route_discovery' => [
    'enabled' => true,
    'discover_controllers_in_directory' => [
        app_path('Http/Controllers'),
    ],
],
```

```php
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\Group;
use Dancycodes\Gale\Routing\Attributes\Middleware;

#[Prefix('/admin')]
class UserController extends Controller
{
    #[Route('GET', '/users', name: 'admin.users.index')]
    public function index() { }

    #[Route('GET', '/users/{id}', name: 'admin.users.show')]
    public function show($id) { }
}

// Group attribute (prefix + middleware + route name prefix in one)
#[Group(prefix: '/api', middleware: ['auth'], as: 'api.')]
class ApiController extends Controller
{
    #[Route('GET', '/data')]
    public function data() { }
}
```

List discovered routes:

```bash
php artisan gale:routes
php artisan gale:routes --json
```

---

## Frontend: Alpine Gale

All frontend features require an Alpine.js context (`x-data` or `x-init`).

### The $action Magic

The `$action` magic handles all HTTP requests. It defaults to **POST with automatic CSRF injection** -- the most common pattern for server actions.

```html
<div x-data="{ count: 0 }" x-sync>
    <!-- Default: POST with CSRF -->
    <button @click="$action('/increment')">+1</button>

    <!-- Method shorthands -->
    <button @click="$action.get('/api/data')">GET</button>
    <button @click="$action.post('/api/save')">POST</button>
    <button @click="$action.put('/api/replace')">PUT</button>
    <button @click="$action.patch('/api/update')">PATCH</button>
    <button @click="$action.delete('/api/remove')">DELETE</button>
</div>
```

CSRF tokens are automatically injected for all non-GET methods. No manual token handling required.

#### Request Options

```html
<button @click="$action('/save', {
    include: ['user', 'settings'],
    exclude: ['tempData'],
    headers: { 'X-Custom': 'value' },
    sse: true,
    retryInterval: 1000,
    retryMaxCount: 10,
    requestCancellation: true,
    debounce: 300,
    throttle: 500,
    onProgress: (percent) => console.log(percent)
})">Save</button>
```

| Option | Type | Default | Description |
|---|---|---|---|
| `method` | string | `'POST'` | HTTP method |
| `include` | string[] | -- | Only send these state keys |
| `exclude` | string[] | -- | Don't send these state keys |
| `headers` | object | `{}` | Additional request headers |
| `sse` | bool | `false` | Force SSE mode for this request |
| `http` | bool | `false` | Force HTTP mode for this request |
| `retryInterval` | number | `1000` | Initial retry delay (ms) |
| `retryScaler` | number | `2` | Exponential backoff multiplier |
| `retryMaxWaitMs` | number | `30000` | Maximum retry delay (ms) |
| `retryMaxCount` | number | `10` | Maximum retry attempts |
| `requestCancellation` | bool | `false` | Cancel previous in-flight request |
| `debounce` | number | -- | Trailing-edge debounce (ms) |
| `throttle` | number | -- | Leading-edge throttle (ms) |
| `onProgress` | function | -- | Upload progress callback (0-100) |

### State Synchronization (x-sync)

The `x-sync` directive controls which Alpine state properties are sent to the server:

```html
<!-- Send everything -->
<div x-data="{ name: '', email: '', open: false }" x-sync>

<!-- Send specific keys only -->
<div x-data="{ name: '', email: '', open: false }" x-sync="['name', 'email']">

<!-- String syntax shorthand -->
<div x-data="{ name: '', email: '' }" x-sync="name, email">

<!-- No x-sync = send nothing automatically -->
<div x-data="{ name: '', temp: null }">
```

| x-sync Value | Result |
|---|---|
| `x-sync` (empty) | Send all state (wildcard) |
| `x-sync="*"` | Send all state (explicit wildcard) |
| `x-sync="['a','b']"` | Send only `a` and `b` |
| `x-sync="a, b"` | Send only `a` and `b` (string syntax) |
| No directive | Send nothing (use `include` option if needed) |

### CSRF Protection

The `@gale` directive adds `<meta name="csrf-token">`. The `$action` magic reads this token automatically for all non-GET requests.

```javascript
// Custom CSRF configuration (rarely needed)
Alpine.gale.configureCsrf({
    headerName: 'X-CSRF-TOKEN',
    metaName: 'csrf-token',
    cookieName: 'XSRF-TOKEN',
});
```

### Global State ($gale)

The `$gale` magic provides global connection state:

```html
<div x-data>
    <div x-show="$gale.loading">Loading...</div>
    <div x-show="$gale.retrying">Reconnecting...</div>
    <div x-show="$gale.error">
        Error: <span x-text="$gale.lastError"></span>
    </div>
    <span x-text="$gale.activeCount + ' requests active'"></span>
    <button @click="$gale.clearErrors()">Clear Errors</button>
</div>
```

| Property | Type | Description |
|---|---|---|
| `loading` | bool | Any request in progress |
| `activeCount` | number | Number of active requests |
| `retrying` | bool | Currently retrying a request |
| `retriesFailed` | bool | All retries exhausted |
| `error` | bool | Has any error |
| `lastError` | string | Most recent error message |
| `errors` | array | All error messages |
| `clearErrors()` | function | Clear all errors |

### Element State ($fetching)

Track per-element loading state:

```html
<button @click="$action('/save')" :disabled="$fetching()">
    <span x-show="!$fetching()">Save</span>
    <span x-show="$fetching()">Saving...</span>
</button>
```

Note: `$fetching` is a function -- always use `$fetching()` with parentheses.

### Loading Directives

#### x-loading

Show/hide elements or apply classes during loading:

```html
<div x-loading>Loading...</div>
<div x-loading.remove>Content visible when not loading</div>
<button x-loading.class="opacity-50">Submit</button>
<button x-loading.attr="disabled">Submit</button>
<div x-loading.delay.200ms>Loading (delayed)...</div>
```

#### x-indicator

Bind a boolean state variable to loading activity:

```html
<div x-data="{ saving: false }" x-indicator="saving">
    <button @click="$action('/save')" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>
</div>
```

### Navigation

#### x-navigate Directive

Enable SPA navigation on links and forms:

```html
<a href="/users" x-navigate>Users</a>
<a href="/users?sort=name" x-navigate.merge>Sort by Name</a>
<a href="/users" x-navigate.replace>Users (replace history)</a>

<!-- Navigation key for partial updates -->
<a href="/users" x-navigate.key.sidebar>Users</a>

<!-- Forms -->
<form action="/search" method="GET" x-navigate>
    <input name="q" type="text" />
    <button type="submit">Search</button>
</form>

<!-- POST form navigation (PRG pattern) -->
<form action="/submit" method="POST" x-navigate>
    <input name="name" type="text" />
    <button type="submit">Submit</button>
</form>
```

| Modifier | Description |
|---|---|
| `.merge` | Merge query params with current URL |
| `.replace` | Replace history entry instead of push |
| `.key.{name}` | Navigation key for targeted updates |
| `.only.{params}` | Keep only these query params |
| `.except.{params}` | Remove these query params |
| `.debounce.{ms}` | Debounce navigation |
| `.throttle.{ms}` | Throttle navigation |

#### $navigate Magic

```html
<button @click="$navigate('/users')">Users</button>

<button @click="$navigate('/users', {
    merge: true,
    replace: true,
    key: 'main-content'
})">Navigate</button>
```

#### x-navigate-skip

Exclude specific links from navigation:

```html
<nav x-navigate>
    <a href="/dashboard">Dashboard</a>
    <a href="/external" x-navigate-skip>External Link</a>
</nav>
```

### Component Registry

Named components that can be targeted from the backend or other components.

```html
<div x-data="{ items: [], total: 0 }" x-component="cart">
    <span x-text="total"></span>
</div>

<!-- Access from another component -->
<div x-data>
    <span x-show="$components.has('cart')">Cart loaded</span>
    <span x-text="$components.state('cart', 'total')"></span>
    <button @click="$components.update('cart', { total: 0 })">Clear</button>
    <button @click="$invoke('cart', 'recalculate')">Recalculate</button>
</div>
```

| Method | Description |
|---|---|
| `get(name)` | Get component Alpine data object |
| `has(name)` | Check if component exists |
| `all()` | Get all registered components |
| `getByTag(tag)` | Get components with tag |
| `state(name, property)` | Get reactive state value |
| `update(name, state)` | Merge state into component |
| `create(name, state)` | Set state (with onlyIfMissing option) |
| `delete(name, keys)` | Remove state keys |
| `invoke(name, method, ...args)` | Call method on component |
| `watch(name, property, callback)` | Watch for changes |
| `when(name, timeout?)` | Promise resolving when component exists |
| `onReady(name, callback)` | Callback when component ready |

### Form Binding (x-name)

Combines `x-model` behavior with automatic state creation and `name` attributes:

```html
<div x-data="{ email: '', password: '' }">
    <input x-name="email" type="email">
    <input x-name="password" type="password">
    <button @click="$action('/login')">Login</button>
</div>
```

Supports nested paths, checkboxes, radios, selects, and modifiers:

```html
<input x-name="user.name" type="text">
<input x-name.lazy="search" type="text">
<input x-name.number="quantity" type="text">
<input x-name.trim="username" type="text">
<input x-name.array="tags" type="checkbox" value="alpha">
```

### File Uploads

```html
<div x-data>
    <input type="file" name="avatar" x-files />

    <div x-show="$file('avatar')">
        <p>Name: <span x-text="$file('avatar')?.name"></span></p>
        <p>Size: <span x-text="$formatBytes($file('avatar')?.size)"></span></p>
        <img :src="$filePreview('avatar')" />
    </div>

    <button @click="$action('/upload')">Upload</button>
</div>
```

| Magic | Description |
|---|---|
| `$file(name)` | Get single file info |
| `$files(name)` | Get array of files |
| `$filePreview(name, index?)` | Get preview URL |
| `$clearFiles(name?)` | Clear file input(s) |
| `$formatBytes(size, decimals?)` | Format bytes to human-readable |
| `$uploading` | Upload in progress |
| `$uploadProgress` | Progress 0-100 |

### Message Display

Display validation errors and notifications from the server:

```html
<div x-data="{ messages: {} }">
    <input x-name="email" type="email">
    <span x-message="email" class="text-red-500"></span>

    <div x-message="_success" class="text-green-500"></div>

    <button @click="$action('/subscribe')">Subscribe</button>
</div>
```

Array validation with dynamic paths:

```html
<template x-for="(item, index) in items" :key="index">
    <div>
        <input x-model="items[index].name">
        <span x-message="`items.${index}.name`" class="text-red-500"></span>
    </div>
</template>
```

### Polling (x-interval)

Run expressions at configurable intervals:

```html
<!-- Increment every second -->
<div x-data="{ count: 0 }" x-interval.1s="count++">
    <span x-text="count"></span>
</div>

<!-- Poll server every 5 seconds -->
<div x-data="{ status: '' }" x-interval.5s="$action.get('/api/status')">
    <span x-text="status"></span>
</div>

<!-- Only run when tab is visible -->
<div x-interval.visible.5s="$action.get('/api/status')">...</div>

<!-- Stop on condition -->
<div x-data="{ done: false, progress: 0 }"
     x-interval.1s="progress += 10; done = progress >= 100"
     x-interval-stop="done">
    Processing...
</div>
```

### Confirmation Dialogs

```html
<button @click="$action.delete('/item/1')" x-confirm="Are you sure?">
    Delete
</button>
```

---

## Configuration Reference

After running `php artisan vendor:publish --tag=gale-config`, edit `config/gale.php`:

```php
return [
    // Default response mode: 'http' (JSON) or 'sse' (Server-Sent Events)
    'mode' => env('GALE_MODE', 'http'),

    // Intercept dd() and dump() during Gale requests, render in debug panel
    'debug' => env('GALE_DEBUG', false),

    // Sanitize HTML in gale-patch-elements events (XSS protection)
    'sanitize_html' => env('GALE_SANITIZE_HTML', true),

    // Allow <script> tags in patched HTML (false = strip scripts)
    'allow_scripts' => env('GALE_ALLOW_SCRIPTS', false),

    // Inject HTML comment markers for conditional/loop Blade blocks
    // Improves morph accuracy; disable in production to reduce payload
    'morph_markers' => env('GALE_MORPH_MARKERS', true),

    // Content Security Policy nonce: null | 'auto' | '<nonce-string>'
    'csp_nonce' => env('GALE_CSP_NONCE', null),

    // Security headers added to all Gale responses
    'headers' => [
        'x_content_type_options' => 'nosniff',
        'x_frame_options' => 'SAMEORIGIN',
        'cache_control' => 'no-store, no-cache, must-revalidate',
        'custom' => [],
    ],

    // Open-redirect prevention
    'redirect' => [
        'allowed_domains' => [],  // e.g. ['payment.stripe.com', '*.myapp.com']
        'allow_external' => false,
        'log_blocked' => true,
    ],

    // Attribute-based route discovery (opt-in)
    'route_discovery' => [
        'enabled' => false,
        'conventions' => true,  // Auto-discover index/show/create/store/edit/update/destroy
        'discover_controllers_in_directory' => [
            // app_path('Http/Controllers'),
        ],
        'discover_views_in_directory' => [],
        'pending_route_transformers' => [
            ...Dancycodes\Gale\Routing\Config::defaultRouteTransformers(),
        ],
    ],
];
```

**Environment variables:**

| Variable | Default | Description |
|---|---|---|
| `GALE_MODE` | `http` | Default response mode (`http` or `sse`) |
| `GALE_DEBUG` | `false` | Enable debug panel and dd()/dump() interception |
| `GALE_SANITIZE_HTML` | `true` | Sanitize patched HTML for XSS |
| `GALE_ALLOW_SCRIPTS` | `false` | Allow `<script>` tags in patched HTML |
| `GALE_MORPH_MARKERS` | `true` | Inject Blade morph anchor comments |
| `GALE_CSP_NONCE` | `null` | CSP nonce value |

---

## Advanced Topics

<details>
<summary><strong>DOM Patching Modes</strong></summary>

Gale provides 9 DOM patching modes in three categories:

| Category | Modes | State Handling |
|---|---|---|
| **Server-driven** | `outer` (default), `inner` | State from server HTML via `initTree()` |
| **Client-preserved** | `outerMorph`, `innerMorph` | Existing Alpine state preserved via `Alpine.morph()` |
| **Insertion/Deletion** | `before`, `after`, `prepend`, `append`, `remove` | New elements initialized |

**Use `outer` when** the server controls all state (forms, server-rendered content).
**Use `outerMorph` when** client state must survive the update (counters, toggles, focus).

**Backward compatibility**: `replace()` maps to `outer()`, `morph()` maps to `outerMorph()`.

**HTMX-compatible aliases**: `outerHTML` = `outer`, `innerHTML` = `inner`, `beforebegin` = `before`, `afterend` = `after`, `afterbegin` = `prepend`, `beforeend` = `append`, `delete` = `remove`.

</details>

<details>
<summary><strong>View Transitions API</strong></summary>

Enable smooth page transitions via the browser's View Transitions API:

```php
gale()->view('page', $data, ['useViewTransition' => true]);
```

Global configuration:

```javascript
Alpine.gale.configure({ viewTransitions: true }); // enabled by default
```

Falls back gracefully in unsupported browsers.

</details>

<details>
<summary><strong>SSE Protocol Specification</strong></summary>

When using SSE mode, Gale streams these event types:

| Event | Purpose |
|---|---|
| `gale-patch-state` | Merge state into Alpine component |
| `gale-patch-elements` | DOM manipulation |
| `gale-patch-component` | Update named component |
| `gale-invoke-method` | Call method on component |

**gale-patch-state format:**

```
event: gale-patch-state
data: state {"count":1}
data: onlyIfMissing false
```

**gale-patch-elements format:**

```
event: gale-patch-elements
data: selector #content
data: mode outer
data: elements <div id="content">...</div>
```

**gale-patch-component format:**

```
event: gale-patch-component
data: component cart
data: state {"total":42}
```

**gale-invoke-method format:**

```
event: gale-invoke-method
data: component cart
data: method recalculate
data: args [10,20]
```

</details>

<details>
<summary><strong>State Serialization</strong></summary>

When making requests, Alpine Gale serializes the component's `x-data` based on `x-sync`:

**Serialized:** Properties in `x-sync`, form fields with `name` attribute, nested objects, arrays.

**Not serialized:** Functions, DOM elements, circular references, properties starting with `_` or `$`.

```html
<div x-data="{ user: {...}, temp: null }" x-sync="['user']">
    <button @click="$action('/save')">Save User</button>
    <!-- Only { user: {...} } is sent -->
</div>
```

</details>

<details>
<summary><strong>Global Configuration API</strong></summary>

```javascript
Alpine.gale.configure({
    defaultMode: 'http',         // 'http' | 'sse'
    viewTransitions: true,       // Enable View Transitions API
    foucTimeout: 3000,           // Max ms to wait for stylesheets during navigation
    navigationIndicator: true,   // Show progress bar during navigation
    pauseOnHidden: true,         // Pause SSE when tab is hidden
    pauseOnHiddenDelay: 1000,    // Debounce delay before pausing (ms)
    settleDuration: 0,           // Swap-settle transition delay (ms)
    csrfRefresh: 'auto',         // CSRF refresh strategy: 'auto' | 'meta' | 'sanctum'
    retry: {
        maxRetries: 3,           // Max retry attempts for network errors
        initialDelay: 1000,      // Initial retry delay (ms)
        backoffMultiplier: 2,    // Exponential backoff multiplier
    },
    redirect: {
        allowedDomains: [],      // Trusted external redirect domains
        allowExternal: false,    // Allow all external redirects
        logBlocked: true,        // Log blocked redirects
    },
});
```

</details>

<details>
<summary><strong>Morph Lifecycle Hooks</strong></summary>

Register callbacks to run before/after DOM morphing. Useful for preserving third-party library state (Chart.js, GSAP, TipTip, Sortable):

```javascript
const cleanup = Alpine.gale.onMorph({
    beforeUpdate(el, toEl) {
        // Called before element is updated
        // Return false to prevent the update
    },
    afterUpdate(el) {
        // Called after element is updated
        myChart.update();
    },
    beforeRemove(el) {
        // Called before element is removed
        // Return false to prevent removal
    },
    afterRemove(el) {
        // Cleanup after removal
    },
});

// Remove hooks when component is destroyed
cleanup();
```

</details>

---

## API Reference

<details>
<summary><strong>GaleResponse Methods</strong></summary>

| Method | Description |
|---|---|
| `state($key, $value, $options)` | Set state to merge into component |
| `patchState($state)` | Set multiple state keys (alias for `state(array)`) |
| `forget($keys)` | Remove state keys |
| `messages($messages)` | Set messages state |
| `clearMessages()` | Clear messages |
| `flash($key, $value)` | Flash to session + Alpine `_flash` state |
| `debug($label, $data)` | Send debug data to debug panel |
| `view($view, $data, $options, $web)` | Render and patch Blade view |
| `fragment($view, $fragment, $data, $options)` | Render named fragment |
| `fragments($fragments)` | Render multiple fragments |
| `html($html, $options, $web)` | Patch raw HTML |
| `outer($selector, $html, $options)` | Replace element (server state) |
| `inner($selector, $html, $options)` | Replace inner content (server state) |
| `outerMorph($selector, $html, $options)` | Morph element (preserve state) |
| `innerMorph($selector, $html, $options)` | Morph children (preserve state) |
| `append($selector, $html, $options)` | Append HTML |
| `prepend($selector, $html, $options)` | Prepend HTML |
| `before($selector, $html, $options)` | Insert before element |
| `after($selector, $html, $options)` | Insert after element |
| `remove($selector)` | Remove element |
| `js($script, $options)` | Execute JavaScript |
| `dispatch($event, $data, $options)` | Dispatch DOM event |
| `navigate($url, $key, $options)` | Trigger SPA navigation |
| `navigateMerge($params, $key)` | Navigate merging query params |
| `navigateReplace($url, $key)` | Navigate replacing history |
| `updateQueries($params, $key)` | Update query params in place |
| `clearQueries($keys)` | Clear query params |
| `reload()` | Full page reload |
| `componentState($name, $state, $options)` | Update component state |
| `componentMethod($name, $method, $args)` | Call component method |
| `redirect($url)` | Create redirect response |
| `stream($callback)` | Stream mode (always SSE) |
| `when($condition, $true, $false)` | Conditional execution |
| `unless($condition, $callback)` | Inverse conditional |
| `whenGale($gale, $web)` | Gale request conditional |
| `whenNotGale($callback)` | Non-Gale conditional |
| `whenGaleNavigate($key, $callback)` | Navigate conditional |
| `web($response)` | Set web fallback response |
| `reset()` | Clear all accumulated events |

</details>

<details>
<summary><strong>Request Macros</strong></summary>

| Macro | Description |
|---|---|
| `isGale()` | Check if request is a Gale request |
| `state($key, $default)` | Get state from component |
| `isGaleNavigate($key)` | Check if navigation request |
| `galeNavigateKey()` | Get navigation key |
| `galeNavigateKeys()` | Get all navigation keys |
| `validateState($rules, $messages, $attrs)` | Validate component state |

</details>

<details>
<summary><strong>Frontend Magics</strong></summary>

| Magic | Description |
|---|---|
| `$action(url, options?)` | POST with auto CSRF (default) |
| `$action.get(url, options?)` | GET request |
| `$action.post(url, options?)` | POST with auto CSRF |
| `$action.put(url, options?)` | PUT with auto CSRF |
| `$action.patch(url, options?)` | PATCH with auto CSRF |
| `$action.delete(url, options?)` | DELETE with auto CSRF |
| `$gale` | Global connection state |
| `$fetching()` | Element loading state (call as function) |
| `$navigate(url, options?)` | Programmatic navigation |
| `$components` | Component registry API |
| `$invoke(name, method, ...args)` | Invoke component method |
| `$file(name)` | Get file info |
| `$files(name)` | Get files array |
| `$filePreview(name, index?)` | Get preview URL |
| `$clearFiles(name?)` | Clear files |
| `$formatBytes(size, decimals?)` | Format bytes |
| `$uploading` | Upload in progress |
| `$uploadProgress` | Upload progress 0-100 |

</details>

<details>
<summary><strong>Frontend Directives</strong></summary>

| Directive | Description |
|---|---|
| `x-sync` | Sync state to server (wildcard or specific keys) |
| `x-navigate` | Enable SPA navigation |
| `x-navigate-skip` | Skip navigation handling |
| `x-component="name"` | Register named component |
| `x-name="field"` | Form binding with state |
| `x-files` | File input binding |
| `x-message="key"` | Display messages |
| `x-loading` | Loading state display |
| `x-indicator="var"` | Loading state variable |
| `x-interval` | Auto-polling / repeating expression |
| `x-interval-stop="expr"` | Stop polling condition |
| `x-confirm` | Confirmation dialog |

</details>

---

## Troubleshooting

| Issue | Cause | Solution |
|---|---|---|
| "Multiple instances of Alpine" | Duplicate Alpine.js loaded | Remove existing Alpine, use `@gale` only |
| `$action` is undefined | Magic used outside `x-data` | Wrap in `x-data` element |
| CSRF 419 error | Token expired or missing | Verify `@gale` is in `<head>` |
| State not updating | Key mismatch | Check `x-data` property names match server keys |
| Navigation not working | Missing directive | Add `x-navigate` to links or container |
| Messages not showing | Wrong key | Ensure `x-message` key matches server message key |
| Counter not updating | Missing `x-sync` | Add `x-sync` to `x-data` element to send state |
| JSON shown instead of page | Missing `web: true` | Add `web: true` to `gale()->view()` for page routes |

For in-depth troubleshooting, see [Debug & Troubleshooting](docs/debug-troubleshooting.md).

---

## Testing

```bash
# Package PHP tests
cd packages/dancycodes/gale
vendor/bin/phpunit

# Run only unit tests
vendor/bin/pest --testsuite Unit

# Run only feature tests
vendor/bin/pest --testsuite Feature

# Static analysis
vendor/bin/phpstan analyse

# Code formatting
vendor/bin/pint

# JavaScript tests (from project root)
npm test
```

---

## Contributing

Contributions are welcome. To contribute:

1. Fork the repository and create a feature branch
2. Write tests for any new functionality
3. Run the full test suite: `vendor/bin/pest && vendor/bin/phpstan analyse`
4. Format code: `vendor/bin/pint`
5. Submit a pull request with a clear description of the change

Report bugs via [GitHub Issues](https://github.com/dancycodes/gale/issues).

---

## License

MIT License. See [LICENSE](LICENSE).

---

## Credits

Created by **DancyCodes** -- dancycodes@gmail.com

- [Laravel](https://laravel.com)
- [Alpine.js](https://alpinejs.dev)
- [Datastar](https://data-star.dev) -- SSE inspiration
