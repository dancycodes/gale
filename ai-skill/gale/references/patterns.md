# Gale Patterns Reference

Complete working examples of common Gale patterns. Read this file for implementation guidance.

## Table of Contents
- [Pattern 1: Basic Counter](#pattern-1-basic-counter)
- [Pattern 2: CRUD with Fragments](#pattern-2-crud-with-fragments)
- [Pattern 3: Live Search with Navigation](#pattern-3-live-search-with-navigation)
- [Pattern 4: Multi-Panel with Navigate Keys](#pattern-4-multi-panel-with-navigate-keys)
- [Pattern 5: Chat with Streaming](#pattern-5-chat-with-streaming)
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

---

## Pattern 1: Basic Counter

The simplest Gale pattern. Demonstrates the full request/response cycle.

**routes/web.php:**
```php
Route::get('/counter', fn() => view('counter'));
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

    public function edit(Task $task)
    {
        return gale()->view('tasks.partials.form', compact('task'));
    }

    public function create()
    {
        return gale()->view('tasks.partials.form');
    }

    private function updateStats($tasks = null)
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

---

## Pattern 3: Live Search with Navigation

Search with debounced input using $navigate for URL sync.

**Controller:**
```php
public function index(Request $request)
{
    $search = $request->search ?? '';
    $category = $request->category ?? '';
    $categories = Category::pluck('name');
    $notes = Note::search($search)->category($category)->paginate(12);

    $data = compact('notes', 'search', 'category', 'categories');

    if ($request->isGaleNavigate('filter')) {
        return gale()->fragment('notes.index', 'notes.list', $data);
    }

    return gale()->view('notes.index', $data, web: true);
}
```

**Blade:**
```blade
@fragment('content')
<div id="content"
     x-data="{ search: '{{ $search ?? '' }}', category: '{{ $category ?? '' }}' }"
     x-sync="['search', 'category']">

    {{-- Search input with debounced navigation --}}
    <input type="text" x-model="search"
           @input.debounce.300ms="$navigate('{{ route('notes') }}?search=' + search + '&category=' + category, {
               key: 'filter', merge: true, replace: true, except: ['page']
           })">

    {{-- Category filter --}}
    <select x-model="category"
            @change="$navigate('{{ route('notes') }}?category=' + category + '&search=' + search, {
                key: 'filter', merge: true, replace: true, except: ['page']
            })">
        <option value="">All</option>
        @foreach($categories as $cat)
            <option value="{{ $cat }}">{{ $cat }}</option>
        @endforeach
    </select>

    {{-- Results (fragment for partial update) --}}
    @fragment('notes.list')
    <div id="notes-list">
        @foreach($notes as $note)
            <div id="note-{{ $note->id }}">{{ $note->title }}</div>
        @endforeach
        {{ $notes->links() }}
    </div>
    @endfragment
</div>
@endfragment
```

**Server-triggered navigation (from controller):**
```php
// Basic server-initiated navigate
gale()->navigate('/notes?search=test', 'filter');

// Merge with current URL params (preserves existing query params)
gale()->navigateMerge('/notes?page=2', 'filter');

// Clean navigate (no merging, replaces all params)
gale()->navigateClean('/notes?search=new', 'filter');

// Replace history entry (replaceState instead of pushState)
gale()->navigateReplace('/notes?search=test', 'filter');

// Merge but exclude specific params
gale()->navigateExcept('/notes?search=test', ['page'], 'filter');

// Merge but keep only specific params
gale()->navigateOnly('/notes?search=test', ['search', 'category'], 'filter');
```

---

## Pattern 4: Multi-Panel with Navigate Keys

Category sidebar + product grid. One navigate key updates multiple fragments.

**Controller:**
```php
public function index(Request $request)
{
    $categories = Category::roots()->with('children')->get();
    $products = Product::latest()->paginate(12);
    $data = compact('categories', 'products');

    if ($request->isGaleNavigate('content')) {
        return gale()
            ->fragment('catalog.index', 'sidebar', $data)
            ->fragment('catalog.index', 'products', $data);
    }

    return gale()->view('catalog.index', $data, web: true);
}

public function category(Request $request, Category $category)
{
    $categories = Category::roots()->with('children')->get();
    $products = $category->products()->paginate(12);
    $data = compact('categories', 'products', 'category');

    if ($request->isGaleNavigate('content')) {
        return gale()
            ->fragment('catalog.index', 'sidebar', $data)
            ->fragment('catalog.index', 'products', $data);
    }

    return gale()->view('catalog.index', $data, web: true);
}
```

**Blade:**
```blade
<div x-data x-navigate>
    @fragment('sidebar')
    <aside id="sidebar">
        @foreach($categories as $cat)
            <a href="{{ route('catalog.category', $cat) }}"
               x-navigate.key.content
               class="{{ ($category ?? null)?->id === $cat->id ? 'font-bold' : '' }}">
                {{ $cat->name }}
            </a>
        @endforeach
    </aside>
    @endfragment

    @fragment('products')
    <main id="main-content">
        @foreach($products as $product)
            <div id="product-{{ $product->id }}">{{ $product->name }}</div>
        @endforeach
        {{ $products->links() }}
    </main>
    @endfragment
</div>
```

---

## Pattern 5: Chat with Streaming

AI chat with streaming responses using gale()->stream().

**Controller:**
```php
public function send(Request $request)
{
    $state = $request->validateState([
        'message' => 'required|string|max:2000',
    ]);

    $userMessage = Message::create([
        'user_id' => auth()->id(),
        'role' => 'user',
        'content' => $state['message'],
    ]);

    $userHtml = view('chat.partials.message', ['message' => $userMessage])->render();

    return gale()
        ->state('message', '')  // Clear input
        ->append('#messages', $userHtml, ['scroll' => 'bottom'])
        ->stream(function ($gale) use ($state) {
            $response = '';
            $gale->append('#messages', '<div id="assistant-typing" class="typing">...</div>', ['scroll' => 'bottom']);

            // Simulate streaming (replace with actual AI API)
            foreach (str_split("Here's my response to: " . $state['message']) as $token) {
                $response .= $token;
                $gale->outerMorph('#assistant-typing',
                    '<div id="assistant-typing">' . e($response) . '</div>');
                usleep(50000);
            }

            $botMessage = Message::create([
                'user_id' => auth()->id(),
                'role' => 'assistant',
                'content' => $response,
            ]);

            $finalHtml = view('chat.partials.message', ['message' => $botMessage])->render();
            $gale->outer('#assistant-typing', $finalHtml);
        });
}
```

**Blade:**
```blade
<div x-data="{ message: '' }">
    <div id="messages" class="overflow-y-auto h-96">
        @foreach($messages as $msg)
            @include('chat.partials.message', ['message' => $msg])
        @endforeach
    </div>

    <form @submit.prevent="$action('{{ route('chat.send') }}', { include: ['message'] })">
        <textarea x-name="message" :disabled="$gale.loading"></textarea>
        <button type="submit" :disabled="$gale.loading || !message.trim()">
            <span x-show="!$fetching()">Send</span>
            <span x-loading.delay.200ms>Streaming...</span>
        </button>
    </form>
</div>
```

---

## Pattern 6: Dashboard with Polling

Multiple widgets updated via componentState from a single polling endpoint.

**Controller:**
```php
public function stats(Request $request)
{
    $stats = $this->generateStats();
    $newActivity = $this->generateActivity();

    return gale()
        ->componentState('stat-users', [
            'value' => $stats['users']['value'],
            'change' => $stats['users']['change'],
        ])
        ->componentState('stat-orders', [
            'value' => $stats['orders']['value'],
            'change' => $stats['orders']['change'],
        ])
        ->componentState('stat-revenue', [
            'value' => $stats['revenue']['value'],
            'change' => $stats['revenue']['change'],
        ])
        ->componentMethod('activity-feed', 'addActivity', [$newActivity]);
}
```

**Blade:**
```blade
<div x-data="{
    paused: false,
    async refreshStats() {
        await $action('{{ route('dashboard.stats') }}');
    }
}"
     x-interval.5s.visible="if (!paused) refreshStats()">

    {{-- Stat cards as named components --}}
    <div x-data="{ value: @js($initialStats['users']['value']), change: 0 }" x-component="stat-users">
        <h3>Users</h3>
        <span x-text="value"></span>
        <span x-text="change + '%'"></span>
    </div>

    <div x-data="{ value: @js($initialStats['orders']['value']), change: 0 }" x-component="stat-orders">
        <h3>Orders</h3>
        <span x-text="value"></span>
    </div>

    {{-- Activity feed --}}
    <div x-data="{
        activities: @js($initialActivities),
        addActivity(item) { this.activities.unshift(item); if (this.activities.length > 10) this.activities.pop(); }
    }" x-component="activity-feed">
        <template x-for="act in activities" :key="act.id">
            <div x-text="act.text"></div>
        </template>
    </div>

    <button @click="paused = !paused" x-text="paused ? 'Resume' : 'Pause'"></button>
