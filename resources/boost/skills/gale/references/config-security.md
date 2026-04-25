# Configuration & Security Reference

Complete reference for `config/gale.php`, middleware stack, CSRF, XSS protection, state checksums, and redirect security.

## config/gale.php

All keys use `config('gale.*')`. Never use `env()` outside config files.

### Transport

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `mode` | string | `'http'` | Default response mode: `'http'` (JSON) or `'sse'` (EventSource) |

### DOM & Morphing

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `morph_markers` | bool | `true` | Inject HTML comment markers for morph stability |

### Debug

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `debug` | bool | `false` | Enable `dd()`/`dump()` interception for Gale requests. When true, `GaleDumpInterceptMiddleware` captures the dump output buffer and routes it to the in-browser debug overlay (preserves page layout instead of producing the white screen of death). |
| `debug_panel` | ?bool | `null` (→ `APP_DEBUG`) | Enable the in-browser **debug panel** (collapsible side panel with Requests / State / Errors tabs). When `true` (or `null` and `APP_DEBUG=true`), the `@gale` directive injects `<script>window.GALE_DEBUG_MODE=true;</script>`, which lights up `debug.js` / `error-overlay.js` in alpine-gale. **Independent of `gale.debug`** — they control different things. |

### Performance

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `etag` | bool | `false` | Global ETag conditional responses (304 Not Modified) |

### XSS Protection

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `sanitize_html` | bool | `true` | Sanitize HTML in `gale-patch-elements` events |
| `allow_scripts` | bool | `false` | Preserve `<script>` tags in patched HTML |

### CSP

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `csp_nonce` | ?string | `null` | CSP nonce: `null` (none), `'auto'` (auto-generate), or literal string |

### Redirect Security

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `redirect.allowed_domains` | array | `[]` | Whitelisted external redirect domains |
| `redirect.allow_external` | bool | `false` | Allow all external redirects |
| `redirect.log_blocked` | bool | `true` | Log blocked redirect attempts |

### Security Headers

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `headers.x_content_type_options` | string\|false | `'nosniff'` | X-Content-Type-Options header |
| `headers.x_frame_options` | string\|false | `'SAMEORIGIN'` | X-Frame-Options header |
| `headers.cache_control` | string\|false | `'no-store, no-cache, must-revalidate'` | Cache-Control for non-SSE responses |
| `headers.custom` | array | `[]` | Custom headers on all Gale responses |

### Route Discovery

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `route_discovery.enabled` | bool | `false` | Enable PHP attribute-based route discovery |
| `route_discovery.conventions` | bool | `true` | Auto-register CRUD method names (index, show, store, etc.) |

## Middleware Aliases

Auto-registered by `GaleServiceProvider::registerMiddlewareAliases()`. Use these aliases on routes — they're shorter than full class names and stable across versions:

| Alias | Underlying class | Use for |
|-------|------------------|---------|
| `gale.checksum` | `VerifyGaleChecksum` | Explicitly enable HMAC-SHA256 state-checksum verification on a route. Useful for API routes outside the `web` group. |
| `gale.without-checksum` | `WithoutGaleChecksum` | Explicitly **disable** checksum verification on a specific route (e.g., a webhook that never sends Gale checksums). |
| `gale.pipeline` | `GalePipelineMiddleware` | Manually mount the Gale pipeline on a route group. Normally applied automatically. |
| `gale.dump-intercept` | `GaleDumpInterceptMiddleware` | Capture `dd()`/`dump()` output for the debug overlay. Auto-applied when `gale.debug` is true. |
| `gale.security-headers` | `AddGaleSecurityHeaders` | Apply Gale's security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Cache-Control`) to a non-Gale route. |

```php
// In routes/web.php or routes/api.php:
Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('gale.checksum');

Route::post('/webhooks/stripe', [WebhookController::class, 'handle'])
    ->middleware('gale.without-checksum');

Route::middleware(['web', 'gale.pipeline'])->group(function () {
    Route::post('/api/...', ...);
});
```

## Middleware Stack

Gale registers 6 middleware classes. They are applied via the `GalePipelineMiddleware` umbrella.

### 1. GalePipelineMiddleware

Umbrella middleware that runs all Gale middleware in order. Applied globally via `GaleServiceProvider`.

