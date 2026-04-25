# Gale Patterns & Architecture Guide

Proven patterns for building Gale applications. Every pattern is sourced from actual codebase usage.

## Page Architecture

### Dual-Mode Pages (Direct URL + Gale)

Pages that work both as direct browser visits and as Gale reactive updates.

```php
// Controller
public function index()
{
    $data = ['products' => Product::paginate(20)];

    return gale()
        ->view('products.index', $data, [], web: true)
        ->state(['page' => request('page', 1)]);
}
```

```blade
{{-- resources/views/products/index.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    @gale
</head>
<body>
    <div x-data="{ page: 1 }">
        @fragment('product-list')
        <div id="product-list">
            @foreach($products as $product)
                <div>{{ $product->name }}</div>
            @endforeach
            {{ $products->links() }}
        </div>
        @endfragment
    </div>
</body>
</html>
```

Key: `web: true` means the full HTML is served on direct browser visit, while Gale requests get only the events payload.

### Gale-Only Endpoints

Endpoints that only serve Gale responses (no direct URL access).

```php
public function increment()
{
    $count = session('count', 0) + 1;
    session(['count' => $count]);

    return gale()->state('count', $count);
}
```

Without `web: true`, non-Gale requests get 204 No Content.

> **Note**: This example demonstrates the *Gale-only endpoint* pattern. In practice, simple
> arithmetic like `count + 1` should stay client-side (`@click="count++"`). Use a server
> round-trip only when the count needs persistence or validation. See `best-practices.md` →
> Design Philosophy for the decision framework.

### Layout with Shared State

```blade
<html>
<head>@gale</head>
<body>
    <nav x-data="{ user: null }" x-component="nav">
        <span x-text="user?.name">Guest</span>
    </nav>

    <main x-data="{ content: '' }">
        @yield('content')
    </main>

    <footer x-data="{ year: {{ date('Y') }} }">
        &copy; <span x-text="year"></span>
    </footer>
</body>
</html>
```

## CRUD Patterns

### List with Fragment Updates

```php
// Controller
public function index()
{
    $items = Item::latest()->paginate(20);

    return gale()
        ->fragment('items.index', 'item-list', ['items' => $items])
        ->state(['page' => request('page', 1)]);
}
```

```blade
{{-- resources/views/items/index.blade.php --}}
@fragment('item-list')
<div id="item-list">
    @foreach($items as $item)
        <div id="item-{{ $item->id }}">{{ $item->name }}</div>
    @endforeach
</div>
@endfragment
```

**Critical**: Only pass `$items` — NOT the full page data. The fragment is compiled in isolation; the full view is never rendered.

### Create with Validation

```php
public function store(Request $request)
{
    // ValidationException auto-converts to gale()->messages() (NOT errors()) for Gale requests.
    // Display via <span x-message="fieldname"> below — see frontend-api.md → x-message.
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
    ]);

    $user = User::create($validated);

    return gale()
        ->state(['saved' => true])
        ->messages(['_success' => 'User created!'])
        ->navigate('/users/' . $user->id, 'create');
}
```

```blade
<form x-data="{ name: '', email: '', saved: false, creating: false }" @submit.prevent="$action('/users')">
    <input x-name="name" type="text" required>
    <span x-message="name" class="text-red-500"></span>

    <input x-name="email" type="email" required>
    <span x-message="email" class="text-red-500"></span>

    <span x-message="_success" class="text-green-500"></span>
    <button type="submit" x-indicator="creating" :disabled="creating">
        <span x-show="!creating">Create</span>
        <span x-show="creating"><svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg></span>
    </button>
</form>
```

> **Note:** Use `x-indicator="creating"` instead of `$fetching` for button-specific loading.
> `$fetching` is per-x-data-scope — in multi-action components, ANY action would disable ALL
> buttons. `x-indicator` only reacts to actions from the element itself or its children.

### Update with Optimistic UI

```php
public function update(Request $request, Item $item)
{
    $validated = $request->validate(['name' => 'required|string']);
    $item->update($validated);

    return gale()
        ->state(['name' => $item->name, 'editing' => false])
        ->messages(['_success' => 'Updated!']);
}
```

```blade
<div x-data="{ name: '{{ $item->name }}', editing: false }">
    <template x-if="!editing">
        <span x-text="name" @click="editing = true"></span>
    </template>
    <template x-if="editing">
        <input x-name="name" @keydown.enter="$action('/items/{{ $item->id }}', {
            method: 'PUT',
            optimistic: { editing: false }
        })">
    </template>
</div>
```

