# Laravel Gale

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-11%2B%20%7C%2012%2B-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Alpine.js](https://img.shields.io/badge/Alpine.js-3.x-8BC0D0?style=flat-square&logo=alpine.js)](https://alpinejs.dev)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

**Laravel Gale** is a complete reactive frontend framework for Laravel applications that combines server-driven state management with Alpine.js reactivity. Build dynamic, real-time interfaces using Blade templates and Server-Sent Events (SSE) - no JavaScript framework needed.

---

## Table of Contents

-   [Why Laravel Gale?](#why-laravel-gale)
-   [Quick Start](#quick-start)
-   [Installation](#installation)
-   [Core Concepts](#core-concepts)
-   [Backend Features (Laravel)](#backend-features-laravel)
    -   [The `gale()` Helper](#the-gale-helper)
    -   [State Management](#state-management)
    -   [DOM Manipulation](#dom-manipulation)
    -   [Blade Fragments](#blade-fragments)
    -   [Redirects](#redirects)
    -   [Streaming Mode](#streaming-mode)
    -   [SPA Navigation](#spa-navigation)
    -   [Custom Events](#custom-events)
    -   [Request Macros](#request-macros)
    -   [Blade Directives](#blade-directives)
    -   [Validation](#validation)
    -   [Route Discovery](#route-discovery)
-   [Frontend Features (Alpine Gale)](#frontend-features-alpine-gale)
    -   [HTTP Magics](#http-magics)
    -   [CSRF Protection](#csrf-protection)
    -   [Loading States](#loading-states)
    -   [SPA Navigation Directive](#spa-navigation-directive)
    -   [Message Display](#message-display)
    -   [Component Registry](#component-registry)
    -   [File Uploads](#file-uploads)
    -   [Polling](#polling)
    -   [Confirmation Dialogs](#confirmation-dialogs)
-   [Advanced Usage](#advanced-usage)
    -   [Conditional Execution](#conditional-execution)
    -   [Component State from Backend](#component-state-from-backend)
    -   [DOM Morphing Modes](#dom-morphing-modes)
    -   [View Transitions API](#view-transitions-api)
-   [Configuration](#configuration)
-   [Comparison with Alternatives](#comparison-with-alternatives)
-   [Testing](#testing)
-   [Contributing](#contributing)
-   [License](#license)

---

## Why Laravel Gale?

Traditional reactive frameworks require you to:

-   Write duplicate logic in JavaScript
-   Manage complex state synchronization
-   Build and maintain APIs
-   Handle SSR/hydration complexity

**Laravel Gale takes a different approach:**

```
Your Laravel Controller          Your Blade Template
        |                               |
        | gale()->state(...)            | x-data, x-text, etc.
        | gale()->view(...)             |
        v                               v
   SSE Response ----------------------> Alpine.js
        |                               |
        '------- Reactive UI Updates ---'
```

**Benefits:**

-   **Server-side rendering** - Full SEO support, fast initial load
-   **No build step required** - Works with vanilla Blade templates
-   **Blade-first development** - Use your existing Laravel knowledge
-   **Automatic CSRF** - Built-in Laravel session integration
-   **Real-time updates** - SSE enables push-based reactivity
-   **Progressive enhancement** - Falls back gracefully
-   **Bundled Dependencies** - Alpine.js, Alpine Morph, and Alpine Gale are bundled together

---

## Quick Start

Here's a complete counter example in just a few lines:

**Route (routes/web.php):**

```php
use Illuminate\Support\Facades\Route;

Route::get('/counter', fn() => view('counter'));

Route::post('/increment', function () {
    $count = request()->state('count', 0);
    return gale()->state('count', $count + 1);
});
```

**Blade Template (resources/views/counter.blade.php):**

```html
<!DOCTYPE html>
<html>
    <head>
        <title>Counter</title>
        @gale
    </head>
    <body>
        <div x-data="{ count: 0 }">
            <p>Count: <span x-text="count"></span></p>
            <button @click="$postx('/increment')">Increment</button>
        </div>
    </body>
</html>
```

That's it! Click the button, and the count updates reactively via SSE.

---

## Installation

### Step 1: Install the Package

```bash
composer require dancycodes/gale
```

The package auto-registers with Laravel - no manual service provider registration needed.

### Step 2: Publish Assets

```bash
php artisan vendor:publish --tag=gale-assets
```

This publishes the JavaScript bundle to `public/vendor/gale/js/gale.js`.

### Step 3: Add the Directive

Add `@gale` to your Blade layout's `<head>`:

```html
<head>
    @gale
</head>
```

This directive outputs:

-   CSRF meta tag for Laravel session integration
-   Alpine.js with Alpine Morph plugin
-   Alpine Gale plugin (all bundled together)

**That's it!** You're ready to build reactive Laravel applications.

### Optional: Publish Configuration

```bash
php artisan vendor:publish --tag=gale-config
```

---

## Core Concepts

### How It Works

Laravel Gale uses **Server-Sent Events (SSE)** to push updates from your Laravel backend to Alpine.js components in the browser.

```
+---------------------------------------------------------------+
|                        BROWSER                                 |
|  +----------------------------------------------------------+  |
|  |  Alpine.js Component (x-data)                            |  |
|  |    State: { count: 0, user: {...} }                      |  |
|  |    Template: <span x-text="count">                       |  |
|  +----------------------------------------------------------+  |
|                           |                                    |
|                           | $postx('/increment')               |
|                           v                                    |
|  +----------------------------------------------------------+  |
|  |  HTTP Request                                            |  |
|  |    Headers: Gale-Request, X-CSRF-TOKEN                   |  |
|  |    Body: { count: 0, user: {...} }  (serialized state)   |  |
|  +----------------------------------------------------------+  |
+---------------------------------------------------------------+
                            |
                            v
+---------------------------------------------------------------+
|                      LARAVEL SERVER                            |
|  +----------------------------------------------------------+  |
|  |  Controller                                              |  |
|  |    $count = request()->state('count');                   |  |
|  |    return gale()->state('count', $count + 1);            |  |
|  +----------------------------------------------------------+  |
|                           |                                    |
|                           v                                    |
|  +----------------------------------------------------------+  |
|  |  SSE Response (text/event-stream)                        |  |
|  |    event: gale-patch-state                               |  |
|  |    data: state {"count":1}                               |  |
|  +----------------------------------------------------------+  |
+---------------------------------------------------------------+
                            |
                            v
+---------------------------------------------------------------+
|  Alpine.js receives SSE event and merges state                 |
|    State: { count: 1, user: {...} }                            |
|    UI automatically updates via Alpine reactivity              |
+---------------------------------------------------------------+
```

### RFC 7386 JSON Merge Patch

State updates use **RFC 7386 JSON Merge Patch** semantics:

-   Values are merged/updated: `{ count: 5 }` sets count to 5
-   `null` deletes a property: `{ count: null }` removes count
-   Nested objects are recursively merged

### Important: Alpine.js Context Requirement

**All Alpine Gale frontend features require an Alpine.js context.** This means:

```html
<!-- CORRECT: Inside x-data -->
<div x-data="{ count: 0 }">
    <button @click="$postx('/increment')">Works!</button>
</div>

<!-- CORRECT: Using x-init for side effects -->
<div x-init="$get('/load-data')">Loading...</div>

<!-- INCORRECT: No Alpine context -->
<button @click="$postx('/increment')">Won't work!</button>
```

---

## Backend Features (Laravel)

### The `gale()` Helper

The `gale()` helper returns a singleton `GaleResponse` instance that provides a fluent API for building SSE responses.

```php
// Basic usage
return gale()->state('count', 42);

// Chaining multiple operations
return gale()
    ->state('count', $count + 1)
    ->state('lastUpdated', now()->toISOString())
    ->messages(['success' => 'Counter incremented!']);

// With view rendering
return gale()
    ->view('partials.counter', ['count' => $count])
    ->state('loading', false);
```

### State Management

#### Setting State

```php
// Single key-value
gale()->state('count', 42);

// Multiple values
gale()->state([
    'count' => 42,
    'user' => ['name' => 'John', 'email' => 'john@example.com'],
    'loading' => false,
]);

// Nested updates (merges with existing)
gale()->state('user', [
    'email' => 'new@example.com'  // Only updates email, keeps name
]);
```

#### Forgetting State

```php
// Remove specific key (sends null per RFC 7386)
gale()->forget('tempData');

// Remove multiple keys
gale()->forget(['tempData', 'cache']);
```

#### Messages State

Messages are a special state for displaying validation errors, success messages, and notifications:

```php
// Set messages
gale()->messages([
    'email' => 'Invalid email address',
    'name' => 'Name is required',
]);

// Success message
gale()->messages(['_success' => 'Profile saved successfully!']);

// Clear all messages
gale()->clearMessages();
```

### DOM Manipulation

#### Rendering Views

```php
// Render and patch a complete view
gale()->view('partials.user-card', ['user' => $user]);

// With patching options
gale()->view('partials.list-item', ['item' => $item], [
    'selector' => '#items-list',
    'mode' => 'append',
]);

// As web fallback for non-Gale requests
gale()->view('dashboard', ['data' => $data], web: true);
```

#### Raw HTML Manipulation

```php
// Replace content
gale()->html('<div id="content">New content</div>');

// With selector and mode
gale()->append('#list', '<li>New item</li>');
gale()->prepend('#list', '<li>First item</li>');
gale()->before('#target', '<div>Before element</div>');
gale()->after('#target', '<div>After element</div>');
gale()->inner('#container', '<p>New inner content</p>');
gale()->outer('#element', '<div id="element">Replaced</div>');
gale()->replace('#old', '<div id="new">Replacement</div>');
gale()->remove('.deprecated-items');
```

### Blade Fragments

Fragments allow you to render only specific parts of a Blade view, avoiding full view compilation:

**Define fragments in your Blade template:**

```blade
<!-- resources/views/todos.blade.php -->
<div id="todo-list">
    @fragment('todo-items')
    @foreach($todos as $todo)
        <div class="todo-item" id="todo-{{ $todo->id }}">
            {{ $todo->title }}
        </div>
    @endforeach
    @endfragment
</div>

@fragment('todo-count')
<span id="count">{{ $todos->count() }} items</span>
@endfragment
```

**Render fragments from your controller:**

```php
// Render single fragment
gale()->fragment('todos', 'todo-items', ['todos' => $todos]);

// Render multiple fragments
gale()->fragments([
    [
        'view' => 'todos',
        'fragment' => 'todo-items',
        'data' => ['todos' => $todos],
        'options' => ['selector' => '#todo-list', 'mode' => 'morph'],
    ],
    [
        'view' => 'todos',
        'fragment' => 'todo-count',
        'data' => ['todos' => $todos],
    ],
]);
```

### Redirects

The redirect builder provides full-page browser redirects with Laravel session flash support:

```php
// Basic redirect
return gale()->redirect('/dashboard');

// With flash data
return gale()->redirect('/login')
    ->with('message', 'Please log in to continue')
    ->with('intended', request()->url());

// With validation errors
return gale()->redirect('/register')
    ->withErrors($validator)
    ->withInput();

// Special redirects
return gale()->redirect('/dashboard')->back('/');      // Go back with fallback
return gale()->redirect('/dashboard')->refresh();      // Refresh current page
return gale()->redirect('/dashboard')->home();         // Go to root URL
return gale()->redirect('/dashboard')->route('home');  // Named route
return gale()->redirect('/dashboard')->intended('/');  // Auth intended URL
return gale()->redirect('/dashboard')->forceReload();  // Hard reload
```

### Streaming Mode

For long-running operations, use streaming mode to send updates in real-time:

```php
return gale()->stream(function ($gale) {
    $users = User::cursor();
    $processed = 0;
    $total = User::count();

    foreach ($users as $user) {
        // Process user...
        $user->processExpensiveOperation();

        // Send progress update immediately
        $processed++;
        $gale->state('progress', [
            'current' => $processed,
            'total' => $total,
            'percentage' => round(($processed / $total) * 100),
        ]);

        // Optional: Update the progress bar fragment
        $gale->fragment('import', 'progress-bar', [
            'percentage' => round(($processed / $total) * 100),
        ]);
    }

    // Final update
    $gale->state([
        'importing' => false,
        'complete' => true,
    ]);
    $gale->messages(['_success' => "Imported {$total} users successfully!"]);
});
```

**Features in streaming mode:**

-   Events are sent immediately as they're added
-   `dd()` and `dump()` output is captured and displayed
-   Exceptions are rendered with full stack traces
-   Redirects work via JavaScript navigation

### SPA Navigation

Navigate programmatically from the backend:

```php
// Basic navigation
gale()->navigate('/users');

// With navigation key (for targeted updates)
gale()->navigate('/users', 'main-content');

// With query parameters merged
gale()->navigateMerge(['page' => 2], 'pagination');

// Clean navigation (no merge)
gale()->navigateClean('/users?page=1');

// Preserve only specific params
gale()->navigateOnly('/search', ['q', 'category']);

// Preserve all except specific params
gale()->navigateExcept('/search', ['page']);

// Replace history instead of push
gale()->navigateReplace('/users');

// Update just query parameters
gale()->updateQueries(['sort' => 'name', 'order' => 'asc']);

// Clear specific query parameters
gale()->clearQueries(['filter', 'search']);
```

### Custom Events

Dispatch browser events for inter-component communication:

```php
// Window event
gale()->dispatch('user-updated', ['id' => $user->id]);

// Targeted to specific elements
gale()->dispatch('refresh', ['section' => 'cart'], [
    'selector' => '.shopping-cart',
]);

// With event options
gale()->dispatch('notification', ['message' => 'Saved!'], [
    'bubbles' => true,
    'cancelable' => true,
]);
```

**Listen in Alpine:**

```html
<div x-data @user-updated.window="fetchUser($event.detail.id)">...</div>
```

### Request Macros

Laravel Gale registers several helpful macros on the Request object:

#### `isGale()`

Check if the current request is a Gale request:

```php
if (request()->isGale()) {
    return gale()->state('data', $data);
}
return view('page', compact('data'));
```

#### `state()`

Access state sent from the Alpine component:

```php
// Get all state
$state = request()->state();

// Get specific key with default
$count = request()->state('count', 0);

// Get nested value
$email = request()->state('user.email');
```

#### `isGaleNavigate()`

Check if the request is a navigation request:

```php
if (request()->isGaleNavigate()) {
    // Return only the main content
    return gale()->fragment('page', 'main-content', $data);
}
```

#### `isGaleNavigate($key)`

Check for specific navigation key:

```php
if (request()->isGaleNavigate('sidebar')) {
    return gale()->fragment('page', 'sidebar', $data);
}

if (request()->isGaleNavigate(['main', 'sidebar'])) {
    return gale()->fragments([...]);
}
```

#### `galeNavigateKey()` / `galeNavigateKeys()`

Get the navigation key(s):

```php
$key = request()->galeNavigateKey();      // 'sidebar' or null
$keys = request()->galeNavigateKeys();    // ['sidebar', 'main']
```

#### `validateState()`

Validate state with reactive message response:

```php
$validated = request()->validateState([
    'email' => 'required|email',
    'name' => 'required|min:2',
], [
    'email.required' => 'Please enter your email',
    'email.email' => 'Invalid email format',
]);

// On validation failure, automatically sends messages via SSE
// On success, returns validated data and clears messages
```

### Blade Directives

#### `@gale`

Include the Gale JavaScript bundle and CSRF meta tag:

```blade
<head>
    @gale
</head>
```

Outputs:

```html
<meta name="csrf-token" content="your-csrf-token" />
<script type="module" src="/vendor/gale/js/gale.js"></script>
```

#### `@ifgale` / `@else`

Conditional rendering based on request type:

```blade
@ifgale
    {{-- Only rendered for Gale requests --}}
    <div id="content">{{ $content }}</div>
@else
    {{-- Only rendered for regular requests --}}
    @include('layouts.full-page')
@endifgale
```

#### `@fragment` / `@endfragment`

Define extractable fragments:

```blade
@fragment('header')
<header>{{ $title }}</header>
@endfragment
```

### Validation

Gale provides seamless validation integration with reactive message display:

```php
// In your controller
public function store(Request $request)
{
    // Method 1: Using validateState macro
    $validated = $request->validateState([
        'name' => 'required|min:2|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8|confirmed',
    ]);

    // If validation fails, messages are automatically sent
    // If validation passes, messages are cleared

    User::create($validated);
    return gale()->messages(['_success' => 'Account created!']);
}

// Method 2: Manual message handling
public function update(Request $request)
{
    $validator = Validator::make($request->state(), [
        'email' => 'required|email',
    ]);

    if ($validator->fails()) {
        return gale()->messages(
            $validator->errors()->toArray()
        );
    }

    // Process...
    return gale()->clearMessages();
}
```

### Route Discovery

Gale includes an optional automatic route discovery system:

**Enable in config/gale.php:**

```php
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

**Use attributes in controllers:**

```php
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\Where;

#[Prefix('/admin')]
class UserController extends Controller
{
    #[Route('GET', '/users')]
    public function index() { ... }

    #[Route('GET', '/users/{id}', name: 'users.show')]
    #[Where('id', '[0-9]+')]
    public function show($id) { ... }
}
```

---

## Frontend Features (Alpine Gale)

**Important:** All frontend features require an Alpine.js context (`x-data` or `x-init`).

### HTTP Magics

Alpine Gale provides magic methods for making HTTP requests that automatically serialize component state and handle SSE responses:

#### Basic HTTP Methods

```html
<div x-data="{ count: 0, user: { name: 'John' } }">
    <!-- GET request -->
    <button @click="$get('/api/data')">Load Data</button>

    <!-- POST request -->
    <button @click="$post('/api/save')">Save</button>

    <!-- PATCH request -->
    <button @click="$patch('/api/update')">Update</button>

    <!-- PUT request -->
    <button @click="$put('/api/replace')">Replace</button>

    <!-- DELETE request -->
    <button @click="$delete('/api/remove')">Delete</button>
</div>
```

#### Request Options

```html
<button
    @click="$post('/save', {
    include: ['user', 'settings'],      // Only send these keys
    exclude: ['tempData', 'cache'],     // Don't send these keys
    headers: { 'X-Custom': 'value' },   // Additional headers
    retryInterval: 1000,                // Initial retry (ms)
    retryScaler: 2,                     // Exponential backoff
    retryMaxWaitMs: 30000,              // Max wait between retries
    retryMaxCount: 10,                  // Max retry attempts
})"
>
    Save with Options
</button>
```

### CSRF Protection

Use the `x` suffix for CSRF-protected requests (recommended for Laravel):

```html
<div x-data="{ form: { name: '', email: '' } }">
    <!-- CSRF-protected requests -->
    <button @click="$postx('/save')">Save (CSRF)</button>
    <button @click="$patchx('/update')">Update (CSRF)</button>
    <button @click="$putx('/replace')">Replace (CSRF)</button>
    <button @click="$deletex('/remove')">Delete (CSRF)</button>
</div>
```

**How CSRF works:**

1. The `@gale` directive adds `<meta name="csrf-token">` to your page
2. `$postx` (and other `x` variants) automatically read this token
3. The token is sent in the `X-CSRF-TOKEN` header
4. Laravel's middleware validates the token

**Configure CSRF:**

```javascript
// Access via Alpine.gale namespace
Alpine.gale.configureCsrf({
    headerName: "X-CSRF-TOKEN",
    metaName: "csrf-token",
    cookieName: "XSRF-TOKEN", // Alternative source
});
```

### Loading States

#### `x-indicator` Directive

Creates a state variable that tracks request activity:

```html
<div x-data="{ saving: false }" x-indicator="saving">
    <button @click="$postx('/save')" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>

    <!-- Loading overlay -->
    <div x-show="saving" class="loading-overlay">Processing...</div>
</div>
```

The indicator tracks all requests originating from within its element tree.

#### `$gale` Magic

Global connection state accessible anywhere:

```html
<div x-data>
    <!-- Global loading indicator -->
    <div x-show="$gale.loading" class="global-spinner">Loading...</div>

    <!-- Error display -->
    <div x-show="$gale.error" class="error-message">
        <p x-text="$gale.error"></p>
        <button @click="$gale.retry()">Retry</button>
    </div>

    <!-- Connection state -->
    <span x-show="$gale.retrying">Reconnecting...</span>
</div>
```

#### `$fetching` Magic

Per-element loading state (set by the element making the request):

```html
<button @click="$postx('/save')" :disabled="$fetching">
    <span x-show="!$fetching">Save</span>
    <span x-show="$fetching">Processing...</span>
</button>
```

### SPA Navigation Directive

The `x-navigate` directive enables client-side navigation without full page reloads:

#### Basic Navigation

```html
<!-- On links -->
<a href="/users" x-navigate>Users</a>

<!-- On forms -->
<form action="/search" x-navigate>
    <input name="q" type="text" />
    <button type="submit">Search</button>
</form>

<!-- On any element with expression -->
<button x-navigate="'/users/' + userId">View User</button>
```

#### Navigation Modifiers

```html
<!-- Merge with current query params -->
<a href="/users?sort=name" x-navigate.merge>Sort by Name</a>

<!-- Replace history instead of push -->
<a href="/users" x-navigate.replace>Users</a>

<!-- Navigation key for targeted updates -->
<a href="/users" x-navigate.key.sidebar>Update Sidebar Only</a>

<!-- Keep only specific params -->
<a href="/search?q=test" x-navigate.only.q.category>Search</a>

<!-- Keep all except specific params -->
<a href="/search" x-navigate.except.page>Reset Pagination</a>

<!-- Debounce navigation (useful for search inputs) -->
<input @input="$navigate('/search?q=' + $el.value)" x-navigate.debounce.300ms />

<!-- Throttle navigation -->
<button x-navigate.throttle.500ms="'/next'">Next</button>

<!-- Combined modifiers -->
<a href="/users?page=2" x-navigate.merge.key.pagination.replace>Page 2</a>
```

#### Programmatic Navigation

```html
<div x-data="{ userId: 1 }">
    <button @click="$navigate('/users/' + userId)">View User</button>

    <button
        @click="$navigate('/users', {
        merge: true,
        key: 'main-content',
        replace: true
    })"
    >
        Navigate with Options
    </button>
</div>
```

#### Skip Navigation

Prevent `x-navigate` from handling specific links:

```html
<nav x-data x-navigate>
    <a href="/dashboard">Dashboard</a>
    <a href="/external" x-navigate-skip>External Link</a>
    <a href="/download.pdf" x-navigate-skip>Download PDF</a>
</nav>
```

### Message Display

The `x-message` directive displays messages from server state:

#### Basic Usage

```html
<div x-data="{ messages: {} }">
    <div>
        <label>Email</label>
        <input name="email" type="email" />
        <span x-message="email" class="text-red-500"></span>
    </div>

    <div>
        <label>Password</label>
        <input name="password" type="password" />
        <span x-message="password" class="text-red-500"></span>
    </div>

    <!-- Success message -->
    <div x-message="_success" class="text-green-500"></div>

    <button @click="$postx('/login')">Login</button>
</div>
```

**Server-side:**

```php
// Send validation errors
gale()->messages([
    'email' => 'Invalid email address',
    'password' => 'Password must be at least 8 characters',
]);

// Send success message
gale()->messages(['_success' => 'Login successful!']);

// Clear all messages
gale()->clearMessages();
```

#### Message Types

Messages can include type prefixes for styling:

```php
gale()->messages([
    'email' => '[ERROR] Invalid email',
    'saved' => '[SUCCESS] Changes saved',
    'warning' => '[WARNING] Session expiring soon',
    'info' => '[INFO] New features available',
]);
```

```html
<span x-message="email"></span>
<!-- Automatically adds class: message-error -->
```

#### Configure Messages

```javascript
Alpine.gale.configureMessage({
    defaultStateKey: "messages", // State property to read from
    autoHide: true, // Hide when no message
    autoShow: true, // Show when message present
    typeClasses: {
        success: "message-success",
        error: "message-error",
        warning: "message-warning",
        info: "message-info",
    },
});
```

### Component Registry

The component registry enables backend targeting of specific Alpine components by name:

#### Registering Components

```html
<!-- Register with x-component directive -->
<div x-data="{ items: [], total: 0 }" x-component="cart">
    <span x-text="total"></span>
</div>

<!-- With tags for grouping -->
<div x-data="{ count: 0 }" x-component="counter" data-tags="dashboard,widgets">
    <span x-text="count"></span>
</div>
```

#### Backend Component Updates

```php
// Update specific component state
gale()->componentState('cart', [
    'items' => $cartItems,
    'total' => $cartTotal,
]);

// Invoke a method on a component
gale()->componentMethod('cart', 'recalculate');

// With arguments
gale()->componentMethod('calculator', 'setValues', [10, 20, 30]);
```

#### Frontend Component Access

```html
<div x-data>
    <!-- Check if component exists -->
    <span x-show="$components.has('cart')">Cart loaded</span>

    <!-- Get component data -->
    <button @click="console.log($components.get('cart'))">Log Cart</button>

    <!-- Update component state -->
    <button @click="$components.update('cart', { total: 0 })">
        Clear Cart
    </button>

    <!-- Invoke component method -->
    <button @click="$components.invoke('cart', 'recalculate')">
        Recalculate
    </button>

    <!-- Get components by tag -->
    <button
        @click="$components.getByTag('dashboard').forEach(c => c.refresh())"
    >
        Refresh Dashboard Widgets
    </button>

    <!-- Get all components -->
    <button @click="console.log($components.all())">Log All Components</button>
</div>
```

#### Shorthand Invoke Magic

```html
<!-- $invoke is a shorthand for $components.invoke -->
<button @click="$invoke('cart', 'addItem', productId, quantity)">
    Add to Cart
</button>
```

### File Uploads

Alpine Gale provides native file upload support that integrates with Laravel's standard file handling:

#### Basic File Upload

```html
<div x-data="{ uploading: false, progress: 0 }">
    <!-- Mark file input with x-files -->
    <input type="file" name="avatar" x-files />

    <!-- Show selected file info -->
    <div x-show="$file('avatar')">
        <p>Selected: <span x-text="$file('avatar')?.name"></span></p>
        <p>Size: <span x-text="$formatBytes($file('avatar')?.size)"></span></p>
        <img :src="$filePreview('avatar')" class="preview" />
    </div>

    <!-- Upload button -->
    <button @click="$postx('/upload')" :disabled="uploading">
        <span x-show="!uploading">Upload</span>
        <span x-show="uploading"
            >Uploading... <span x-text="progress"></span>%</span
        >
    </button>
</div>
```

**Server-side:**

```php
public function upload(Request $request)
{
    $request->validate([
        'avatar' => 'required|image|max:2048',
    ]);

    $path = $request->file('avatar')->store('avatars');

    return gale()
        ->state('avatarUrl', Storage::url($path))
        ->messages(['_success' => 'Avatar uploaded!']);
}
```

#### Multiple Files

```html
<div x-data="{ files: [] }">
    <input type="file" name="documents" x-files multiple />

    <!-- List selected files -->
    <template x-for="(file, index) in $files('documents')" :key="index">
        <div>
            <span x-text="file.name"></span>
            <span x-text="$formatBytes(file.size)"></span>
            <button @click="$clearFiles('documents')">Clear</button>
        </div>
    </template>

    <button @click="$postx('/upload-multiple')">Upload All</button>
</div>
```

#### Client-Side Validation

```html
<!-- Max file size (5MB) -->
<input type="file" x-files.max-size-5mb />

<!-- Max number of files -->
<input type="file" x-files.max-files-3 multiple />

<!-- Combined -->
<input type="file" x-files.max-size-10mb.max-files-5 multiple />

<!-- Handle validation errors -->
<div x-data @gale:file-error="alert($event.detail.message)">
    <input type="file" x-files.max-size-1mb />
</div>
```

#### File Magics Reference

| Magic                        | Description                                 |
| ---------------------------- | ------------------------------------------- |
| `$file(name)`                | Get single file info (name, size, type)     |
| `$files(name)`               | Get array of file info for multiple uploads |
| `$filePreview(name, index?)` | Get preview URL for images                  |
| `$clearFiles(name?)`         | Clear file input(s)                         |
| `$formatBytes(size)`         | Format bytes to human-readable              |
| `$uploading`                 | Boolean - upload in progress                |
| `$uploadProgress`            | Number - upload progress (0-100)            |
| `$uploadError`               | String - upload error message               |

### Polling

The `x-poll` directive automatically fetches data at intervals:

#### Basic Polling

```html
<!-- Poll every 5 seconds (default) -->
<div x-data="{ status: 'pending' }" x-poll="/api/job-status">
    Status: <span x-text="status"></span>
</div>

<!-- Custom interval -->
<div x-poll.2s="/api/notifications">...</div>
<div x-poll.500ms="/api/live-data">...</div>
<div x-poll.30s="/api/stats">...</div>
```

#### Conditional Polling

```html
<!-- Only poll when tab is visible -->
<div x-data x-poll.visible.5s="/api/status">...</div>

<!-- Stop polling when condition is met -->
<div
    x-data="{ jobComplete: false }"
    x-poll.2s="/api/job"
    x-poll-stop="jobComplete"
>
    <span x-show="!jobComplete">Processing...</span>
    <span x-show="jobComplete">Complete!</span>
</div>
```

#### With CSRF Protection

```html
<div x-poll.csrf.5s="/api/protected-endpoint">...</div>
```

### Confirmation Dialogs

The `x-confirm` directive shows confirmation before executing actions:

```html
<!-- Basic confirmation -->
<button @click="$deletex('/user/123')" x-confirm>Delete User</button>

<!-- Custom message -->
<button
    @click="$deletex('/user/123')"
    x-confirm="Are you sure you want to delete this user?"
>
    Delete User
</button>

<!-- With expression -->
<button
    @click="$deletex('/user/' + userId)"
    x-confirm="'Delete ' + userName + '?'"
>
    Delete
</button>
```

**Configure confirmation dialog:**

```javascript
Alpine.gale.configureConfirm({
    defaultMessage: "Are you sure?",
    // Custom handler for styled modals
    handler: async (message) => {
        return await myCustomModal.confirm(message);
    },
});
```

---

## Advanced Usage

### Conditional Execution

Execute callbacks based on conditions:

```php
// When condition is true
gale()->when($user->isAdmin(), function ($gale) {
    $gale->state('adminTools', true);
});

// With fallback
gale()->when(
    $user->isAdmin(),
    fn($gale) => $gale->state('role', 'admin'),
    fn($gale) => $gale->state('role', 'user')
);

// Unless (inverted when)
gale()->unless($user->isGuest(), function ($gale) use ($user) {
    $gale->state('user', $user->toArray());
});

// Based on request type
gale()->whenGale(
    fn($gale) => $gale->state('partial', true),
    fn($gale) => $gale->web(view('full-page'))
);

// For navigate requests
gale()->whenGaleNavigate('sidebar', function ($gale) {
    $gale->fragment('layout', 'sidebar', $data);
});
```

### Component State from Backend

Target specific components by name for precise updates:

```php
// Update cart component
gale()->componentState('cart', [
    'items' => $cartItems,
    'total' => number_format($total, 2),
    'count' => count($cartItems),
]);

// Only set if property doesn't exist
gale()->componentState('cart', ['currency' => 'USD'], [
    'onlyIfMissing' => true,
]);

// Invoke component methods
gale()->componentMethod('cart', 'recalculate');
gale()->componentMethod('notifications', 'markAllRead');
gale()->componentMethod('form', 'reset', ['keepValues' => true]);
```

### DOM Morphing Modes

Gale supports 8 different DOM patching modes:

| Mode      | Description                                               |
| --------- | --------------------------------------------------------- |
| `morph`   | Intelligent DOM diffing (default, preserves Alpine state) |
| `inner`   | Replace inner HTML only                                   |
| `outer`   | Replace entire element including itself                   |
| `replace` | Same as outer                                             |
| `prepend` | Insert as first child                                     |
| `append`  | Insert as last child                                      |
| `before`  | Insert as previous sibling                                |
| `after`   | Insert as next sibling                                    |
| `remove`  | Remove matched elements                                   |

```php
// Using mode option
gale()->html($html, [
    'selector' => '#list',
    'mode' => 'append',
]);

// Convenience methods
gale()->append('#list', '<li>New item</li>');
gale()->prepend('#list', '<li>First item</li>');
gale()->before('#target', '<div>Before</div>');
gale()->after('#target', '<div>After</div>');
gale()->inner('#container', '<p>New content</p>');
gale()->outer('#element', '<div id="element">Replaced</div>');
gale()->remove('.deprecated');
```

### View Transitions API

Enable smooth animations using the View Transitions API:

```php
gale()->view('partials.page', $data, [
    'useViewTransition' => true,
]);

gale()->html($html, [
    'selector' => '#content',
    'mode' => 'morph',
    'useViewTransition' => true,
]);
```

**CSS for view transitions:**

```css
::view-transition-old(root) {
    animation: fade-out 0.3s ease-out;
}

::view-transition-new(root) {
    animation: fade-in 0.3s ease-in;
}

@keyframes fade-out {
    from {
        opacity: 1;
    }
    to {
        opacity: 0;
    }
}

@keyframes fade-in {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}
```

---

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=gale-config
```

**config/gale.php:**

```php
return [
    'route_discovery' => [
        // Enable automatic route discovery
        'enabled' => false,

        // Directories to scan for controllers
        'discover_controllers_in_directory' => [
            // app_path('Http/Controllers'),
        ],

        // Directories to scan for views (key = URL prefix)
        'discover_views_in_directory' => [
            // 'docs' => resource_path('views/docs'),
        ],

        // Route transformers for customization
        'pending_route_transformers' => [
            ...Dancycodes\Gale\Routing\Config::defaultRouteTransformers(),
        ],
    ],
];
```

---

## Testing

### Package Tests

```bash
cd packages/dancycodes/gale

# Run all tests
vendor/bin/phpunit

# Run with coverage
vendor/bin/phpunit --coverage-html coverage

# Run specific test suite
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Feature

# Static analysis
vendor/bin/phpstan analyse

# Code formatting
vendor/bin/pint
```

### Application Tests

```bash
# Feature tests
php artisan test tests/Feature/

# Browser tests (requires Playwright)
php artisan test tests/Browser/

# Specific test
php artisan test --filter="can increment counter"
```

---

## Contributing

Contributions are welcome! Please submit issues and pull requests on GitHub.

### Development Setup

```bash
# Clone the repository
git clone https://github.com/dancycodes/gale.git

# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Check code style
vendor/bin/pint --test

# Run static analysis
vendor/bin/phpstan analyse
```

---

## License

Laravel Gale is open-sourced software licensed under the [MIT License](LICENSE).

---

## Credits

Created by **DancyCodes** - dancycodes@gmail.com

Special thanks to:

-   [Laravel](https://laravel.com) for the amazing framework
-   [Alpine.js](https://alpinejs.dev) for the lightweight reactivity
-   [Datastar](https://data-star.dev) for SSE protocol inspiration