</div>
```

---

## Pattern 7: Kanban Board

Drag-drop with x-sort, all DOM manipulation modes demonstrated.

**Controller (key methods):**
```php
// Create card → fragment with selector
public function store(Request $request)
{
    $data = $request->validateState([
        'title' => 'required|string|max:255',
        'status' => 'required|in:todo,in_progress,done',
    ]);

    $card = Card::create([...$data, 'user_id' => auth()->id(), 'position' => Card::nextPosition(auth()->id(), $data['status'])]);
    $cards = Card::where('user_id', auth()->id())->where('status', $data['status'])->ordered()->get();

    return gale()
        ->fragment('board.partials.cards-list', 'cards.list', ['cards' => $cards, 'status' => $data['status']], [
            'selector' => "#cards-{$data['status']}", 'mode' => 'outer', 'settle' => 100,
        ])
        ->state('title', '')
        ->dispatch('toast', ['type' => 'success', 'message' => 'Card created!']);
}

// Update card → outerMorph preserves focus
public function update(Request $request, Card $card)
{
    $data = $request->validateState(['title' => 'required|string|max:255']);
    $card->update($data);
    $cardHtml = view('board.partials.card', ['card' => $card->fresh()])->render();

    return gale()
        ->outerMorph("#card-{$card->id}", $cardHtml)
        ->dispatch('board:edit-done')
        ->dispatch('toast', ['type' => 'success', 'message' => 'Updated!']);
}

