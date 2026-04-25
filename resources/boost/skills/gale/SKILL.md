---
name: gale
description: >
  Build and debug Laravel apps using dancycodes/gale — a server-driven reactive framework
  for Laravel built on Alpine.js. Transport is HTTP JSON by default with SSE streaming as
  opt-in (per-action `{ sse: true }`, `gale()->stream()`, or global config). Use when working
  with controllers or Blade that involve gale(), $action, x-navigate, x-message, x-files,
  x-component, x-sync, x-loading, x-indicator, x-confirm, x-interval, x-lazy, x-listen,
  x-validate, x-prefetch, x-dirty, $gale, $fetching, $dirty, $navigate, $components, or @gale.
  Also for Gale-specific symptoms: validation errors not displaying inline, $action returning
  419/CSRF errors, fragment not rendering, state not patching, action not firing, checksum
  mismatch, debug panel issues, agents trying to "npm install alpine-gale" (it's internal —
  never install separately). Skip for plain Laravel without Gale.
---

# Gale Development Skill

## What is Gale

Gale is a server-driven reactive framework for Laravel that uses Alpine.js as its client runtime.
Controllers return `gale()->...` responses that patch Alpine state, morph DOM elements, execute
JavaScript, dispatch events, trigger downloads, and navigate pages — all without full page reloads.
The transport is HTTP JSON by default with SSE streaming available via opt-in.

## Installation

```bash
composer require dancycodes/gale
php artisan gale:install
```

`gale:install` publishes the bundled JS/CSS to `public/vendor/gale/`. Then add `@gale` to your
layout `<head>` (replaces any Alpine CDN script tag):

```blade
<head>
    @gale
    {{-- Or with a CSP nonce: @gale(['nonce' => $nonce]) --}}
</head>
```

> **DO NOT `npm install alpine-gale`.** alpine-gale is **private/internal** — it's bundled into
> `vendor/gale/js/gale.js` and loaded automatically by `@gale`. There is no public npm package
> to install. The npm version exists only as a build artifact for monorepo tag synchronization.
> Agents that try to `npm install alpine-gale` are wrong.

To upgrade: `composer update dancycodes/gale && php artisan gale:install --force`. See
`references/installation.md` for the full setup, CSP details, and verification steps.

## Artisan Commands

| Command | Purpose |
|---------|---------|
| `php artisan gale:install` | Publish JS/CSS assets to `public/vendor/gale/` |
| `php artisan gale:routes [--method=] [--path=] [--name=] [--controller=] [--json]` | List routes registered by Gale's attribute-based discovery |

## Middleware Aliases

Auto-registered by `GaleServiceProvider`. Use these on routes instead of full class names:

| Alias | Purpose |
|-------|---------|
| `gale.checksum` | Verify state checksum (HMAC-SHA256) |
| `gale.without-checksum` | Opt out of checksum verification on a specific route |
| `gale.pipeline` | Coordinated request pipeline middleware |
| `gale.dump-intercept` | Capture `dd()`/`dump()` output for the debug panel |
| `gale.security-headers` | Add Gale's security headers to a response |

```php
Route::post('/api/orders', OrderController::class)->middleware('gale.checksum');
Route::post('/webhook/stripe', WebhookController::class)->middleware('gale.without-checksum');
```

## Required Reading — Match Your Task to a Reference

BEFORE writing any code, identify your task below and read the corresponding reference file.
Skipping this step leads to incorrect patterns, missing options, and subtle bugs.

