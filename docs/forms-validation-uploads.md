# Forms, Validation & Uploads

> **See also:** [Backend API Reference](backend-api.md) | [Frontend API Reference](frontend-api.md) | [Core Concepts](core-concepts.md)

This guide covers everything needed to build reactive forms with Gale: field binding with `x-name` and `x-sync`, displaying validation errors with `x-message`, file uploads with `x-files`, HTML5 browser validation, Laravel Form Request integration, and complete patterns for common form scenarios.

---

## Table of Contents

- [Form Fundamentals](#form-fundamentals)
- [x-name — Field Binding](#x-name--field-binding)
- [x-sync — State Synchronization](#x-sync--state-synchronization)
- [Validation Errors](#validation-errors)
  - [Server-Side Validation](#server-side-validation)
  - [x-message — Field Error Display](#x-message--field-error-display)
  - [Validation Lifecycle](#validation-lifecycle)
- [File Uploads](#file-uploads)
  - [x-files Directive](#x-files-directive)
  - [Upload Progress](#upload-progress)
  - [Multi-File Uploads](#multi-file-uploads)
  - [Server-Side File Validation](#server-side-file-validation)
- [HTML5 Form Validation Integration](#html5-form-validation-integration)
- [Form Request Integration](#form-request-integration)
- [Form Patterns](#form-patterns)
  - [Create Form](#create-form)
  - [Edit Form](#edit-form)
  - [Inline Editing](#inline-editing)
  - [Search-as-You-Type](#search-as-you-type)
  - [Multi-Step Wizard](#multi-step-wizard)

---

## Form Fundamentals

Gale forms work by serializing Alpine component state and submitting it to a Laravel controller. The controller validates input, then returns a `GaleResponse` that patches the component state reactively.

The minimum required pieces are:

1. An Alpine component (`x-data`) with state properties for each field
2. A way to bind fields to state (`x-model`, `x-name`, or `x-sync`)
3. A way to submit the form (`$action` or `x-navigate`)
4. A controller that returns `gale()->`
5. Error display (`x-message`)

Here is a minimal working example:

```html
<div x-data="{ name: '', email: '', messages: {} }">
    <input x-model="name" name="name" type="text" placeholder="Name">
    <span x-message="name"></span>

    <input x-model="email" name="email" type="email" placeholder="Email">
    <span x-message="email"></span>

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

    // ValidationException is auto-converted to reactive messages
    // No special handling required

    // On success:
    return gale()->patchState(['success' => true]);
}
```

> **Note:** Add `messages: {}` to your `x-data` so `x-message` has a place to display server errors. Gale patches the `messages` key automatically when validation fails.

---

## x-name — Field Binding

`x-name` is a shorthand directive that auto-creates Alpine state, binds the field value two-ways, and sets the `name` attribute — all in one directive. It is the fastest way to wire up form fields when the state does not already exist.

### Basic Usage

```html
<div x-data>
    <input x-name="email" type="email">
    <input x-name="password" type="password">
    <button @click="$action('/login')">Sign In</button>
</div>
```

`x-name="email"` does three things automatically:

1. **Creates state** — adds `email: ''` to the component's Alpine scope if it does not already exist
2. **Binds two-way** — the field value stays in sync with the Alpine state in both directions
3. **Sets the name attribute** — adds `name="email"` if not already present (for FormData compatibility)

### Input Type Defaults

`x-name` infers the correct default value from the input type:

| Input type | Default value |
|------------|---------------|
| `text`, `email`, `password`, `url`, `tel`, `search`, `textarea` | `''` (empty string) |
| `number`, `range` | `null` |
| `checkbox` (single) | `false` |
| `checkbox` (multiple with same name) | `[]` (array) |
| `radio` | `null` |
| `select[multiple]` | `[]` (array) |
| `file` | Delegates to `x-files` |

### Modifiers

| Modifier | Effect |
|----------|--------|
| `.lazy` | Binds on `change` event instead of `input` (fires on blur) |
| `.number` | Coerces the string value to a number |
| `.trim` | Trims whitespace from the string value |
| `.array` | Forces checkbox to array mode |
| `.debounce.300ms` | Debounces the input event by the given delay |

```html
<!-- Bind on change (blur), trim whitespace, coerce to number -->
<input x-name.lazy.trim="username" type="text">
<input x-name.number="age" type="number">
<input x-name.debounce.500ms="search" type="text">
```

### Nested State (Dot Notation)

`x-name` supports dot-notation for nested state. Intermediate objects are auto-created:

```html
<div x-data>
    <input x-name="user.name" type="text">
    <input x-name="user.email" type="email">
    <input x-name="address.city" type="text">
</div>
```

This creates Alpine state: `{ user: { name: '', email: '' }, address: { city: '' } }`.

### Checkboxes and Radio Buttons

```html
<!-- Single checkbox — boolean state -->
<input x-name="agreed" type="checkbox">

<!-- Multiple checkboxes — auto-detects array mode -->
<input x-name="roles" type="checkbox" value="admin">
<input x-name="roles" type="checkbox" value="editor">
<input x-name="roles" type="checkbox" value="viewer">

<!-- Explicit array mode -->
<input x-name.array="tags" type="checkbox" value="php">
<input x-name.array="tags" type="checkbox" value="laravel">

<!-- Radio buttons -->
<input x-name="plan" type="radio" value="basic">
<input x-name="plan" type="radio" value="pro">
```

### File Inputs

When `x-name` is used on a file input, it automatically delegates to the `x-files` system:

```html
<!-- These are equivalent -->
<input x-name="avatar" type="file">
<input x-files="avatar" type="file">
```

### When to Use x-name vs x-model

| Use `x-name` when | Use `x-model` when |
|-------------------|-------------------|
| State doesn't exist yet — x-name creates it | State already exists in `x-data` |
| You want the `name` attribute auto-set | You manage `name` attributes manually |
| You want smart input type defaults | You need custom initial values |
| Fast scaffolding of a new form | You're integrating with existing Alpine state |

> **Warning:** `x-name` requires an Alpine component context — it must be inside an element with `x-data`. Without a component ancestor, Gale logs a warning and does nothing.

---

## x-sync — State Synchronization

`x-sync` is a directive placed on the `x-data` element that controls which state properties are sent to the server with each request. It is the key to the Gale state synchronization system.

### How x-sync Works

By default in Gale v2, **no state is sent with requests** unless `x-sync` is present. This is a deliberate security and performance design: you explicitly declare what the server should see.

```html
<!-- No x-sync — $action sends nothing (empty body) -->
<div x-data="{ count: 0 }">
    <button @click="$action('/reset')">Reset</button>
</div>

<!-- x-sync (empty) — sends all state (wildcard) -->
<div x-data="{ count: 0, name: '' }" x-sync>
    <button @click="$action('/save')">Save</button>
    <!-- Server receives: { count: 0, name: '' } -->
</div>

<!-- x-sync with explicit keys — sends only 'name' and 'email' -->
<div x-data="{ name: '', email: '', _private: 'secret' }" x-sync="['name', 'email']">
    <button @click="$action('/update')">Update</button>
    <!-- Server receives: { name: '', email: '' } -->
</div>
```

### x-sync Syntax Options

```html
<!-- Wildcard (send everything) -->
<div x-data="{ a: 1, b: 2 }" x-sync>
<div x-data="{ a: 1, b: 2 }" x-sync="*">

<!-- Array syntax -->
<div x-data="{ a: 1, b: 2, c: 3 }" x-sync="['a', 'b']">

<!-- String syntax (convenience) -->
<div x-data="{ a: 1, b: 2, c: 3 }" x-sync="a, b">
```

### Debounced Sync for Text Inputs

For search-as-you-type or auto-save patterns, combine `x-sync` with `$action`'s `debounce` option:

```html
<div x-data="{ query: '' }" x-sync>
    <input x-model="query" type="text" placeholder="Search..."
           @input="$action('/search', { debounce: 300 })">
    <div id="results"><!-- morphed by server --></div>
</div>
```

### x-sync vs x-name — Two-Way Binding vs State Selection

These are different concepts:

- **`x-name`** creates two-way binding between a form field and Alpine state (field ↔ state). It is a field-level directive.
- **`x-sync`** declares which state keys are sent to the server. It is a component-level directive.

You typically use both together:

```html
<div x-data x-sync>
    <input x-name="email" type="email">  <!-- creates state + two-way binds -->
    <!-- x-sync sends 'email' with every $action -->
</div>
```

### Form Fields Are Always Included

Form fields with `name` attributes inside a `<form>` element are always included in the request body via `FormData`, regardless of `x-sync`. This means standard HTML forms work naturally:

```html
<form x-data="{ messages: {} }">
    <input name="email" type="email">
    <!-- 'email' is included via FormData even without x-sync -->
    <span x-message="email"></span>
    <button @click="$action('/subscribe')">Subscribe</button>
</form>
```

---

## Validation Errors

### Server-Side Validation

Use Laravel's standard `$request->validate()` in your controller. When validation fails, Gale automatically converts the `ValidationException` into a reactive state patch — no special handling required.

```php
public function store(Request $request): GaleResponse
{
    // Standard Laravel validation — auto-converts for Gale requests
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
        'age'   => ['required', 'integer', 'min:18'],
    ]);

    $user = User::create($validated);

    return gale()->patchState([
        'success' => true,
        'userId'  => $user->id,
    ]);
}
```

When validation fails, Gale patches the `messages` state key with the first error per field:

```json
{
  "messages": {
    "email": "The email field is required.",
    "name":  "The name field must be at least 3 characters."
  }
}
```

User input in the component is **preserved** during validation errors. The server response patches `messages` without overwriting the field values — so the user's text stays in the form.

### Manual messages()

You can also send messages manually without triggering a ValidationException:

```php
// Send a custom message for one field
return gale()->messages(['email' => 'That email is already in use.']);

// Send messages for multiple fields
return gale()->messages([
    'email'    => 'Already registered.',
    'username' => 'Username taken.',
]);

// Clear all messages (e.g., after success)
return gale()->clearMessages();
```

### x-message — Field Error Display

`x-message` binds an element to a specific field in the component's `messages` state. It shows the message when one exists and hides itself when there is none.

```html
<div x-data="{ name: '', email: '', messages: {} }">
    <div>
        <label>Name</label>
        <input x-model="name" name="name" type="text">
        <span x-message="name" class="text-red-500 text-sm"></span>
    </div>

    <div>
        <label>Email</label>
        <input x-model="email" name="email" type="email">
        <span x-message="email" class="text-red-500 text-sm"></span>
    </div>

    <button @click="$action('/register')">Register</button>
</div>
```

**Key behaviors:**

- The element is automatically hidden (`display: none`) when no message exists
- The element is shown and its text content set when a message exists
- Messages are reactive — when the server clears `messages`, the element hides again

### Reading messages from the errors State Key

When using `$request->validate()` with multiple errors per field, Gale stores them in an `errors` key (arrays of strings) in addition to `messages` (single string per field). Use `x-message.from.errors` to display from the errors key:

```html
<div x-data="{ name: '', email: '', messages: {}, errors: {} }">
    <input x-model="email" name="email" type="email">

    <!-- Shows first message from 'messages' key -->
    <span x-message="email" class="text-red-500 text-sm"></span>

    <!-- Shows first message from 'errors' key (when using errors()) -->
    <span x-message.from.errors="email" class="text-red-500 text-sm"></span>
</div>
```

### Accessing All Errors

To display all errors at once (e.g., a summary at the top of a form), iterate over the `messages` state:

```html
<div x-show="Object.keys(messages).length > 0"
     class="bg-red-50 border border-red-200 rounded p-4">
    <p class="font-medium text-red-800">Please fix the following:</p>
    <ul class="list-disc list-inside mt-2">
        <template x-for="[field, message] in Object.entries(messages)">
            <li class="text-red-700" x-text="message"></li>
        </template>
    </ul>
</div>
```

### x-message with Array Validation (Nested Fields)

For array-validated fields (e.g., `items.*.name`), `x-message` supports both flat dot-notation keys and dynamic template literal expressions:

```html
<!-- Flat key: 'items.0.name' — matches Laravel's ValidationException format directly -->
<span x-message="items.0.name"></span>

<!-- Dynamic key in x-for loop -->
<template x-for="(item, index) in items">
    <div>
        <input x-model="items[index].name" :name="`items[${index}][name]`">
        <span x-message="`items.${index}.name`" class="text-red-500 text-sm"></span>
    </div>
</template>
```

### Validation Lifecycle

Understanding the full lifecycle helps when debugging:

1. **User fills the form** — Alpine state reflects field values via `x-model` or `x-name`
2. **User submits** — `$action('/url')` is called; state serialized per `x-sync`
3. **HTML5 validation** — browser checks `required`, `type`, `pattern`, `minlength`, etc. (if inside a `<form>`)
4. **Request sent** — POST body contains serialized state and/or form fields
5. **Server validates** — `$request->validate()` runs the validation rules
6. **On failure** — `ValidationException` is auto-converted to a `gale-patch-state` event with `messages`
7. **Frontend receives** — `messages` state is patched; `x-message` elements show errors
8. **User sees errors** — fields still contain their values; only messages changed
9. **User corrects and resubmits** — cycle repeats from step 1
10. **On success** — server returns the success state; messages are cleared

> **Note:** User input is never lost during validation errors. Gale only patches `messages` (and whatever else your controller sends) — it does not reset `name`, `email`, or any other field state.

---

## File Uploads

### x-files Directive

Add `x-files` to a file input to register it with Gale's file upload system. When the component makes a `$action` call, Gale automatically detects registered file inputs with selected files and sends the request as `multipart/form-data` instead of JSON.

```html
<div x-data="{ messages: {} }">
    <input type="file" x-files="avatar" accept="image/*">
    <span x-message="avatar" class="text-red-500 text-sm"></span>

    <button @click="$action('/upload/avatar')" :disabled="$fetching()">
        Upload
    </button>
</div>
```

```php
public function uploadAvatar(Request $request): GaleResponse
{
    $request->validate([
        'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
    ]);

    $path = $request->file('avatar')->store('avatars', 'public');

    return gale()->patchState([
        'avatarUrl' => Storage::url($path),
    ]);
}
```

### Image Preview

Use `$filePreview('name')` to get a blob URL for image previews before upload:

```html
<div x-data="{ messages: {} }">
    <input type="file" x-files="avatar" accept="image/*">

    <!-- Preview before upload -->
    <img x-show="$filePreview('avatar')"
         :src="$filePreview('avatar')"
         class="w-24 h-24 rounded-full object-cover mt-2">

    <button @click="$action('/upload/avatar')" :disabled="$fetching()">
        Upload
    </button>
</div>
```

### File Metadata Magics

| Magic | Signature | Returns |
|-------|-----------|---------|
| `$file(name)` | `$file('avatar')` | `{ name, size, type, lastModified }` or `null` |
| `$files(name)` | `$files('photos')` | Array of `{ name, size, type, lastModified }` |
| `$filePreview(name, index?)` | `$filePreview('avatar')` | Blob URL string or `''` |
| `$clearFiles(name?)` | `$clearFiles('avatar')` | Clears the file input |
| `$formatBytes(bytes)` | `$formatBytes($file('avatar')?.size)` | `'1.23 MB'` |

```html
<div x-data="{ messages: {} }">
    <input type="file" x-files="document" accept=".pdf">

    <p x-show="$file('document')" class="text-sm text-gray-600">
        <span x-text="$file('document')?.name"></span>
        (<span x-text="$formatBytes($file('document')?.size ?? 0)"></span>)
    </p>

    <button x-show="$file('document')" @click="$clearFiles('document')">
        Remove
    </button>
</div>
```

### Upload Progress

Use the upload state magics to show progress while a file is uploading:

```html
<div x-data="{ messages: {} }">
    <input type="file" x-files="video" accept="video/*">

    <!-- Progress bar -->
    <div x-show="$uploading" class="mt-2">
        <div class="bg-gray-200 rounded-full h-2">
            <div class="bg-blue-500 h-2 rounded-full transition-all"
                 :style="'width: ' + $uploadProgress + '%'"></div>
        </div>
        <p class="text-sm text-gray-600 mt-1"
           x-text="$uploadProgress + '% uploaded'"></p>
    </div>

    <!-- Error display -->
    <p x-show="$uploadError" x-text="$uploadError"
       class="text-red-500 text-sm mt-1"></p>

    <button @click="$action('/upload/video')"
            :disabled="$uploading || $fetching()">
        <span x-show="!$uploading">Upload Video</span>
        <span x-show="$uploading">Uploading...</span>
    </button>
</div>
```

| Magic | Type | Description |
|-------|------|-------------|
| `$uploading` | `boolean` | `true` while upload is in progress |
| `$uploadProgress` | `number` | Progress percentage (0–100) |
| `$uploadError` | `string\|null` | Error message if upload failed, otherwise `null` |

### Multi-File Uploads

Add the `multiple` attribute to the file input to allow multiple file selection. Gale uses array notation (`photos[]`) when appending to FormData:

```html
<div x-data="{ messages: {} }">
    <input type="file" x-files="photos" accept="image/*" multiple>

    <!-- Show selected file list -->
    <ul x-show="$files('photos').length > 0" class="mt-2 space-y-1">
        <template x-for="file in $files('photos')">
            <li class="flex items-center gap-2 text-sm">
                <span x-text="file.name"></span>
                <span x-text="$formatBytes(file.size)" class="text-gray-500"></span>
            </li>
        </template>
    </ul>

    <button @click="$action('/upload/photos')" :disabled="$fetching()">
        Upload (<span x-text="$files('photos').length"></span> files)
    </button>
</div>
```

```php
public function uploadPhotos(Request $request): GaleResponse
{
    $request->validate([
        'photos'   => ['required', 'array', 'max:10'],
        'photos.*' => ['image', 'max:5120'],
    ]);

    $urls = [];
    foreach ($request->file('photos') as $photo) {
        $path = $photo->store('photos', 'public');
        $urls[] = Storage::url($path);
    }

    return gale()->patchState(['photoUrls' => $urls]);
}
```

### Client-Side Validation

`x-files` supports client-side validation via modifiers, dispatching a `gale:file-error` event before the file is registered when validation fails:

```html
<!-- Validate file size client-side (before uploading) -->
<input type="file" x-files="avatar"
       x-files.max-size-2mb
       accept="image/*">

<!-- Validate max file count -->
<input type="file" x-files="photos"
       x-files.max-files-5
       accept="image/*"
       multiple>
```

Listen to upload events in `init()`:

```html
<div x-data="{ fileError: '', messages: {} }"
     x-init="
        $nextTick(() => {
            $el.addEventListener('gale:file-change', e => { fileError = '' });
            $el.addEventListener('gale:file-error', e => { fileError = e.detail.message });
        })
     ">
    <input type="file" x-files="avatar" x-files.max-size-2mb accept="image/*">
    <p x-show="fileError" x-text="fileError" class="text-red-500 text-sm"></p>
</div>
```

### Server-Side File Validation

For server-side file validation in Gale, use standard Laravel validation rules (`image`, `mimes`, `max`, `dimensions`, etc.). All standard file validation rules work because Gale sends files as real `multipart/form-data`:

```php
$request->validate([
    'avatar' => [
        'required',
        'image',
        'mimes:jpeg,png,gif,webp',
        'max:2048',          // 2MB in kilobytes
        'dimensions:min_width=100,min_height=100,max_width=2000',
    ],
]);
```

> **Note:** PHP upload limits (`upload_max_filesize`, `post_max_size` in `php.ini`) apply before Laravel validation. If a file exceeds PHP's limit, the upload fails silently at the PHP level. Set these to appropriate values in your server configuration. When a PHP-level upload failure occurs, `$request->file('avatar')` returns `null` and validation will fail with a "required" error.

---

## HTML5 Form Validation Integration

When a `$action` is triggered from inside a `<form>` element, Gale integrates with the browser's built-in HTML5 validation before sending the request.

### How It Works

1. Gale detects if the triggering element is inside a `<form>`
2. If yes, Gale calls `form.checkValidity()` before sending any request
3. If any field is invalid, the browser shows its native validation tooltip
4. The Gale request is **not sent** — the user must fix the field first
5. A `gale:validation-failed` event is dispatched on the form with `{ fields: [...] }`
6. Only when all fields are valid does Gale send the request to the server

```html
<form x-data="{ email: '', messages: {} }">
    <!-- Browser enforces 'required' and valid email format -->
    <input name="email" type="email" required x-model="email">
    <span x-message="email" class="text-red-500 text-sm"></span>

    <button @click="$action('/subscribe')">Subscribe</button>
</form>
```

HTML5 validation attributes respected by Gale:

| Attribute | Effect |
|-----------|--------|
| `required` | Field must not be empty |
| `type="email"` | Must match email format |
| `type="url"` | Must match URL format |
| `type="number"` | Must be a number |
| `min` / `max` | Numeric range |
| `minlength` / `maxlength` | String length |
| `pattern` | Regex pattern |

### Bypassing Browser Validation

If you rely entirely on server-side validation or need to show a loading state immediately, add `novalidate` to the form to skip browser validation:

```html
<form x-data="{ email: '', messages: {} }" novalidate>
    <input name="email" type="email" required x-model="email">
    <span x-message="email" class="text-red-500 text-sm"></span>
    <button @click="$action('/subscribe')">Subscribe</button>
</form>
```

With `novalidate`, Gale sends the request immediately. The server's `$request->validate()` still runs and returns reactive errors.

### Custom Validation with x-validate

For cross-field validation (e.g., password confirmation) that cannot be expressed with HTML5 attributes alone, use `x-validate` on the `<form>` element:

```html
<form x-data="{ password: '', confirm: '', messages: {} }"
      x-validate="password === confirm || document.querySelector('[name=confirm]').setCustomValidity('Passwords do not match')">

    <input x-model="password" name="password" type="password">
    <input x-model="confirm"  name="confirm"  type="password"
           @input="document.querySelector('[name=confirm]').setCustomValidity('')">

    <button @click="$action('/register')">Register</button>
</form>
```

> **Tip:** Client-side validation (HTML5 or `x-validate`) is always in addition to server-side validation, never a replacement. Always validate on the server.

---

## Form Request Integration

Laravel Form Request classes work seamlessly with Gale. The same `ValidationException` auto-conversion that applies to `$request->validate()` applies to Form Requests — no changes to your Form Request class are needed.

```php
// app/Http/Requests/StoreUserRequest.php
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
```

```php
// app/Http/Controllers/UserController.php
public function store(StoreUserRequest $request): GaleResponse
{
    // $request->validated() contains only the validated data
    // ValidationException is auto-converted if validation fails
    $user = User::create($request->validated());

    return gale()->patchState(['success' => true, 'userId' => $user->id]);
}
```

The controller receives a fully validated `$request` — if validation fails, Gale intercepts and converts the `ValidationException` before the controller body runs.

### Form Request Authorization Failures

If `authorize()` returns `false`, Laravel throws an `AuthorizationException` (403). This is handled by Gale's error system and dispatched as a `gale:error` event. To show a user-friendly message, listen to that event or configure a global error handler:

```html
<div x-data="{ authError: false }"
     @gale:error.document="if ($event.detail.status === 403) authError = true">
    <p x-show="authError" class="text-red-500">
        You are not authorized to perform this action.
    </p>
</div>
```

---

## Form Patterns

### Create Form

A standard "create resource" form with validation and success handling:

**Blade template:**

```html
<div x-data="{
    name: '',
    email: '',
    role: 'user',
    messages: {},
    created: false,
}" x-sync="['name', 'email', 'role']">

    <div x-show="created" class="bg-green-50 border border-green-200 rounded p-4 mb-4">
        User created successfully!
    </div>

    <div x-show="!created">
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Name</label>
            <input x-model="name" name="name" type="text" class="input"
                   :class="{ 'border-red-500': messages.name }">
            <span x-message="name" class="text-red-500 text-sm"></span>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Email</label>
            <input x-model="email" name="email" type="email" class="input"
                   :class="{ 'border-red-500': messages.email }">
            <span x-message="email" class="text-red-500 text-sm"></span>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Role</label>
            <select x-model="role" name="role" class="input">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
            <span x-message="role" class="text-red-500 text-sm"></span>
        </div>

        <button @click="$action('/users')" :disabled="$fetching()"
                class="btn btn-primary">
            <span x-show="!$fetching()">Create User</span>
            <span x-show="$fetching()">Creating...</span>
        </button>
    </div>
</div>
```

**Controller:**

```php
public function store(Request $request): GaleResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
        'role'  => ['required', 'in:user,admin'],
    ]);

    $user = User::create($validated);

    return gale()
        ->patchState(['created' => true])
        ->clearMessages();
}
```

### Edit Form

An edit form loads existing data and saves changes:

**Controller (two actions — load and save):**

```php
public function edit(User $user): GaleResponse
{
    return gale()->patchState([
        'name'  => $user->name,
        'email' => $user->email,
        'role'  => $user->role,
        'userId' => $user->id,
    ])->view('users.edit', ['user' => $user], web: true);
}

public function update(Request $request, User $user): GaleResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        'role'  => ['required', 'in:user,admin'],
    ]);

    $user->update($validated);

    return gale()
        ->patchState(['saved' => true])
        ->clearMessages();
}
```

**Blade template:**

```html
<div x-data="{
    name: '{{ $user->name }}',
    email: '{{ $user->email }}',
    role: '{{ $user->role }}',
    userId: {{ $user->id }},
    messages: {},
    saved: false,
}" x-sync="['name', 'email', 'role']">

    <div x-show="saved" x-transition
         class="bg-green-50 border border-green-200 rounded p-3 mb-4">
        Changes saved.
    </div>

    <div class="mb-4">
        <label>Name</label>
        <input x-model="name" name="name" type="text">
        <span x-message="name" class="text-red-500 text-sm"></span>
    </div>

    <div class="mb-4">
        <label>Email</label>
        <input x-model="email" name="email" type="email">
        <span x-message="email" class="text-red-500 text-sm"></span>
    </div>

    <button @click="$action('/users/' + userId, { method: 'PUT' })"
            :disabled="$fetching()">
        Save Changes
    </button>
</div>
```

### Inline Editing

Click-to-edit a field without a full form page:

**Blade template:**

```html
<div x-data="{ editing: false, name: '{{ $user->name }}', messages: {} }"
     x-sync="['name']">

    <!-- View mode -->
    <div x-show="!editing" class="flex items-center gap-2">
        <span x-text="name" class="font-medium"></span>
        <button @click="editing = true" class="text-blue-500 text-sm">Edit</button>
    </div>

    <!-- Edit mode -->
    <div x-show="editing" class="flex items-center gap-2">
        <input x-model="name" type="text" class="input input-sm"
               @keydown.enter="$action('/users/{{ $user->id }}/name')"
               @keydown.escape="editing = false">
        <button @click="$action('/users/{{ $user->id }}/name')" :disabled="$fetching()">
            Save
        </button>
        <button @click="editing = false">Cancel</button>
    </div>

    <span x-message="name" class="text-red-500 text-sm"></span>
</div>
```

**Controller:**

```php
public function updateName(Request $request, User $user): GaleResponse
{
    $request->validate([
        'name' => ['required', 'string', 'max:255'],
    ]);

    $user->update(['name' => $request->input('name')]);

    return gale()->patchState(['editing' => false])->clearMessages();
}
```

### Search-as-You-Type

A reactive search that queries the server as the user types:

**Blade template:**

```html
<div x-data="{ query: '' }" x-sync="['query']">
    <input x-model="query" type="text" placeholder="Search users..."
           @input="$action('/users/search', { debounce: 300 })"
           class="input w-full">

    <div x-show="$fetching()" class="text-gray-500 text-sm mt-2">
        Searching...
    </div>

    @fragment('results')
    <div id="search-results" class="mt-4">
        @foreach ($results ?? [] as $user)
            <div class="py-2 border-b">
                <span class="font-medium">{{ $user->name }}</span>
                <span class="text-gray-500 text-sm">{{ $user->email }}</span>
            </div>
        @endforeach

        @if (isset($results) && $results->isEmpty())
            <p class="text-gray-500 text-sm">No users found.</p>
        @endif
    </div>
    @endfragment
</div>
```

**Controller:**

```php
public function search(Request $request): GaleResponse
{
    $query = $request->input('query', '');

    $results = $query
        ? User::where('name', 'like', "%{$query}%")
               ->orWhere('email', 'like', "%{$query}%")
               ->limit(20)->get()
        : collect();

    return gale()->fragment('users.search', 'results', compact('results', 'query'));
}
```

### Multi-Step Wizard

A multi-step form that validates each step before advancing:

**Blade template:**

```html
<div x-data="{
    step: 1,
    // Step 1 fields
    name: '',
    email: '',
    // Step 2 fields
    plan: '',
    billing: 'monthly',
    // Step 3 fields
    cardNumber: '',
    cardExpiry: '',
    messages: {},
    done: false,
}" x-sync>

    <!-- Progress indicator -->
    <div class="flex gap-2 mb-6">
        <template x-for="s in [1, 2, 3]">
            <div class="h-2 flex-1 rounded-full"
                 :class="step >= s ? 'bg-blue-500' : 'bg-gray-200'"></div>
        </template>
    </div>

    <!-- Step 1: Account Info -->
    <div x-show="step === 1">
        <h2 class="text-lg font-semibold mb-4">Step 1: Account Info</h2>

        <div class="mb-4">
            <label>Full Name</label>
            <input x-model="name" name="name" type="text"
                   :class="{ 'border-red-500': messages.name }">
            <span x-message="name" class="text-red-500 text-sm"></span>
        </div>

        <div class="mb-4">
            <label>Email</label>
            <input x-model="email" name="email" type="email"
                   :class="{ 'border-red-500': messages.email }">
            <span x-message="email" class="text-red-500 text-sm"></span>
        </div>

        <button @click="$action('/onboarding/step1')" :disabled="$fetching()">
            Next &rarr;
        </button>
    </div>

    <!-- Step 2: Plan Selection -->
    <div x-show="step === 2">
        <h2 class="text-lg font-semibold mb-4">Step 2: Choose a Plan</h2>

        <div class="mb-4">
            <label><input x-model="plan" name="plan" type="radio" value="starter"> Starter</label>
            <label><input x-model="plan" name="plan" type="radio" value="pro"> Pro</label>
            <label><input x-model="plan" name="plan" type="radio" value="enterprise"> Enterprise</label>
            <span x-message="plan" class="text-red-500 text-sm block"></span>
        </div>

        <div class="flex gap-2">
            <button @click="step = 1">&larr; Back</button>
            <button @click="$action('/onboarding/step2')" :disabled="$fetching()">
                Next &rarr;
            </button>
        </div>
    </div>

    <!-- Step 3: Payment -->
    <div x-show="step === 3">
        <h2 class="text-lg font-semibold mb-4">Step 3: Payment</h2>

        <div class="mb-4">
            <label>Card Number</label>
            <input x-model="cardNumber" name="cardNumber" type="text" maxlength="19">
            <span x-message="cardNumber" class="text-red-500 text-sm"></span>
        </div>

        <div class="flex gap-2">
            <button @click="step = 2">&larr; Back</button>
            <button @click="$action('/onboarding/complete')" :disabled="$fetching()">
                Complete Setup
            </button>
        </div>
    </div>

    <!-- Done -->
    <div x-show="done" class="text-center py-8">
        <p class="text-green-600 text-xl font-semibold">Setup complete!</p>
        <a href="/dashboard" class="btn btn-primary mt-4">Go to Dashboard</a>
    </div>
</div>
```

**Controllers:**

```php
public function step1(Request $request): GaleResponse
{
    $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
    ]);

    session(['onboarding.name' => $request->name, 'onboarding.email' => $request->email]);

    return gale()->patchState(['step' => 2])->clearMessages();
}

public function step2(Request $request): GaleResponse
{
    $request->validate([
        'plan' => ['required', 'in:starter,pro,enterprise'],
    ]);

    session(['onboarding.plan' => $request->plan]);

    return gale()->patchState(['step' => 3])->clearMessages();
}

public function complete(Request $request): GaleResponse
{
    $request->validate([
        'cardNumber' => ['required', 'string', 'min:13', 'max:19'],
    ]);

    // Create user and subscription...
    $data = session('onboarding');
    $user = User::create([
        'name'  => $data['name'],
        'email' => $data['email'],
    ]);

    return gale()->patchState(['done' => true])->clearMessages();
}
```

---

## Next Steps

- Read [Components, Events & Polling](components-events-polling.md) for event-driven patterns and server-pushed updates
- Read [Backend API Reference](backend-api.md) for all `gale()` validation and messaging methods
- Read [Frontend API Reference](frontend-api.md) for the complete `x-sync`, `x-name`, `x-message`, and `x-files` API reference
- Read [Debug & Troubleshooting](debug-troubleshooting.md) if validation errors are not displaying correctly
