# Gale Troubleshooting Reference

Common issues and their solutions when working with Gale.

---

## State Not Updating

**Symptom:** Button click sends request but Alpine state doesn't change.

**Cause 1: No x-sync and no include option**
```html
<!-- ❌ No state sent -->
<div x-data="{ count: 0 }">
    <button @click="$action('/increment')">+</button>
</div>

<!-- ✅ Fix: Add x-sync -->
<div x-data="{ count: 0 }" x-sync>
    <button @click="$action('/increment')">+</button>
</div>

<!-- ✅ Fix: Or use include per-action -->
<div x-data="{ count: 0 }">
    <button @click="$action('/increment', { include: ['count'] })">+</button>
</div>
```

**Cause 2: Backend not returning gale() response**
```php
// ❌ Wrong
return response()->json(['count' => 1]);

// ✅ Correct
return gale()->state('count', 1);
```

**Cause 3: No Alpine context**
```html
<!-- ❌ No x-data parent -->
<button @click="$action('/save')">Save</button>

<!-- ✅ Add x-data -->
<div x-data="{ count: 0 }">
    <button @click="$action('/save')">Save</button>
</div>
```

---

## Fragment Not Replacing

**Symptom:** Fragment renders but DOM doesn't update.

**Cause 1: Missing ID on fragment root element**
```blade
<!-- ❌ No ID — Gale can't find element to patch -->
@fragment('items')
<div>
    @foreach($items as $item) ... @endforeach
</div>
@endfragment

<!-- ✅ Add ID -->
@fragment('items')
<div id="items-list">
    @foreach($items as $item) ... @endforeach
</div>
@endfragment
```

**Cause 2: Fragment name mismatch**
```php
// ❌ Fragment name doesn't match blade
gale()->fragment('items.index', 'item-list', $data);

// ✅ Must match @fragment('items-list')
gale()->fragment('items.index', 'items-list', $data);
```

**Cause 3: View path incorrect**
```php
// ❌ Wrong view path
gale()->fragment('items', 'items-list', $data);

// ✅ Use dot notation matching file path
gale()->fragment('items.index', 'items-list', $data);
```

---

## $fetching Not Working

**Symptom:** `$fetching()` always returns false.

**Cause: Missing parentheses**
```html
<!-- ❌ Wrong — property access, not function call -->
<span x-show="$fetching">Loading...</span>

<!-- ✅ Correct — function call -->
<span x-show="$fetching()">Loading...</span>
```

---

## CSRF Token Mismatch (419)

**Symptom:** POST requests return 419 error.

**Cause 1: Missing @gale directive**
```blade
<!-- ❌ No CSRF meta tag -->
<head></head>

<!-- ✅ @gale outputs CSRF meta + Alpine + Gale -->
<head>@gale</head>
```

**Cause 2: Expired session**
The CSRF token is read from `<meta name="csrf-token">`. If the session expires, the token becomes stale. Gale's retry mechanism will handle this, but for long-lived pages consider refreshing the token.

---

## Duplicate Alpine.js Errors

**Symptom:** Console errors about Alpine being initialized twice.

**Cause: @gale + separate Alpine script**
```blade
<!-- ❌ Duplicate Alpine -->
<head>
    @gale
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
</head>

<!-- ✅ Remove separate Alpine — @gale includes it -->
<head>
    @gale
</head>
```

Also check `resources/js/app.js` for `import Alpine from 'alpinejs'` — remove it.

---

## Navigation Not Working

**Symptom:** Links cause full page reload instead of SPA navigation.

**Cause 1: Missing x-navigate on container or link**
```html
<!-- ❌ No navigation handling -->
<a href="/page">Link</a>

<!-- ✅ Add x-navigate -->
<div x-data x-navigate>
    <a href="/page">Now it works</a>
</div>
```

**Cause 2: Missing x-data context**
```html
<!-- ❌ x-navigate without Alpine context -->
<div x-navigate>
    <a href="/page">Won't work</a>
</div>

<!-- ✅ Add x-data -->
<div x-data x-navigate>
    <a href="/page">Works</a>
</div>
```

**Cause 3: Backend not handling navigate request**
```php
// ❌ Returns full page for navigate requests too
public function index()
{
    return view('page');
}

// ✅ Check for navigate and return fragments
public function index(Request $request)
{
    $data = $this->getData();

    if ($request->isGaleNavigate('content')) {
        return gale()->fragment('page', 'content', $data);
    }
    return gale()->view('page', $data, web: true);
}
```

---

## Validation Errors Not Showing

**Symptom:** Server validates but errors don't appear in UI.

**Cause 1: Missing x-message directive**
```html
<!-- ❌ No error display -->
<input x-name="email">

<!-- ✅ Add x-message -->
<input x-name="email">
<p x-message="email" class="text-red-600 text-sm"></p>
```

**Cause 2: Message key mismatch**
```php
// Server validates 'email_address'
$request->validateState(['email_address' => 'required|email']);
```
```html
<!-- ❌ Key doesn't match -->
<p x-message="email"></p>

<!-- ✅ Must match validation key -->
<p x-message="email_address"></p>
```