| Your Task | Read FIRST | Why |
|-----------|-----------|-----|
| Installing or upgrading Gale in a project | `references/installation.md` | Composer/artisan/Blade setup, CSP, "do NOT npm install alpine-gale" |
| Writing a controller or building a response | `references/backend-api.md` | Every gale() method with full signatures, options, and gotchas |
| Writing Blade templates or using $action | `references/frontend-api.md` | All directive/magic options, $action config, Alpine.gale API |
| Building a CRUD feature, form, or component | `references/patterns.md` | Complete real-world patterns you must follow, not reinvent |
| Debugging errors or unexpected behavior | `references/debug-testing.md` | Debug panel, dd/dump interception, error overlay, test helpers |
| SPA navigation, history, or morph issues | `references/navigation-morph.md` | Navigate modes, morph strategies, history cache, transitions |
| Performance tuning or architecture decisions | `references/best-practices.md` | Decision trees, performance rules, validation display patterns |
| Security, middleware, CSRF, or config | `references/config-security.md` | Full middleware stack, CSRF handling, XSS protection, CSP |
| Integrating third-party JS (GSAP, editors) | `references/js-compat.md` | Plugin system, library-specific integration patterns |
| Setting up attribute-based routing | `references/route-discovery.md` | #[Route], #[Group], discovery config, conventions |

Multiple tasks? Read multiple references. When in doubt, read `backend-api.md` + `frontend-api.md`.

## Golden Rules

These are non-negotiable. Violating any of them produces broken behavior.

1. **Every controller returns `gale()->...`** — never bare `view()`, `response()`, or `json()`.
   For pages needing direct URL access, use `gale()->view('name', $data, [], web: true)`.

2. **`@gale` in `<head>`** — replaces the Alpine CDN script tag. Provides Alpine + Gale plugin
   + CSRF meta + security config globals. Use `@gale(['nonce' => $nonce])` for CSP.

3. **`x-data` required on every interactive element** — Gale state patches target Alpine
   components. No `x-data` means no reactivity.

4. **`gale()` is a request-scoped singleton** — calling `gale()` anywhere in a request returns
   the same `GaleResponse` instance. Events accumulate across multiple calls. The instance
   resets automatically after `toResponse()`.

