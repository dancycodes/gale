# Getting Started

> **See also:** [Core Concepts](core-concepts.md) | [Backend API Reference](backend-api.md) | [Frontend API Reference](frontend-api.md)

This guide walks you through installing Gale, wiring up Alpine.js, and building your first
reactive page — a working counter that updates without a page reload. Estimated time: 10 minutes.

---

## Requirements

- PHP 8.2 or higher
- Laravel 11 or 12

> **Note:** `@gale` replaces any existing Alpine.js CDN script. Do not load Alpine separately
> alongside `@gale` — it is already bundled.

---

## Installation

### Step 1 — Install the PHP package

```bash
composer require dancycodes/gale
```

### Step 2 — Run the Gale installer

The installer publishes the Alpine Gale JS bundle to your `public/vendor/gale/` folder.

```bash
php artisan gale:install
```

To also publish the config file (optional — Gale works with sensible defaults):

```bash
php artisan vendor:publish --tag=gale-config
```

### Step 3 — Add `@gale` to your layout

Open your main Blade layout (typically `resources/views/layouts/app.blade.php`) and add the
`@gale` directive inside `<head>`. Remove any existing Alpine.js CDN `<script>` tag — `@gale`
includes Alpine already.

```html
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @gale
</head>
<body>
    @yield('content')
</body>
</html>
```

`@gale` emits:
- A CSRF meta tag (`<meta name="csrf-token">`)
- The Alpine Gale JS bundle (`/vendor/gale/js/gale.js`)
- The Gale CSS file (`/vendor/gale/css/gale.css`)
- Optional debug script injection when `APP_DEBUG=true`

That is all the setup needed. No `npm install`, no build step.

> **Advanced (Vite/ES modules):** If you use a bundler, install via npm instead:
> `npm install alpine-gale alpinejs`, then `import Gale from 'alpine-gale'` and
> `Alpine.plugin(Gale)` in your `app.js`. See [Frontend API Reference](frontend-api.md).

---

## Configuration

If you published the config file, it lives at `config/gale.php`. The most commonly
adjusted options are:

```php
// config/gale.php

'mode' => env('GALE_MODE', 'http'),   // 'http' (default) or 'sse'
'debug' => env('GALE_DEBUG', false),  // intercepts dd()/dump() in Gale requests
```

You can also set these in `.env`:

```
GALE_MODE=http
GALE_DEBUG=true
```

> **HTTP vs SSE modes:**
> - **HTTP mode (default):** Actions return `application/json` with batched events. Works everywhere, no special server config.
> - **SSE mode (opt-in):** Actions return `text/event-stream`, streaming events in real time. Enable per action via `$action('/url', { sse: true })` or globally with `GALE_MODE=sse`.

---

## Your First Reactive Page

This tutorial builds a reactive counter. The counter value lives in Alpine state on the browser,
is sent to the server on each button click, and the server returns the incremented value. No page
reload occurs.

### Step 1 — Create the controller

```bash
php artisan make:controller CounterController
```

Open `app/Http/Controllers/CounterController.php` and replace its contents:

```php
<?php

namespace App\Http\Controllers;

use Dancycodes\Gale\Http\GaleResponse;
use Illuminate\Http\Request;

class CounterController extends Controller
{
    /**
     * Render the counter page (handles both first page load and Gale navigate).
     */
    public function show(): GaleResponse
    {
        return gale()->view('counter', ['count' => 0], web: true);
    }

    /**
     * Increment the counter and return the updated state.
     */
    public function increment(Request $request): GaleResponse
    {
        $count = $request->state('count', 0) + 1;

        return gale()->state(['count' => $count]);
    }
}
```

**What each method does:**

- `show()` — Returns `gale()->view()` with `web: true`. On a direct browser visit (first
  page load) this renders the full Blade view. On a Gale navigation request it returns only
  the page fragment. The `web: true` argument is what enables this dual behavior.

- `increment()` — Reads the current `count` from the request state (the Alpine `x-data`
  value the browser sent), adds one, and returns `gale()->state()` to push the new
  value back to Alpine.

### Step 2 — Create the Blade view

Create `resources/views/counter.blade.php`:

```html
@extends('layouts.app')

@section('content')
<div x-data="{ count: {{ $count }} }" class="p-8 max-w-sm mx-auto" x-sync>

    <h1 class="text-2xl font-bold mb-4">Counter</h1>

    <p class="text-5xl font-mono mb-6" x-text="count">{{ $count }}</p>

    <div class="flex gap-4">
        <button
            @click="$action('/counter/increment')"
            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
            Increment
        </button>
    </div>

    <p class="mt-4 text-sm text-gray-500" x-show="$fetching()">Updating...</p>

</div>
@endsection
```

**Key pieces explained:**

- `x-data="{ count: {{ $count }} }"` — Alpine component with `count` initialized from
  the server-rendered PHP value.
- `x-sync` — Tells Alpine Gale to include all component state in every request body.
  This is how the server reads the current `count` via `$request->state('count')`.
- `@click="$action('/counter/increment')"` — The `$action` magic sends a POST request to
  `/counter/increment` with the current Alpine state as the JSON body, then applies the
  server's response events (in this case, a state patch) to the component.
- `x-text="count"` — Alpine reactive binding that updates the displayed number whenever
  `count` changes.
- `$fetching()` — Returns `true` while a Gale action is in flight, useful for showing
  loading indicators.

### Step 3 — Register the routes

Add to `routes/web.php`:

```php
use App\Http\Controllers\CounterController;

Route::get('/counter', [CounterController::class, 'show']);
Route::post('/counter/increment', [CounterController::class, 'increment']);
```

### Step 4 — Visit the page

Navigate to `/counter` in your browser. You should see a counter at 0. Click the Increment
button — the count increases without a page reload.

---

## How the Data Flow Works

On each button click, Gale completes this round-trip without a page reload:

1. **Browser** — `$action('/counter/increment')` fires, Alpine Gale sends a POST with
   `Gale-Request: true` header and body `{ "count": 0 }` (the current `x-data` state
   via `x-sync`).
2. **Controller** — `$request->state('count')` reads `0` from the JSON body. The
   controller returns `gale()->state(['count' => 1])`.
3. **Response (HTTP mode)** — Gale sends `{ "events": [{ "type": "gale-patch-state",
   "data": { "count": 1 } }] }` as `application/json`.
4. **Browser** — Alpine Gale merges `{ "count": 1 }` into `x-data` via RFC 7386 JSON
   Merge Patch. Alpine reactivity updates `x-text="count"` to display `1`.

In SSE mode the identical events stream over `text/event-stream`. Controller code is
unchanged — only the transport differs.

---

## Common Issues

**"The counter does not update after clicking."**
Check that `x-sync` is on the `x-data` element. Without it, Alpine state is not sent in the
request body, so `$request->state('count')` returns the default (`0`) on every click.

**"I get a 419 CSRF mismatch error."**
Verify `@gale` is inside `<head>`. The `@gale` directive emits the CSRF meta tag that Alpine
Gale reads for every request. Without it, CSRF tokens are missing.

**"The page shows a JSON object instead of rendering."**
Your controller is returning `gale()->view(...)` without `web: true` on the first page load.
Add `web: true` as the third argument so Gale renders the full view for direct browser visits.

**"I see Alpine CDN already loaded warnings."**
Remove any `<script src="...alpinejs...">` or `<script defer src="cdn.jsdelivr.net/npm/alpinejs...">` tags from your layout. `@gale` bundles Alpine — loading it twice causes conflicts.

---

## API Cheat Sheet

### Backend — `gale()` methods

| Method | Signature | Example |
|--------|-----------|---------|
| `view()` | `view(string $view, array $data = [], array $options = [], bool $web = false)` | `gale()->view('home', ['title' => 'Home'], web: true)` |
| `state()` | `state(string\|array $key, mixed $value = null)` | `gale()->state(['count' => 5])` or `gale()->state('user', $user)` |
| `fragment()` | `fragment(string $view, string $fragment, array $data = [])` | `gale()->fragment('posts.index', 'list', compact('posts'))` |
| `messages()` | `messages(array $messages)` | `gale()->messages(['email' => 'Required'])` |
| `redirect()` | `redirect(?string $url = null)` | `gale()->redirect('/dashboard')->with('status', 'Saved')` |
| `download()` | `download(string $pathOrContent, string $filename)` | `gale()->download(storage_path('file.pdf'), 'report.pdf')` |
| `stream()` | `stream(Closure $callback)` | `gale()->stream(fn($g) => $g->state(['done' => true]))` |
| `debug()` | `debug(mixed $labelOrData, mixed $data = null)` | `gale()->debug('payload', $request->all())` |
| `dispatch()` | `dispatch(string $event, array $data = [])` | `gale()->dispatch('toast', ['message' => 'Saved!'])` |

### Frontend — Alpine Gale magics and directives

| Magic / Directive | Purpose | Example |
|------------------|---------|---------|
| `$action(url, opts?)` | POST with full state, apply response | `@click="$action('/save')"` |
| `$get(url, opts?)` | GET request, apply response | `@click="$get('/data')"` |
| `$post(url, body, opts?)` | POST with explicit body | `@click="$post('/save', { name })"` |
| `$fetching()` | `true` while a request is in flight | `x-show="$fetching()"` |
| `$gale` | Reactive Gale state (`loading`, `errors`) | `x-show="$gale.loading"` |
| `x-sync` | Sync all state with each request | `<div x-data="..." x-sync>` |
| `x-sync="[...]"` | Sync only named keys | `x-sync="['name', 'email']"` |
| `x-navigate` | Enable SPA navigation on links | `<div x-navigate>...</div>` |
| `$navigate(url, opts?)` | Programmatic SPA navigation | `$navigate('/page?q=' + query)` |
| `x-message="field"` | Display validation error for field | `<p x-message="email"></p>` |
| `x-loading` | Show element while request is in flight | `<span x-loading>Saving...</span>` |
| `x-interval.5s` | Poll an action every N seconds | `x-interval.5s="$action('/poll')"` |
| `x-component="name"` | Name a component for server targeting | `<div x-data x-component="widget">` |

---

## What's Next?

- [Core Concepts](core-concepts.md) — Understand the dual-mode architecture, RFC 7386 state
  merging, and how Alpine Gale integrates with the request lifecycle.
- [Backend API Reference](backend-api.md) — Full documentation for every `gale()` method:
  fragments, redirects, DOM patching, downloads, streaming, and more.
- [Frontend API Reference](frontend-api.md) — Full documentation for every Alpine Gale magic,
  directive, and `Alpine.gale.configure()` option.
- [Navigation & SPA](navigation.md) — Build SPA-style navigation with history, prefetching,
  and the PRG pattern for POST forms.
- [Forms, Validation & Uploads](forms-validation-uploads.md) — Reactive form handling,
  server-side validation, file uploads with progress, and multi-step forms.