### Delete with Confirmation

```php
public function destroy(Item $item)
{
    $item->delete();
    return gale()->remove('#item-' . $item->id);
}
```

```blade
<button @click="$action.delete('/items/{{ $item->id }}')"
        x-confirm="Delete this item?">
    Delete
</button>
```

## Form Patterns

### Multi-Step Form

```php
public function step(Request $request)
{
    $step = $request->input('step', 1);
    $validated = match($step) {
        1 => $request->validate(['name' => 'required']),
        2 => $request->validate(['email' => 'required|email']),
        3 => $request->validate(['plan' => 'required|in:basic,pro']),
    };

    session()->put("form.step{$step}", $validated);

    if ($step < 3) {
        return gale()->state(['step' => $step + 1]);
    }

    // Final step — create the record
    $data = collect(range(1, 3))
        ->flatMap(fn($s) => session("form.step{$s}", []))
        ->all();

    User::create($data);
    return gale()->redirect()->route('users.index');
}
```

```blade
<div x-data="{ step: 1, name: '', email: '', plan: '' }">
    <template x-if="step === 1">
        <div>
            <input x-name="name" placeholder="Name">
            <button @click="$action('/register/step', { include: ['name', 'step'] })">Next</button>
        </div>
    </template>
    <template x-if="step === 2">
        <div>
            <input x-name="email" placeholder="Email">
            <button @click="$action('/register/step', { include: ['email', 'step'] })">Next</button>
        </div>
    </template>
    <template x-if="step === 3">
        <div>
            <select x-name="plan">
                <option value="basic">Basic</option>
                <option value="pro">Pro</option>
            </select>
            <button @click="$action('/register/step', { include: ['plan', 'step'] })">Submit</button>
        </div>
    </template>
</div>
```

### File Upload

```php
public function upload(Request $request)
{
    $request->validate([
        'avatar' => 'required|image|max:5120',
    ]);

    $path = $request->file('avatar')->store('avatars', 'public');

    return gale()
        ->state(['avatarUrl' => Storage::url($path)])
        ->messages(['_success' => 'Photo uploaded!']);
}
```

```blade
<div x-data="{ avatarUrl: '' }">
    <img :src="avatarUrl" x-show="avatarUrl" class="w-20 h-20 rounded-full">
    <img :src="$filePreview('avatar')" x-show="$file('avatar') && !avatarUrl" class="w-20 h-20 rounded-full opacity-50">

    <input type="file" x-files="avatar" accept="image/*" data-max-size="5242880">

    <div x-show="$uploading" class="text-sm text-gray-500">
        Uploading: <span x-text="$uploadProgress + '%'"></span>
    </div>

    <button @click="$action('/upload')" :disabled="!$file('avatar') || $uploading">
        Upload
    </button>
</div>
```

### Search with Debounce

```php
public function search(Request $request)
{
    $results = Product::where('name', 'like', '%' . $request->input('query') . '%')
        ->limit(10)
        ->get();

    return gale()->fragment('products.search', 'results', ['results' => $results]);
}
```

```blade
<div x-data="{ query: '' }">
    <input x-name="query" @input="$action.get('/search', {
        debounce: 300,
        include: ['query']
    })">

    @fragment('results')
    <div id="results">
        @foreach($results ?? [] as $result)
            <div>{{ $result->name }}</div>
        @endforeach
    </div>
    @endfragment
</div>
```

## Component Communication

### Named Components

```blade
{{-- Sidebar --}}
<div x-data="{ count: 0 }" x-component="cart-badge">
    Cart (<span x-text="count">0</span>)
</div>

{{-- Product Card --}}
<div x-data="{ added: false }">
    <button @click="$action('/cart/add/{{ $product->id }}')" x-show="!added">
        Add to Cart
    </button>
</div>
```

```php
// Server updates BOTH components in one response
public function addToCart(Product $product)
{
    $cart = session('cart', []);
    $cart[] = $product->id;
    session(['cart' => $cart]);

    return gale()
        ->state(['added' => true])                       // Update triggering component
        ->componentState('cart-badge', ['count' => count($cart)]); // Update sidebar badge
}
```

### Tag-Based Broadcasting