5. **`ValidationException` auto-converts to messages state** — `$request->validate()`
   failures are caught by `GaleMessageException` and rendered as `gale()->state('messages', [...])`
   (i.e. `gale()->messages([...])`). No manual error handling needed. **The data lands in
   `messages` state — NOT `errors` state.** This determines which directive you use to display
   it (see Golden Rule #7).

6. **`redirect()` auto-converts** — standard Laravel `redirect()` calls are intercepted by
   `ConvertRedirectForGale` middleware and converted to `gale()->redirect()` for Gale requests.

7. **Validation errors MUST display inline via `x-message="fieldname"`** — every validated
   form field needs `<span x-message="fieldname">` adjacent to its input. This reads from
   `messages` state, where `$request->validate()` auto-conversion writes. Use
   `<span x-message.from.errors="fieldname">` ONLY when controllers explicitly call
   `gale()->errors([...])` (which writes to `errors` state). Success messages use
   `<span x-message="_success">` or `dispatch('show-toast', ...)`. Never show per-field
   errors in a global container or toast.

8. **Client-first, server-minimal, response-surgical** — if Alpine can handle it locally
   (toggle, counter, filter, sort), do NOT round-trip to the server. When the server IS needed,
   send the minimum payload (`include`, `exclude`, `delta`). Return the minimum response
   (`state` over `fragment`, `fragment` over `view`, `componentState` over broadcast).

9. **ALWAYS debounce text inputs, ALWAYS use `include` for forms** — search/filter inputs
   MUST use `debounce: 300`. Forms with 3+ fields MUST use `include` to send only relevant
   state. Polling intervals >= 5000ms for non-critical data. See `best-practices.md` for the
   full performance ruleset.

## Design Philosophy

**Client-first, server-minimal, response-surgical.** Alpine handles local state; the server
handles persistence and authority. Every byte sent should be justified; every byte returned
should be surgical. See `best-practices.md` for the full decision framework.

## Dual-Mode Architecture

| Aspect | HTTP Mode (Default) | SSE Mode (Opt-in) |
|--------|-------------------|------------------|
| Content-Type | `application/json` | `text/event-stream` |
| Response format | `{ events: [...] }` | SSE event stream |
| When to use | Most endpoints | Long-running ops, real-time |
| Activate | Default / `{ http: true }` | `{ sse: true }` / `gale()->stream()` |

**Mode resolution priority** (highest to lowest):
1. `gale()->stream()` — always SSE
2. Per-action option: `$action(url, { sse: true })`
3. `Gale-Mode` request header
4. `Alpine.gale.configure({ defaultMode: 'sse' })`
5. `config('gale.mode')` — server default
6. Built-in default: `'http'`

## Backend Quick Reference — gale() Methods

### State Management
| Method | Description |
|--------|-------------|
| `state($key, $value)` | Set single state property |
| `state(['k' => 'v', ...])` | Set multiple state properties |
| `messages(['field' => 'msg'])` | Set validation messages (for x-message) |
| `clearMessages()` | Clear all messages |
| `errors(['field' => ['msg1']])` | Set validation errors (for x-message.from.errors) |
| `clearErrors()` | Clear all validation errors |
| `forget('key')` / `forget(['k1','k2'])` | Delete state keys (RFC 7386 null) |
| `flash('key', 'value')` | Session flash + immediate `_flash` state |
| `flash(['k' => 'v'])` | Flash array of data |
| `componentState('name', ['k'=>'v'])` | Patch named component state |
| `tagState('tag', ['k'=>'v'])` | Patch all components with tag |
| `componentMethod('name', 'method', $args)` | Invoke method on named component |
| `patchStore('store', ['k'=>'v'])` | Patch Alpine.store() |

### DOM Manipulation
| Method | Description |
|--------|-------------|
| `view('blade.name', $data, $opts, web: true)` | Render full view |
| `fragment('view', 'name', $data, $opts)` | Render @fragment only |
| `fragments([...configs])` | Multiple fragments |
| `html($html, $opts)` | Patch raw HTML |
| `outer('#sel', $html)` | Replace element (DEFAULT mode) |
| `inner('#sel', $html)` | Replace inner content |
| `outerMorph('#sel', $html)` | Smart morph (preserves client state) |
| `innerMorph('#sel', $html)` | Smart morph inner only |
| `morph('#sel', $html)` | Alias for outerMorph |
| `replace('#sel', $html)` | Alias for outer |
| `append('#sel', $html)` | Append as last child |
| `prepend('#sel', $html)` | Prepend as first child |
| `before('#sel', $html)` | Insert before element |
| `after('#sel', $html)` | Insert after element |
| `remove('#sel')` / `delete('#sel')` | Remove element |

### Navigation & Redirect
| Method | Description |
|--------|-------------|
| `navigate($url, $key, $opts)` | SPA navigation with history |
| `navigateMerge($url, $key)` | Navigate merging query params |
| `navigateClean($url, $key)` | Navigate with clean params |
| `navigateOnly($url, ['p1'], $key)` | Navigate keeping only listed params |
| `navigateExcept($url, ['p1'], $key)` | Navigate excluding listed params |
| `navigateReplace($url, $key)` | Navigate with replaceState |
| `updateQueries(['k'=>'v'])` | Update query params in-place |
| `clearQueries(['k1','k2'])` | Clear specific query params |
| `redirect('/url')` | Full-page redirect (returns GaleRedirect) |
| `redirect()->back()` | Redirect to previous URL |
| `redirect()->route('name')` | Redirect to named route |
| `redirect()->away($url)` | Redirect to external URL |
| `reload()` | Force full page reload |

### Streaming & Advanced
| Method | Description |
|--------|-------------|
| `stream(fn($gale) => ...)` | SSE streaming for long operations |
| `js($script, $opts)` | Execute JavaScript in browser |
| `dispatch('event', $data, '#target')` | Dispatch CustomEvent |
| `download($path, 'file.pdf')` | Trigger file download |
| `push('channel')` | Get push channel broadcaster |
| `web($response)` | Set non-Gale fallback response |
| `when($cond, $cb, $else)` | Conditional chaining |
| `unless($cond, $cb)` | Inverse conditional |
| `whenGale($cb, $else)` | Execute only for Gale requests |
| `whenGaleNavigate($key, $cb)` | Execute for navigate requests |
| `withHeaders(['H' => 'v'])` | Add response headers |
| `etag()` | Enable ETag conditional response |
| `debug($data)` / `debug('label', $data)` | Send debug data to panel |

Full method signatures, all options, edge cases, and return types: see `references/backend-api.md`.

## Frontend Quick Reference — Magics

| Magic | Description |
|-------|-------------|
| `$action(url, opts)` | POST request (default) |
| `$action.get(url, opts)` | GET request |
| `$action.post(url, opts)` | POST request |
| `$action.put(url, opts)` | PUT request |
| `$action.patch(url, opts)` | PATCH request |
| `$action.delete(url, opts)` | DELETE request |
| `$gale` | Global connection state (`loading`, `errors`, `online`, `retrying`) |
| `$fetching` | Per-**x-data-scope** loading boolean — true when ANY `$action` in the same `x-data` is in flight. Use `x-indicator` for button-specific loading in multi-action components |
| `$navigate(url, opts)` | Programmatic SPA navigation |
| `$file('name')` | Single file info from x-files input |
| `$files('name')` | Array of file info from x-files input |
| `$filePreview('name', idx)` | Preview URL for uploaded file |
| `$clearFiles('name')` | Clear file input |
| `$formatBytes(size)` | Human-readable file size |
| `$uploading` | Boolean: upload in progress |
| `$uploadProgress` | Number: upload percentage |
| `$uploadError` | String or null: upload error |
| `$dirty()` | Boolean: any property changed |
| `$dirty('prop')` | Boolean: specific property changed |
| `$lazy(url, opts)` | Programmatic lazy load |
| `$listen('channel')` | Subscribe to push channel |
| `$components` | Component registry (see reference) |

Full $action options object, global config, error handling, and SSE mode: see `references/frontend-api.md`.

## Frontend Quick Reference — Directives

| Directive | Description |
|-----------|-------------|
| `x-navigate` | SPA link/form navigation |
| `x-navigate.replace` | Navigate with replaceState |
| `x-navigate.key="name"` | Navigate key for partial updates |
| `x-message="field"` | Display message for field — **reads from `messages` state** (where `$request->validate()` writes) |
| `x-message.from.errors="field"` | Display message for field — reads from `errors` state (where explicit `gale()->errors([...])` writes) |
| `x-component="name"` | Register component in registry |
| `x-files` | Mark file input for upload system |
| `x-name="prop"` | Auto-bind form element to state |
| `x-sync="prop"` | Two-way state sync with server |
| `x-loading` | Show/hide during $action requests |
| `x-indicator="varName"` | **Element + children scoped** boolean toggled while a `$action` from this element/descendants is in flight (default name `loading`) |
| `x-confirm="msg"` | Confirmation dialog before action |
| `x-interval="ms"` | Polling at interval |
| `x-interval.sse="ms"` | SSE polling at interval |
| `x-dirty` | Show when component is dirty |
| `x-lazy="/url"` | Load content when visible |
| `x-lazy.sse="/url"` | Load via SSE when visible |
| `x-listen="channel"` | Subscribe to push channel |
| `x-validate` | HTML5 form validation integration |
| `x-prefetch` | Prefetch link on hover |

Full directive modifiers, attribute syntax, and interaction examples: see `references/frontend-api.md`.

## Blade Directives

```blade
{{-- In <head> — loads Alpine + Gale plugin + config --}}
@gale
@gale(['nonce' => $nonce])

{{-- Named fragment for partial rendering --}}
@fragment('item-list')
  <div id="items">...</div>
@endfragment

{{-- Conditional: is this a Gale request? --}}
@ifgale
  {{-- Gale-specific markup --}}
@else
  {{-- Standard HTML --}}
@endifgale

{{-- @galeState — DEPRECATED. Inject initial state into window.galeState. --}}
{{-- Prefer putting initial state directly in x-data="{...}". --}}
@galeState(['count' => 0])
```

## Config Keys — config/gale.php

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `mode` | string | `'http'` | Default response mode: `'http'` or `'sse'` |
| `morph_markers` | bool | `true` | Inject HTML comment markers for morph stability |
| `debug` | bool | `false` | Enable `dd()`/`dump()` interception for Gale requests |
| `debug_panel` | ?bool | `null` (→ `APP_DEBUG`) | Enable in-browser debug panel; sets `window.GALE_DEBUG_MODE`. Independent of `debug`. |
| `etag` | bool | `false` | Global ETag conditional responses |
| `sanitize_html` | bool | `true` | XSS sanitize HTML in gale-patch-elements |
| `allow_scripts` | bool | `false` | Preserve `<script>` tags in patched HTML |
| `csp_nonce` | ?string | `null` | CSP nonce: `null`, `'auto'`, or string |
| `redirect.allowed_domains` | array | `[]` | Whitelisted external redirect domains |
| `redirect.allow_external` | bool | `false` | Allow all external redirects |
| `redirect.log_blocked` | bool | `true` | Log blocked redirect attempts |
| `headers.x_content_type_options` | string\|false | `'nosniff'` | X-Content-Type-Options header |
| `headers.x_frame_options` | string\|false | `'SAMEORIGIN'` | X-Frame-Options header |
| `headers.cache_control` | string\|false | `'no-store...'` | Cache-Control for non-SSE |
| `headers.custom` | array | `[]` | Custom headers on all Gale responses |
| `route_discovery.enabled` | bool | `false` | Enable attribute-based route discovery |
| `route_discovery.conventions` | bool | `true` | Auto-register CRUD method names |

## Common Anti-Patterns

1. **WRONG**: `return view('page')` — CORRECT: `return gale()->view('page', $data, [], web: true)`
2. **WRONG**: `return response()->json($data)` — CORRECT: `return gale()->state($data)`
3. **WRONG**: Passing full page data to `fragment()` — CORRECT: Only pass data the fragment needs
4. **WRONG**: `gale()->redirect('/url')->state('k','v')` — redirect is terminal, state is lost
5. **WRONG**: Multiple `navigate()` calls in one response — only one allowed per response
6. **WRONG**: Using `gale()` outside request context — it is request-scoped
7. **WRONG**: `echo` inside `stream()` — use `$gale->state()` etc. instead
8. **WRONG**: Missing `x-data` on elements expecting state patches — patches silently fail
9. **WRONG**: `@gale` in `<body>` — must be in `<head>` for proper initialization
10. **WRONG**: Using `env()` in controllers — use `config('gale.*')` instead
11. **WRONG**: `$action('/increment')` for `count + 1` — CORRECT: `@click="count++"` (pure client arithmetic needs no server)
12. **WRONG**: Single `<div id="errors">` with all errors concatenated — CORRECT: `<span x-message="email">` per field (auto-validation writes to `messages` state). Use `.from.errors` only when controllers explicitly call `gale()->errors([...])`.
13. **WRONG**: `$action('/search')` without debounce on text input — CORRECT: `$action.get('/search', { debounce: 300, include: ['query'] })`
14. **WRONG**: Sending full `x-data` when only 1 field matters — CORRECT: `$action('/url', { include: ['field'] })`
15. **WRONG**: `x-interval="1000"` for non-critical data — CORRECT: `x-interval="5000"` minimum; use push channels for true real-time
16. **WRONG**: `:disabled="$fetching"` on a button in a component with multiple `$action` triggers — `$fetching` is per-x-data-scope, so ANY action disables ALL buttons. CORRECT: Use `x-indicator="adding"` on the button and `:disabled="adding"` for truly local loading state.
17. **WRONG**: Button only shows `:disabled="$fetching"` with no spinner — users can't tell if the action is processing. CORRECT: Every action button must swap text for a spinner during loading.

For the full decision framework on when to use state vs fragment vs view, debounce rules, and polling limits: see `references/best-practices.md`.