// Delete card → remove or fragment for empty state
public function destroy(Request $request, Card $card)
{
    $status = $card->status;
    $card->delete();
    $cards = Card::where('user_id', auth()->id())->where('status', $status)->ordered()->get();

    return gale()
        ->when($cards->count() === 0, function ($gale) use ($status, $cards) {
            $gale->fragment('board.partials.cards-list', 'cards.list', ['cards' => $cards, 'status' => $status], [
                'selector' => "#cards-{$status}", 'mode' => 'outer',
            ]);
        }, function ($gale) use ($card) {
            $gale->remove("#card-{$card->id}", ['settle' => 200]);
        })
        ->dispatch('toast', ['type' => 'success', 'message' => 'Deleted!']);
}
```

**Blade (cards list with x-sort):**
```blade
@fragment('cards.list')
<div id="cards-{{ $status }}"
     x-sort="(itemId, position) => handleSort(itemId, position, '{{ $status }}')"
     x-sort:group="cards"
     x-sort:config="{ animation: 150 }">
    @forelse($cards as $card)
        <div id="card-{{ $card->id }}" x-sort:item="{{ $card->id }}">
            {{ $card->title }}
        </div>
    @empty
        <div class="empty-state">Drop cards here</div>
    @endforelse
</div>
@endfragment
```

**Additional DOM operations (before, after, inner, useViewTransition):**
```php
// Insert as siblings
->before('#card-5', $newCardHtml)              // Insert before element
->after('#card-5', $newCardHtml)               // Insert after element

// Inner replaces children only, outer replaces entire element
->inner('#cards-todo', $cardsHtml)             // Replace children, keep container
->outer('#cards-todo', $cardsHtml)             // Replace entire element

// View Transitions API for smooth CSS transitions
->outer('#card-5', $html, ['useViewTransition' => true])
->before('#card-5', $html, ['useViewTransition' => true])
->append('#cards-todo', $html, ['useViewTransition' => true])
```

---

## Pattern 8: File Upload Gallery

Upload with progress, preview, drag-reorder, bulk delete.

**Controller:**
```php
public function store(Request $request)
{
    $request->validate(['images.*' => 'required|image|max:5120']);

    $gale = gale();
    foreach ($request->file('images') as $file) {
        $image = Image::create([
            'user_id' => auth()->id(),
            'filename' => $file->getClientOriginalName(),
            'path' => $file->store('gallery', 'public'),
        ]);
        $html = view('gallery.partials.image-card', compact('image'))->render();
        $gale->append('#gallery-grid', $html, ['show' => 'center']);
    }

    return $gale
        ->state('imageCount', auth()->user()->images()->count())
        ->js("document.querySelector('[x-files]')?.__x_files?.clear()")
        ->dispatch('toast', ['type' => 'success', 'message' => 'Uploaded!']);
}
```

**Blade (upload zone):**
```blade
<div x-data>
    <input type="file" name="images" x-files multiple accept="image/*" class="hidden" id="file-input">
    <label for="file-input">Choose Files</label>

    <template x-for="(file, i) in $files('images')" :key="i">
        <div>
            <img :src="$filePreview('images', i)">
            <span x-text="file.name"></span>
            <span x-text="$formatBytes(file.size)"></span>
        </div>
    </template>

    <div x-show="$uploading">
        <div :style="'width: ' + $uploadProgress + '%'" class="bg-blue-600 h-2"></div>
    </div>

    <button @click="$action('{{ route('gallery.store') }}', {
        onProgress: (p) => {}
    })" :disabled="$files('images').length === 0">Upload</button>
