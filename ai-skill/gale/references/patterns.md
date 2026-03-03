# Patterns — Complete Working Examples

Copy-paste examples for every common Gale scenario. Each shows both backend (PHP) and frontend (Blade/HTML).

---

## Table of Contents

- [Counter (Minimal)](#counter-minimal)
- [CRUD — Create Form](#crud--create-form)
- [CRUD — Edit Form](#crud--edit-form)
- [CRUD — List with Delete](#crud--list-with-delete)
- [Search with Instant Results](#search-with-instant-results)
- [File Upload with Preview](#file-upload-with-preview)
- [SPA Navigation with Sidebar](#spa-navigation-with-sidebar)
- [Fragment-Based Updates](#fragment-based-updates)
- [Polling Dashboard](#polling-dashboard)
- [Streaming Progress](#streaming-progress)
- [Multi-Component Communication](#multi-component-communication)
- [Toast Notifications](#toast-notifications)
- [Inline Editing](#inline-editing)
- [Infinite Scroll](#infinite-scroll)
- [Multi-Step Wizard](#multi-step-wizard)

---

## Counter (Minimal)

```php
// CounterController.php
public function show(): mixed
{
    return gale()->view('counter', ['count' => 0], web: true);
}

public function increment(Request $request): GaleResponse
{
    $count = $request->state('count', 0) + 1;
    return gale()->patchState(['count' => $count]);
}
```

```html
<!-- counter.blade.php -->
<div x-data="{ count: {{ $count }} }" x-sync>
    <span x-text="count"></span>
    <button @click="$action('/counter/increment')">+1</button>
    <span x-show="$fetching()">...</span>
</div>
```

---

## CRUD — Create Form

```php
// ContactController.php
public function create(): mixed
{
    return gale()->view('contacts.create', web: true);
}

public function store(Request $request): GaleResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:contacts'],
        'phone' => ['nullable', 'string', 'max:20'],
    ]);

    $contact = Contact::create($validated);

    return gale()->redirect('/')->route('contacts.show', $contact)
        ->with('success', 'Contact created!');
}
```

```html
<!-- contacts/create.blade.php -->
<div x-data="{ name: '', email: '', phone: '', messages: {} }" x-sync>
    <form @submit.prevent="$action('/contacts')">
        <div>
            <label>Name</label>
            <input type="text" x-model="name" x-name="name">
            <p x-message="name" class="text-red-600 text-sm"></p>
        </div>

        <div>
            <label>Email</label>
            <input type="email" x-model="email" x-name="email">
            <p x-message="email" class="text-red-600 text-sm"></p>
        </div>

        <div>
            <label>Phone</label>
            <input type="tel" x-model="phone" x-name="phone">
            <p x-message="phone" class="text-red-600 text-sm"></p>
        </div>

        <button type="submit" :disabled="$fetching()">
            <span x-show="!$fetching()">Create Contact</span>
            <span x-show="$fetching()">Creating...</span>
        </button>
    </form>
</div>
```

---

## CRUD — Edit Form

```php
public function edit(Contact $contact): mixed
{
    return gale()->view('contacts.edit', compact('contact'), web: true);
}

public function update(Request $request, Contact $contact): GaleResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:contacts,email,' . $contact->id],
    ]);

    $contact->update($validated);

    return gale()
        ->patchState(['saved' => true])
        ->flash('success', 'Contact updated!');
}
```

```html
<!-- contacts/edit.blade.php -->
<div x-data="{
    name: '{{ $contact->name }}',
    email: '{{ $contact->email }}',
    saved: false,
    messages: {}
}" x-sync>
    <form @submit.prevent="$action('/contacts/{{ $contact->id }}')">
        <input type="text" x-model="name" x-name="name">
        <p x-message="name" class="text-red-600 text-sm"></p>

        <input type="email" x-model="email" x-name="email">
        <p x-message="email" class="text-red-600 text-sm"></p>

        <button type="submit" :disabled="$fetching()">
            <span x-show="!$fetching()">Update</span>
            <span x-show="$fetching()">Saving...</span>
        </button>

        <p x-show="saved" class="text-green-600">Saved!</p>
    </form>
</div>
```

---

## CRUD — List with Delete

```php
public function index(): mixed
{
    $contacts = Contact::latest()->get();
    return gale()->view('contacts.index', compact('contacts'), web: true);
}

public function destroy(Request $request, Contact $contact): GaleResponse
{
    $contact->delete();

    return gale()
        ->remove('#contact-' . $contact->id)
        ->patchState(['total' => Contact::count()]);
}
```

```html
<!-- contacts/index.blade.php -->
<div x-data="{ total: {{ $contacts->count() }} }">
    <p>Total: <span x-text="total"></span></p>

    <div id="contact-list">
        @foreach($contacts as $contact)
            <div id="contact-{{ $contact->id }}" class="flex items-center justify-between p-2">
                <span>{{ $contact->name }}</span>
                <button
                    @click="$action('/contacts/{{ $contact->id }}/delete', { confirm: 'Delete this contact?' })"
                    :disabled="$fetching()"
                >
                    Delete
                </button>
            </div>
        @endforeach
    </div>
</div>
```

---

## Search with Instant Results

```php
public function search(Request $request): GaleResponse
{
    $query = $request->state('query', '');

    $results = Product::query()
        ->when($query, fn($q) => $q->where('name', 'like', "%{$query}%"))
        ->limit(20)
        ->get();

    return gale()
        ->fragment('products.index', 'results', compact('results'))
        ->patchState(['count' => $results->count()]);
}
```

```html
<div x-data="{ query: '', count: 0, messages: {} }" x-sync="['query']">
    <input
        type="search"
        x-model="query"
        @input.debounce.300ms="$action('/products/search')"
        placeholder="Search products..."
    >
    <span x-show="$fetching()">Searching...</span>
    <p>Found <span x-text="count"></span> results</p>

    @fragment('results')
    <div id="results">
        @foreach($results as $result)
            <div>{{ $result->name }}</div>
        @endforeach
    </div>
    @endfragment
</div>
```

---

## File Upload with Preview

```php
public function upload(Request $request): GaleResponse
{
    $request->validate([
        'photos.*' => ['required', 'image', 'max:5120'],
    ]);

    $paths = [];
    foreach ($request->file('photos') as $file) {
        $paths[] = $file->store('uploads', 'public');
    }

    return gale()->patchState([
        'uploaded' => true,
        'paths' => $paths,
    ]);
}
```

```html
<div x-data="{ uploaded: false, paths: [], messages: {} }">
    <input type="file" x-files="photos" multiple accept="image/*">

    <!-- Previews -->
    <div class="flex gap-2">
        <template x-for="(file, i) in $files('photos')" :key="i">
            <img :src="$filePreview('photos', i)" class="w-20 h-20 object-cover rounded">
        </template>
    </div>

    <!-- Upload progress -->
    <div x-show="$uploading">
        <progress :value="$uploadProgress" max="100"></progress>
    </div>

    <p x-message="photos" class="text-red-600 text-sm"></p>

    <button
        @click="$action('/upload')"
        :disabled="$fetching() || !$files('photos').length"
    >
        <span x-show="!$fetching()">Upload</span>
        <span x-show="$fetching()">Uploading...</span>
    </button>

    <p x-show="uploaded" class="text-green-600">Upload complete!</p>
</div>
```

---

## SPA Navigation with Sidebar

```html
<!-- layouts/app.blade.php -->
<html>
<head>@gale</head>
<body>
    <div class="flex h-screen">
        <nav class="w-64 bg-gray-800 text-white p-4" x-navigate>
            <a href="/dashboard" class="{{ request()->is('dashboard') ? 'bg-white/10' : '' }} block px-3 py-2 rounded">
                Dashboard
            </a>
            <a href="/contacts" class="{{ request()->is('contacts*') ? 'bg-white/10' : '' }} block px-3 py-2 rounded">
                Contacts
            </a>
            <a href="https://docs.example.com" x-navigate-skip>
                External Docs
            </a>
        </nav>

        <main class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </main>
    </div>
</body>
</html>
```

```php
// DashboardController.php
public function index(): mixed
{
    $stats = $this->getStats();
    return gale()->view('dashboard', compact('stats'), web: true);
}
```

---

## Fragment-Based Updates

```php
public function index(Request $request): mixed
{
    $data = ['products' => Product::paginate(20)];

    // SPA navigation: return only changed fragments
    if ($request->isGaleNavigate('catalog')) {
        return gale()
            ->fragment('catalog.index', 'sidebar', $data)
            ->fragment('catalog.index', 'results', $data);
    }

    return gale()->view('catalog.index', $data, web: true);
}
```

```html
<div x-data>
    @fragment('sidebar')
    <aside id="sidebar">
        <nav x-navigate.key.catalog>
            <a href="/products">All</a>
            <a href="/products?cat=electronics">Electronics</a>
        </nav>
    </aside>
    @endfragment

    @fragment('results')
    <div id="results">
        @foreach($products as $product)
            <div id="product-{{ $product->id }}">{{ $product->name }}</div>
        @endforeach
    </div>
    @endfragment
</div>
```

---

## Polling Dashboard

```php
public function stats(): GaleResponse
{
    return gale()->componentState('stats', [
        'visitors' => Analytics::currentVisitors(),
        'orders'   => Order::today()->count(),
        'revenue'  => Order::today()->sum('total'),
    ]);
}
```

```html
<div x-data="{ visitors: 0, orders: 0, revenue: 0 }"
     x-component="stats"
     x-interval.5s.visible="$action('/dashboard/stats')">

    <div>Visitors: <span x-text="visitors"></span></div>
    <div>Orders: <span x-text="orders"></span></div>
    <div>Revenue: $<span x-text="revenue.toFixed(2)"></span></div>
</div>
```

---

## Streaming Progress

```php
public function processImport(Request $request): StreamedResponse
{
    $rows = $request->state('rows', []);

    return gale()->stream(function ($gale) use ($rows) {
        $total = count($rows);
        foreach ($rows as $i => $row) {
            processRow($row);
            $gale->patchState([
                'progress' => round(($i + 1) / $total * 100),
                'current'  => $i + 1,
                'total'    => $total,
            ]);
        }
        $gale->patchState(['complete' => true]);
    });
}
```

```html
<div x-data="{ progress: 0, current: 0, total: 0, complete: false }">
    <button @click="$action('/import', { sse: true })" :disabled="$fetching()">
        Start Import
    </button>

    <div x-show="$fetching() || complete">
        <div class="w-full bg-gray-200 rounded">
            <div class="bg-blue-600 h-2 rounded" :style="'width:' + progress + '%'"></div>
        </div>
        <p x-text="current + '/' + total + ' processed'"></p>
        <p x-show="complete" class="text-green-600">Import complete!</p>
    </div>
</div>
```

---

## Multi-Component Communication

```php
// CartController.php — updates both cart badge AND cart panel
public function addItem(Request $request): GaleResponse
{
    $product = Product::find($request->state('productId'));
    $cart = Cart::add($product);

    return gale()
        ->componentState('cart-badge', ['count' => $cart->count()])
        ->componentState('cart-panel', [
            'items' => $cart->items()->toArray(),
            'total' => $cart->total(),
        ])
        ->dispatch('item-added', ['name' => $product->name]);
}
```

```html
<!-- Badge in header -->
<span x-data="{ count: 0 }" x-component="cart-badge">
    Cart (<span x-text="count"></span>)
</span>

<!-- Cart panel -->
<div x-data="{ items: [], total: 0 }" x-component="cart-panel">
    <template x-for="item in items" :key="item.id">
        <div x-text="item.name + ' - $' + item.price"></div>
    </template>
    <div>Total: $<span x-text="total.toFixed(2)"></span></div>
</div>

<!-- Product page — dispatches to both components -->
<button @click="$action('/cart/add', { include: ['productId'] })">Add to Cart</button>

<!-- Toast on item added -->
<div @item-added.window="alert('Added: ' + $event.detail.name)"></div>
```

---

## Toast Notifications

```php
// In any controller
return gale()
    ->patchState(['saved' => true])
    ->dispatch('toast', ['message' => 'Item saved!', 'type' => 'success']);
```

```html
<!-- Toast container (in layout) -->
<div x-data="{ toasts: [] }"
     @toast.window="toasts.push({...$event.detail, id: Date.now()});
                     setTimeout(() => toasts.shift(), 3000)">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="px-4 py-2 rounded shadow-lg mb-2"
             :class="toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'"
             x-text="toast.message"
             x-transition></div>
    </template>
</div>
```

---

## Inline Editing

```php
public function update(Request $request, Contact $contact): GaleResponse
{
    $validated = $request->validate(['name' => 'required|string|max:255']);
    $contact->update($validated);

    return gale()->patchState(['editing' => false, 'name' => $contact->name]);
}
```

```html
<div x-data="{ editing: false, name: '{{ $contact->name }}', messages: {} }" x-sync="['name']">
    <template x-if="!editing">
        <span>
            <span x-text="name"></span>
            <button @click="editing = true">Edit</button>
        </span>
    </template>
    <template x-if="editing">
        <span>
            <input x-model="name" @keydown.enter="$action('/contacts/{{ $contact->id }}/update')"
                   @keydown.escape="editing = false">
            <button @click="$action('/contacts/{{ $contact->id }}/update')" :disabled="$fetching()">Save</button>
            <button @click="editing = false">Cancel</button>
            <p x-message="name" class="text-red-600 text-sm"></p>
        </span>
    </template>
</div>
```

---

## Infinite Scroll

```php
public function loadMore(Request $request): GaleResponse
{
    $page = $request->state('page', 1) + 1;

    $items = Item::latest()->paginate(20, ['*'], 'page', $page);

    $html = '';
    foreach ($items as $item) {
        $html .= view('items._item', compact('item'))->render();
    }

    return gale()
        ->patchElements($html, selector: '#items-list', mode: 'append')
        ->patchState([
            'page' => $page,
            'hasMore' => $items->hasMorePages(),
        ]);
}
```

```html
<div x-data="{ page: 1, hasMore: true }" x-sync="['page']">
    <div id="items-list">
        @foreach($items as $item)
            @include('items._item', compact('item'))
        @endforeach
    </div>

    <button
        x-show="hasMore"
        @click="$action('/items/load-more')"
        :disabled="$fetching()"
    >
        <span x-show="!$fetching()">Load More</span>
        <span x-show="$fetching()">Loading...</span>
    </button>
</div>
```

---

## Multi-Step Wizard

```php
public function step1(): mixed
{
    return gale()->view('wizard.step1', web: true);
}

public function storeStep1(Request $request): mixed
{
    $validated = $request->validate([
        'name'  => 'required|string|max:255',
        'email' => 'required|email',
    ]);

    session(['wizard' => array_merge(session('wizard', []), $validated)]);

    return redirect()->route('wizard.step2');
}
```

```html
<!-- wizard/step1.blade.php -->
<form method="POST" action="/wizard/step-1" x-navigate>
    @csrf
    <h2>Step 1: Basic Information</h2>

    <input type="text" name="name" placeholder="Name">
    <span x-message="name" class="text-red-500 text-sm"></span>

    <input type="email" name="email" placeholder="Email">
    <span x-message="email" class="text-red-500 text-sm"></span>

    <button type="submit">Next Step</button>
</form>
```
