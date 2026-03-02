# Debug & Troubleshooting

> **See also:** [Backend API Reference](backend-api.md) | [Core Concepts](core-concepts.md)

Use the built-in Gale Debug Panel, `gale()->debug()` server helper, request/response logging,
state diff visualization, performance timing, and the error overlay. Includes a troubleshooting
guide for the most common Gale issues.

> This guide is a placeholder. Full content is added by F-103 (Debug & Troubleshooting Guide).

---

## Enabling the Debug Panel

Enable the debug panel in development by setting `debug` to `true` in your Gale config:

```php
// config/gale.php
return [
    'debug' => env('GALE_DEBUG', false),
];
```

```env
# .env
GALE_DEBUG=true
```

The debug panel appears as a floating overlay in the bottom-right corner of the browser.

---

## Debug Panel Features

The Gale Debug Panel provides:

- **Request/Response Log** — view every Gale request, its payload, and the server response
- **State Diffs** — see exactly which state keys changed after each request
- **Performance Timing** — request duration, render time, and morph time
- **Error Overlay** — detailed error context when something goes wrong

---

## Server-Side Debug Helper

Use `gale()->debug()` to send debug values to the debug panel from your controllers:

```php
public function update(Request $request): GaleResponse
{
    $data = $this->processData($request);

    // Debug values appear in the panel's "Server Debug" section
    return gale()->debug($data, $request->all())
        ->patchState(['result' => $data->toArray()]);
}
```

> **Note:** `gale()->debug()` is a no-op in production (when `app.debug` is false).

---

## Console Logging

Gale logs to the browser console at configurable verbosity levels:

```javascript
Alpine.gale.configure({
    log: 'warn',  // 'silent', 'error', 'warn', 'info', 'debug', 'verbose'
});
```

| Level | Output |
|-------|--------|
| `silent` | No console output |
| `error` | Only errors |
| `warn` | Errors and warnings |
| `info` | + Request/response summaries |
| `debug` | + State patches |
| `verbose` | + All internal events |

---

## Error Events

Gale errors dispatch on `document` as `gale:error` events. Catch them for custom error handling:

```javascript
document.addEventListener('gale:error', (e) => {
    const { type, status, message } = e.detail;

    if (status === 401) {
        window.location.href = '/login';
    } else if (status === 429) {
        alert('Too many requests. Please slow down.');
    }
});
```

---

## Common Issues & Solutions

### The page does not react to server responses

**Check:** Is `@gale` in the `<head>` of your layout?

**Check:** Is the controller returning `gale()->...` and not a bare `view()`?

```php
// Wrong
return view('my-view', $data);

// Correct
return gale()->view('my-view', $data);
```

### Validation errors do not appear

**Check:** Does the Alpine component have `messages: {}` in its `x-data`?

**Check:** Does the `x-message` directive name match the validation field name?

```html
<!-- The 'email' in x-message must match the validation key -->
<input name="email" type="email" x-model="email">
<span x-message="email"></span>
```

### State is not updating after a request

**Check:** Is the `patchState` key spelled the same as the `x-data` property?

**Check:** Open the Debug Panel and look at the State Diffs tab to see what the server sent.

### File uploads return 422 validation errors

**Check:** Are you using `$postx` (not `$post`) for file uploads?

**Check:** Is the file input bound with `x-files`?

```html
<!-- Correct: use x-files and $postx -->
<input type="file" x-files="photo">
<button @click="$postx('/upload')">Upload</button>
```

### Navigation creates a full page reload instead of SPA

**Check:** Does the link/container have `x-navigate`?

**Check:** Is the navigation target controller returning `gale()->view()` (not `view()`)?

### SSE stream does not receive events

**Check:** Is the browser EventSource connection established? Look in the Network tab for
a `text/event-stream` response.

**Check:** Is there output buffering enabled in PHP? SSE requires output buffering to be disabled.

### Alpine reactive expressions throw ReferenceError

**Check:** If a state key can be set to `null` by the server (which deletes it via RFC 7386),
use `$data.keyName` instead of the bare variable name in Alpine expressions:

```html
<!-- Wrong if 'result' can be null/deleted -->
<span x-text="result"></span>

<!-- Correct: always defined even if deleted -->
<span x-text="$data.result ?? ''"></span>
```

---

## Reporting Issues

If you encounter a bug not covered here:

1. Enable the debug panel and capture the request/response log.
2. Note the browser console errors.
3. Open an issue at [github.com/dancycodes/gale](https://github.com/dancycodes/gale) with:
   - Gale version
   - Laravel version
   - PHP version
   - Steps to reproduce
   - Debug panel output

---

## Next Steps

- Read [Backend API Reference](backend-api.md) for all `gale()->debug()` options
- Read [Core Concepts](core-concepts.md) to understand the request/response flow