</div>
```

---

## Pattern 9: Bulk Operations Table

Selective state, includeComponents, bulk actions.

**Controller:**
```php
public function bulkDelete(Request $request)
{
    $ids = $request->state('selectedIds', []);
    Record::whereIn('id', $ids)->where('user_id', auth()->id())->delete();
    $stats = $this->getStats();

    $gale = gale();
    foreach ($ids as $id) {
        $gale->remove("#record-{$id}");
    }

    return $gale
        ->state('selectedIds', [])
        ->state('selectAll', false)
        ->fragment('table.index', 'records-stats', compact('stats'))
        ->dispatch('toast', ['type' => 'success', 'message' => count($ids) . ' records deleted!']);
}
```

**Blade:**
```blade
<button @click="$action.delete('{{ route('table.bulk-delete') }}', {
    include: ['selectedIds'],
    includeFormFields: false
})" x-confirm="'Delete ' + selectedIds.length + ' record(s)?'"
    :disabled="!hasSelection">
    Delete Selected
</button>

{{-- Export with cross-component data --}}
<div x-component="export-panel" data-tags="export,panel"
     x-data="{ format: 'csv', selectedColumns: ['title', 'status'] }">
    <select x-model="format">
        <option value="csv">CSV</option>
        <option value="json">JSON</option>
    </select>
    <button @click="$action.post('/table/export', {
        includeComponents: ['export-panel'],
        include: ['selectedIds']
    })">Export</button>
</div>

{{-- PUT = full replacement (all fields required) --}}
<button @click="$action.put('/records/' + id, { include: ['title', 'description', 'status'] })">
    Save All (PUT)
</button>

{{-- PATCH = partial update (only changed fields) --}}
<button @click="$action.patch('/records/' + id, { include: ['status'] })">
    Update Status (PATCH)
</button>
```

**Export endpoint (reading component state):**
```php
public function export(Request $request): mixed
{
    // Component state arrives nested under _components.{name}
    $panel = $request->state('_components.export-panel', []);
    $format = $panel['format'] ?? 'csv';
    $columns = $panel['selectedColumns'] ?? ['title', 'status'];
    $ids = $request->state('selectedIds', []);

    $records = Record::whereIn('id', $ids)->get();

    return gale()
        ->state('exportResult', ['format' => $format, 'count' => $records->count()])
        ->dispatch('toast', ['type' => 'success', 'message' => "Exported as {$format}"]);
}
```

**Raw HTML injection:**
```php
// Inject arbitrary HTML with selector and mode
gale()->html($html, ['selector' => '#target', 'mode' => 'inner']);
```

**Viewport scroll options (available on append, prepend, outer, etc.):**
```php
->append('#list', $html, ['scroll' => 'bottom'])    // Scroll container to bottom
->prepend('#list', $html, ['scroll' => 'top'])       // Scroll container to top
->append('#list', $html, ['show' => 'center'])        // Scroll new element into viewport center
->append('#list', $html, ['focusScroll' => true])     // Maintain current scroll position
```

**Multiple fragments (array syntax):**
```php
gale()->fragments([
    ['view' => 'table.index', 'fragment' => 'records-list', 'data' => compact('records')],
    ['view' => 'table.index', 'fragment' => 'records-stats', 'data' => compact('stats')],
]);
```

---

## Pattern 10: Form Validation

Server-side validation with real-time error display.

**Controller:**
```php
public function store(Request $request)
{
    $data = $request->validateState([
        'name' => 'required|min:2|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8',
    ], [
        'name.required' => 'Please enter your name.',
        'email.unique' => 'This email is already registered.',
    ]);

    User::create($data);
    return gale()->messages(['_success' => 'Account created!']);
}
```

**Blade:**
```blade
<form @submit.prevent="$action('{{ route('register') }}')" x-data x-sync>
    <input x-name="name" type="text" placeholder="Name">
    <p x-message="name" class="text-red-600 text-sm"></p>

    <input x-name="email" type="email" placeholder="Email">
    <p x-message="email" class="text-red-600 text-sm"></p>

    <input x-name="password" type="password" placeholder="Password">
    <p x-message="password" class="text-red-600 text-sm"></p>

    <p x-message="_success" class="text-green-600"></p>

    <button type="submit" :disabled="$fetching()">
        <span x-show="!$fetching()">Register</span>
        <span x-show="$fetching()">Creating...</span>
    </button>
</form>
```

**x-name Modifiers:**
```blade
{{-- .trim — auto-trims whitespace --}}
<input x-name.trim="name" type="text" placeholder="Name">

