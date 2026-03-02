# Gale Documentation

**Laravel Gale** is a server-driven reactive framework for Laravel. It delivers real-time UI
updates to Alpine.js components using standard HTTP JSON responses (default) or Server-Sent
Events (SSE, opt-in). No JavaScript framework, no build complexity, no API layer required.

> This file is the documentation table of contents. Each guide below covers a specific area
> of Gale. If you are new to Gale, start with [Getting Started](getting-started.md).

---

## Quick Links

| Task | Guide |
|------|-------|
| Install and configure Gale | [Getting Started](getting-started.md) |
| Understand the dual-mode architecture | [Core Concepts](core-concepts.md) |
| Use `gale()` helper, fragments, redirects | [Backend API Reference](backend-api.md) |
| Use `$get`, `$post`, `$action`, `$navigate` | [Frontend API Reference](frontend-api.md) |
| Build SPA navigation and history | [Navigation & SPA](navigation.md) |
| Handle forms, validation, and file uploads | [Forms, Validation & Uploads](forms-validation-uploads.md) |
| Use components, events, and polling | [Components, Events & Polling](components-events-polling.md) |
| Debug Gale requests and troubleshoot issues | [Debug & Troubleshooting](debug-troubleshooting.md) |

---

## Essentials

### [Getting Started](getting-started.md)

Install Gale, configure Alpine.js via `@gale`, write your first reactive controller, and run
the example application. Covers requirements, installation steps, and a quick-start example.

### [Core Concepts](core-concepts.md)

Understand the dual-mode architecture (HTTP JSON vs SSE), the request/response flow, RFC 7386
JSON Merge Patch state management, and how Alpine.js integrates with server-driven reactivity.

---

## Guides

### [Backend API Reference](backend-api.md)

Complete reference for the `gale()` helper and `GaleResponse` builder: `view()`, `fragment()`,
`patchState()`, `patchElements()`, `patchComponent()`, `redirect()`, `download()`, `stream()`,
`messages()`, `flash()`, `debug()`, and all configuration options.

### [Frontend API Reference](frontend-api.md)

Complete reference for Alpine Gale magics and directives: `$get`, `$post`, `$postx`, `$action`,
`$navigate`, `$gale`, `$fetching`, `x-navigate`, `x-component`, `$components`, and all
configuration options for `Alpine.gale.configure()`.

### [Navigation & SPA](navigation.md)

Build SPA-style navigation with `x-navigate`, `$navigate`, and `POST` form navigation. Covers
the PRG pattern, history cache, link prefetching, scroll restoration, and the `gale:navigate:*`
event system.

### [Forms, Validation & Uploads](forms-validation-uploads.md)

Handle reactive forms with server-side validation, file uploads with `$postx`, multi-step
form patterns, HTML5 validation integration, confirm dialogs, and the `x-message` directive
for displaying field-level errors.

### [Components, Events & Polling](components-events-polling.md)

Use named components with `x-component` and `$components`, dispatch and listen to `gale:*`
events, integrate Alpine `$dispatch`, implement polling with debounce/throttle options, use
Alpine Store patching, and leverage the Gale plugin/extension system.

### [Debug & Troubleshooting](debug-troubleshooting.md)

Use the built-in Gale Debug Panel, `gale()->debug()` server helper, request/response logging,
state diff visualization, performance timing, and the error overlay. Includes a troubleshooting
guide for the most common Gale issues.

---

## Contributing to Docs

When adding or updating documentation, follow these conventions:

### Heading Hierarchy

```
# H1 — Document title (one per file)
## H2 — Major sections
### H3 — Subsections
#### H4 — Only when strictly necessary
```

### Code Blocks

Always use fenced code blocks with a language identifier:

```php
// PHP example
gale()->view('my-view')->patchState(['count' => 1]);
```

```javascript
// JavaScript example
await $post('/increment');
```

```html
<!-- HTML/Blade example -->
<div x-data="{ count: 0 }">
    <button @click="$post('/increment')">+</button>
</div>
```

```bash
# Shell example
php artisan vendor:publish --tag=gale-assets --force
```

### Cross-References

Use relative links between docs files:

```markdown
See the [Backend API Reference](backend-api.md) for all `gale()` methods.
```

### Admonitions

Use blockquotes with a bold label for notes, warnings, and tips:

```markdown
> **Note:** This behavior only applies in SSE mode.

> **Warning:** Never use `view()` directly — always use `gale()->view()`.

> **Tip:** Use `gale()->debug()` during development to inspect state changes.
```

### Update the TOC

When you add a new guide, add an entry to this README under the appropriate section
(Essentials or Guides) with the filename, title, and a one-sentence description.
