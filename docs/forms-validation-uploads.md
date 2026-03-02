# Forms, Validation & Uploads

> **See also:** [Backend API Reference](backend-api.md) | [Frontend API Reference](frontend-api.md)

Handle reactive forms with server-side validation, file uploads with `$postx`, multi-step
form patterns, HTML5 validation integration, confirm dialogs, and the `x-message` directive
for displaying field-level errors.

> This guide is a placeholder. Full content is added by F-101 (Forms, Validation & Uploads Guide).

---

## Reactive Forms

Use `$post` to submit form data. Gale serializes the Alpine component state as the request body:

```html
<div x-data="{ name: '', email: '' }">
    <input x-model="name" name="name" type="text">
    <input x-model="email" name="email" type="email">
    <span x-message="name"></span>
    <span x-message="email"></span>
    <button @click="$post('/users')">Create</button>
</div>
```

---

## Server-Side Validation

Use `$request->validate()` in your controller. Gale auto-converts `ValidationException`
to a reactive state patch with validation messages — no special handling needed:

```php
public function store(Request $request): GaleResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
    ]);

    $user = User::create($validated);

    return gale()->patchState(['success' => true, 'userId' => $user->id]);
}
```

---

## Displaying Validation Errors

Use `x-message` to bind a field to its validation error:

```html
<input name="email" type="email" x-model="email">
<span x-message="email" class="text-red-500 text-sm"></span>
```

Or access all messages via `messages` state:

```html
<ul x-show="Object.keys(messages).length > 0">
    <template x-for="[field, errors] in Object.entries(messages)">
        <li>
            <span x-text="field"></span>: <span x-text="errors[0]"></span>
        </li>
    </template>
</ul>
```

---

## File Uploads

Use `$postx` with `x-files` for file uploads. `$postx` sends a `multipart/form-data` request:

```html
<div x-data="{ photo: null, messages: {} }">
    <input type="file" x-files="photo" accept="image/*">
    <img :src="photo?.previewUrl" x-show="photo">
    <span x-message="photo"></span>
    <button @click="$postx('/upload')" :disabled="$fetching">Upload</button>
</div>
```

Server-side validation for file uploads works the same as text fields:

```php
public function upload(Request $request): GaleResponse
{
    $request->validate([
        'photo' => ['required', 'image', 'max:2048'],
    ]);

    $path = $request->file('photo')->store('photos');

    return gale()->patchState(['photoUrl' => Storage::url($path)]);
}
```

---

## Multi-Step Forms

Use Alpine component state to track the current step:

```html
<div x-data="{ step: 1, name: '', email: '', plan: '', messages: {} }">
    <!-- Step 1 -->
    <div x-show="step === 1">
        <input x-model="name" name="name" placeholder="Name">
        <span x-message="name"></span>
        <button @click="$post('/onboarding/step1')">Next</button>
    </div>

    <!-- Step 2 -->
    <div x-show="step === 2">
        <input x-model="email" name="email" type="email" placeholder="Email">
        <span x-message="email"></span>
        <button @click="step = 1">Back</button>
        <button @click="$post('/onboarding/step2')">Next</button>
    </div>
</div>
```

Each step controller validates its fields and patches state to advance the step:

```php
public function step1(Request $request): GaleResponse
{
    $request->validate(['name' => ['required', 'string', 'max:255']]);

    return gale()->patchState(['step' => 2]);
}
```

---

## HTML5 Validation Integration

When `x-navigate` is on a form element, Gale integrates with the browser's native HTML5
validation. The browser validates fields before Gale submits the form. Use the `novalidate`
attribute on the form to bypass native validation and rely entirely on server-side validation:

```html
<form method="POST" action="/submit" x-navigate novalidate>
    <input name="email" type="email" required>
    <button type="submit">Submit</button>
</form>
```

---

## Confirm Dialogs

Use the `confirm` option to show a confirmation dialog before executing a request:

```html
<button @click="$post('/users/' + userId + '/delete', { confirm: 'Delete this user?' })">
    Delete
</button>
```

---

## Flash Messages

Use `gale()->flash()` to deliver flash messages to both the session and the Alpine component:

```php
return gale()->flash('success', 'Record saved successfully.')
    ->redirect(route('items.index'));
```

Display flash messages in Alpine:

```html
<div x-data="{ _flash: {} }">
    <div x-show="_flash.success" x-text="_flash.success" class="alert-success"></div>
    <div x-show="_flash.error" x-text="_flash.error" class="alert-error"></div>
</div>
```

---

## Next Steps

- Read [Components, Events & Polling](components-events-polling.md) for event-driven patterns
- Read [Backend API Reference](backend-api.md) for all `gale()` validation methods