{{-- .lazy — updates on blur, not every keystroke --}}
<textarea x-name.lazy="bio" placeholder="Bio"></textarea>

{{-- .number — coerces value to number type --}}
<input x-name.number="age" type="number" placeholder="Age">

{{-- .array — collects checked checkbox values into array --}}
<input type="checkbox" value="email" x-name.array="notifications"> Email
<input type="checkbox" value="push" x-name.array="notifications"> Push
<input type="checkbox" value="sms" x-name.array="notifications"> SMS
{{-- Result: { notifications: ['email', 'sms'] } when email+sms checked --}}
```

```php
// Server-side — modifiers apply before state arrives
$data = $request->validateState([
    'name' => 'required|string|max:255',       // Already trimmed by .trim
    'bio' => 'nullable|string|max:500',         // Updated on blur by .lazy
    'age' => 'nullable|integer|min:13|max:120', // Already number by .number
    'notifications' => 'nullable|array',        // Array from .array
    'notifications.*' => 'string|in:email,push,sms',
]);
```

**x-name Modifier Reference:**

| Modifier | Example | Behavior |
|----------|---------|----------|
| (none) | `x-name="email"` | Two-way bind, updates on input |
| `.trim` | `x-name.trim="name"` | Trims whitespace |
| `.lazy` | `x-name.lazy="bio"` | Updates on blur instead of input |
| `.number` | `x-name.number="age"` | Coerces to number type |
| `.array` | `x-name.array="tags"` | Collects checkbox values to array |
| `.fill` | `x-name.fill="title"` | Populate only if state has value |

**Manual message control:**
```php
// Set specific messages without validation
gale()->messages(['email' => 'Already taken', '_info' => 'Check your inbox']);

// Clear all messages
gale()->clearMessages();
```

**Scoped state with include/exclude:**
```blade
{{-- Only send profile fields, not other Alpine state --}}
<form @submit.prevent="$action.patch('/settings/profile', {
    include: ['name', 'email', 'bio', 'age']
})">

{{-- Send everything EXCEPT certain keys --}}
<form @submit.prevent="$action.patch('/settings/profile', {
    exclude: ['tempData', 'uiState']
})">
```

---

## Pattern 11: Redirect Patterns

```php
// Simple redirect with flash
return gale()->redirect('/dashboard')->with('message', 'Welcome!');

// Back with fallback
return gale()->redirect('/')->back('/home');

// Back with named route fallback
return gale()->redirect('/')->backOr('dashboard');

// Named route redirect
return gale()->redirect('/')->route('profile.show', ['id' => $user->id]);

// Redirect with errors (failed validation)
return gale()->redirect('/')->back()->withErrors($validator)->withInput();

// Refresh current page
return gale()->redirect('/')->refresh();

// Force reload (bypass cache)
return gale()->redirect('/')->forceReload(true);

// Simple reload
return gale()->reload();
```

---

## Pattern 12: Conditional Responses

```php
// Different responses for Gale vs regular requests
return gale()
    ->whenGale(function ($gale) use ($data) {
        $gale->state('items', $data);
    }, function ($gale) use ($data) {
        $gale->web(view('items.index', compact('data')));
    });

// Navigate-aware responses
return gale()
    ->whenGaleNavigate('content', function ($gale) use ($data) {
        $gale->fragment('page', 'sidebar', $data)
             ->fragment('page', 'content', $data);
    })
    ->view('page', $data, web: true);

// Conditional DOM operations
return gale()
    ->when($items->count() === 0, function ($gale) {
        $gale->fragment('items.index', 'items.list', ['items' => collect()]);
    }, function ($gale) use ($newItem) {
        $gale->append('#items-list', view('items.partials.item', ['item' => $newItem])->render());
    });

// unless() = inverse of when()
return gale()
    ->unless($user->isAdmin(), function ($gale) {
        $gale->dispatch('toast', ['type' => 'warning', 'message' => 'Read-only mode']);
    })
    ->when($user->isAdmin(), function ($gale) use ($data) {
        $gale->state('canEdit', true);
    });
```

---

## Pattern 13: Error Handling and Retry

Resilient request handling with global error state, automatic retry, and request cancellation.

**Controller:**
```php
class ApiController extends Controller
{
    public function triggerError(int $code): mixed
    {
        return response()->json(['error' => true, 'message' => "HTTP {$code}"], $code);
    }