- Detects Gale requests via the `Gale-Request: true` header
- Runs the checksum verifier, redirect converter, security headers, and dump interceptor
- Non-Gale requests pass through untouched

### 2. VerifyGaleChecksum

HMAC-SHA256 verification of incoming state payloads.

- Every Gale request carrying Alpine state must include a `_checksum` field
- Recomputes HMAC over the submitted state using `APP_KEY`
- Returns HTTP 403 on mismatch (frontend dispatches `gale:security-error`)
- Empty body (no state) is allowed through without checksum
- Opt-out: apply `WithoutGaleChecksum` middleware or `#[WithoutGaleChecksum]` attribute

### 3. ConvertRedirectForGale

Intercepts standard Laravel `redirect()` calls and converts them for Gale requests.

- `redirect('/url')` becomes `gale()->redirect('/url')` for Gale requests
- `redirect()->back()` becomes `gale()->redirect()->back()`
- Flash data (`with()`, `withErrors()`, `withInput()`) is preserved
- Non-Gale requests pass through unchanged

### 4. AddGaleSecurityHeaders

Adds security headers to all Gale responses.

- `X-Content-Type-Options: nosniff` (configurable)
- `X-Frame-Options: SAMEORIGIN` (configurable)
- `Cache-Control: no-store, no-cache, must-revalidate` for non-SSE (configurable)
- Custom headers from `config('gale.headers.custom')`
- Set to `false` in config to disable any individual header

### 5. GaleDumpInterceptMiddleware

Captures `dd()` and `dump()` output during Gale requests.

- Only active when `config('gale.debug')` is `true`
- Intercepts output buffer and sends as `gale-debug-dump` SSE event
- In HTTP mode, adds dump data to the JSON response
- Page layout is preserved (no white screen of death from dd)
- Non-debug mode: dd/dump behave normally

### 6. WithoutGaleChecksum

Bypass middleware — disables checksum verification for specific routes.

```php
Route::post('/webhook', [WebhookController::class, 'handle'])
    ->middleware('without-gale-checksum');
```

Sets a request attribute flag that `VerifyGaleChecksum` checks.

## CSRF Protection

### How It Works

1. `@gale` directive injects a `<meta name="csrf-token">` tag
2. `$action` reads the token from the meta tag for every non-GET request
3. Token is sent as `X-CSRF-TOKEN` header
4. Laravel's standard `VerifyCsrfToken` middleware validates it

### Token Refresh

When a 419 (CSRF token mismatch) response is received:

1. **Auto strategy** (`csrfRefresh: 'auto'`): Try meta tag first, then `/sanctum/csrf-cookie`
2. **Meta strategy** (`csrfRefresh: 'meta'`): Re-read `<meta name="csrf-token">` only
3. **Sanctum strategy** (`csrfRefresh: 'sanctum'`): Fetch from `/sanctum/csrf-cookie`

After refresh, the failed request is automatically retried once.

### Configuration

```js
Alpine.gale.configureCsrf({
    tokenSelector: 'meta[name="csrf-token"]',
});
```

## State Checksum (HMAC-SHA256)

### Overview

Every state patch sent from server to client is signed with HMAC-SHA256 using a key derived from `APP_KEY`. When the client sends state back, the middleware verifies the signature.

### How It Works

**Server side** (`StateChecksum::sign()`):
1. State array is canonicalized (keys sorted recursively)
2. HMAC-SHA256 computed over JSON-encoded canonical form
3. `_checksum` field appended to state before sending

**Client side**:
1. State is received with `_checksum`
2. On next `$action`, the state (including `_checksum`) is serialized and sent back
3. Server middleware recomputes and compares

**Verification** (`StateChecksum::verify()`):
1. Extract `_checksum` from submitted state
2. Remove `_checksum` from state copy
3. Canonicalize remaining state
4. Recompute HMAC-SHA256
5. Timing-safe comparison (`hash_equals`)

### Key Derivation

```php
// StateChecksum::deriveSecret()
return hash_hmac('sha256', 'gale-state-checksum', config('app.key'));
```

Uses `APP_KEY` as base, derives a purpose-specific key via HMAC.

### Opting Out

