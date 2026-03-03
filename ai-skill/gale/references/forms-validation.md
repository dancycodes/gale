# Forms, Validation & Uploads Reference

Complete guide for reactive forms: x-name, x-sync, validation with x-message, file uploads with x-files, HTML5 validation, Form Request integration, and common form patterns.

---

## Table of Contents

- [Form Fundamentals](#form-fundamentals)
- [x-name — Auto Field Binding](#x-name--auto-field-binding)
- [x-sync — State Sync Control](#x-sync--state-sync-control)
- [Validation](#validation)
- [File Uploads](#file-uploads)
- [HTML5 Validation](#html5-validation)
- [Form Request Integration](#form-request-integration)
- [Common Patterns](#common-patterns)

---

## Form Fundamentals

Minimum required pieces:
1. `x-data` with state properties for fields (include `messages: {}`)
2. Field binding: `x-model`, `x-name`, or `x-sync`
3. Submit trigger: `$action` or `x-navigate` on form
4. Controller returning `gale()->`
5. Error display: `x-message`

```html
<div x-data="{ name: '', email: '', messages: {} }">
    <input x-model="name" x-name="name" type="text">
    <span x-message="name" class="text-red-600 text-sm"></span>

    <input x-model="email" x-name="email" type="email">
    <span x-message="email" class="text-red-600 text-sm"></span>

    <button @click="$action('/contact')" :disabled="$fetching()">
        <span x-show="!$fetching()">Send</span>
        <span x-show="$fetching()">Sending...</span>
    </button>
</div>
```

```php
public function store(Request $request): GaleResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email'],
    ]);
    // ValidationException auto-converts to reactive messages

    return gale()->patchState(['success' => true]);
}
```

---

## x-name — Auto Field Binding

`x-name="field"` does three things:
1. **Creates state** — adds the key to Alpine scope if not present
2. **Binds two-way** — syncs field value with Alpine state
3. **Sets name attribute** — for FormData compatibility

### Input Type Defaults

| Type | Default value |
|------|--------------|
| text, email, password, url, tel, search, textarea | `''` |
| number, range | `null` |
| checkbox (single) | `false` |
| checkbox (multiple, same name) | `[]` |
| radio | `null` |
| select[multiple] | `[]` |
| file | Delegates to `x-files` |

### Modifiers
```html
<input x-name.lazy="username">       <!-- Bind on change/blur -->
<input x-name.number="age">          <!-- Coerce to number -->
<input x-name.trim="email">          <!-- Trim whitespace -->
<input x-name.debounce.500ms="query"> <!-- Debounce input -->
```

### Nested State (Dot Notation)
```html
<input x-name="user.name">      <!-- Creates { user: { name: '' } } -->
<input x-name="user.email">
<input x-name="address.city">
```

### Checkboxes & Radios
```html
<!-- Single checkbox → boolean -->
<input x-name="agreed" type="checkbox">

<!-- Multiple checkboxes → auto-array -->
<input x-name="roles" type="checkbox" value="admin">
<input x-name="roles" type="checkbox" value="editor">

<!-- Radio group → single value -->
<input x-name="plan" type="radio" value="basic">
<input x-name="plan" type="radio" value="pro">
```

### x-name vs x-model

| Use `x-name` | Use `x-model` |
|--------------|--------------|
| State doesn't exist yet | State already in `x-data` |
| Want name attr auto-set | Manage name manually |
| Smart type defaults | Custom initial values |
| Fast form scaffolding | Existing Alpine integration |

---

## x-sync — State Sync Control

Controls which state keys are sent with requests.

```html
<!-- Send ALL state -->
<div x-data="{ a: 1, b: 2, c: 3 }" x-sync>

<!-- Send specific keys only -->
<div x-data="{ a: 1, b: 2, draft: '' }" x-sync="['a', 'b']">

<!-- No x-sync → sends NOTHING -->
<div x-data="{ a: 1 }">
    <!-- Use include per-action -->
    <button @click="$action('/save', { include: ['a'] })">Save</button>
</div>
```

### Per-Action Overrides
```html
<!-- Include extra keys for this action -->
<button @click="$action('/save', { include: ['draft'] })">Save Draft</button>

<!-- Exclude keys from x-sync for this action -->
<button @click="$action('/search', { exclude: ['draft'] })">Search</button>
```

---

## Validation

### Auto-Conversion
Standard `$request->validate()` auto-converts on failure:
- `ValidationException` → `gale()->messages()` with 422 status
- Passing fields get `null` (clears previous errors)
- Uses HTTP mode even if SSE was requested

### x-message Directive
```html
<p x-message="email" class="text-red-600 text-sm"></p>
```

Reads from `messages.email` in Alpine state. Auto shows/hides.

**Requires `messages: {}` in x-data.**

### Validation Lifecycle
1. User submits → `$action('/save')`
2. Server validates → throws `ValidationException`
3. Gale auto-converts → patches `messages` state
4. `x-message` directives update → errors shown
5. User fixes & resubmits → passing fields get `null` → errors clear

### State vs FormData Validation
```php
// For $action() submissions (state in JSON body):
$request->validate(['email' => 'required|email']); // reads from state

// For x-navigate form POST (FormData body):
$request->validate(['email' => 'required|email']); // reads from form fields
```

Both work identically with `$request->validate()`.

---

## File Uploads

### x-files Directive
```html
<input type="file" x-files="photos" multiple accept="image/*">
```

### File Magics
```html
$files('photos')               <!-- Array of File objects -->
$file('avatar')                <!-- Single File object -->
$filePreview('photos', 0)      <!-- Preview URL for image -->
$clearFiles('photos')          <!-- Clear selected files -->
$uploading                     <!-- Boolean: upload in progress -->
$uploadProgress                <!-- Number: 0-100 -->
$uploadError                   <!-- String: error message -->
```

### Complete Upload Example
```html
<div x-data="{ messages: {} }">
    <input type="file" x-files="photos" multiple accept="image/*">

    <div class="flex gap-2">
        <template x-for="(f, i) in $files('photos')" :key="i">
            <div class="relative">
                <img :src="$filePreview('photos', i)" class="w-20 h-20 object-cover rounded">
                <span x-text="$formatBytes(f.size)" class="text-xs"></span>
            </div>
        </template>
    </div>

    <div x-show="$uploading">
        <progress :value="$uploadProgress" max="100"></progress>
    </div>

    <p x-message="photos" class="text-red-600 text-sm"></p>

    <button @click="$action('/upload')" :disabled="$fetching() || !$files('photos').length">
        Upload
    </button>
</div>
```

### Server-Side Handling
```php
public function upload(Request $request): GaleResponse
{
    $request->validate([
        'photos'   => ['required', 'array', 'max:10'],
        'photos.*' => ['required', 'image', 'max:5120'],
    ]);

    $paths = [];
    foreach ($request->file('photos') as $file) {
        $paths[] = $file->store('uploads', 'public');
    }

    return gale()->patchState(['uploaded' => true, 'paths' => $paths]);
}
```

Files are sent via FormData automatically when `x-files` inputs have selections.

---

## HTML5 Validation

### x-validate Directive
```html
<form x-validate @submit.prevent="$action('/save')">
    <input type="email" required x-name="email" placeholder="Email">
    <input type="text" required minlength="2" x-name="name" placeholder="Name">
    <button type="submit">Save</button>
</form>
```

Gale triggers native browser constraint validation before submission. If invalid, browser shows default validation bubbles. If valid, `$action` fires normally.

---

## Form Request Integration

Laravel Form Request classes work natively:

```php
class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:contacts'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
        ];
    }
}
```

```php
public function store(StoreContactRequest $request): GaleResponse
{
    Contact::create($request->validated());
    return gale()->redirect('/')->route('contacts.index');
}
```

Authorization failures (403) auto-convert to `gale:error` events.

---

## Common Patterns

### Search-as-You-Type
```html
<div x-data="{ query: '', results: [] }" x-sync="['query']">
    <input x-model="query" @input.debounce.300ms="$action('/search')">
    <span x-show="$fetching()">Searching...</span>
</div>
```

### Inline Edit Toggle
```html
<div x-data="{ editing: false, value: '{{ $item->name }}', messages: {} }" x-sync="['value']">
    <template x-if="!editing">
        <span x-text="value" @dblclick="editing = true"></span>
    </template>
    <template x-if="editing">
        <input x-model="value"
               @keydown.enter="$action('/update')"
               @keydown.escape="editing = false"
               @blur="$action('/update')">
    </template>
    <p x-message="value" class="text-red-600 text-sm"></p>
</div>
```

### Confirm Before Submit
```html
<button @click="$action('/delete', { confirm: 'Are you sure you want to delete this?' })">
    Delete
</button>
```

### Optimistic UI
```html
<button @click="$action('/like', {
    optimistic: { liked: true, likeCount: likeCount + 1 }
})">
    <span x-text="liked ? '❤️' : '🤍'"></span>
    <span x-text="likeCount"></span>
</button>
```
State applies immediately. Reverts automatically on server error.
