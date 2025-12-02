# Laravel Gale

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-11%2B%20%7C%2012%2B-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Alpine.js](https://img.shields.io/badge/Alpine.js-3.x-8BC0D0?style=flat-square&logo=alpine.js)](https://alpinejs.dev)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

**Laravel Gale** is a server-driven reactive framework for Laravel. It combines Server-Sent Events (SSE) with Alpine.js to enable real-time UI updates directly from your Blade templates—no JavaScript framework, no build complexity, no API layer.

This README documents both:

-   **Laravel Gale** — The PHP backend package
-   **Alpine Gale** — An Alpine.js frontend plugin (bundled with Laravel Gale)

---

## Table of Contents

-   [Quick Start](#quick-start)
-   [Installation](#installation)
-   [Architecture](#architecture)
    -   [Request/Response Flow](#requestresponse-flow)
    -   [RFC 7386 JSON Merge Patch](#rfc-7386-json-merge-patch)
    -   [Alpine.js Context Requirement](#alpinejs-context-requirement)
-   [Backend: Laravel Gale](#backend-laravel-gale)
    -   [The gale() Helper](#the-gale-helper)
    -   [State Management](#state-management)
    -   [DOM Manipulation](#dom-manipulation)
    -   [Blade Fragments](#blade-fragments)
    -   [Redirects](#redirects)
    -   [Streaming Mode](#streaming-mode)
    -   [Navigation](#navigation)
    -   [Events and JavaScript](#events-and-javascript)
    -   [Component Targeting](#component-targeting)
    -   [Request Macros](#request-macros)
    -   [Blade Directives](#blade-directives)
    -   [Validation](#validation)
    -   [Route Discovery](#route-discovery)
-   [Frontend: Alpine Gale](#frontend-alpine-gale)
    -   [HTTP Magics](#http-magics)
    -   [CSRF Protection](#csrf-protection)
    -   [Global State ($gale)](#global-state-gale)
    -   [Element State ($fetching)](#element-state-fetching)
    -   [Loading Directives](#loading-directives)
    -   [Navigation](#navigation-1)
    -   [Component Registry](#component-registry)
    -   [File Uploads](#file-uploads)
    -   [Message Display](#message-display)
    -   [Polling](#polling)
    -   [Confirmation Dialogs](#confirmation-dialogs)
-   [Advanced Topics](#advanced-topics)
    -   [SSE Protocol Specification](#sse-protocol-specification)
    -   [State Serialization](#state-serialization)
    -   [DOM Morphing Modes](#dom-morphing-modes)
    -   [View Transitions API](#view-transitions-api)
    -   [Conditional Execution](#conditional-execution)
-   [API Reference](#api-reference)
    -   [GaleResponse Methods](#galeresponse-methods)
    -   [GaleRedirect Methods](#galeredirect-methods)
    -   [Request Macros Reference](#request-macros-reference)
    -   [Frontend Magics Reference](#frontend-magics-reference)
    -   [Frontend Directives Reference](#frontend-directives-reference)
    -   [Request Options Reference](#request-options-reference)
    -   [SSE Events Reference](#sse-events-reference)
    -   [Configuration Reference](#configuration-reference)
-   [Troubleshooting](#troubleshooting)
-   [Testing](#testing)
-   [License](#license)

---

## Quick Start

A complete counter in 15 lines:

**routes/web.php:**

```php
Route::get('/counter', fn() => view('counter'));

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
        <div x-data="{ count: 0 }">
            <span x-text="count"></span>
            <button @click="$postx('/increment')">+</button>
        </div>
    </body>
</html>
```

Click the button. The count updates via SSE. No page reload, no JavaScript written.

---

## Installation

### Step 1: Install Package

```bash
composer require dancycodes/gale
```

### Step 2: Publish Assets

```bash
php artisan vendor:publish --tag=gale-assets
```

Publishes to `public/vendor/gale/js/gale.js`.

### Step 3: Add Directive

Add `@gale` to your layout's `<head>`:

```html
<head>
    @gale
</head>
```

This outputs:

-   CSRF meta tag
-   Alpine.js with Morph plugin
-   Alpine Gale plugin

### Optional: Publish Configuration

```bash
php artisan vendor:publish --tag=gale-config
```

---

## Architecture

### Request/Response Flow

```
┌─────────────────────────────────────────────────────────────┐
│                         BROWSER                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Alpine.js Component (x-data)                           │ │
│  │   State: { count: 0, user: {...} }                     │ │
│  └────────────────────────────────────────────────────────┘ │
│                           │                                  │
│                           │ $postx('/increment')             │
│                           ▼                                  │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ HTTP Request                                           │ │
│  │   Headers: Gale-Request, X-CSRF-TOKEN                  │ │
│  │   Body: { count: 0, user: {...} }  (serialized state)  │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                      LARAVEL SERVER                          │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Controller                                             │ │
│  │   $count = request()->state('count');                  │ │
│  │   return gale()->state('count', $count + 1);           │ │
│  └────────────────────────────────────────────────────────┘ │
│                           │                                  │
│                           ▼                                  │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ SSE Response (text/event-stream)                       │ │
│  │   event: gale-patch-state                              │ │
│  │   data: state {"count":1}                              │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Alpine.js receives SSE, merges state via RFC 7386           │
│   State: { count: 1, user: {...} }                          │
│   UI reactively updates                                      │
└─────────────────────────────────────────────────────────────┘
```

### RFC 7386 JSON Merge Patch

State updates follow RFC 7386:

| Server Sends                       | Current State                                    | Result                                           |
| ---------------------------------- | ------------------------------------------------ | ------------------------------------------------ |
| `{ count: 5 }`                     | `{ count: 0, name: "John" }`                     | `{ count: 5, name: "John" }`                     |
| `{ name: null }`                   | `{ count: 0, name: "John" }`                     | `{ count: 0 }`                                   |
| `{ user: { email: "new@x.com" } }` | `{ user: { name: "John", email: "old@x.com" } }` | `{ user: { name: "John", email: "new@x.com" } }` |

-   **Values merge**: Sent values replace existing values
-   **Null deletes**: Sending `null` removes the property
-   **Deep merge**: Nested objects merge recursively

### Alpine.js Context Requirement

All Alpine Gale features require an Alpine.js context:

```html
<!-- Works: Inside x-data -->
<div x-data="{ count: 0 }">
    <button @click="$postx('/increment')">+</button>
</div>

<!-- Works: x-init provides context -->
<div x-init="$get('/load')">Loading...</div>

<!-- Fails: No Alpine context -->
<button @click="$postx('/increment')">Broken</button>
```

---

## Backend: Laravel Gale

### The gale() Helper

Returns a singleton `GaleResponse` instance with fluent API:

```php
return gale()
    ->state('count', 42)
    ->state('updated', now()->toISOString())
    ->messages(['success' => 'Saved!']);
```

The same instance accumulates events throughout the request, then streams them as SSE.

### State Management

#### state()

Set state values to merge into Alpine component:

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
```

**Options:**

```php
// Only set if key doesn't exist in component state
gale()->state('defaults', ['theme' => 'dark'], ['onlyIfMissing' => true]);
```

| Option          | Type | Default | Description                        |
| --------------- | ---- | ------- | ---------------------------------- |
| `onlyIfMissing` | bool | `false` | Only set if property doesn't exist |

#### forget()

Remove state properties (sends `null` per RFC 7386):

```php
// Single key
gale()->forget('tempData');

// Multiple keys
gale()->forget(['tempData', 'cache', 'draft']);
```

#### messages()

Set the `messages` state object (commonly used for validation):

```php
gale()->messages([
    'email' => 'Invalid email address',
    'password' => 'Password too short',
]);

// Success pattern
gale()->messages(['_success' => 'Profile saved!']);
```

#### clearMessages()

Clear all messages (sends empty object):

```php
gale()->clearMessages();
```

### DOM Manipulation

#### view()

Render a Blade view and patch it into the DOM:

```php
// Basic: morphs by matching element IDs
gale()->view('partials.user-card', ['user' => $user]);

// With selector and mode
gale()->view('partials.item', ['item' => $item], [
    'selector' => '#items-list',
    'mode' => 'append',
]);

// As web fallback for non-Gale requests
gale()->view('dashboard', $data, web: true);
```

**Options:**

| Option              | Type   | Default   | Description                     |
| ------------------- | ------ | --------- | ------------------------------- |
| `selector`          | string | `null`    | CSS selector for target element |
| `mode`              | string | `'morph'` | DOM patching mode               |
| `useViewTransition` | bool   | `false`   | Enable View Transitions API     |
| `settle`            | int    | `0`       | Delay (ms) before patching      |
| `limit`             | int    | `null`    | Max elements to patch           |

#### fragment()

Render only a named fragment from a Blade view:

```php
gale()->fragment('todos', 'todo-items', ['todos' => $todos]);

// With options
gale()->fragment('todos', 'todo-items', ['todos' => $todos], [
    'selector' => '#todo-list',
    'mode' => 'morph',
]);
```

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

#### fragments()

Render multiple fragments at once:

```php
gale()->fragments([
    [
        'view' => 'todos',
        'fragment' => 'todo-items',
        'data' => ['todos' => $todos],
        'options' => ['selector' => '#todo-list'],
    ],
    [
        'view' => 'todos',
        'fragment' => 'todo-count',
        'data' => ['count' => $todos->count()],
    ],
]);
```

#### html()

Patch raw HTML into the DOM:

```php
gale()->html('<div id="content">New content</div>');

// With options
gale()->html('<li>New item</li>', [
    'selector' => '#list',
    'mode' => 'append',
]);
```

#### DOM Convenience Methods

```php
gale()->append('#list', '<li>Last</li>');
gale()->prepend('#list', '<li>First</li>');
gale()->before('#target', '<div>Before</div>');
gale()->after('#target', '<div>After</div>');
gale()->inner('#container', '<p>Inner content</p>');
gale()->outer('#element', '<div id="element">Replaced</div>');
gale()->replace('#old', '<div id="new">New</div>');
gale()->remove('.deprecated');
```

| Method                      | Equivalent Mode   |
| --------------------------- | ----------------- |
| `append($selector, $html)`  | `mode: 'append'`  |
| `prepend($selector, $html)` | `mode: 'prepend'` |
| `before($selector, $html)`  | `mode: 'before'`  |
| `after($selector, $html)`   | `mode: 'after'`   |
| `inner($selector, $html)`   | `mode: 'inner'`   |
| `outer($selector, $html)`   | `mode: 'outer'`   |
| `replace($selector, $html)` | `mode: 'replace'` |
| `remove($selector)`         | `mode: 'remove'`  |

### Blade Fragments

Fragments extract specific sections from Blade views without rendering the entire template.

**Defining fragments:**

```blade
{{-- resources/views/dashboard.blade.php --}}
<div class="dashboard">
    @fragment('stats')
    <div id="stats">
        <span>Users: {{ $userCount }}</span>
        <span>Orders: {{ $orderCount }}</span>
    </div>
    @endfragment

    @fragment('recent-orders')
    <ul id="recent-orders">
        @foreach($recentOrders as $order)
            <li>{{ $order->number }}</li>
        @endforeach
    </ul>
    @endfragment
</div>
```

**Rendering fragments:**

```php
// Single fragment
gale()->fragment('dashboard', 'stats', [
    'userCount' => User::count(),
    'orderCount' => Order::count(),
]);

// Multiple fragments
gale()->fragments([
    ['view' => 'dashboard', 'fragment' => 'stats', 'data' => $statsData],
    ['view' => 'dashboard', 'fragment' => 'recent-orders', 'data' => $ordersData],
]);
```

### Redirects

Full-page browser redirects with session flash support:

```php
// Basic redirect
return gale()->redirect('/dashboard');

// With flash data
return gale()->redirect('/dashboard')
    ->with('message', 'Welcome back!')
    ->with(['key' => 'value', 'another' => 'data']);

// With validation errors and input
return gale()->redirect('/register')
    ->withErrors($validator)
    ->withInput();
```

#### GaleRedirect Methods

```php
$redirect = gale()->redirect('/url');

// Flash data
$redirect->with('key', 'value');
$redirect->with(['key' => 'value']);
$redirect->withInput();
$redirect->withInput(['name', 'email']);
$redirect->withErrors($validator);

// URL modifiers
$redirect->back('/fallback');
$redirect->backOr('route.name', ['param' => 'value']);
$redirect->refresh(preserveQuery: true, preserveFragment: false);
$redirect->home();
$redirect->route('route.name', ['id' => 1]);
$redirect->intended('/default');
$redirect->forceReload(bypassCache: false);
```

| Method                       | Description                            |
| ---------------------------- | -------------------------------------- |
| `with($key, $value)`         | Flash data to session                  |
| `withInput($input)`          | Flash form input for repopulation      |
| `withErrors($errors)`        | Flash validation errors                |
| `back($fallback)`            | Redirect to previous URL with fallback |
| `backOr($route, $params)`    | Back with named route fallback         |
| `refresh($query, $fragment)` | Reload current page                    |
| `home()`                     | Redirect to root URL                   |
| `route($name, $params)`      | Redirect to named route                |
| `intended($default)`         | Redirect to auth intended URL          |
| `forceReload($bypass)`       | Hard reload via JavaScript             |

### Streaming Mode

For long-running operations, stream events in real-time:

```php
return gale()->stream(function ($gale) {
    $users = User::cursor();
    $total = User::count();
    $processed = 0;

    foreach ($users as $user) {
        $user->processExpensiveOperation();
        $processed++;

        // Sent immediately
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

**Streaming features:**

-   Events sent immediately as they're added
-   `dd()` and `dump()` output captured and displayed
-   Exceptions rendered with stack traces
-   Redirects work via JavaScript

### Navigation

Trigger SPA navigation from the backend:

```php
// Basic navigation (pushes to history)
gale()->navigate('/users');

// With navigation key
gale()->navigate('/users', 'main-content');

// Navigate with explicit merge control
gale()->navigateWith('/users', 'main', merge: true);

// Merge query params
gale()->navigateMerge(['page' => 2]);
gale()->navigateMerge(['sort' => 'name'], 'table');

// Clean navigation (no param merging)
gale()->navigateClean('/users');

// Keep only specific params
gale()->navigateOnly('/search', ['q', 'category']);

// Keep all except specific params
gale()->navigateExcept('/search', ['page', 'cursor']);

// Replace history instead of push
gale()->navigateReplace('/users');

// Update just query parameters
gale()->updateQueries(['sort' => 'name', 'order' => 'asc']);

// Clear specific query parameters
gale()->clearQueries(['filter', 'search']);

// Full page reload
gale()->reload();
```

| Method                             | Description                          |
| ---------------------------------- | ------------------------------------ |
| `navigate($url, $key)`             | Navigate to URL with optional key    |
| `navigateWith($url, $key, $merge)` | Navigate with explicit merge control |
| `navigateMerge($params, $key)`     | Merge query params into current URL  |
| `navigateClean($url)`              | Navigate without merging params      |
| `navigateOnly($url, $keep)`        | Keep only specified params           |
| `navigateExcept($url, $remove)`    | Remove specified params              |
| `navigateReplace($url)`            | Replace history entry                |
| `updateQueries($params)`           | Update query params in place         |
| `clearQueries($keys)`              | Remove query params                  |
| `reload()`                         | Full page reload                     |

### Events and JavaScript

#### dispatch()

Dispatch custom DOM events:

```php
// Window-level event
gale()->dispatch('user-updated', ['id' => $user->id]);

// Targeted to selector
gale()->dispatch('refresh', ['section' => 'cart'], [
    'selector' => '.shopping-cart',
]);

// With event options
gale()->dispatch('notification', ['message' => 'Saved!'], [
    'bubbles' => true,
    'cancelable' => true,
    'composed' => false,
]);
```

**Listen in Alpine:**

```html
<div x-data @user-updated.window="handleUpdate($event.detail)"></div>
```

| Option       | Type   | Default | Description              |
| ------------ | ------ | ------- | ------------------------ |
| `selector`   | string | `null`  | Target element(s)        |
| `bubbles`    | bool   | `true`  | Event bubbles up         |
| `cancelable` | bool   | `true`  | Event can be canceled    |
| `composed`   | bool   | `false` | Event crosses shadow DOM |

#### js()

Execute JavaScript in the browser:

```php
gale()->js('console.log("Hello from server")');

gale()->js('myApp.showNotification("Saved!")', [
    'autoRemove' => true,
]);
```

| Option       | Type | Default | Description                           |
| ------------ | ---- | ------- | ------------------------------------- |
| `autoRemove` | bool | `false` | Remove script element after execution |

### Component Targeting

Target specific named Alpine components:

#### componentState()

Update a component's state by name:

```php
gale()->componentState('cart', [
    'items' => $cartItems,
    'total' => $total,
]);

// Only set if property doesn't exist
gale()->componentState('cart', ['currency' => 'USD'], [
    'onlyIfMissing' => true,
]);
```

#### componentMethod()

Invoke a method on a named component:

```php
gale()->componentMethod('cart', 'recalculate');
gale()->componentMethod('form', 'reset');
gale()->componentMethod('calculator', 'setValues', [10, 20, 30]);
```

### Request Macros

Laravel Gale registers these macros on the Request object:

#### isGale()

Check if the request is a Gale request:

```php
if (request()->isGale()) {
    return gale()->state('data', $data);
}
return view('page', compact('data'));
```

#### state()

Access state sent from the Alpine component:

```php
// All state
$state = request()->state();

// Specific key with default
$count = request()->state('count', 0);

// Nested value (dot notation)
$email = request()->state('user.email');
```

#### isGaleNavigate()

Check if request is a navigation request:

```php
// Any navigate request
if (request()->isGaleNavigate()) {
    return gale()->fragment('page', 'content', $data);
}

// Specific key
if (request()->isGaleNavigate('sidebar')) {
    return gale()->fragment('page', 'sidebar', $data);
}

// Multiple keys (matches any)
if (request()->isGaleNavigate(['main', 'sidebar'])) {
    // ...
}
```

#### galeNavigateKey() / galeNavigateKeys()

Get the navigation key(s):

```php
$key = request()->galeNavigateKey();     // 'sidebar' or null
$keys = request()->galeNavigateKeys();   // ['sidebar', 'main'] or []
```

#### validateState()

Validate state with automatic SSE error response:

```php
$validated = request()->validateState([
    'email' => 'required|email',
    'name' => 'required|min:2',
], [
    'email.required' => 'Email is required',
]);

// On failure: throws GaleMessageException (SSE error response)
// On success: returns validated data, clears messages for validated fields
```

The validation uses selective clearing—only messages for validated fields are cleared, preserving other messages.

### Blade Directives

#### @gale

Include the JavaScript bundle and CSRF meta tag:

```blade
<head>
    @gale
</head>
```

Outputs:

```html
<meta name="csrf-token" content="..." />
<script type="module" src="/vendor/gale/js/gale.js"></script>
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
    {{-- Gale request: partial content --}}
    <div id="content">{{ $content }}</div>
@else
    {{-- Regular request: full page --}}
    @include('layouts.app')
@endifgale
```

### Validation

#### Using validateState Macro

```php
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

#### Using GaleMessageException

For custom validation flows:

```php
use Dancycodes\Gale\Exceptions\GaleMessageException;

public function update(Request $request)
{
    $validator = Validator::make($request->state(), [
        'email' => 'required|email',
    ]);

    if ($validator->fails()) {
        throw new GaleMessageException($validator);
    }

    // Process...
}
```

#### Manual Message Handling

```php
if ($validator->fails()) {
    return gale()->messages($validator->errors()->toArray());
}

// On success
return gale()->clearMessages();
```

### Route Discovery

Optional attribute-based route discovery:

#### Enable in Configuration

```php
// config/gale.php
return [
    'route_discovery' => [
        'enabled' => true,
        'discover_controllers_in_directory' => [
            app_path('Http/Controllers'),
        ],
        'discover_views_in_directory' => [
            'docs' => resource_path('views/docs'),
        ],
    ],
];
```

#### Controller Attributes

```php
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\Where;
use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Routing\Attributes\WithTrashed;

#[Prefix('/admin')]
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

#### Route Attribute Options

| Attribute          | Parameters                                                      | Description                 |
| ------------------ | --------------------------------------------------------------- | --------------------------- |
| `#[Route]`         | `methods`, `uri`, `name`, `middleware`, `domain`, `withTrashed` | Define route                |
| `#[Prefix]`        | `prefix`                                                        | URL prefix for class        |
| `#[Where]`         | `param`, `pattern`                                              | Route parameter constraint  |
| `#[DoNotDiscover]` | —                                                               | Exclude from discovery      |
| `#[WithTrashed]`   | —                                                               | Include soft-deleted models |

**Where Constants:**

| Constant              | Pattern        |
| --------------------- | -------------- |
| `Where::ALPHA`        | `[a-zA-Z]+`    |
| `Where::NUMERIC`      | `[0-9]+`       |
| `Where::ALPHANUMERIC` | `[a-zA-Z0-9]+` |
| `Where::UUID`         | UUID pattern   |

---

## Frontend: Alpine Gale

All frontend features require an Alpine.js context (`x-data` or `x-init`).

### HTTP Magics

#### Basic HTTP Methods

```html
<div x-data="{ count: 0 }">
    <button @click="$get('/api/data')">GET</button>
    <button @click="$post('/api/save')">POST</button>
    <button @click="$patch('/api/update')">PATCH</button>
    <button @click="$put('/api/replace')">PUT</button>
    <button @click="$delete('/api/remove')">DELETE</button>
</div>
```

#### CSRF-Protected Variants

For Laravel's CSRF protection, use the `x` suffix:

```html
<button @click="$postx('/save')">POST with CSRF</button>
<button @click="$patchx('/update')">PATCH with CSRF</button>
<button @click="$putx('/replace')">PUT with CSRF</button>
<button @click="$deletex('/remove')">DELETE with CSRF</button>
```

#### Request Options

```html
<button
    @click="$postx('/save', {
    include: ['user', 'settings'],
    exclude: ['tempData'],
    headers: { 'X-Custom': 'value' },
    retryInterval: 1000,
    retryScaler: 2,
    retryMaxWaitMs: 30000,
    retryMaxCount: 10,
    requestCancellation: true,
    onProgress: (percent) => console.log(percent)
})"
>
    Save
</button>
```

| Option                | Type     | Default | Description                    |
| --------------------- | -------- | ------- | ------------------------------ |
| `include`             | array    | `null`  | Only send these state keys     |
| `exclude`             | array    | `null`  | Don't send these state keys    |
| `headers`             | object   | `{}`    | Additional request headers     |
| `retryInterval`       | number   | `1000`  | Initial retry delay (ms)       |
| `retryScaler`         | number   | `2`     | Exponential backoff multiplier |
| `retryMaxWaitMs`      | number   | `30000` | Maximum retry delay (ms)       |
| `retryMaxCount`       | number   | `10`    | Maximum retry attempts         |
| `requestCancellation` | bool     | `false` | Cancel previous request        |
| `onProgress`          | function | `null`  | Upload progress callback       |

### CSRF Protection

The `@gale` directive adds `<meta name="csrf-token">`. The `x` variants (`$postx`, etc.) read this token automatically.

#### Configuration

```javascript
Alpine.gale.configureCsrf({
    headerName: "X-CSRF-TOKEN",
    metaName: "csrf-token",
    cookieName: "XSRF-TOKEN",
});

// Get current config
const config = Alpine.gale.getCsrfConfig();
```

| Option       | Default          | Description             |
| ------------ | ---------------- | ----------------------- |
| `headerName` | `'X-CSRF-TOKEN'` | Header name for token   |
| `metaName`   | `'csrf-token'`   | Meta tag name to read   |
| `cookieName` | `'XSRF-TOKEN'`   | Cookie name as fallback |

### Global State ($gale)

The `$gale` magic provides global connection state:

```html
<div x-data>
    <div x-show="$gale.loading">Loading...</div>
    <div x-show="$gale.retrying">Reconnecting...</div>
    <div x-show="$gale.retriesFailed">Connection failed</div>

    <div x-show="$gale.error">
        Error: <span x-text="$gale.lastError"></span>
    </div>

    <span x-text="$gale.activeCount + ' requests active'"></span>

    <ul>
        <template x-for="err in $gale.errors">
            <li x-text="err"></li>
        </template>
    </ul>

    <button @click="$gale.clearErrors()">Clear Errors</button>
</div>
```

| Property        | Type     | Description                  |
| --------------- | -------- | ---------------------------- |
| `loading`       | bool     | Any request in progress      |
| `activeCount`   | number   | Number of active requests    |
| `retrying`      | bool     | Currently retrying a request |
| `retriesFailed` | bool     | All retries exhausted        |
| `error`         | bool     | Has any error                |
| `lastError`     | string   | Most recent error message    |
| `errors`        | array    | All error messages           |
| `clearErrors()` | function | Clear all errors             |

### Element State ($fetching)

The `$fetching()` magic function tracks per-element loading state:

```html
<button @click="$postx('/save')" :disabled="$fetching()">
    <span x-show="!$fetching()">Save</span>
    <span x-show="$fetching()">Saving...</span>
</button>
```

Note: `$fetching` is a function—always use `$fetching()` with parentheses. Set automatically when the element initiates a request.

### Loading Directives

#### x-indicator

Creates a boolean state variable tracking requests within the element tree:

```html
<div x-data="{ saving: false }" x-indicator="saving">
    <button @click="$postx('/save')" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>
</div>
```

#### x-loading

Show/hide elements or apply classes during loading:

```html
<!-- Show during loading -->
<div x-loading>Loading...</div>

<!-- Hide during loading -->
<div x-loading.remove>Content</div>

<!-- Add class during loading -->
<button x-loading.class="opacity-50">Submit</button>

<!-- Add attribute during loading -->
<button x-loading.attr="disabled">Submit</button>

<!-- Delay showing (prevents flicker for fast requests) -->
<div x-loading.delay.200ms>Loading...</div>
```

| Modifier      | Description                  |
| ------------- | ---------------------------- |
| `.class`      | Add class(es) during loading |
| `.attr`       | Add attribute during loading |
| `.remove`     | Hide element during loading  |
| `.delay.{ms}` | Delay before showing         |

### Navigation

#### x-navigate Directive

Enable SPA navigation on links and forms:

```html
<!-- Links -->
<a href="/users" x-navigate>Users</a>

<!-- Forms -->
<form action="/search" method="GET" x-navigate>
    <input name="q" type="text" />
    <button type="submit">Search</button>
</form>

<!-- Dynamic URL -->
<button x-navigate="'/users/' + userId">View</button>
```

#### Navigation Modifiers

```html
<!-- Merge with current query params -->
<a href="/users?sort=name" x-navigate.merge>Sort</a>

<!-- Replace history instead of push -->
<a href="/users" x-navigate.replace>Users</a>

<!-- Navigation key for partial updates -->
<a href="/users" x-navigate.key.sidebar>Users</a>

<!-- Keep only specific params -->
<a href="/search?q=test" x-navigate.only.q.category>Search</a>

<!-- Keep all except specific params -->
<a href="/search" x-navigate.except.page>Reset Page</a>

<!-- Debounce (for inputs) -->
<input x-navigate.debounce.300ms="'/search?q=' + $el.value" />

<!-- Throttle -->
<button x-navigate.throttle.500ms="'/next'">Next</button>

<!-- Combined -->
<a href="/users?page=2" x-navigate.merge.key.pagination.replace>Page 2</a>
```

| Modifier           | Description                     |
| ------------------ | ------------------------------- |
| `.merge`           | Merge query params with current |
| `.replace`         | Replace history entry           |
| `.key.{name}`      | Navigation key for targeting    |
| `.only.{params}`   | Keep only these params          |
| `.except.{params}` | Remove these params             |
| `.debounce.{ms}`   | Debounce navigation             |
| `.throttle.{ms}`   | Throttle navigation             |

#### $navigate Magic

```html
<button @click="$navigate('/users')">Users</button>

<button
    @click="$navigate('/users', {
    merge: true,
    replace: true,
    key: 'main-content',
    only: ['q'],
    except: ['page']
})"
>
    Navigate
</button>
```

#### x-navigate-skip

Exclude specific links from navigation handling:

```html
<nav x-navigate>
    <a href="/dashboard">Dashboard</a>
    <a href="/external" x-navigate-skip>External</a>
    <a href="/file.pdf" x-navigate-skip>Download</a>
</nav>
```

### Component Registry

Named components that can be targeted from the backend or other components.

#### x-component Directive

```html
<div x-data="{ items: [], total: 0 }" x-component="cart">
    <span x-text="total"></span>
</div>

<!-- With tags -->
<div x-data="{ count: 0 }" x-component="counter" data-tags="widgets,dashboard">
    <span x-text="count"></span>
</div>
```

#### $components Magic

```html
<div x-data>
    <!-- Check existence -->
    <span x-show="$components.has('cart')">Cart loaded</span>

    <!-- Get component data -->
    <button @click="console.log($components.get('cart'))">Log Cart</button>

    <!-- Get all components -->
    <button @click="console.log($components.all())">Log All</button>

    <!-- Get by tag -->
    <button @click="$components.getByTag('widgets').forEach(c => c.refresh())">
        Refresh Widgets
    </button>

    <!-- Update state -->
    <button @click="$components.update('cart', { total: 0 })">
        Clear Cart
    </button>

    <!-- Create state (like state() but creates new) -->
    <button @click="$components.create('cart', { currency: 'EUR' })">
        Set Currency
    </button>

    <!-- Delete state keys -->
    <button @click="$components.delete('cart', ['tempItems'])">Clean Up</button>

    <!-- Invoke method -->
    <button @click="$components.invoke('cart', 'recalculate')">
        Recalculate
    </button>

    <!-- Reactive state access -->
    <span x-text="$components.state('cart', 'total')"></span>

    <!-- Watch for changes -->
    <div
        x-init="$components.watch('cart', 'total', (val) => console.log(val))"
    ></div>

    <!-- Wait for component -->
    <div x-init="$components.when('cart').then(c => console.log(c))"></div>

    <!-- Callback when ready -->
    <div x-init="$components.onReady('cart', (c) => console.log(c))"></div>
</div>
```

| Method                            | Description                             |
| --------------------------------- | --------------------------------------- |
| `get(name)`                       | Get component Alpine data object        |
| `getByTag(tag)`                   | Get all components with tag             |
| `all()`                           | Get all registered components           |
| `has(name)`                       | Check if component exists               |
| `invoke(name, method, ...args)`   | Call method on component                |
| `when(name, timeout?)`            | Promise resolving when component exists |
| `onReady(name, callback)`         | Callback when component ready           |
| `state(name, property)`           | Get reactive state value                |
| `update(name, state)`             | Merge state into component              |
| `create(name, state, options)`    | Set state (with onlyIfMissing option)   |
| `delete(name, keys)`              | Remove state keys                       |
| `watch(name, property, callback)` | Watch for changes                       |

#### $invoke Shorthand

```html
<button @click="$invoke('cart', 'addItem', productId, qty)">Add</button>
```

#### Lifecycle Hooks

```javascript
// When component registers
Alpine.gale.onComponentRegistered((name, component) => {
    console.log(`${name} registered`);
});

// When component unregisters
Alpine.gale.onComponentUnregistered((name) => {
    console.log(`${name} unregistered`);
});

// When component state changes
Alpine.gale.onComponentStateChanged((name, property, value) => {
    console.log(`${name}.${property} = ${value}`);
});
```

### File Uploads

#### x-files Directive

```html
<div x-data>
    <input type="file" name="avatar" x-files />

    <div x-show="$file('avatar')">
        <p>Name: <span x-text="$file('avatar')?.name"></span></p>
        <p>Size: <span x-text="$formatBytes($file('avatar')?.size)"></span></p>
        <img :src="$filePreview('avatar')" />
    </div>

    <button @click="$postx('/upload')">Upload</button>
</div>
```

#### Multiple Files

```html
<input type="file" name="docs" x-files multiple />

<template x-for="(file, i) in $files('docs')" :key="i">
    <div>
        <span x-text="file.name"></span>
        <span x-text="$formatBytes(file.size)"></span>
        <img :src="$filePreview('docs', i)" />
    </div>
</template>

<button @click="$clearFiles('docs')">Clear</button>
```

#### Validation Modifiers

```html
<!-- Max size (5MB) -->
<input type="file" x-files.max-size-5mb />

<!-- Max files -->
<input type="file" x-files.max-files-3 multiple />

<!-- Combined -->
<input type="file" x-files.max-size-10mb.max-files-5 multiple />
```

#### File Events

```html
<div x-data @gale:file-change="console.log($event.detail)">
    <input type="file" x-files />
</div>

<div x-data @gale:file-error="alert($event.detail.message)">
    <input type="file" x-files.max-size-1mb />
</div>
```

| Event              | Detail                    |
| ------------------ | ------------------------- |
| `gale:file-change` | `{ name, files }`         |
| `gale:file-error`  | `{ name, message, type }` |

#### File Magics

| Magic                           | Description                    |
| ------------------------------- | ------------------------------ |
| `$file(name)`                   | Get single file info           |
| `$files(name)`                  | Get array of files             |
| `$filePreview(name, index?)`    | Get preview URL                |
| `$clearFiles(name?)`            | Clear file input(s)            |
| `$formatBytes(size, decimals?)` | Format bytes to human-readable |
| `$uploading`                    | Upload in progress             |
| `$uploadProgress`               | Progress 0-100                 |
| `$uploadError`                  | Error message                  |

### Message Display

#### x-message Directive

Display messages from state:

```html
<div x-data="{ messages: {} }">
    <input name="email" type="email" />
    <span x-message="email" class="text-red-500"></span>

    <input name="password" type="password" />
    <span x-message="password" class="text-red-500"></span>

    <div x-message="_success" class="text-green-500"></div>

    <button @click="$postx('/login')">Login</button>
</div>
```

#### Message Path

Access nested message paths:

```html
<span x-message="user.email"></span>
```

#### Array Validation with x-for

Use template literals to display validation errors for array items in loops:

```html
<div x-data="{
    items: [
        { name: '', quantity: 1 },
        { name: '', quantity: 1 }
    ],
    messages: {}
}">
    <template x-for="(item, index) in items" :key="index">
        <div>
            <input x-model="items[index].name">
            <span x-message="`items.${index}.name`" class="text-red-500"></span>

            <input x-model="items[index].quantity" type="number">
            <span x-message="`items.${index}.quantity`" class="text-red-500"></span>
        </div>
    </template>

    <button @click="$postx('/validate-items')">Validate</button>
</div>
```

Backend validation with `validateState`:

```php
Route::post('/validate-items', function (Request $request) {
    $validated = $request->validateState([
        'items' => 'required|array|min:1',
        'items.*.name' => 'required|string|min:2',
        'items.*.quantity' => 'required|integer|min:1',
    ], [
        'items.*.name.required' => 'Item name is required',
        'items.*.name.min' => 'Item name must be at least 2 characters',
        'items.*.quantity.min' => 'Quantity must be at least 1',
    ]);

    return gale()->messages(['_success' => 'All items validated!']);
});
```

**Supported expression syntaxes:**

```html
<!-- Template literals (recommended) -->
<span x-message="`items.${index}.name`"></span>

<!-- String concatenation -->
<span x-message="'items.' + index + '.name'"></span>

<!-- Nested arrays -->
<span x-message="`items.${i}.details.${j}.value`"></span>
```

**Wildcard clearing:** When using `validateState` with wildcard rules like `items.*.name`, all matching message keys (e.g., `items.0.name`, `items.1.name`) are automatically cleared before validation, ensuring stale errors from removed items don't persist.

#### Message Types

Messages can include type prefixes for styling:

```php
gale()->messages([
    'email' => '[ERROR] Invalid email',
    'saved' => '[SUCCESS] Changes saved',
    'note' => '[WARNING] Session expiring',
    'info' => '[INFO] New features available',
]);
```

Auto-applied classes: `message-error`, `message-success`, `message-warning`, `message-info`.

#### Configuration

```javascript
Alpine.gale.configureMessage({
    defaultStateKey: "messages",
    autoHide: true,
    autoShow: true,
    typeClasses: {
        success: "message-success",
        error: "message-error",
        warning: "message-warning",
        info: "message-info",
    },
});
```

### Polling

#### x-poll Directive

```html
<!-- Default 5 second interval -->
<div x-data="{ status: '' }" x-poll="/api/status">
    <span x-text="status"></span>
</div>

<!-- Custom interval -->
<div x-poll.2s="/api/data">...</div>
<div x-poll.500ms="/api/live">...</div>
<div x-poll.30s="/api/stats">...</div>
```

#### Modifiers

```html
<!-- Only poll when visible -->
<div x-poll.visible.5s="/api/status">...</div>

<!-- With CSRF -->
<div x-poll.csrf.5s="/api/protected">...</div>

<!-- Stop on condition -->
<div x-data="{ done: false }" x-poll.2s="/api/job" x-poll-stop="done">
    Processing...
</div>
```

| Modifier   | Description                           |
| ---------- | ------------------------------------- |
| `.{time}`  | Poll interval (e.g., `.5s`, `.500ms`) |
| `.visible` | Only poll when tab visible            |
| `.csrf`    | Include CSRF token                    |

### Confirmation Dialogs

#### x-confirm Directive

```html
<!-- Default message -->
<button @click="$deletex('/item/1')" x-confirm>Delete</button>

<!-- Custom message -->
<button
    @click="$deletex('/item/1')"
    x-confirm="Are you sure you want to delete this item?"
>
    Delete
</button>

<!-- Dynamic message -->
<button
    @click="$deletex('/user/' + userId)"
    x-confirm="'Delete ' + userName + '?'"
>
    Delete
</button>
```

#### Configuration

```javascript
Alpine.gale.configureConfirm({
    defaultMessage: "Are you sure?",
    handler: async (message) => {
        // Custom modal
        return await myModal.confirm(message);
    },
});
```

---

## Advanced Topics

### SSE Protocol Specification

Gale uses Server-Sent Events with specific event types and data formats.

#### Event Types

| Event                  | Purpose                           |
| ---------------------- | --------------------------------- |
| `gale-patch-state`     | Merge state into Alpine component |
| `gale-patch-elements`  | DOM manipulation                  |
| `gale-patch-component` | Update named component            |
| `gale-invoke-method`   | Call method on component          |

#### gale-patch-state Format

```
event: gale-patch-state
data: state {"count":1,"user":{"name":"John"}}
data: onlyIfMissing false
```

| Data Line              | Description         |
| ---------------------- | ------------------- |
| `state {json}`         | State to merge      |
| `onlyIfMissing {bool}` | Only set if missing |

#### gale-patch-elements Format

```
event: gale-patch-elements
data: selector #content
data: mode morph
data: elements <div id="content">...</div>
data: useViewTransition true
data: settle 100
data: limit 10
```

| Data Line                  | Description                            |
| -------------------------- | -------------------------------------- |
| `selector {css}`           | Target element(s)                      |
| `mode {mode}`              | Patch mode                             |
| `elements {html}`          | HTML content (can span multiple lines) |
| `useViewTransition {bool}` | Use View Transitions                   |
| `settle {ms}`              | Delay before patch                     |
| `limit {n}`                | Max elements                           |

#### gale-patch-component Format

```
event: gale-patch-component
data: component cart
data: state {"total":42}
data: onlyIfMissing false
```

#### gale-invoke-method Format

```
event: gale-invoke-method
data: component cart
data: method recalculate
data: args [10,20]
```

### State Serialization

When making requests, Alpine Gale serializes the component's `x-data`:

#### What Gets Serialized

-   All enumerable properties on the Alpine data object
-   Nested objects (recursively)
-   Arrays

#### What Doesn't Get Serialized

-   Functions
-   DOM elements
-   Circular references (skipped)
-   Properties starting with `_` or `$`

#### Controlling Serialization

```html
<button
    @click="$postx('/save', {
    include: ['user', 'settings'],
    exclude: ['cache', 'temp']
})"
>
    Save
</button>
```

#### Component Inclusion

```html
<button
    @click="$postx('/save', {
    includeComponents: ['cart', 'wishlist'],
    includeComponentsByTag: ['forms']
})"
>
    Save All
</button>
```

### DOM Morphing Modes

| Mode      | Alias         | Description                              |
| --------- | ------------- | ---------------------------------------- |
| `morph`   | —             | Intelligent diff, preserves Alpine state |
| `inner`   | `innerHTML`   | Replace inner content                    |
| `outer`   | `outerHTML`   | Replace entire element                   |
| `replace` | —             | Same as outer                            |
| `prepend` | `beforebegin` | Insert as first child                    |
| `append`  | `beforeend`   | Insert as last child                     |
| `before`  | `afterbegin`  | Insert before element                    |
| `after`   | `afterend`    | Insert after element                     |
| `remove`  | `delete`      | Remove element(s)                        |

#### Morph Behavior

The `morph` mode uses Alpine Morph to:

-   Preserve Alpine component state
-   Minimize DOM changes
-   Maintain focus and scroll position
-   Handle script execution

### View Transitions API

Enable smooth page transitions:

```php
gale()->view('page', $data, ['useViewTransition' => true]);

gale()->html($html, [
    'selector' => '#content',
    'useViewTransition' => true,
]);
```

**CSS:**

```css
::view-transition-old(root) {
    animation: fade-out 0.3s ease-out;
}

::view-transition-new(root) {
    animation: fade-in 0.3s ease-in;
}

@keyframes fade-out {
    to {
        opacity: 0;
    }
}
@keyframes fade-in {
    from {
        opacity: 0;
    }
}
```

Falls back gracefully in unsupported browsers.

### Conditional Execution

#### when() / unless()

```php
gale()->when($condition, function ($gale) {
    $gale->state('visible', true);
});

gale()->when(
    $user->isAdmin(),
    fn($g) => $g->state('role', 'admin'),
    fn($g) => $g->state('role', 'user')
);

gale()->unless($user->isGuest(), function ($gale) use ($user) {
    $gale->state('user', $user->toArray());
});
```

#### whenGale() / whenNotGale()

```php
gale()->whenGale(
    fn($g) => $g->state('partial', true),
    fn($g) => $g->web(view('full'))
);

gale()->whenNotGale(function ($gale) {
    return view('full-page');
});
```

#### whenGaleNavigate()

```php
gale()->whenGaleNavigate('sidebar', function ($gale) {
    $gale->fragment('layout', 'sidebar', $data);
});
```

#### web()

Set response for non-Gale requests:

```php
return gale()
    ->state('data', $data)
    ->web(view('page', compact('data')));
```

---

## API Reference

### GaleResponse Methods

| Method             | Signature                                                                                          | Description                 |
| ------------------ | -------------------------------------------------------------------------------------------------- | --------------------------- |
| `state`            | `state(string\|array $key, mixed $value = null, array $options = [])`                              | Set state                   |
| `forget`           | `forget(string\|array $keys)`                                                                      | Remove state keys           |
| `messages`         | `messages(array $messages)`                                                                        | Set messages state          |
| `clearMessages`    | `clearMessages()`                                                                                  | Clear messages              |
| `view`             | `view(string $view, array $data = [], array $options = [], bool $web = false)`                     | Render view                 |
| `fragment`         | `fragment(string $view, string $fragment, array $data = [], array $options = [])`                  | Render fragment             |
| `fragments`        | `fragments(array $fragments)`                                                                      | Render multiple fragments   |
| `html`             | `html(string $html, array $options = [], bool $web = false)`                                       | Patch HTML                  |
| `append`           | `append(string $selector, string $html)`                                                           | Append HTML                 |
| `prepend`          | `prepend(string $selector, string $html)`                                                          | Prepend HTML                |
| `before`           | `before(string $selector, string $html)`                                                           | Insert before               |
| `after`            | `after(string $selector, string $html)`                                                            | Insert after                |
| `inner`            | `inner(string $selector, string $html)`                                                            | Replace inner               |
| `outer`            | `outer(string $selector, string $html)`                                                            | Replace outer               |
| `replace`          | `replace(string $selector, string $html)`                                                          | Replace element             |
| `remove`           | `remove(string $selector)`                                                                         | Remove element              |
| `js`               | `js(string $script, array $options = [])`                                                          | Execute JavaScript          |
| `dispatch`         | `dispatch(string $event, array $data = [], array $options = [])`                                   | Dispatch event              |
| `navigate`         | `navigate(string\|array $url, string $key = 'true', array $options = [])`                          | Navigate                    |
| `navigateWith`     | `navigateWith(string\|array $url, string $key = 'true', bool $merge = false, array $options = [])` | Navigate with merge control |
| `navigateMerge`    | `navigateMerge(string\|array $url, string $key = 'true', array $options = [])`                     | Navigate with merge         |
| `navigateClean`    | `navigateClean(string\|array $url, string $key = 'true', array $options = [])`                     | Navigate without merge      |
| `navigateOnly`     | `navigateOnly(string\|array $url, array $only, string $key = 'true')`                              | Keep only params            |
| `navigateExcept`   | `navigateExcept(string\|array $url, array $except, string $key = 'true')`                          | Remove params               |
| `navigateReplace`  | `navigateReplace(string\|array $url, string $key = 'true', array $options = [])`                   | Replace history             |
| `updateQueries`    | `updateQueries(array $queries, string $key = 'filters', bool $merge = true)`                       | Update query params         |
| `clearQueries`     | `clearQueries(array $paramNames, string $key = 'clear')`                                           | Clear query params          |
| `reload`           | `reload()`                                                                                         | Reload page                 |
| `componentState`   | `componentState(string $name, array $state, array $options = [])`                                  | Update component            |
| `componentMethod`  | `componentMethod(string $name, string $method, array $args = [])`                                  | Call component method       |
| `redirect`         | `redirect(string $url)`                                                                            | Create redirect             |
| `stream`           | `stream(callable $callback)`                                                                       | Stream mode                 |
| `when`             | `when(mixed $condition, callable $true, ?callable $false = null)`                                  | Conditional                 |
| `unless`           | `unless(mixed $condition, callable $callback)`                                                     | Inverse conditional         |
| `whenGale`         | `whenGale(callable $gale, ?callable $web = null)`                                                  | Gale request check          |
| `whenNotGale`      | `whenNotGale(callable $callback)`                                                                  | Non-Gale check              |
| `whenGaleNavigate` | `whenGaleNavigate(?string $key, callable $callback)`                                               | Navigate check              |
| `web`              | `web(mixed $response)`                                                                             | Set web fallback            |
| `withEventId`      | `withEventId(string $id)`                                                                          | Set SSE event ID            |
| `withRetry`        | `withRetry(int $ms)`                                                                               | Set SSE retry               |
| `reset`            | `reset()`                                                                                          | Clear events                |

### GaleRedirect Methods

| Method        | Signature                                                        | Description              |
| ------------- | ---------------------------------------------------------------- | ------------------------ |
| `with`        | `with(string\|array $key, mixed $value = null)`                  | Flash data               |
| `withInput`   | `withInput(?array $input = null)`                                | Flash input              |
| `withErrors`  | `withErrors(mixed $errors)`                                      | Flash errors             |
| `back`        | `back(string $fallback = '/')`                                   | Go back                  |
| `backOr`      | `backOr(string $route, array $params = [])`                      | Back with route fallback |
| `refresh`     | `refresh(bool $query = true, bool $fragment = false)`            | Refresh page             |
| `home`        | `home()`                                                         | Go to root               |
| `route`       | `route(string $name, array $params = [], bool $absolute = true)` | Named route              |
| `intended`    | `intended(string $default = '/')`                                | Auth intended            |
| `forceReload` | `forceReload(bool $bypass = false)`                              | Hard reload              |

### Request Macros Reference

| Macro              | Signature                                                              | Description             |
| ------------------ | ---------------------------------------------------------------------- | ----------------------- |
| `isGale`           | `isGale()`                                                             | Check Gale request      |
| `state`            | `state(?string $key = null, mixed $default = null)`                    | Get state               |
| `isGaleNavigate`   | `isGaleNavigate(string\|array\|null $key = null)`                      | Check navigate          |
| `galeNavigateKey`  | `galeNavigateKey()`                                                    | Get navigate key        |
| `galeNavigateKeys` | `galeNavigateKeys()`                                                   | Get navigate keys array |
| `validateState`    | `validateState(array $rules, array $messages = [], array $attrs = [])` | Validate state          |

### Frontend Magics Reference

| Magic                            | Description                        |
| -------------------------------- | ---------------------------------- |
| `$get(url, options?)`            | GET request                        |
| `$post(url, options?)`           | POST request                       |
| `$postx(url, options?)`          | POST with CSRF                     |
| `$patch(url, options?)`          | PATCH request                      |
| `$patchx(url, options?)`         | PATCH with CSRF                    |
| `$put(url, options?)`            | PUT request                        |
| `$putx(url, options?)`           | PUT with CSRF                      |
| `$delete(url, options?)`         | DELETE request                     |
| `$deletex(url, options?)`        | DELETE with CSRF                   |
| `$gale`                          | Global connection state            |
| `$fetching()`                    | Element loading state (function)   |
| `$navigate(url, options?)`       | Programmatic navigation            |
| `$components`                    | Component registry API             |
| `$invoke(name, method, ...args)` | Shorthand for `$components.invoke` |
| `$file(name)`                    | Get file info                      |
| `$files(name)`                   | Get files array                    |
| `$filePreview(name, index?)`     | Get preview URL                    |
| `$clearFiles(name?)`             | Clear files                        |
| `$formatBytes(size, decimals?)`  | Format bytes                       |
| `$uploading`                     | Upload in progress                 |
| `$uploadProgress`                | Upload progress 0-100              |
| `$uploadError`                   | Upload error message               |

### Frontend Directives Reference

| Directive            | Description              |
| -------------------- | ------------------------ |
| `x-navigate`         | Enable SPA navigation    |
| `x-navigate-skip`    | Skip navigation handling |
| `x-component="name"` | Register named component |
| `x-files`            | File input binding       |
| `x-message="key"`    | Display message          |
| `x-loading`          | Loading state display    |
| `x-indicator="var"`  | Create loading variable  |
| `x-poll="url"`       | Auto-polling             |
| `x-poll-stop="expr"` | Stop polling condition   |
| `x-confirm`          | Confirmation dialog      |

### Request Options Reference

| Option                   | Type     | Default | Description              |
| ------------------------ | -------- | ------- | ------------------------ |
| `include`                | string[] | —       | Only send these keys     |
| `exclude`                | string[] | —       | Don't send these keys    |
| `headers`                | object   | `{}`    | Additional headers       |
| `retryInterval`          | number   | `1000`  | Initial retry (ms)       |
| `retryScaler`            | number   | `2`     | Backoff multiplier       |
| `retryMaxWaitMs`         | number   | `30000` | Max retry wait (ms)      |
| `retryMaxCount`          | number   | `10`    | Max retries              |
| `requestCancellation`    | boolean  | `false` | Cancel previous          |
| `onProgress`             | function | —       | Progress callback        |
| `includeComponents`      | string[] | —       | Include component states |
| `includeComponentsByTag` | string[] | —       | Include by tag           |

### SSE Events Reference

| Event                  | Data Lines                                                             |
| ---------------------- | ---------------------------------------------------------------------- |
| `gale-patch-state`     | `state`, `onlyIfMissing`                                               |
| `gale-patch-elements`  | `selector`, `mode`, `elements`, `useViewTransition`, `settle`, `limit` |
| `gale-patch-component` | `component`, `state`, `onlyIfMissing`                                  |
| `gale-invoke-method`   | `component`, `method`, `args`                                          |

### Configuration Reference

#### Backend (config/gale.php)

```php
return [
    'route_discovery' => [
        'enabled' => false,
        'discover_controllers_in_directory' => [],
        'discover_views_in_directory' => [],
        'pending_route_transformers' => [
            ...Dancycodes\Gale\Routing\Config::defaultRouteTransformers(),
        ],
    ],
];
```

#### Frontend (JavaScript)

```javascript
// CSRF
Alpine.gale.configureCsrf({
    headerName: "X-CSRF-TOKEN",
    metaName: "csrf-token",
    cookieName: "XSRF-TOKEN",
});

// Messages
Alpine.gale.configureMessage({
    defaultStateKey: "messages",
    autoHide: true,
    autoShow: true,
    typeClasses: {
        /* ... */
    },
});

// Confirmation
Alpine.gale.configureConfirm({
    defaultMessage: "Are you sure?",
    handler: (message) => confirm(message),
});

// Navigation
Alpine.gale.configureNavigation({
    // Navigation options
});

// Get current configs
Alpine.gale.getCsrfConfig();
Alpine.gale.getMessageConfig();
Alpine.gale.getConfirmConfig();
Alpine.gale.getNavigationConfig();
```

---

## Troubleshooting

### Common Issues

| Issue                  | Cause                     | Solution                           |
| ---------------------- | ------------------------- | ---------------------------------- |
| "No Alpine context"    | Magic used outside x-data | Wrap in x-data element             |
| CSRF token mismatch    | Token not sent            | Use `$postx` instead of `$post`    |
| State not updating     | Wrong key                 | Check Alpine x-data property names |
| 419 error              | Session expired           | Refresh page or use meta token     |
| Navigation not working | Missing x-navigate        | Add directive to link/form         |
| File upload fails      | Missing x-files           | Add directive to file input        |
| Messages not showing   | Wrong key                 | Check x-message matches server key |
| Polling not stopping   | Condition never true      | Check x-poll-stop expression       |

### Debugging

```html
<!-- Log all state changes -->
<div x-data="{ count: 0 }" x-init="$watch('count', v => console.log(v))">
    <!-- Check $gale state -->
    <div x-data x-init="console.log($gale)">
        <!-- View component registry -->
        <button @click="console.log($components.all())">Debug</button>
    </div>
</div>
```

---

## Testing

### Package Tests

```bash
cd packages/dancycodes/gale
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/pint
```

---

## License

MIT License. See [LICENSE](LICENSE).

---

## Credits

Created by **DancyCodes** — dancycodes@gmail.com

-   [Laravel](https://laravel.com)
-   [Alpine.js](https://alpinejs.dev)
-   [Datastar](https://data-star.dev) — SSE inspiration