    public function flaky(Request $request): mixed
    {
        $attempt = Cache::increment('flaky_' . auth()->id());
        Cache::put('flaky_' . auth()->id(), $attempt, now()->addMinutes(5));
        $failUntil = (int) $request->state('failUntil', 3);

        if ($attempt <= $failUntil) {
            return response()->json(['error' => true, 'message' => "Attempt {$attempt} failed"], 500);
        }

        Cache::forget('flaky_' . auth()->id());

        return gale()
            ->state('result', "Succeeded on attempt {$attempt}!")
            ->dispatch('toast', ['type' => 'success', 'message' => 'Done!']);
    }

    public function slow(Request $request): mixed
    {
        sleep((int) $request->state('delay', 3));

        return gale()->state('slowResult', 'Completed after delay');
    }
}
```

**Blade:**
```blade
<div x-data="{ failUntil: 3, delay: 3, result: '', slowResult: '' }">

    {{-- Error banner — $gale.error is true when lastError exists --}}
    <div x-show="$gale.error" x-transition class="bg-red-50 border border-red-200 p-4 rounded">
        <strong x-text="'Error ' + $gale.lastError?.status"></strong>
        <p x-text="$gale.lastError?.message"></p>
        <button @click="$gale.clearErrors()">Dismiss</button>
    </div>

    {{-- Retry banner — visible during automatic exponential backoff --}}
    <div x-show="$gale.retrying && !$gale.retriesFailed" class="bg-yellow-50 p-3">
        Reconnecting...
    </div>
    <div x-show="$gale.retriesFailed" class="bg-red-100 p-3">
        Connection failed after max retries.
    </div>

    {{-- Status indicator — $gale.loading + $gale.activeCount --}}
    <div class="flex items-center gap-2">
        <span :class="$gale.loading ? 'bg-yellow-500' : 'bg-green-500'" class="w-3 h-3 rounded-full"></span>
        <span x-show="$gale.loading">
            Processing (<span x-text="$gale.activeCount"></span> active)...
        </span>
    </div>

    {{-- Trigger errors — $fetching() is per-element --}}
    <button @click="$action.post('/api/error/404')" :disabled="$fetching()">Trigger 404</button>
    <button @click="$action.post('/api/error/500')" :disabled="$fetching()">Trigger 500</button>

    {{-- Retry with custom config --}}
    <button @click="$action.post('/api/flaky', {
        retryInterval: 500,
        retryScaler: 1.5,
        retryMaxCount: 5,
        retryMaxWaitMs: 10000,
        include: ['failUntil']
    })" :disabled="$fetching()">
        <span x-show="!$fetching()">Test Flaky Endpoint</span>
        <span x-show="$fetching()">Retrying...</span>
    </button>
    <p x-text="result"></p>

    {{-- Cancellation: 'auto' (default) cancels previous request from same element --}}
    <button @click="$action.post('/api/slow', { include: ['delay'] })">
        <span x-loading.delay.150ms>Loading...</span>
        Slow (auto cancel)
    </button>

    {{-- Cancellation: 'disabled' allows concurrent requests --}}
    <button @click="$action.post('/api/slow', {
        requestCancellation: 'disabled',
        include: ['delay']
    })">Slow (concurrent OK)</button>

    {{-- Error history — last 10 errors --}}
    <template x-for="(err, i) in $gale.errors" :key="err.timestamp + '-' + i">
        <div><span x-text="err.status"></span>: <span x-text="err.message"></span></div>
    </template>
    <button @click="$gale.clearErrors()" :disabled="!$gale.errors?.length">Clear All</button>
</div>
```

**Loading State Reference:**

| Indicator | Scope | Usage |
|-----------|-------|-------|
| `$gale.loading` | Global | True when ANY request in flight |
| `$gale.activeCount` | Global | Count of concurrent requests |
| `$gale.error` | Global | True if lastError exists |
| `$gale.lastError` | Global | `{ timestamp, status, message }` |
| `$gale.errors` | Global | Array of last 10 errors |
| `$gale.retrying` | Global | True during retry backoff |
| `$gale.retriesFailed` | Global | True when max retries exceeded |
| `$gale.clearErrors()` | Global | Clears lastError, errors, retriesFailed |
| `$fetching()` | Per-element | True for THIS element's request only |
| `x-loading` | Directive | Show/hide element during request |
| `x-loading.delay.Nms` | Directive | Delayed show (prevents flash for fast requests) |

**Retry Options (passed to $action):**

| Option | Default | Description |
|--------|---------|-------------|
| `retryInterval` | 1000 | Initial retry delay in ms |
| `retryScaler` | 2 | Multiplier for exponential backoff |
| `retryMaxWaitMs` | 30000 | Maximum retry interval cap |
| `retryMaxCount` | 10 | Max retry attempts before giving up |
| `requestCancellation` | `'auto'` | `'auto'` = cancel previous from same element, `'disabled'` = allow concurrent |

---

## Pattern 14: Configuration and Lifecycle

Customize Gale's CSRF, message display, confirm dialogs, navigation, and track component lifecycle.

**Controller:**
```php
class ConfigController extends Controller
{
    public function index(): mixed
    {
        return gale()->view('config.index', [], web: true);
    }