```blade
<div x-data="{ inStock: true }" x-component="card-{{ $product->id }}" data-tags="product-card">
    <span x-show="!inStock" class="text-red-500">Out of Stock</span>
</div>
```

```php
// Update ALL product cards at once
gale()->tagState('product-card', ['inStock' => false]);
```

### Component Method Invocation

```php
gale()->componentMethod('cart', 'recalculate', [true]);
```

Calls `recalculate(true)` on the `cart` component's `x-data` object.

### Alpine Store Patching

```php
gale()->patchStore('notifications', ['unread' => 3]);
```

```blade
<div x-data x-text="$store.notifications.unread">0</div>
```

## Navigation Patterns

### SPA with Navigate Keys

```blade
{{-- Sidebar triggers page content update --}}
<a href="/products" x-navigate.key="main-content">Products</a>
<a href="/settings" x-navigate.key="main-content">Settings</a>
```

```php
public function products()
{
    return gale()
        ->whenGaleNavigate('main-content', function ($g) {
            return $g->fragment('pages.products', 'main', ['products' => Product::all()]);
        })
        ->view('pages.products', ['products' => Product::all()], [], web: true);
}
```

### Pagination

```php
public function index(Request $request)
{
    $products = Product::paginate(20);

    return gale()
        ->fragment('products.index', 'product-list', ['products' => $products])
        ->navigateMerge(['page' => $request->page], 'pagination');
}
```

### Query Parameter Updates

```php
// Add/update query params without full navigation
gale()->updateQueries(['search' => 'laptop', 'page' => 1]);

// Clear specific params
gale()->clearQueries(['search', 'filter']);
```

## Streaming Patterns

### Long-Running Operations

```php
public function processReport()
{
    return gale()->stream(function ($gale) {
        $gale->state('status', 'Fetching data...');

        $records = Record::all();
        $total = $records->count();

        foreach ($records as $i => $record) {
            processRecord($record);
            $gale->state('progress', round(($i + 1) / $total * 100));
        }

        $gale->state('status', 'Complete!');
        $gale->state('progress', 100);
    });
}
```

```blade
<div x-data="{ status: '', progress: 0 }">
    <button @click="$action('/process-report')">Generate</button>
    <div x-show="status">
        <p x-text="status"></p>
        <div class="bg-gray-200 h-2 rounded">
            <div class="bg-blue-500 h-2 rounded" :style="'width:' + progress + '%'"></div>
        </div>
    </div>
</div>
```

Rules: `stream()` always uses SSE. Session is closed before streaming. Never `echo` directly. Exceptions emit `gale-error` (page preserved). Redirects inside stream work via `GaleStreamRedirector`.

### AI Chat Streaming

```php
public function chat(Request $request)
{
    return gale()->stream(function ($gale) use ($request) {
        $gale->state('thinking', true);

        $response = '';
        foreach (streamFromLLM($request->input('message')) as $chunk) {
            $response .= $chunk;
            $gale->state('response', $response);
        }

        $gale->state('thinking', false);
    });
}
```

## Push Channel Patterns

### Real-Time Notifications

```php
// In a Job, Event Listener, or anywhere
gale()->push('notifications')
    ->patchState(['count' => Notification::unread()->count()])
    ->send();
```

```blade
<div x-data="{ count: 0 }" x-listen="notifications">
    <span x-text="count">0</span> unread
</div>
```

### Live Dashboard

```php
// In a scheduled command or event listener
gale()->push('dashboard')
    ->patchState(['visitors' => Analytics::current()])
    ->patchElements('#chart', view('partials.chart', ['data' => $chartData])->render())
    ->send();
```

Always call `->send()` to flush events.

## Conditional Response Patterns

```php
return gale()
    // Only for Gale requests
    ->whenGale(fn($g) => $g->state('reactive', true))

    // Only for non-Gale requests
    ->whenNotGale(fn($g) => $g->web(view('static')))

    // Only for navigate requests with specific key
    ->whenGaleNavigate('sidebar', fn($g) => $g->fragment('layout', 'sidebar', $data))

    // General conditions
    ->when($isAdmin, fn($g) => $g->state('admin', true))
    ->unless($isGuest, fn($g) => $g->state('role', 'user'));
```

## Flash Data Pattern

```php
gale()->flash('success', 'Record saved!');
gale()->flash(['status' => 'updated', 'count' => 5]);
```