**Cause 3: Using validate() instead of validateState()**
```php
// ❌ For Alpine state, this won't work correctly
$request->validate(['email' => 'required|email']);

// ✅ Use validateState() for Alpine state
$request->validateState(['email' => 'required|email']);
```

---

## outerMorph Not Preserving State

**Symptom:** Using outerMorph but Alpine state still resets.

**Cause: ID mismatch between old and new HTML**
```php
// ❌ New HTML has different structure/ID
$html = '<div id="item-new">...</div>';
gale()->outerMorph('#item-1', $html);

// ✅ IDs must match for morph to work
$html = '<div id="item-1">...updated...</div>';
gale()->outerMorph('#item-1', $html);
```

---

## Streaming Not Working

**Symptom:** All events arrive at once instead of progressively.

**Cause 1: Not using stream()**
```php
// ❌ Events batch and send together
$gale = gale();
foreach ($items as $item) {
    $gale->state('progress', $i);
}
return $gale;

// ✅ Use stream() for immediate delivery
return gale()->stream(function ($gale) use ($items) {
    foreach ($items as $i => $item) {
        $gale->state('progress', $i);
    }
});
```

**Cause 2: Output buffering**
Check for `ob_start()` or middleware that buffers output. Streaming requires unbuffered output.

---

## File Upload Issues

**Symptom:** Files not received by server.

**Cause 1: Missing x-files directive on the input**
```html
<!-- ❌ Files not tracked -->
<input type="file" name="images">

<!-- ✅ Add x-files on the input element -->
<div x-data>
    <input type="file" name="images" x-files multiple>
</div>
```

**Cause 2: Using validateState() for files**
```php
// ❌ Files come via FormData, not Alpine state
$request->validateState(['images' => 'required']);

// ✅ Use standard Laravel validation for files
$request->validate(['images.*' => 'required|image|max:5120']);
```

---

## Component State Not Updating

**Symptom:** `componentState()` called but component doesn't update.

**Cause 1: Component not registered**
```html
<!-- ❌ No x-component -->
<div x-data="{ value: 0 }">
    <span x-text="value"></span>
</div>

<!-- ✅ Register with x-component -->
<div x-data="{ value: 0 }" x-component="my-widget">
    <span x-text="value"></span>
</div>
```

**Cause 2: Name mismatch**
```php
// ❌ Name doesn't match
gale()->componentState('myWidget', ['value' => 42]);

// ✅ Must match x-component attribute exactly
gale()->componentState('my-widget', ['value' => 42]);
```

---

## Polling Issues

**Symptom:** Polling doesn't start or stops unexpectedly.

**Cause 1: Missing time modifier**
```html
<!-- ❌ No interval specified -->
<div x-interval="refresh()">

<!-- ✅ Specify interval -->
<div x-interval.5s="refresh()">
```

**Cause 2: x-interval-stop evaluating true immediately**
```html
<!-- ❌ Starts stopped -->
<div x-data="{ done: true }" x-interval.5s="check()" x-interval-stop="done">

<!-- ✅ Start with false -->
<div x-data="{ done: false }" x-interval.5s="check()" x-interval-stop="done">
```

---

## Non-Gale Request Errors (LogicException)

**Symptom:** First page load throws error.

**Cause: No web fallback**
```php
// ❌ No fallback for initial page load
return gale()->state('data', $data);

// ✅ Provide web fallback
return gale()->view('page', $data, web: true);

// ✅ Or use web() method
return gale()->state('data', $data)->web(view('page', compact('data')));
```

---

## Redirect Not Working in Gale Requests

**Symptom:** Standard Laravel redirect() doesn't work with Gale requests.

**Cause: Using Laravel's redirect() instead of gale()->redirect()**
```php
// ❌ Standard redirect won't work for SSE requests
return redirect('/dashboard');

// ✅ Use Gale redirect
return gale()->redirect('/dashboard');

// ✅ With flash data
return gale()->redirect('/dashboard')->with('message', 'Saved!');
```

---

## Back Button Causes Full Page Reload

**Symptom:** Clicking browser back/forward after Gale navigation causes a full page reload instead of SPA-style transition.

**This is expected behavior.** Gale uses `history.pushState()` for URL updates during navigation, and the popstate handler intentionally triggers `window.location.reload()`. This ensures:
- Correct state on every page (no stale Alpine data)
- No mismatch between server and client state
- Reliable behavior regardless of how many navigations occurred

**If you need SPA-like back behavior**, consider using fragments and navigate keys so the reload only fetches the changed content:
```php
if ($request->isGaleNavigate('content')) {
    return gale()->fragment('page', 'content', $data);
}
return gale()->view('page', $data, web: true);
```

---

## Common Performance Tips

1. **Use fragments over full views** — Only re-render what changed
2. **Use outerMorph for interactive elements** — Preserves user input
3. **Use x-interval.visible** — Stop polling when tab hidden
4. **Use { include: [...] }** — Send only needed state, not everything
5. **Use settle for animations** — `['settle' => 200]` gives CSS time to animate
6. **Use componentState for multi-widget updates** — One request, many updates
7. **Use streaming for long operations** — Progressive feedback
