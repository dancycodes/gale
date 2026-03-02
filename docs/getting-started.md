# Getting Started

> **See also:** [Core Concepts](core-concepts.md) | [Backend API Reference](backend-api.md) | [Frontend API Reference](frontend-api.md)

This guide walks you through installing Gale, configuring Alpine.js, writing your first
reactive controller, and running the included example application.

> This guide is a placeholder. Full content is added by F-096 (Getting Started Guide).

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Alpine.js v3

---

## Installation

```bash
composer require dancycodes/gale
```

Publish the Gale assets (Alpine Gale plugin JS):

```bash
php artisan vendor:publish --tag=gale-assets --force
```

---

## Quick Start

Add `@gale` to your layout `<head>` — this replaces any Alpine.js CDN script:

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @gale
</head>
<body>
    @yield('content')
</body>
</html>
```

Write a reactive controller:

```php
use DancyCodes\Gale\Http\GaleResponse;

class CounterController extends Controller
{
    public function show(): GaleResponse
    {
        return gale()->view('counter', ['count' => 0]);
    }

    public function increment(Request $request): GaleResponse
    {
        $count = $request->input('count', 0) + 1;
        return gale()->patchState(['count' => $count]);
    }
}
```

Write the Blade view:

```html
<div x-data="{ count: 0 }">
    <p>Count: <span x-text="count"></span></p>
    <button @click="$post('/increment')">Increment</button>
</div>
```

---

## Next Steps

- Read [Core Concepts](core-concepts.md) to understand the dual-mode architecture
- Read [Backend API Reference](backend-api.md) for all `gale()` methods
- Read [Frontend API Reference](frontend-api.md) for all Alpine Gale magics
