# Gale Patterns Reference

Complete working examples of common Gale patterns. All examples use **HTTP mode** (the default). SSE opt-in variants are shown where applicable. Read this file for implementation guidance.

## Table of Contents
- [Pattern 1: Basic Counter (HTTP Mode)](#pattern-1-basic-counter)
- [Pattern 2: CRUD with Fragments](#pattern-2-crud-with-fragments)
- [Pattern 3: Live Search with Navigation](#pattern-3-live-search-with-navigation)
- [Pattern 4: Multi-Panel with Navigate Keys](#pattern-4-multi-panel-with-navigate-keys)
- [Pattern 5: Streaming Progress (SSE Mode)](#pattern-5-streaming-progress)
- [Pattern 6: Dashboard with Polling](#pattern-6-dashboard-with-polling)
- [Pattern 7: Kanban Board (DOM Modes)](#pattern-7-kanban-board)
- [Pattern 8: File Upload Gallery](#pattern-8-file-upload-gallery)
- [Pattern 9: Bulk Operations Table](#pattern-9-bulk-operations-table)
- [Pattern 10: Form Validation](#pattern-10-form-validation)
- [Pattern 11: Redirect Patterns](#pattern-11-redirect-patterns)
- [Pattern 12: Conditional Responses](#pattern-12-conditional-responses)
- [Pattern 13: Error Handling and Retry](#pattern-13-error-handling-and-retry)
- [Pattern 14: Configuration and Lifecycle](#pattern-14-configuration-and-lifecycle)
- [Pattern 15: Authentication Flow](#pattern-15-authentication-flow)
- [Pattern 16: Mode Selection Guide](#pattern-16-mode-selection-guide)

---

## Pattern 1: Basic Counter

The simplest Gale pattern. Demonstrates the full HTTP mode request/response cycle.

**routes/web.php:**
```php
Route::get('/counter', fn() => gale()->view('counter', web: true));
Route::post('/increment', function () {
    return gale()->state('count', request()->state('count', 0) + 1);
});
```

**counter.blade.php:**
```blade
<head>@gale</head>
<body>
    <div x-data="{ count: 0 }" x-sync>
        <span x-text="count"></span>
        <button @click="$action('/increment')">+</button>
    </div>
</body>
```

**How it works in HTTP mode:**
1. `$action('/increment')` sends POST with `{ count: 0 }` as JSON body
2. Server returns `{ "events": [{ "type": "gale-patch-state", "data": { "state": { "count": 1 } } }] }`
3. Frontend processes JSON events and merges `{ count: 1 }` into Alpine state
4. `x-text="count"` reactively updates

**SSE variant** (opt-in -- same backend code, different frontend option):
```html
<button @click="$action('/increment', { sse: true })">+</button>
```

---

## Pattern 2: CRUD with Fragments

Task manager with add, edit, toggle, delete. Uses fragments and componentState.

**Controller:**
```php
class TaskController extends Controller
{
    public function index(Request $request)
    {
        $tasks = auth()->user()->tasks;
        return gale()->view('tasks.index', compact('tasks'), web: true);
    }

    public function store(Request $request)
    {
        $data = $request->validateState([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        auth()->user()->tasks()->create($data);
        $tasks = auth()->user()->tasks;

        return gale()
            ->componentState('tasks-stats', [
                'total' => $tasks->count(),
                'completed' => $tasks->where('is_completed', true)->count(),
                'pending' => $tasks->where('is_completed', false)->count(),
            ])
            ->fragment('tasks.index', 'tasks.list', compact('tasks'))
            ->dispatch('toast', ['type' => 'success', 'message' => 'Task added!']);
    }

    public function toggleCompletion(Task $task)
    {
        $task->update(['is_completed' => !$task->is_completed]);
        $this->updateStats();

        return gale()
            ->view('tasks.partials.item', compact('task'))
            ->dispatch('toast', ['type' => 'success', 'message' => 'Status updated!']);
    }

    public function destroy(Task $task)
    {
        $id = $task->id;
        $task->delete();
        $tasks = auth()->user()->tasks;

        $this->updateStats($tasks);

        return gale()
            ->when($tasks->count() === 0, function ($gale) {
                $gale->fragment('tasks.index', 'tasks.list', ['tasks' => []]);
            }, function ($gale) use ($id) {
                $gale->remove("#task-{$id}");
            })
            ->dispatch('toast', ['type' => 'success', 'message' => 'Deleted!']);
    }

    private function updateStats($tasks = null): void
    {
        $tasks = $tasks ?? auth()->user()->tasks;
        gale()->componentState('tasks-stats', [
            'total' => $tasks->count(),
            'completed' => $tasks->where('is_completed', true)->count(),
            'pending' => $tasks->where('is_completed', false)->count(),
        ]);
    }
}
```

**Blade (index):**
```blade
<div class="max-w-2xl mx-auto">
    <div class="mb-6">@include('tasks.partials.form')</div>

    {{-- Stats with x-component for server targeting --}}
    <div x-data="{ total: @js($tasks->count()), completed: @js($tasks->where('is_completed', true)->count()), pending: @js($tasks->where('is_completed', false)->count()) }"
         x-component="tasks-stats">
        <span>Total: <strong x-text="total"></strong></span>
        <span>Done: <strong x-text="completed"></strong></span>
    </div>

    {{-- Fragment for task list --}}
    @fragment('tasks.list')
    <div id="tasks-list">
        @forelse($tasks as $task)
            @include('tasks.partials.item', ['task' => $task])
        @empty
            <p>No tasks yet.</p>
        @endforelse
    </div>
    @endfragment
</div>
```

**Key points:**
- Controller code is identical for HTTP and SSE modes
- `componentState()` updates the stats widget from any controller action
- `fragment()` re-renders just the task list, not the entire page
- `dispatch()` sends a custom browser event for toast notifications

---

## Pattern 3: Live Search with Navigation

Search with URL updates using SPA navigation.

**Controller:**
```php
public function search(Request $request)
{
    $q = $request->input('q', '');
    $results = Product::query()
        ->when($q, fn($query) => $query->where('name', 'like', "%{$q}%"))
        ->paginate(20);

    if ($request->isGaleNavigate('search')) {
        return gale()->fragment('products.search', 'results', compact('results', 'q'));
    }
    return gale()->view('products.search', compact('results', 'q'), web: true);
}
```

**Blade:**
```blade
<div x-data x-navigate>
    <input type="text"
           @input.debounce.300ms="$navigate('/search?q=' + $el.value, {
               key: 'search', merge: true, replace: true, except: ['page']
           })">

    @fragment('results')
    <div id="search-results">
        @foreach($results as $product)
            <div id="product-{{ $product->id }}">{{ $product->name }}</div>
        @endforeach
    </div>
    @endfragment
</div>
```

---

## Pattern 4: Multi-Panel with Navigate Keys

Sidebar + main content panel with different navigate keys.

**Controller:**
```php
public function show(Request $request, Category $category)
{
    $categories = Category::all();
    $products = $category->products;

    if ($request->isGaleNavigate('sidebar')) {
        return gale()
            ->fragment('catalog.show', 'sidebar', compact('categories', 'category'))
            ->fragment('catalog.show', 'products', compact('products', 'category'));
    }

    if ($request->isGaleNavigate('products')) {
        return gale()->fragment('catalog.show', 'products', compact('products', 'category'));
    }

    return gale()->view('catalog.show', compact('categories', 'products', 'category'), web: true);
}
```

**Blade:**
```blade
<div x-data x-navigate>
    @fragment('sidebar')
    <nav id="sidebar">
        @foreach($categories as $cat)
            <a href="/catalog/{{ $cat->slug }}"
               x-navigate.key.sidebar
               class="{{ $cat->id === $category->id ? 'font-bold' : '' }}">
                {{ $cat->name }}
            </a>
        @endforeach
    </nav>
    @endfragment

    @fragment('products')
    <main id="products">
        <h2>{{ $category->name }}</h2>
        @foreach($products as $product)
            <div>{{ $product->name }} - ${{ $product->price }}</div>
        @endforeach
    </main>
    @endfragment
</div>
```

---

## Pattern 5: Streaming Progress (SSE Mode)

Long-running operations with real-time progress. **This pattern always uses SSE** via `stream()`.

**Controller:**
```php
public function processExport(Request $request)
{
    return gale()->stream(function ($gale) {
        $users = User::cursor();
        $total = User::count();
        $processed = 0;

        foreach ($users as $user) {
            $user->generateReport();
            $processed++;

            $gale->state('progress', [
                'current' => $processed,
                'total' => $total,
                'percent' => round(($processed / $total) * 100),
            ]);
        }

        $gale->state('complete', true);
        $gale->messages(['_success' => "Exported {$total} users"]);
    });
}
```

**Blade:**
```blade
<div x-data="{ progress: { percent: 0, current: 0, total: 0 }, complete: false }">
    <button @click="$action('/export')"
            :disabled="$fetching()">
        <span x-show="!$fetching()">Start Export</span>
        <span x-show="$fetching()">Exporting...</span>
    </button>

    <div x-show="$fetching() || complete">
        <div class="bg-gray-200 rounded-full h-4">
            <div class="bg-blue-600 h-4 rounded-full transition-all"
                 :style="'width: ' + progress.percent + '%'"></div>
        </div>
        <p x-text="progress.current + ' / ' + progress.total"></p>
    </div>

    <p x-show="complete" class="text-green-600">Export complete!</p>
</div>
```

**Why this works without `{ sse: true }`:** The backend uses `gale()->stream()`, which forces SSE mode on the response. The frontend auto-detects `text/event-stream` content type and processes it via the SSE parser, regardless of the requested mode. No frontend changes needed.

---

## Pattern 6: Dashboard with Polling

Auto-refreshing dashboard with named components.

**Controller:**
```php
public function dashboardStats(Request $request)
{
    return gale()
        ->componentState('stat-users', ['value' => User::count()])
        ->componentState('stat-orders', ['value' => Order::today()->count()])
        ->componentState('stat-revenue', ['value' => Order::today()->sum('total')]);
}
```

**Blade:**
```blade
<div x-data x-interval.10s.visible="$action.get('/dashboard/stats')">
    <div x-data="{ value: @js($users) }" x-component="stat-users">
        Users: <span x-text="value"></span>
    </div>
    <div x-data="{ value: @js($orders) }" x-component="stat-orders">
        Orders: <span x-text="value"></span>
    </div>
    <div x-data="{ value: @js($revenue) }" x-component="stat-revenue">
        Revenue: $<span x-text="value.toLocaleString()"></span>
    </div>
</div>
```

**Tag-based targeting** (update all widgets at once):
```html
<div x-data="{ value: @js($users) }" x-component="stat-users" data-tags="dashboard-widget">
<div x-data="{ value: @js($orders) }" x-component="stat-orders" data-tags="dashboard-widget">
```
```php
// Update all components tagged 'dashboard-widget' at once
gale()->tagState('dashboard-widget', ['refreshed' => true, 'lastUpdate' => now()->toISOString()]);
```

**Key design choices:**
- `.visible` modifier stops polling when tab is hidden (saves server resources)
- `$action.get()` uses HTTP GET -- lightweight, cacheable by CDNs/proxies
- `componentState()` targets each widget independently from a single request
- `tagState()` targets all components with a specific tag for bulk updates

---

## Pattern 7: Kanban Board (DOM Modes)

Demonstrates different DOM manipulation modes for different use cases.

**Controller:**
```php
public function moveCard(Request $request, Card $card)
{
    $card->update(['status' => $request->state('newStatus')]);

    // Remove from old column, add to new column
    return gale()
        ->remove("#card-{$card->id}")
        ->append("#column-{$card->status}", view('cards.item', compact('card'))->render(), [
            'scroll' => 'bottom',
            'settle' => 100,
        ]);
}

public function updateCard(Request $request, Card $card)
{
    $card->update($request->validateState(['title' => 'required|max:255']));

    // Use outerMorph to preserve user focus/state
    return gale()->outerMorph(
        "#card-{$card->id}",
        view('cards.item', compact('card'))->render()
    );
}
```

---

## Pattern 8: File Upload Gallery

Image upload with preview and progress tracking.

**Controller:**
```php
public function uploadImages(Request $request)
{
    $request->validate([
        'images.*' => 'required|image|max:5120',
    ]);

    $html = '';
    foreach ($request->file('images') as $file) {
        $path = $file->store('gallery', 'public');
        $image = Image::create(['path' => $path, 'name' => $file->getClientOriginalName()]);
        $html .= view('gallery.item', compact('image'))->render();
    }

    return gale()
        ->append('#gallery-grid', $html)
        ->state('imageCount', Image::count())
        ->dispatch('toast', ['type' => 'success', 'message' => 'Images uploaded!']);
}
```

**Blade:**
```blade
<div x-data="{ imageCount: @js($images->count()) }">
    <input type="file" name="images" x-files.max-size-5mb.max-files-10 multiple accept="image/*">

    {{-- Preview before upload --}}
    <div class="grid grid-cols-4 gap-2">
        <template x-for="(file, i) in $files('images')" :key="i">
            <img :src="$filePreview('images', i)" class="w-full h-24 object-cover rounded">
        </template>
    </div>

    {{-- Upload progress --}}
    <div x-show="$uploading" class="mt-2">
        <div class="bg-gray-200 rounded-full h-2">
            <div class="bg-blue-600 h-2 rounded-full" :style="'width: ' + $uploadProgress + '%'"></div>
        </div>
    </div>

    <button @click="$action('/gallery/upload', { onProgress: p => {} })"
            :disabled="$uploading || !$files('images').length">
        Upload <span x-text="$files('images').length"></span> images
    </button>

    <p>Total images: <span x-text="imageCount"></span></p>

    <div id="gallery-grid" class="grid grid-cols-4 gap-4">
        @foreach($images as $image)
            @include('gallery.item', ['image' => $image])
        @endforeach
    </div>
</div>
```

---

## Pattern 9: Bulk Operations Table

Select multiple items and perform bulk operations.

**Controller:**
```php
public function bulkDelete(Request $request)
{
    $ids = $request->state('selectedIds', []);
    Task::whereIn('id', $ids)->delete();

    foreach ($ids as $id) {
        gale()->remove("#task-{$id}");
    }

    return gale()
        ->state('selectedIds', [])
        ->componentState('tasks-stats', [
            'total' => Task::count(),
        ])
        ->dispatch('toast', ['type' => 'success', 'message' => count($ids) . ' tasks deleted']);
}
```

**Blade:**
```blade
<div x-data="{ selectedIds: [], selectAll: false }">
    <div class="mb-4 flex gap-2">
        <button @click="$action.delete('/tasks/bulk', { include: ['selectedIds'] })"
                :disabled="!selectedIds.length"
                x-confirm="'Delete ' + selectedIds.length + ' task(s)?'">
            Delete Selected
        </button>
    </div>

    <table>
        <thead>
            <tr>
                <th><input type="checkbox" x-model="selectAll"
                    @change="selectedIds = selectAll ? @js($tasks->pluck('id')) : []"></th>
                <th>Title</th>
            </tr>
        </thead>
        <tbody>
            @foreach($tasks as $task)
            <tr id="task-{{ $task->id }}">
                <td><input type="checkbox" value="{{ $task->id }}" x-model="selectedIds"></td>
                <td>{{ $task->title }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

---

## Pattern 10: Form Validation

Three approaches to form validation, all working in HTTP mode by default.

### Approach A: validateState() (Alpine State)
```php
// Controller
$validated = $request->validateState([
    'name' => 'required|min:2|max:255',
    'email' => 'required|email|unique:users',
    'password' => 'required|min:8|confirmed',
]);
User::create($validated);
return gale()->redirect('/dashboard')->with('message', 'Account created!');
```

```blade
<div x-data="{ name: '', email: '', password: '', password_confirmation: '' }" x-sync>
    <div>
        <input x-name="name" type="text" placeholder="Name">
        <p x-message="name" class="text-red-500 text-sm"></p>
    </div>
    <div>
        <input x-name="email" type="email" placeholder="Email">
        <p x-message="email" class="text-red-500 text-sm"></p>
    </div>
    <div>
        <input x-name="password" type="password" placeholder="Password">
        <p x-message="password" class="text-red-500 text-sm"></p>
    </div>
    <div>
        <input x-name="password_confirmation" type="password" placeholder="Confirm">
    </div>
    <button @submit.prevent="$action('/register')">Register</button>
</div>
```

### Approach B: Standard validate() (Auto-Converts)
```php
// Controller -- standard Laravel validate() just works for Gale requests
$validated = $request->validate([
    'name' => 'required|min:2|max:255',
    'email' => 'required|email|unique:users',
]);
// ValidationException auto-converts to gale()->messages() for Gale requests
```

### Approach C: Form Request Class (Auto-Converts)
```php
// Form Request
class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter your name.',
            'email.unique' => 'This email is already registered.',
        ];
    }
}

// Controller
public function register(RegisterRequest $request)
{
    // Validation happens automatically -- errors auto-convert for Gale requests
    $user = User::create($request->validated());
    return gale()->redirect('/dashboard');
}
```

---

## Pattern 11: Redirect Patterns

```php
// Basic redirect
return gale()->redirect('/dashboard');

// With flash data
return gale()->redirect('/dashboard')->with('message', 'Saved!');

// Back (with fallback)
return gale()->redirect('/')->back('/dashboard');
return gale()->redirect('/')->backOr('dashboard.index');

// Named route
return gale()->redirect('/')->route('users.show', ['user' => $user->id]);

// With errors and input preservation
return gale()->redirect('/')->back()->withErrors($validator)->withInput();

// Refresh current page
return gale()->redirect('/')->refresh();

// Hard reload (force browser reload)
return gale()->reload();

// External URL
return gale()->redirect('/')->away('https://example.com');

// After authentication
return gale()->redirect('/')->intended('/dashboard');
```

---

## Pattern 12: Conditional Responses

```php
// Simple conditional
return gale()->when($user->isAdmin(), function ($g) {
    $g->state('role', 'admin');
    $g->state('permissions', $user->permissions->pluck('name'));
}, function ($g) {
    $g->state('role', 'user');
});

// Gale vs non-Gale request
return gale()
    ->whenGale(fn($g) => $g->state('partial', true))
    ->whenNotGale(fn() => view('full-page'));

// Navigate-specific
return gale()
    ->whenGaleNavigate('sidebar', fn($g) => $g->fragment('layout', 'sidebar', $data))
    ->whenGaleNavigate('content', fn($g) => $g->fragment('layout', 'content', $data))
    ->web(view('layout.full', $data));

// Unless pattern
return gale()->unless($user->isGuest(), function ($g) use ($user) {
    $g->state('user', $user->toArray());
    $g->state('notifications', $user->unreadNotifications->count());
});
```

---

## Pattern 13: Error Handling and Retry

### HTTP Mode Error Handling (Default)

```blade
{{-- HTTP mode: errors shown as toast notifications automatically --}}
<div x-data="{ count: 0 }" x-sync>
    <button @click="$action('/might-fail')">Try Action</button>

    {{-- Global error display --}}
    <div x-show="$gale.error" class="bg-red-100 p-4 rounded">
        <p x-text="$gale.lastError?.message"></p>
        <button @click="$gale.clearErrors()">Dismiss</button>
    </div>

    {{-- Retry indicator --}}
    <div x-show="$gale.retrying" class="bg-yellow-100 p-4 rounded">
        Reconnecting...
    </div>
</div>
```

### Custom Error Notification Configuration

```javascript
// Configure toast notification behavior for server errors
Alpine.gale.configureErrors({
    autoDismissMs: 8000,    // Auto-dismiss after 8s (default: 5000, 0 = never)
    maxToasts: 3,           // Max 3 toasts visible at once (default: 5)
});

// Custom error handler (replaces default toast)
Alpine.gale.configureErrors({
    onError: (status, statusText) => {
        // Your custom notification system
        myNotify.error(`Server error: ${status} ${statusText}`);
    },
});
```

### Custom Retry Configuration

```javascript
// Global: configure retry behavior for HTTP network errors
Alpine.gale.configure({
    retry: {
        maxRetries: 5,         // More retries
        initialDelay: 500,     // Start faster
        backoffMultiplier: 1.5, // Slower backoff
    },
});
```

### SSE Mode Retry (Opt-In)

```html
<!-- Per-action SSE retry configuration -->
<button @click="$action('/stream', {
    sse: true,
    retryInterval: 2000,
    retryScaler: 2,
    retryMaxWaitMs: 30000,
    retryMaxCount: 5
})">Stream with Retry</button>
```

### Lifecycle Events

```html
<div @gale:started="console.log('Request started')"
     @gale:finished="console.log('Request finished')"
     @gale:error="console.log('Error:', $event.detail.status)"
     @gale:retrying="console.log('Retrying...', $event.detail.attempt)">
    <button @click="$action('/save')">Save</button>
</div>
```

---

## Pattern 14: Configuration and Lifecycle

### Global Mode Configuration

```javascript
// Set SSE as default mode (usually not needed -- HTTP is better for most cases)
Alpine.gale.configure({ defaultMode: 'sse' });

// Enable View Transitions for SPA navigation
Alpine.gale.configure({ viewTransitions: true });

// Configure FOUC prevention
Alpine.gale.configure({
    foucTimeout: 5000,        // Wait up to 5s for stylesheets
    navigationIndicator: true, // Show progress bar
});

// Configure SSE tab visibility behavior
Alpine.gale.configure({
    pauseOnHidden: true,       // Pause SSE when tab hidden
    pauseOnHiddenDelay: 2000,  // Wait 2s before pausing
});

// Configure CSRF refresh strategy
Alpine.gale.configure({ csrfRefresh: 'sanctum' }); // or 'auto' or 'meta'
```

### Swap/Settle CSS Transitions

```javascript
// Configure animation timing for DOM patches
Alpine.gale.configureSwapSettle({
    timing: {
        swapDelay: 150,     // 150ms exit animation
        settleDelay: 150,   // 150ms enter animation
        addedDuration: 500, // Keep gale-added class for 500ms
    },
});
```

```css
/* CSS for swap/settle animations */
.gale-swapping { opacity: 1; transition: opacity 150ms ease-out; }
.gale-swapping { opacity: 0; }
.gale-settling { opacity: 0; transition: opacity 150ms ease-in; }
.gale-added { opacity: 1; }
```

### Custom Confirmation Handler

```javascript
// Replace browser confirm() with custom modal
Alpine.gale.configureConfirm({
    handler: async (message) => {
        return new Promise((resolve) => {
            // Show your custom modal...
            myModal.show(message, resolve);
        });
    },
});
```

### Component Lifecycle

```javascript
// Monitor component registration
Alpine.gale.onComponentRegistered((name, component) => {
    console.log(`${name} registered with state:`, component._x_dataStack[0]);
});

// Watch for state changes on specific component
Alpine.gale.onComponentStateChanged(({ name, updates, oldValues }) => {
    if (name === 'cart') {
        analytics.track('cart_updated', updates);
    }
});
```

---

## Pattern 15: Authentication Flow

Login form with validation, redirect, and flash data.

**Controller:**
```php
class LoginController extends Controller
{
    public function showLogin()
    {
        return gale()->view('auth.login', web: true);
    }

    public function login(Request $request)
    {
        $credentials = $request->validateState([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->state('remember', false))) {
            $request->session()->regenerate();
            return gale()->redirect('/')->intended('/dashboard');
        }

        return gale()->messages([
            'email' => 'These credentials do not match our records.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return gale()->redirect('/login')->with('message', 'You have been logged out.');
    }
}
```

**Blade:**
```blade
<div x-data="{ email: '', password: '', remember: false }" x-sync>
    <form @submit.prevent="$action('/login')">
        <div>
            <input x-name="email" type="email" placeholder="Email">
            <p x-message="email" class="text-red-500 text-sm"></p>
        </div>
        <div>
            <input x-name="password" type="password" placeholder="Password">
            <p x-message="password" class="text-red-500 text-sm"></p>
        </div>
        <label>
            <input x-name="remember" type="checkbox"> Remember me
        </label>
        <button type="submit" :disabled="$fetching()">
            <span x-show="!$fetching()">Sign In</span>
            <span x-show="$fetching()">Signing in...</span>
        </button>
    </form>
</div>
```

---

## Pattern 16: Mode Selection Guide

### When to Use HTTP Mode (Default)

HTTP mode is the right choice for:
- **Standard CRUD operations** (create, read, update, delete)
- **Form submissions** and validation
- **SPA navigation** with fragments
- **Polling** with `x-interval`
- **File uploads**
- **Any action that completes quickly** (under 2-3 seconds)

**Advantages:**
- Works with all hosting environments (shared hosting, serverless, CDNs)
- No long-lived connections (better resource usage)
- Simpler debugging (standard request/response in DevTools)
- CDN/proxy-friendly
- No special server configuration needed

### When to Use SSE Mode (Opt-In)

SSE mode is the right choice for:
- **Long-running operations** (data processing, exports, AI generation)
- **Real-time progress updates** (progress bars, streaming output)
- **Server-pushed state updates** that arrive over time

**How to opt in:**

```html
<!-- Per-action -->
<button @click="$action('/process', { sse: true })">Process</button>
```

```javascript
// Global (rare -- only if your ENTIRE app needs SSE)
Alpine.gale.configure({ defaultMode: 'sse' });
```

```php
// Backend: stream() always forces SSE regardless of mode
return gale()->stream(function ($gale) {
    // Progressive updates sent immediately
});
```

### Mode Comparison Table

| Feature | HTTP Mode | SSE Mode |
|---------|-----------|----------|
| Default? | Yes | No (opt-in) |
| Transport | `fetch()` + JSON | EventSource streaming |
| Response format | `{ "events": [...] }` | `text/event-stream` |
| Progressive updates | No (all events arrive at once) | Yes (events arrive as sent) |
| Proxy/CDN compatible | Yes | May require configuration |
| Serverless compatible | Yes | No (needs long-lived connections) |
| Resource usage | Low (request/response) | Higher (open connection) |
| Retry behavior | Automatic with backoff | Configurable per-action |
| File uploads | Standard FormData | Standard FormData |

### Mixed Mode in the Same App

You can use both modes in the same application:

```html
<!-- HTTP mode for quick actions (default) -->
<button @click="$action('/save')">Save</button>

<!-- SSE mode for streaming operations -->
<button @click="$action('/export', { sse: true })">Export (SSE)</button>

<!-- Backend stream() forces SSE regardless -->
<button @click="$action('/generate')">Generate Report</button>
<!-- ^ If /generate uses gale()->stream(), the response is SSE even without { sse: true } -->
```

The frontend auto-detects the response content type, so a `stream()` response works even when the frontend didn't explicitly request SSE mode.
