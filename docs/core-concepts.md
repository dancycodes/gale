# Core Concepts

> **See also:** [Getting Started](getting-started.md) | [Backend API Reference](backend-api.md) | [Frontend API Reference](frontend-api.md)

This guide explains the fundamental concepts behind Gale: dual-mode architecture, the
request/response flow, RFC 7386 JSON Merge Patch state management, and Alpine.js integration.

> This guide is a placeholder. Full content is added by F-097 (Core Concepts Guide).

---

## Dual-Mode Architecture

Gale supports two transport modes:

| Mode | Default | How to Enable | Best For |
|------|---------|---------------|----------|
| HTTP JSON | Yes | Default | Standard interactions, forms, navigation |
| SSE | No | `{ sse: true }` or global config | Long-running ops, real-time streaming |

HTTP mode returns a JSON response:

```json
{
    "events": [
        { "type": "gale-patch-state", "data": { "count": 1 } }
    ]
}
```

SSE mode returns a `text/event-stream` response with the same events as SSE messages.

---

## The Request/Response Flow

```
Browser (Alpine.js + Gale plugin)
    ↓ HTTP request with serialized x-data state
Laravel Controller
    ↓ Returns gale()->patchState([...])
SSE or JSON Response
    ↓ Alpine component receives and merges state
Browser updates reactively via RFC 7386 JSON Merge Patch
```

---

## RFC 7386 JSON Merge Patch

Gale uses JSON Merge Patch (RFC 7386) to update Alpine component state:

- Setting a key updates it: `{ "count": 5 }` sets `count = 5`
- Setting a key to `null` removes it from the component state
- Nested objects are merged recursively

```javascript
// Current state
{ count: 3, user: { name: "Alice" } }

// Patch from server
{ count: 5, user: { role: "admin" } }

// Result
{ count: 5, user: { name: "Alice", role: "admin" } }
```

---

## The Main Law

Every controller action that responds to a Gale request MUST return `gale()->...`.
Never return a bare `view()` for a Gale-aware route.

```php
// Correct
return gale()->view('my-view', ['data' => $data]);

// Wrong — breaks reactive state management
return view('my-view', ['data' => $data]);
```

---

## Auto-Conversion Rules

Gale automatically converts standard Laravel responses for Gale requests:

- `redirect()` → converted to a `gale-execute-script` event that updates `window.location`
- `ValidationException` → converted to a `gale-patch-state` event with validation messages
- `request()->validate()` → works reactively without any special handling

---

## Next Steps

- Read [Backend API Reference](backend-api.md) for the complete `gale()` API
- Read [Frontend API Reference](frontend-api.md) for Alpine Gale magics and directives
- Read [Navigation & SPA](navigation.md) for SPA navigation patterns