```php
// Via middleware
Route::post('/public-endpoint', [Controller::class, 'action'])
    ->middleware('without-gale-checksum');

// Via PHP attribute
#[WithoutGaleChecksum]
public function publicAction() { ... }
```

## XSS Protection

### HTML Sanitization

Enabled by default (`config('gale.sanitize_html') = true`). Applied to all `gale-patch-elements` events.

**What it does**:
- Strips `<script>` tags (unless `allow_scripts = true`)
- Removes `on*` event handler attributes (`onclick`, `onerror`, etc.)
- Strips `javascript:` protocol URLs
- Removes `data:` URIs in dangerous contexts

**Configuration priority** (highest to lowest):
1. Runtime: `Alpine.gale.configure({ sanitizeHtml: false })`
2. Page init: `window.GALE_SANITIZE_HTML` (from `@gale` directive)
3. Built-in default: `true`

### Script Execution

`gale()->js()` bypasses the HTML sanitizer entirely. It uses the `gale-execute-script` event type which is handled separately from DOM patching. Scripts injected via `js()` can optionally include a CSP nonce:

```php
gale()->js('initMap()', ['nonce' => $nonce]);
```

### CSP Nonce Support

```blade
@gale(['nonce' => $nonce])
```

This sets `window.GALE_CSP_NONCE` which is read by the frontend. Dynamic scripts created by `gale()->js()` will include this nonce attribute.

## Redirect Security

### Server-Side (GaleRedirect)

`GaleRedirect::validateRedirectSecurity()` runs on every redirect:

1. **Protocol validation**: Blocks `javascript:`, `data:`, `vbscript:` protocols
2. **Domain validation**: External domains must be in `redirect.allowed_domains` or `redirect.allow_external` must be true
3. **Wildcard matching**: `*.stripe.com` matches `checkout.stripe.com`
4. **Logging**: Blocked redirects are logged when `redirect.log_blocked` is true

```php
// config/gale.php
'redirect' => [
    'allowed_domains' => ['*.stripe.com', 'github.com'],
    'allow_external' => false,
    'log_blocked' => true,
],
```

### Client-Side (redirect-security.js)

`gale-redirect` SSE events are validated before navigation:

1. Same-origin redirects always allowed
2. External redirects checked against `allowedDomains`
3. Blocked redirects dispatch `gale:redirect-blocked` event on document
4. `Alpine.gale.validateRedirectUrl(url)` available for manual checks

```js
Alpine.gale.configureRedirect({
    allowedDomains: ['*.stripe.com'],
    allowExternal: false,
    logBlocked: true,
});
```

### The `away()` Method

`gale()->redirect()->away($url)` bypasses domain validation for intentional external redirects. Use `redirect()->to($url)` for same-domain redirects (validated).

## Request Detection

### Gale Request Detection

GaleServiceProvider registers a `Request::macro('isGale')` that checks for the `Gale-Request: true` header.

```php
if ($request->isGale()) {
    // This is a Gale request
}
```

### Navigate Request Detection

Navigate requests include additional headers:
- `GALE-NAVIGATE: true`
- `GALE-NAVIGATE-KEY: {key}` — the navigate key for partial updates

```php
// In controller — conditional response based on navigate key
gale()->whenGaleNavigate('sidebar', function ($g) {
    return $g->fragment('layout', 'sidebar', $sidebarData);
});
```

## Response Security Headers

All Gale responses include these headers by default:

| Header | Default Value | Purpose |
|--------|---------------|---------|
| `X-Content-Type-Options` | `nosniff` | Prevent MIME type sniffing |
| `X-Frame-Options` | `SAMEORIGIN` | Prevent clickjacking |
| `Cache-Control` | `no-store, no-cache, must-revalidate` | Prevent caching of state |
| `X-Gale-Response` | `true` | Identifies Gale responses |

SSE responses use `Content-Type: text/event-stream` with `Cache-Control: no-cache`.
HTTP responses use `Content-Type: application/json`.

## ETag Support

When enabled (`config('gale.etag') = true` or per-response `gale()->etag()`):

1. Response content is hashed
2. `ETag` header is set on response
3. On subsequent requests with `If-None-Match`, returns 304 if unchanged
4. Never applied to SSE streaming responses

```php
// Per-response
gale()->etag()->state('data', $data);

// Global (config/gale.php)
'etag' => true,
```