```blade
<div x-data="{ _flash: {} }">
    <div x-show="_flash.success" x-text="_flash.success" class="bg-green-100 p-4"></div>
</div>
```

Flash data goes to Laravel session AND is delivered as `_flash` state in the current response.

## Multiple Fragments Pattern

```php
return gale()->fragments([
    ['view' => 'dashboard', 'fragment' => 'stats', 'data' => ['stats' => $stats]],
    ['view' => 'dashboard', 'fragment' => 'chart', 'data' => ['chart' => $chart]],
    ['view' => 'dashboard', 'fragment' => 'recent', 'data' => ['items' => $items]],
]);
```

## Error Handling Pattern

```php
// ValidationException is auto-converted — just use standard Laravel validation.
// On failure: data lands in `messages` state (one string per field).
// Display via <span x-message="email"> in your form.
$request->validate(['email' => 'required|email']);

// Or use the Gale-native validator (same effect, more explicit name):
$request->validateState(['email' => 'required|email']);

// Manual: write to `errors` state explicitly. Used when you want array-of-strings per field
// AND you want to display via <span x-message.from.errors="email"> (different state slot).
return gale()->errors(['email' => ['This email is already taken.']]);

// Clear messages and errors independently:
return gale()->clearMessages();   // wipes `messages` state
return gale()->clearErrors();     // wipes `errors` state
return gale()->clearMessages()->clearErrors();
```

**`messages` vs `errors` — choose one consistently:**

| Server method | State slot | Display directive |
|---|---|---|
| `$request->validate()` (auto) | `messages` | `<span x-message="field">` |
| `$request->validateState()` | `messages` | `<span x-message="field">` |
| `gale()->messages([...])` | `messages` | `<span x-message="field">` |
| `gale()->errors([...])` | `errors` | `<span x-message.from.errors="field">` |

For most apps, `messages` + `<span x-message>` is enough. Reach for `errors` + `.from.errors` only when you need to surface multiple errors per field at once (the array-of-strings shape).

## Redirect Patterns

```php
// Simple redirect
return gale()->redirect('/dashboard');

// Named route
return gale()->redirect()->route('dashboard');

// Back with fallback
return gale()->redirect()->back();

// With flash data
return gale()->redirect('/login')->with('message', 'Please log in');

// With validation errors
return gale()->redirect()->back()->withErrors($validator)->withInput();

// External URL (bypasses domain validation)
return gale()->redirect()->away('https://stripe.com/checkout');
```

**Anti-pattern**: `gale()->redirect('/url')->state('key', 'value')` — redirect is terminal, state calls after it are lost.

## Download Pattern

```php
// File download
gale()->download(storage_path('reports/q1.pdf'), 'Q1-Report.pdf');

// Dynamic content download
gale()->download($csvContent, 'export.csv', 'text/csv', isContent: true);

// Chainable
gale()->download($path, 'report.pdf')->state('lastExport', now()->toIso8601String());
```

## Lifecycle Hooks Pattern

```php
// In AppServiceProvider::boot()
GaleResponse::beforeRequest(function (Request $request) {
    logger('Gale request: ' . $request->path());
});

GaleResponse::afterResponse(function ($response, Request $request) {
    $response->headers->set('X-Debug-Time', microtime(true));
    return $response;
});
```

## Macro Pattern

```php
// Register — typically in AppServiceProvider::boot()
GaleResponse::macro('toast', function (string $msg, string $type = 'success') {
    return $this->dispatch('show-toast', ['message' => $msg, 'type' => $type]);
});

// Use anywhere
gale()->toast('Saved!');
gale()->toast('Error!', 'error');
```

Macro names cannot conflict with existing GaleResponse methods (throws `RuntimeException`).

## Polling Pattern

```blade
{{-- Poll every 5 seconds --}}
<div x-data="{ status: 'pending' }"
     x-interval="5000"
     data-url="/check-status">
    Status: <span x-text="status"></span>
</div>
```

```php
public function checkStatus()
{
    return gale()->state('status', Job::latest()->status);
}
```

## Lazy Loading Pattern

```blade
{{-- Lazy load comments when scrolled into view --}}
<div x-data x-lazy="/comments/{{ $post->id }}">
    {{-- Shimmer placeholder shown automatically --}}
</div>
```

```php
public function comments(Post $post)
{
    return gale()->html(
        view('partials.comments', ['comments' => $post->comments])->render()
    );
}
```