    // Echo custom headers back
    public function headers(Request $request): mixed
    {
        $custom = collect($request->headers->all())
            ->filter(fn($v, $k) => str_starts_with($k, 'x-custom'))
            ->map(fn($v) => $v[0] ?? '')
            ->toArray();

        return gale()->state('receivedHeaders', $custom);
    }

    // Update a named component
    public function updateComponent(string $name): mixed
    {
        return gale()->componentState($name, [
            'serverValue' => 'Updated at ' . now()->format('H:i:s'),
        ]);
    }
}
```

**Blade:**
```blade
<div x-data="{
    receivedHeaders: {},
    logs: [],
    confirmOpen: false,
    confirmMessage: '',
    confirmResolve: null,

    init() {
        // Custom confirm modal (replaces browser confirm())
        Alpine.gale.configureConfirm((message) => {
            return new Promise((resolve) => {
                this.confirmMessage = message;
                this.confirmResolve = resolve;
                this.confirmOpen = true;
            });
        });

        // Lifecycle hooks — return cleanup functions
        this._cleanup1 = Alpine.gale.onComponentRegistered((detail) => {
            this.logs.unshift({ event: 'registered', name: detail.name, tags: detail.tags });
        });
        this._cleanup2 = Alpine.gale.onComponentStateChanged((detail) => {
            this.logs.unshift({ event: 'stateChanged', name: detail.name });
        });
    },

    resolveConfirm(result) {
        if (this.confirmResolve) this.confirmResolve(result);
        this.confirmOpen = false;
    },

    destroy() {
        this._cleanup1?.();
        this._cleanup2?.();
    }
}">

    {{-- Read current config --}}
    <div x-data="{ csrf: Alpine.gale.getCsrfConfig() }">
        CSRF header: <span x-text="csrf.headerName"></span>
    </div>

    {{-- Custom headers --}}
    <button @click="$action.post('/config/headers', {
        headers: { 'X-Custom-Feature': 'dark-mode', 'X-Custom-Version': '2.0' }
    })">Send Custom Headers</button>
    <template x-for="(value, key) in receivedHeaders" :key="key">
        <div><span x-text="key"></span>: <span x-text="value"></span></div>
    </template>

    {{-- Tagged components — use data-tags attribute --}}
    <div x-component="stat-card" data-tags="card,metric"
         x-data="{ value: 42, serverValue: null }">
        Stat: <span x-text="serverValue ?? value"></span>
    </div>
    <div x-component="filter-bar" data-tags="card,filter"
         x-data="{ query: '', serverValue: null }">
        Filter: <input x-model="query">
    </div>

    {{-- Query by tag --}}
    <button @click="
        const cards = $components.getByTag('card');
        alert('Found ' + cards.length + ' components tagged card');
    ">Find 'card' tagged</button>

    {{-- Server update (triggers onComponentStateChanged) --}}
    <button @click="$action.post('/config/update-component/stat-card')">
        Update stat-card from server
    </button>

    {{-- Lifecycle log --}}
    <template x-for="log in logs" :key="log.event + log.name">
        <div x-text="log.event + ': ' + log.name"></div>
    </template>

    {{-- Action with custom confirm modal --}}
    <button @click="$action.delete('/config/dangerous')"
            x-confirm="'This action is irreversible. Continue?'">
        Dangerous Action
    </button>

    {{-- Custom confirm modal --}}
    <div x-show="confirmOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="bg-black/50 fixed inset-0" @click="resolveConfirm(false)"></div>
        <div class="relative bg-white rounded-lg p-6 max-w-md">
            <p x-text="confirmMessage"></p>
            <div class="flex justify-end gap-3 mt-4">
                <button @click="resolveConfirm(false)">Cancel</button>
                <button @click="resolveConfirm(true)" class="bg-red-600 text-white px-4 py-2 rounded">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>
