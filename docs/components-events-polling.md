# Components, Events & Polling

> **See also:** [Frontend API Reference](frontend-api.md) | [Backend API Reference](backend-api.md) | [Core Concepts](core-concepts.md)

Build multi-component pages where panels update independently, components communicate with each other, server pushes real-time updates, and polling keeps data fresh automatically.

---

## Table of Contents

- [Named Components with x-component](#named-components-with-x-component)
  - [Naming Syntax & Conventions](#naming-syntax--conventions)
  - [Server-Addressable Patching](#server-addressable-patching)
  - [Tag-Based Patching](#tag-based-patching)
  - [Invoking Component Methods](#invoking-component-methods)
  - [Component Lifecycle](#component-lifecycle)
  - [Navigation Lifecycle](#navigation-lifecycle)
- [Cross-Component Communication with $components](#cross-component-communication-with-components)
  - [Accessing Component State](#accessing-component-state)
  - [Updating Component State](#updating-component-state)
  - [Waiting for Components to Register](#waiting-for-components-to-register)
  - [Watching Component State](#watching-component-state)
  - [Error Handling for Missing Components](#error-handling-for-missing-components)
- [Event System](#event-system)
  - [Dispatching Events from the Server](#dispatching-events-from-the-server)
  - [Listening for Server Events in Alpine](#listening-for-server-events-in-alpine)
  - [Gale Lifecycle Events](#gale-lifecycle-events)
  - [Component Registry Events](#component-registry-events)
  - [Cross-Component Event Patterns](#cross-component-event-patterns)
- [Polling with x-interval](#polling-with-x-interval)
  - [Basic Polling](#basic-polling)
  - [Interval Format](#interval-format)
  - [Visibility-Aware Polling](#visibility-aware-polling)
  - [Stopping and Restarting Polling](#stopping-and-restarting-polling)
  - [Server-Controlled Stop](#server-controlled-stop)
  - [Automatic Cleanup](#automatic-cleanup)
- [Server Push with SSE](#server-push-with-sse)
  - [Polling vs. Push: Which to Use](#polling-vs-push-which-to-use)
  - [SSE Mode Requests](#sse-mode-requests)
  - [Long-Running Stream Responses](#long-running-stream-responses)
- [Complete Patterns](#complete-patterns)
  - [Multi-Panel Dashboard with Independent Updates](#multi-panel-dashboard-with-independent-updates)
  - [Notification Bell with Live Count](#notification-bell-with-live-count)
  - [Auto-Refreshing Data Table](#auto-refreshing-data-table)
  - [Real-Time Activity Feed](#real-time-activity-feed)
- [Alpine Store Patching](#alpine-store-patching)
- [Morph Lifecycle Hooks](#morph-lifecycle-hooks)
- [Gale Plugin / Extension System](#gale-plugin--extension-system)
- [Performance Considerations](#performance-considerations)

---

## Named Components with x-component

### Naming Syntax & Conventions

Add `x-component="name"` to any Alpine component (`x-data`) element to register it in Gale's
component registry. The server and other client-side components can then reference it by name.

```html
<div x-data="{ count: 0, total: 0 }" x-component="cart">
    <span x-text="count"></span> items — $<span x-text="total"></span>
</div>
```

**Naming conventions:**
- Use `kebab-case` names: `cart`, `notification-bell`, `user-profile`
- Names must be unique on the page. If two elements share a name, the last one to
  initialize wins (the registry replaces the previous entry and updates element references)
- Names are scoped to the current page — they do not persist across SPA navigations

**Tags for grouping components:**

Add `data-tags` to group components under shared labels, enabling one server call to update
multiple components at once:

```html
<div x-data="{ price: 0 }" x-component="product-123" data-tags="product-card,featured">
    $<span x-text="price"></span>
</div>

<div x-data="{ price: 0 }" x-component="product-456" data-tags="product-card">
    $<span x-text="price"></span>
</div>
```

---

### Server-Addressable Patching

Target any named component from any controller using `gale()->componentState()`:

```php
return gale()->componentState('cart', [
    'total' => $cart->total,
    'count' => $cart->itemCount(),
]);
```

This sends a `gale-patch-component` event to the browser. The named component receives a
[RFC 7386 JSON Merge Patch](https://datatracker.ietf.org/doc/html/rfc7386) — only the keys
you specify are updated; all other state is preserved.

**Multiple components in one response:**

```php
return gale()
    ->componentState('cart', ['count' => 3, 'total' => 99.99])
    ->componentState('notification-bell', ['unread' => 5])
    ->patchState(['lastUpdated' => now()->toISOString()]);
```

**onlyIfMissing option:**

Initialize state only when the property does not already exist on the component:

```php
return gale()->componentState('cart', ['currency' => 'USD'], ['onlyIfMissing' => true]);
```

---

### Tag-Based Patching

Update all components sharing a tag simultaneously with `gale()->tagState()`:

```php
// Updates ALL components with data-tags="product-card"
return gale()->tagState('product-card', ['inStock' => false]);
```

Use tags when multiple components display the same shared data (e.g., product price shown in
a card grid, a sidebar, and a "recently viewed" list all tagged `product-display`).

---

### Invoking Component Methods

Call a method on a component's Alpine `x-data` object from the server:

```php
// PHP — calls modal.open('Confirm Delete') on the client
return gale()->componentMethod('modal', 'open', ['Confirm Delete']);
```

```html
<!-- HTML -->
<div x-data="{
    isOpen: false,
    title: '',
    open(t) { this.title = t; this.isOpen = true; },
    close() { this.isOpen = false; }
}" x-component="modal">
    <div x-show="isOpen">
        <h2 x-text="title"></h2>
        <button @click="close()">Close</button>
    </div>
</div>
```

The method must exist on the component's `x-data` object. If the component or method is not
found, a console warning is logged and the call is silently skipped.

---

### Component Lifecycle

A component is **registered** when Alpine initializes the element with `x-component` during
`x-init`. It is **unregistered** when the element is removed from the DOM — either via Alpine's
cleanup callback (synchronous on component destroy) or via a MutationObserver (catches
force-removed elements).

During DOM morphs, the registry pauses cleanup. Elements that survive a morph have their
element references updated in-place, so named components remain reachable across morphs
without any action from the developer.

**Listening to registration events in JavaScript:**

```javascript
// Listen on document for component lifecycle events
document.addEventListener('gale:component-registered', (e) => {
    console.log('Component registered:', e.detail.name, e.detail.tags);
});

document.addEventListener('gale:component-unregistered', (e) => {
    console.log('Component unregistered:', e.detail.name);
});

document.addEventListener('gale:component-stateChanged', (e) => {
    console.log('State changed on:', e.detail.name, e.detail.updates);
});
```

---

### Navigation Lifecycle

When the user navigates to a new page via Gale's SPA navigation (`x-navigate`), all named
components on the old page are unregistered before the new page content morphs into place.
Components on the new page are registered fresh as Alpine initializes them.

**What this means in practice:**
- `$components.get('cart')` returns `null` immediately after a navigation, then returns
  the component again once the new page's `cart` component has initialized
- Server push events targeting a component that no longer exists are silently discarded —
  no error occurs when the user has navigated away
- If you need to ensure a component exists before acting on it, use
  `$components.when('name')` (see [Waiting for Components](#waiting-for-components-to-register))

---

## Cross-Component Communication with $components

The `$components` magic provides frontend access to the component registry from any Alpine
component. It is available inside any `x-data` context.

### Accessing Component State

```html
<div x-data>
    <!-- Read reactive state from another component -->
    <span x-text="$components.state('cart')?.count ?? 0"></span>

    <!-- Read a specific property -->
    <span x-text="$components.state('cart', 'total') ?? '0.00'"></span>

    <!-- Check if a component exists -->
    <button x-show="$components.has('cart')">View Cart</button>

    <!-- List all registered components -->
    <template x-for="comp in $components.all()">
        <div x-text="comp.name"></div>
    </template>
</div>
```

`$components.state(name)` returns the component's **reactive Alpine proxy** — the same live
object that Alpine uses to drive the template. Accessing properties on it from inside Alpine
expressions automatically tracks them for reactivity, so your display updates whenever that
state changes.

`$components.state(name)` returns `null` when the component does not exist. Always guard
with optional chaining (`?.`) or a nullish coalescing default (`?? fallback`).

---

### Updating Component State

Update another component's state directly from the client side:

```html
<div x-data>
    <!-- Directly update another component's reactive state -->
    <button @click="$components.update('cart', { count: 0, total: 0 })">
        Clear Cart
    </button>

    <!-- Delete properties from another component's state -->
    <button @click="$components.delete('user', ['tempData', 'draft'])">
        Discard Draft
    </button>
</div>
```

`$components.update()` applies RFC 7386 JSON Merge Patch semantics — nested objects are
merged recursively; setting a key to `null` removes it.

**Invoke a method on another component:**

```html
<button @click="$components.invoke('modal', 'open', 'Confirm Delete')">
    Delete
</button>
```

---

### Waiting for Components to Register

Components initialize asynchronously. If one component needs to interact with another that
may not yet be registered, use `$components.when()` or `$components.onReady()`:

```html
<div x-data x-init="
    $components.when('cart').then(cart => {
        console.log('Cart is ready with state:', cart);
    });
">
    ...
</div>
```

```javascript
// Inside x-init or a script block — register a callback:
$components.onReady('cart', (cart) => {
    console.log('Cart ready:', cart);
});

// Wait for multiple components:
$components.onReady(['cart', 'user'], ({ cart, user }) => {
    console.log('Both ready');
});
```

`$components.when(name, timeout)` returns a Promise. The default timeout is 5000ms; it
rejects with an error if the component does not register in time.

`$components.onReady(name, callback)` fires immediately if the component is already
registered, or waits for it — no timeout. It returns a cleanup function to cancel the
callback.

---

### Watching Component State

React to state changes on another component:

```html
<div x-data x-init="
    // Watch entire state (fires on any change)
    $components.watch('cart', (newState, oldState) => {
        console.log('Cart changed');
    });

    // Watch a specific key
    $components.watch('cart', 'count', (newCount, oldCount) => {
        console.log('Count changed from', oldCount, 'to', newCount);
    });
">
    ...
</div>
```

The returned value is a cleanup function. Call it to stop watching. Alpine's own reactivity
system drives the watch — no polling occurs.

---

### Error Handling for Missing Components

All `$components` methods handle missing components gracefully:

| Method | Behavior when component missing |
|--------|--------------------------------|
| `$components.get(name)` | Returns `null` |
| `$components.state(name)` | Returns `null` |
| `$components.has(name)` | Returns `false` |
| `$components.update(name, data)` | Returns `false`, no error thrown |
| `$components.invoke(name, method)` | Logs `console.warn`, returns `undefined` |
| `$components.watch(name, ...)` | Queues the watcher; fires once component registers |

Server-side, `gale()->componentState('name', [...])` for a component that is not on the
page is silently discarded — the event is sent but no component processes it.

---

## Event System

Gale integrates with Alpine's standard `CustomEvent` / `$dispatch` system. The server can
dispatch events to the browser; the browser can listen with standard Alpine syntax.

### Dispatching Events from the Server

Use `gale()->dispatch()` to fire a `CustomEvent` on `window` (or a specific element) from
any controller:

```php
// Dispatch on window — any component can listen with @event-name.window
return gale()->dispatch('show-toast', [
    'message' => 'Profile saved!',
    'type' => 'success',
]);
```

```php
// Dispatch on a specific element — listener does not need .window
return gale()->dispatch('refresh', [], '#sidebar');
```

```php
// Chain multiple dispatches with other response events
return gale()
    ->patchState(['saved' => true])
    ->dispatch('show-toast', ['message' => 'Saved!'])
    ->dispatch('analytics-track', ['event' => 'profile_save']);
```

`dispatch()` works in both HTTP mode (default) and SSE mode — use it freely regardless of
transport.

If the CSS selector passed as the third argument matches no element, the event is dispatched
on `window` as a fallback and a `console.warn` is logged.

---

### Listening for Server Events in Alpine

Server-dispatched events are standard `CustomEvent` objects. Listen with Alpine's `@event`
syntax:

```html
<!-- Window event — use .window modifier -->
<div x-data x-on:show-toast.window="showToast($event.detail)">
    ...
</div>

<!-- Targeted element event — no .window modifier needed -->
<aside id="sidebar" @refresh="loadItems()">
    ...
</aside>
```

Event data is in `$event.detail`:

```html
<div x-data="{ toasts: [] }" @show-toast.window="toasts.push($event.detail)">
    <template x-for="t in toasts">
        <div :class="t.type" x-text="t.message"></div>
    </template>
</div>
```

---

### Gale Lifecycle Events

Gale dispatches these lifecycle events on `document`. Listen to them for analytics, logging,
or coordinating UI behavior:

```javascript
// Request lifecycle
document.addEventListener('gale:request:start', (e) => {
    // e.detail: { url, element, requestId }
    showSpinner();
});

document.addEventListener('gale:request:end', (e) => {
    // e.detail: { url, element, requestId, duration }
    hideSpinner();
});

// Error handling
document.addEventListener('gale:error', (e) => {
    // e.detail: { type, status, message, context, recoverable }
    console.error('Gale error:', e.detail.message);
});

// Navigation lifecycle
document.addEventListener('gale:navigate:start', (e) => {
    // e.detail: { url }
    showProgress();
});

document.addEventListener('gale:navigate:end', (e) => {
    hideProgress();
});

// DOM morphing
document.addEventListener('gale:after-morph', (e) => {
    // e.detail: { el } — re-initialize third-party libraries after morph
    const el = e.detail.el;
    if (el.matches('[data-chart]')) {
        initChart(el);
    }
});
```

---

### Component Registry Events

The component registry dispatches DOM events when components register, unregister, or change
state. These bubble from `document` and are standard `CustomEvent` objects:

```html
<!-- React to any component registering -->
<div x-data x-on:gale:component-registered.document="
    console.log('New component:', $event.detail.name)
">
    ...
</div>
```

```javascript
// Outside Alpine — direct DOM listener
document.addEventListener('gale:component-registered', (e) => {
    // e.detail: { name, tags, element }
});

document.addEventListener('gale:component-unregistered', (e) => {
    // e.detail: { name, tags }
});

document.addEventListener('gale:component-stateChanged', (e) => {
    // e.detail: { name, updates, oldValues }
});
```

---

### Cross-Component Event Patterns

**Pattern: trigger server refresh of another component from client action**

When the user marks a message as read in one component, the notification bell in the header
should update. The cleanest approach is to call a server endpoint that updates both:

```html
<!-- Message list component -->
<div x-data="{ messages: [] }" x-component="message-list">
    <template x-for="msg in messages" :key="msg.id">
        <div>
            <span x-text="msg.text"></span>
            <button @click="$post('/messages/' + msg.id + '/read')">
                Mark Read
            </button>
        </div>
    </template>
</div>

<!-- Notification bell — separate component anywhere on the page -->
<div x-data="{ unread: 0 }" x-component="notification-bell">
    <span x-text="unread"></span>
</div>
```

```php
// MarkMessageReadController.php
public function __invoke(Message $message): Response
{
    $message->markAsRead();

    return gale()
        ->componentState('message-list', [
            'messages' => Message::latest()->get()->toArray(),
        ])
        ->componentState('notification-bell', [
            'unread' => Message::unread()->count(),
        ]);
}
```

**Pattern: decouple components with server events**

Use `gale()->dispatch()` to let components react without the controller knowing about
all the listeners:

```php
// After marking read — dispatch one event; any listener handles it
return gale()
    ->componentState('message-list', ['messages' => $messages])
    ->dispatch('messages-updated', ['unreadCount' => Message::unread()->count()]);
```

```html
<!-- Bell listens independently -->
<div x-data="{ unread: 0 }" x-component="notification-bell"
     @messages-updated.window="unread = $event.detail.unreadCount">
    <span x-text="unread"></span>
</div>
```

---

## Polling with x-interval

`x-interval` runs an Alpine expression on a repeating timer. Combine it with `$get` or
`$post` to poll the server for fresh data without any JavaScript setup code.

### Basic Polling

```html
<!-- Poll every 5 seconds using $get -->
<div x-data="{ stats: {} }" x-interval.5s="$get('/api/stats')">
    <span x-text="stats.visitors"></span> visitors
</div>

<!-- Poll every 30 seconds using $post with a payload -->
<div x-data="{ items: [] }" x-interval.30s="$post('/queue/items', { status: 'pending' })">
    <template x-for="item in items" :key="item.id">
        <div x-text="item.title"></div>
    </template>
</div>
```

The expression is evaluated immediately on mount (first tick fires right away), then
repeats at the specified interval.

---

### Interval Format

Specify the duration using a modifier on `x-interval`:

| Modifier | Duration |
|----------|----------|
| `.5s` | 5 seconds |
| `.30s` | 30 seconds |
| `.500ms` | 500 milliseconds |
| `.1000ms` | 1 second |

Use seconds (`.Xs`) for most polling scenarios. Use milliseconds (`.Xms`) only for
sub-second updates — be mindful of server load (see [Performance Considerations](#performance-considerations)).

```html
<!-- Seconds format -->
<div x-interval.10s="$get('/heartbeat')">...</div>

<!-- Milliseconds format -->
<div x-interval.500ms="updateClock()">...</div>
```

---

### Visibility-Aware Polling

Add the `.visible` modifier to automatically pause polling when the browser tab is hidden
or the element has scrolled out of the viewport:

```html
<div x-data="{ feed: [] }" x-interval.visible.30s="$get('/activity-feed')">
    <template x-for="item in feed">
        <div x-text="item.text"></div>
    </template>
</div>
```

**Behavior:**
- Polling pauses when either the browser tab is hidden (`document.hidden === true`) **or**
  the element is less than 10% visible in the viewport
- Polling resumes with an **immediate tick** when both conditions are met again
- Uses the Page Visibility API and Intersection Observer API. Falls back to basic polling
  with a console warning on browsers that do not support these APIs

This modifier is ideal for below-the-fold content that does not need to update while
the user cannot see it — saves server requests and battery on mobile.

---

### Stopping and Restarting Polling

**Stop polling when a condition is met:**

```html
<!-- Polling stops permanently once isDone becomes truthy -->
<div x-data="{ progress: 0, isDone: false }"
     x-interval.2s="$get('/job/status')"
     x-interval-stop="isDone">
    <div x-text="progress + '%'"></div>
</div>
```

`x-interval-stop` is evaluated **after each tick's response is processed**, so `isDone`
can be set by a server `patchState` event and polling stops on the next cycle check.

**Manually stop or restart polling by dispatching events on the element:**

```html
<div x-data="{ polling: true }"
     x-ref="poller"
     x-interval.5s="$get('/feed')"
     @gale-interval-stopped="polling = false"
     @gale-interval-restarted="polling = true">

    <button @click="$refs.poller.dispatchEvent(new CustomEvent('gale-interval-stop'))">
        Pause
    </button>
    <button @click="$refs.poller.dispatchEvent(new CustomEvent('gale-interval-restart'))">
        Resume
    </button>
</div>
```

**Element state attribute:**

When polling is stopped, the element gains `data-interval-stopped="true"`. You can style
paused pollers with CSS: `[data-interval-stopped] { opacity: 0.5; }`.

---

### Server-Controlled Stop

The server can stop a polling element by dispatching the `gale-interval-stop` event:

```php
// Stop all x-interval pollers on the page
return gale()->dispatch('gale-interval-stop');

// Stop a specific element (if you gave it an id or class)
// Note: gale-interval-stop on window stops all pollers;
// dispatch on the element directly for targeted stop:
return gale()->dispatch('gale-interval-stop', [], '#my-poller');
```

---

### Automatic Cleanup

`x-interval` automatically cleans up when the Alpine component is destroyed or the element
is removed from the DOM:

- The `setInterval` timer is cleared
- Page Visibility and Intersection Observer listeners are removed
- No manual cleanup is required from the developer

---

## Server Push with SSE

### Polling vs. Push: Which to Use

| | Polling (`x-interval`) | Push (`gale()->stream()`) |
|---|---|---|
| **Who initiates** | Client requests on a timer | Server sends at any time |
| **Use when** | Data changes at predictable intervals | Data changes on server events |
| **Connection** | Short-lived HTTP requests | Long-lived SSE connection |
| **Complexity** | Simple — one line | Requires streaming endpoint |
| **Server load** | Proportional to polling frequency | One open connection per client |
| **Best for** | Dashboards, queue status, live clocks | Chat, notifications, collaboration |

For most use cases, start with polling. Move to server push only when you need sub-second
latency or event-driven updates from the server side.

---

### SSE Mode Requests

Any `$action` call can opt into SSE mode by passing `{ sse: true }`. This keeps the
connection open until the server closes it, receiving multiple events:

```html
<div x-data="{ messages: [] }" x-init="$get('/notifications/stream', { sse: true })">
    <template x-for="msg in messages">
        <div x-text="msg.text"></div>
    </template>
</div>
```

The server endpoint uses `gale()->stream()` to send events as they occur:

```php
// NotificationController.php
public function stream(): StreamedResponse
{
    return gale()->stream(function () {
        foreach (Notification::pending() as $notification) {
            gale()->patchState([
                'messages' => array_merge(
                    request()->get('messages', []),
                    [['text' => $notification->text]]
                ),
            ]);
            // Flush immediately so the browser receives it
        }
    });
}
```

---

### Long-Running Stream Responses

For true server push — where the server sends events over time — use `gale()->stream()`
with a loop that sleeps between sends:

```php
// ActivityFeedController.php
public function stream(): StreamedResponse
{
    return gale()->stream(function () {
        $lastId = 0;

        // Keep streaming for up to 30 seconds
        $deadline = now()->addSeconds(30);

        while (now()->lt($deadline)) {
            $newActivity = Activity::where('id', '>', $lastId)->latest()->get();

            if ($newActivity->isNotEmpty()) {
                $lastId = $newActivity->first()->id;

                gale()->patchState([
                    'feed' => $newActivity->toArray(),
                ]);
            }

            // Sleep 1 second between checks
            sleep(1);
        }
    });
}
```

```html
<div x-data="{ feed: [] }"
     x-init="$get('/activity/stream', { sse: true })">
    <template x-for="item in feed" :key="item.id">
        <div x-text="item.text"></div>
    </template>
</div>
```

**SSE connection lifecycle notes:**
- When the user navigates to a new page via `x-navigate`, active SSE connections for
  in-flight requests are aborted automatically. Long-polling connections started on the
  previous page are closed.
- If the SSE connection drops, the browser's native `EventSource` reconnect behavior
  applies (1-second default retry, configurable via `gale()->retryMs(N)`)
- Use `$get('/endpoint', { sse: true })` from `x-init` for "subscribe on mount" behavior

---

## Complete Patterns

### Multi-Panel Dashboard with Independent Updates

Each dashboard panel is a named component. The server can update any panel independently
without re-rendering the whole dashboard.

**Blade template:**

```html
{{-- resources/views/dashboard.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="grid grid-cols-3 gap-6">
    {{-- Stats panel --}}
    <div x-data="{ visitors: 0, revenue: 0, orders: 0 }"
         x-component="stats"
         x-interval.visible.60s="$get('/dashboard/stats')">
        <h3>Visitors</h3>
        <p x-text="visitors"></p>
        <h3>Revenue</h3>
        <p>$<span x-text="revenue"></span></p>
        <h3>Orders</h3>
        <p x-text="orders"></p>
    </div>

    {{-- Chart panel --}}
    <div x-data="{ chartData: @json($chartData) }"
         x-component="chart"
         x-interval.visible.300s="$get('/dashboard/chart')">
        <canvas id="revenue-chart"></canvas>
    </div>

    {{-- Activity feed panel --}}
    <div x-data="{ feed: @json($feed) }"
         x-component="feed">
        <h3>Recent Activity</h3>
        <template x-for="item in feed" :key="item.id">
            <div x-text="item.description"></div>
        </template>
    </div>
</div>
@endsection
```

**Controllers:**

```php
// DashboardStatsController.php
public function __invoke(): Response
{
    return gale()->componentState('stats', [
        'visitors' => Analytics::todayVisitors(),
        'revenue'  => Order::todayRevenue(),
        'orders'   => Order::todayCount(),
    ]);
}

// DashboardChartController.php
public function __invoke(): Response
{
    return gale()->componentState('chart', [
        'chartData' => ChartService::weeklyRevenue(),
    ]);
}

// From any controller — push an activity event to the feed:
public function completeOrder(Order $order): Response
{
    $order->complete();

    return gale()
        ->componentState('feed', [
            'feed' => Activity::latest()->limit(10)->get()->toArray(),
        ])
        ->componentState('stats', [
            'orders'  => Order::todayCount(),
            'revenue' => Order::todayRevenue(),
        ])
        ->dispatch('show-toast', ['message' => 'Order completed!']);
}
```

**Routes:**

```php
Route::get('/dashboard', DashboardController::class);
Route::get('/dashboard/stats', DashboardStatsController::class);
Route::get('/dashboard/chart', DashboardChartController::class);
```

---

### Notification Bell with Live Count

The notification bell and the notification list are separate components. When the user reads
notifications in the list, the bell count updates.

```html
{{-- Header — anywhere in the layout --}}
<div x-data="{ unread: {{ $unread }} }" x-component="notification-bell">
    <button class="relative">
        <svg>...</svg>
        <span x-show="unread > 0"
              x-text="unread"
              class="absolute -top-1 -right-1 badge">
        </span>
    </button>
</div>

{{-- Notification list — on a notifications page --}}
<div x-data="{ notifications: @json($notifications) }"
     x-component="notification-list"
     x-interval.visible.30s="$get('/notifications')">
    <template x-for="n in notifications" :key="n.id">
        <div class="flex justify-between">
            <span x-text="n.message"></span>
            <button @click="$post('/notifications/' + n.id + '/read')">
                Mark Read
            </button>
        </div>
    </template>
</div>
```

```php
// NotificationReadController.php
public function __invoke(Notification $notification): Response
{
    $notification->markAsRead();

    return gale()
        ->componentState('notification-bell', [
            'unread' => auth()->user()->unreadNotifications()->count(),
        ])
        ->componentState('notification-list', [
            'notifications' => auth()->user()->notifications()->get()->toArray(),
        ]);
}
```

---

### Auto-Refreshing Data Table

A data table that polls every 30 seconds, pauses when the tab is hidden, and lets the user
manually force a refresh:

```html
<div x-data="{ rows: @json($rows), loading: false }"
     x-component="data-table"
     x-interval.visible.30s="$get('/table/data')">

    <div class="flex justify-between mb-4">
        <h2>Orders</h2>
        <button @click="$get('/table/data')" :disabled="$fetching">
            <span x-show="$fetching">Refreshing...</span>
            <span x-show="!$fetching">Refresh Now</span>
        </button>
    </div>

    <table>
        <template x-for="row in rows" :key="row.id">
            <tr>
                <td x-text="row.id"></td>
                <td x-text="row.status"></td>
                <td x-text="row.total"></td>
            </tr>
        </template>
    </table>
</div>
```

```php
// TableDataController.php
public function __invoke(): Response
{
    return gale()->componentState('data-table', [
        'rows' => Order::latest()->paginate(50)->items(),
    ]);
}
```

---

### Real-Time Activity Feed

An activity feed that uses SSE streaming to receive updates from the server as they happen:

```html
<div x-data="{ events: @json($events) }"
     x-component="activity-feed"
     x-init="$get('/feed/stream', { sse: true })">

    <h3>Live Activity</h3>
    <ul>
        <template x-for="event in events" :key="event.id">
            <li>
                <span x-text="event.user"></span>
                <span x-text="event.action"></span>
                <time x-text="event.time"></time>
            </li>
        </template>
    </ul>
</div>
```

```php
// ActivityFeedController.php
public function index(): Response
{
    return gale()->view('feed', [
        'events' => ActivityEvent::latest()->limit(20)->get(),
    ], web: true);
}

public function stream(): StreamedResponse
{
    return gale()->stream(function () {
        $deadline = now()->addSeconds(60);
        $lastId = ActivityEvent::latest()->value('id') ?? 0;

        while (now()->lt($deadline)) {
            $newEvents = ActivityEvent::where('id', '>', $lastId)
                ->latest()
                ->limit(10)
                ->get();

            if ($newEvents->isNotEmpty()) {
                $lastId = $newEvents->max('id');

                // Prepend new events to the feed
                gale()->componentState('activity-feed', [
                    'events' => $newEvents->toArray(),
                ]);
            }

            sleep(2);
        }
    });
}
```

```php
// Routes
Route::get('/feed', [ActivityFeedController::class, 'index']);
Route::get('/feed/stream', [ActivityFeedController::class, 'stream']);
```

---

## Alpine Store Patching

Patch Alpine global stores from the server using `gale()->patchStore()`. Stores are
application-wide shared state, accessible via `$store.name` in any component.

```php
// Update the global theme store
return gale()->patchStore('theme', ['mode' => 'dark', 'color' => 'indigo']);
```

```javascript
// Register the store once (in your app.js or a Blade script block)
Alpine.store('theme', { mode: 'light', color: 'blue' });
```

```html
<!-- Any component can read the store -->
<body :class="$store.theme.mode === 'dark' ? 'dark' : ''">
    ...
</body>

<div x-data>
    <span x-text="$store.theme.color"></span>
</div>
```

Multiple `patchStore()` calls in one response each emit a separate event and can target
different stores:

```php
return gale()
    ->patchStore('cart', ['total' => 149.99, 'itemCount' => 3])
    ->patchStore('notifications', ['unread' => 7]);
```

Store patches use RFC 7386 merge semantics — only specified keys are updated.

---

## Morph Lifecycle Hooks

Register callbacks that run before or after DOM morphing. Use these to save and restore
third-party library state (charts, editors, sortable lists) that does not survive a DOM diff:

```javascript
// Register once in a script block or your app.js
Alpine.gale.onMorph({
    beforeUpdate(el, toEl) {
        // Save state before the DOM diff
        if (el._chartInstance) {
            el._chartData = el._chartInstance.data;
        }
    },
    afterUpdate(el) {
        // Restore state after the DOM diff
        if (el._chartInstance && el._chartData) {
            el._chartInstance.data = el._chartData;
            el._chartInstance.update();
        }
    },
    beforeRemove(el) {
        // Clean up before element is removed
        if (el._chartInstance) {
            el._chartInstance.destroy();
        }
    },
});
```

**Important:** Hook callbacks run outside Alpine's reactive context. Do not use Alpine
magics (`$data`, `$nextTick`, `$el`) inside them. To update Alpine state from a hook,
capture a reference before registering:

```html
<div x-data="{ chartReady: false }" x-init="
    const self = $data;
    Alpine.gale.onMorph({
        afterUpdate(el) {
            setTimeout(() => { self.chartReady = true; }, 0);
        }
    });
">
    ...
</div>
```

---

## Gale Plugin / Extension System

Register custom Gale plugins to extend the framework with additional behavior:

```javascript
Alpine.gale.use({
    name: 'my-analytics',
    install(gale) {
        // Hook into request lifecycle
        document.addEventListener('gale:request:start', (e) => {
            analytics.track('gale_request', { url: e.detail.url });
        });

        // Add custom event handlers
        document.addEventListener('gale:after-morph', (e) => {
            analytics.track('gale_morph', { el: e.detail.el.id });
        });
    },
});
```

Plugins are installed once during `Alpine.start()`. The `install` function receives the
`gale` context object. Register plugins before `Alpine.start()` in your `alpine:init`
event handler or in your app bootstrap script.

---

## Performance Considerations

**Component count:** Named components use a WeakMap and two Maps internally — registration
and lookup are O(1). Having 50 or 100 named components on a page has negligible overhead.
Avoid creating components that are never targeted by the server; use plain `x-data` instead.

**Polling frequency:** The recommended minimum polling interval is 5 seconds for most use
cases. Polling at 1 second per component effectively means continuous server load. Consider:
- Use `.visible` modifier so off-screen panels do not poll
- Share one polling endpoint for multiple panels and use `componentState()` to update each
- Use SSE streaming for true real-time updates instead of aggressive polling

**Too-frequent intervals warning:** Do not use `x-interval.100ms` on production pages. A
single user visiting the page would generate 10 requests/second. For high-frequency client
updates (live clocks, progress bars), drive them with client-side JavaScript rather than
server polling.

**SSE connection limits:** Browsers limit concurrent connections to the same origin (usually
6 for HTTP/1.1, practically unlimited for HTTP/2). Each `$get('/url', { sse: true })` call
in `x-init` opens a persistent connection. On a page with many SSE subscriptions over
HTTP/1.1, connections queue. Prefer multiplexing — use one SSE endpoint that updates
multiple components — over many separate stream endpoints.

**Component scope:** `$components.get('name')` only accesses components registered on
the **current page**. After an SPA navigation, all components are unregistered and
re-registered fresh. Do not rely on components from a previous page being available.

**Duplicate names:** If two elements share the same `x-component` name, the registry
keeps the most recently initialized one. The previous element's registration is silently
replaced. Always use unique names per page, or use `data-tags` for grouping instead.