```

**Configuration APIs:**

| Method | Purpose |
|--------|---------|
| `Alpine.gale.configureCsrf({...})` | Set CSRF token source (headerName, metaTagName, cookieName, priority, customTokenGetter) |
| `Alpine.gale.configureMessage({...})` | Customize message display behavior |
| `Alpine.gale.configureConfirm(handler)` | Replace browser confirm() with custom modal (return Promise) |
| `Alpine.gale.configureNavigation({...})` | Customize navigation behavior |
| `Alpine.gale.getCsrfConfig()` | Read current CSRF config |
| `Alpine.gale.getMessageConfig()` | Read current message config |
| `Alpine.gale.getConfirmConfig()` | Read current confirm config |
| `Alpine.gale.getNavigationConfig()` | Read current navigation config |

**Lifecycle Hooks:**

| Hook | Callback receives | Cleanup |
|------|-------------------|---------|
| `onComponentRegistered(cb)` | `{ name, tags, el }` | Returns cleanup function |
| `onComponentUnregistered(cb)` | `{ name, el }` | Returns cleanup function |
| `onComponentStateChanged(cb)` | `{ name, updates }` | Returns cleanup function |

**Component Tagging:**

```html
<!-- Tag via data-tags attribute (comma-separated) -->
<div x-component="widget" data-tags="dashboard,interactive" x-data="{ ... }">

<!-- Query by tag -->
<script>
const dashboardComponents = $components.getByTag('dashboard');
const all = Alpine.gale.getAllComponents();
</script>
```

---

## Pattern 15: Authentication Flow

Login/register with validation, redirect + flash, error dispatch, and cross-component state.

**Controller:**
```php
class AuthController extends Controller
{
    public function signIn(): mixed
    {
        return gale()->view('auth.login', web: true);
    }

    public function login(Request $request): mixed
    {
        $credentials = $request->validateState([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (auth()->attempt($credentials, $request->state('remember', false))) {
            $request->session()->regenerate();

            // Redirect with flash data (toast reads from session)
            return gale()->redirect(session('url.intended', route('home')))
                ->with('toast', ['type' => 'success', 'message' => 'Welcome back!']);
        }

        // Dispatch error event — stays on page, no redirect
        return gale()->dispatch('toast', [
            'type' => 'error',
            'message' => 'Invalid credentials.',
        ]);
    }

    public function register(Request $request): mixed
    {
        $data = $request->validateState([
            'name' => 'required|string|max:255|min:3',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create($data);
        auth()->login($user);

        return gale()->redirect(route('home'))
            ->with('toast', ['type' => 'success', 'message' => 'Registration successful!']);
    }

    public function logout(Request $request): mixed
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return gale()->redirect(route('login'))
            ->with('toast', ['type' => 'success', 'message' => 'Logged out.']);
    }
}
```

**Login Blade:**
```blade
<form x-data @submit.prevent="$action('{{ route('auth.login') }}')">
    <input x-name="email" type="email" placeholder="Email">
    <p x-message="email" class="text-red-600 text-sm"></p>

    <input x-name="password" type="password" placeholder="Password">
    <p x-message="password" class="text-red-600 text-sm"></p>

    <label><input type="checkbox" x-name="remember"> Remember me</label>

    <button type="submit" :disabled="$fetching()">
        <span x-show="!$fetching()">Sign In</span>
        <span x-show="$fetching()">Signing in...</span>
    </button>

    {{-- SPA navigation to register --}}
    <div x-navigate>
        <a href="{{ route('auth.register') }}">Create an account</a>
    </div>
</form>
```

**Register Blade (cross-component password visibility):**
```blade
<form x-data @submit.prevent="$action('{{ route('auth.register') }}')">
    <input x-name="name" type="text" placeholder="Name">
    <p x-message="name" class="text-red-600 text-sm"></p>

    <input x-name="email" type="email" placeholder="Email">
    <p x-message="email" class="text-red-600 text-sm"></p>

    {{-- Password with x-component for cross-component show/hide --}}
    <div x-data="{ show: false }" x-component="password-field">
        <input :type="show ? 'text' : 'password'" x-name="password" placeholder="Password">
        <button type="button" @click="show = !show" x-text="show ? 'Hide' : 'Show'"></button>
        <p x-message="password" class="text-red-600 text-sm"></p>
    </div>

    {{-- Confirm reads show state from password-field component --}}
    <input :type="$components.state('password-field', 'show') ? 'text' : 'password'"
           x-name="password_confirmation" placeholder="Confirm password">
    <p x-message="password_confirmation" class="text-red-600 text-sm"></p>

    <button type="submit" :disabled="$fetching()">Create Account</button>
</form>
```

**Toast Notifier (reads flash data from session):**
```blade
{{-- In layout — reads gale()->redirect()->with('toast', ...) --}}
<div x-data="{ toasts: [] }"
     x-init="@js(session('toast') ? [session('toast')] : []).forEach(t => addToast(t))"
     @toast.window="addToast($event.detail)">
    <template x-for="toast in toasts" :key="toast.id">
        <div :class="toast.type === 'error' ? 'bg-red-500' : 'bg-green-500'"
             x-text="toast.message" x-show="toast.visible" x-transition></div>
    </template>
</div>
```
