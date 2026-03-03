# Debug & Troubleshooting Reference

Complete guide for diagnosing and fixing Gale issues: debug panel, console logging, server-side debug helper, error overlay, DevTools inspection, performance diagnostics, mode-specific debugging, and server infrastructure.

---

## Table of Contents

- [Debugging Checklist](#debugging-checklist)
- [Debug Panel](#debug-panel)
- [Console Logging](#console-logging)
- [Server-Side Debug Helper](#server-side-debug-helper)
- [Error Overlay](#error-overlay)
- [Browser DevTools](#browser-devtools)
- [Performance Diagnostics](#performance-diagnostics)
- [Mode-Specific Debugging](#mode-specific-debugging)
- [Server Infrastructure (SSE)](#server-infrastructure-sse)
- [Common Issues Quick Reference](#common-issues-quick-reference)

---

## Debugging Checklist

Follow this sequence for any Gale issue:

1. **Gale loaded?** — Console shows `[Gale]` log. `@gale` in `<head>` (not `<body>`).
2. **Request sent?** — DevTools Network tab shows request on action trigger. No request = missing `x-data` or `@click`.
3. **Response correct?** — HTTP mode: `Content-Type: application/json` with `{ "events": [...] }`. SSE mode: `Content-Type: text/event-stream` with `event:` / `data:` lines.
4. **Controller returns gale()?** — Bare `view()`, `response()`, or `redirect()` won't trigger reactive updates.
5. **State keys match?** — `patchState(['count' => 1])` key must match `x-data` property exactly. Check Debug Panel > State tab.
6. **Client errors?** — Debug Panel > Errors tab, or console for `[Gale]` errors. Error Overlay appears for 5xx when `APP_DEBUG=true`.
7. **Narrow location** — Server (wrong response data), Transport (SSE buffering, proxy timeout), Client (key mismatch, expression error, morph ID collision).

---

## Debug Panel

Floating overlay in browser bottom-right. Active only in development.

### Enable
```php
// config/gale.php
'debug' => env('GALE_DEBUG', false),
```
```env
GALE_DEBUG=true
```
Run `php artisan config:clear` after changing `.env`.

### Toggle
**Ctrl+Shift+G** (Win/Linux) or **Cmd+Shift+G** (Mac). Remembers open/closed state via `localStorage`.

### Panel Not Showing?
1. `php artisan config:clear`
2. `php artisan vendor:publish --tag=gale-assets --force`
3. `npm run build`
4. Verify `@gale` is in `<head>`, not `<body>`
5. Console: `window.GALE_DEBUG_MODE` should be `true`
6. Hard refresh: Ctrl+Shift+R

### Tabs

**Requests** — Every Gale request: method, URL, status, duration, mode badge (HTTP/SSE). Click to expand full request/response.

**State** — State diffs after every server patch. Shows changed (yellow), added (green), removed (red) keys with before/after values.

> If State tab shows correct data but UI doesn't update → problem is in Alpine template (`x-text`, `x-show`, `x-model`), not the server.

**Errors** — All Gale errors with type, status, and context. Dismissed overlay errors still appear here.

### Clear Data
```javascript
Alpine.gale.debug.clear();  // in browser console
```
Auto-FIFO at 100 entries per tab.

---

## Console Logging

All entries prefixed with `[Gale]`. Filter console by `[Gale]` to isolate.

### Log Levels

| Level | What It Logs |
|-------|-------------|
| `off` | Nothing. Default in production. |
| `info` | Request summaries, navigation events, errors. Default in dev. |
| `verbose` | Everything + state diffs, DOM morph details, SSE event parsing, dispatches. |

### Set Level
```html
<!-- Before Alpine init (in <head>) -->
<script>window.GALE_DEBUG = 'verbose';</script>
```
```javascript
// At runtime
Alpine.gale.configure({ debug: { logLevel: 'verbose' } });
```

### Output Examples
```
info:    [Gale] POST /increment -> 200 (45ms)
info:    [Gale] Navigate: /dashboard
verbose: [Gale] State diff (/increment): 2 changes
verbose: [Gale] Morph: #result-list [inner] (3 changed, 1 added)
verbose: [Gale] SSE event: gale-patch-state
```

---

## Server-Side Debug Helper

`gale()->debug()` sends data from PHP to the browser's Debug Panel.

```php
// Basic
gale()->debug($result);

// Labeled (appears as separate entries)
gale()->debug($request->all(), 'Input');
gale()->debug($result->toArray(), 'Computed Result');

// In SSE streaming — emits at exact point in stream
return gale()->stream(function ($gale) {
    $gale->debug(['phase' => 'start'], 'Progress');
    $result = $this->expensiveOperation();
    $gale->debug($result->toArray(), 'Done');
    $gale->patchState(['result' => $result->formatted()]);
});
```

**Accepts any PHP value**: scalars, arrays, `JsonSerializable`, Eloquent models (via `toArray()`), closures (string repr), circular refs (truncated).

**Production safe**: No-op when `config('app.debug')` is `false`. Safe to leave in code.

> Do not pass passwords, tokens, or PII to `debug()` even with this safety guarantee.

---

## Error Overlay

Full-screen modal in development for unhandled errors.

### Triggers Overlay
- **5xx server errors** — red overlay with status, message, context, response body
- **419 CSRF expired** — red overlay with "reload the page" instruction
- **Client-side JS errors** during Gale ops — orange overlay with error + stack trace

### Does NOT Trigger
- 422 validation errors (shown via `x-message`)
- 401/403 authorization errors (handled via `gale:error` events)
- 429 rate limit (handled via `gale:error` events)

### Dismiss
Escape key, X button, backdrop click, or Dismiss button. Queued errors appear sequentially.

### Disable
```php
// config/gale.php
'error_overlay' => env('GALE_ERROR_OVERLAY', true),
```
```env
GALE_ERROR_OVERLAY=false
```
Auto-disabled in production (`APP_DEBUG=false`).

---

## Browser DevTools

### Network Tab — HTTP Mode
1. Filter by **Fetch/XHR**
2. Trigger action
3. Check response: `Content-Type: application/json`
4. Response body should be:
```json
{
  "events": [
    { "type": "gale-patch-state", "data": { "updates": { "count": 1 } } }
  ]
}
```
**If response is HTML** → controller not returning `gale()->...`

### Network Tab — SSE Mode
1. Filter by **Other**
2. Trigger SSE action: `$action('/url', {sse: true})`
3. Check: `Content-Type: text/event-stream`
4. **EventStream tab (Chrome)** shows each event as it arrives

**If EventStream missing** → server not in SSE mode. Check controller uses `gale()->stream()`.

### Console Tab
Filter by `[Gale]`. At `info` level: one-line per request. At `verbose`: expand collapsed groups for state diffs and morph details.

### Elements Panel
Watch for morph flashes when triggering actions. `verbose` mode logs: `[Gale] Morph: #my-list [inner] (2 changed)`.

---

## Performance Diagnostics

### Timing (Debug Panel > Requests)

| Phase | Measures |
|-------|----------|
| Request → TTFB | Network + server processing |
| TTFB → Response end | Response body transfer |
| State patch | RFC 7386 merge into Alpine |
| DOM morph | Element update/morph |
| Total | End-to-end |

Warning badges: Yellow >500ms TTFB, Orange >100ms morph, Red >1000ms total.

### Configure Thresholds
```javascript
Alpine.gale.configure({
    debug: {
        thresholds: {
            response: 300,  // ms (default 500)
            domMorph: 50,   // ms (default 100)
            total: 800,     // ms (default 1000)
        }
    }
});
```

### Large Responses
- Return only changed state keys, not full component state
- Use `gale()->fragment()` for partial HTML updates
- Use `patchState()` with minimal keys

### Morph Frequency
Set `verbose` logging, trigger action, count `[Gale] Morph:` lines. Multiple morphs per request = consolidate `patchElements()` calls server-side.

### Memory (Long-Running Pages)
1. DevTools > Memory > baseline heap snapshot
2. Run 50-100 polling cycles
3. Compare snapshots — look for growing component counts or detached DOM
4. Fix: ensure `x-interval` cleanup, `onMorph()` hooks unregistered, `x-init` listeners cleaned up

---

## Mode-Specific Debugging

### HTTP Mode Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Response is HTML not JSON | Controller returns `view()` | Use `gale()->view()` |
| JSON parse error in console | Server returned error page/redirect | Check Network response body, enable `APP_DEBUG=true` |
| `Content-Type: text/html` | Middleware intercepted before Gale | Check middleware order in `bootstrap/app.php` |
| Action works once then stops | CSRF token expired (419) | Reload page, check session lifetime |

### SSE Mode Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| SSE fires once, closes | Normal — SSE is per-request, not persistent | Expected behavior |
| EventStream shows no events | Proxy buffering SSE | Set `proxy_buffering off;` in Nginx |
| Events arrive in batches | PHP output buffering | Use `gale()->stream()` (handles automatically) |
| Connection drops every 30s | Load balancer timeout | Increase idle timeout |
| State patches received but no update | Component name mismatch | Check `x-component` matches `patchComponent()` |
| 422 in SSE but form is correct | Validation needs HTTP response | `gale()->messages($errors)->forceHttp()` |

---

## Server Infrastructure (SSE)

### Nginx — Disable Buffering
```nginx
location / {
    proxy_buffering off;
    proxy_cache off;
    proxy_set_header X-Accel-Buffering no;
    proxy_set_header Connection '';
    proxy_http_version 1.1;
    chunked_transfer_encoding on;
}
```
`gale()->stream()` sets `X-Accel-Buffering: no` automatically.

### Apache — Disable Buffering
```apache
<Location />
    ProxyPass http://your-backend/
    SetEnv proxy-nokeepalive 1
    SetEnv proxy-initial-not-pooled 1
</Location>
```

### Load Balancer Timeouts
- **AWS ALB**: Target group idle timeout → 3600s
- **HAProxy**: `timeout tunnel 1h`
- **Nginx LB**: `proxy_read_timeout 3600s;`
- **Laravel Herd**: No config needed — handles SSE correctly

### PHP-FPM
```ini
# /etc/php/8.4/fpm/pool.d/www.conf
request_terminate_timeout = 3600
```
`gale()->stream()` calls `set_time_limit(0)` automatically.

### CSP (Content Security Policy)
```blade
@gale(['nonce' => $nonce])
```
Same-origin SSE needs no extra CSP. Cross-origin:
```
Content-Security-Policy: connect-src 'self' https://api.example.com;
```

---

## Common Issues Quick Reference

| # | Symptom | Fix |
|---|---------|-----|
| 1 | Nothing happens on click | Wrap in `<div x-data="{ ... }">` — Gale needs Alpine context |
| 2 | Page doesn't react to responses | Return `gale()->view()` not `view()` |
| 3 | State doesn't update | Key name mismatch — check Debug Panel > State tab |
| 4 | Validation errors don't appear | Add `messages: {}` to `x-data`, check `x-message` field name |
| 5 | File upload 422 | Use `$action()` (includes CSRF) not `$post()` for uploads |
| 6 | Full reload instead of SPA | Add `x-navigate` to link/container, target must return `gale()->view()` |
| 7 | SSE receives no events | Nginx buffering — set `proxy_buffering off;` |
| 8 | `ReferenceError` in Alpine | State key deleted by `null` — use `$data.key ?? ''` |
| 9 | `$action` no network request | Missing `x-data`, or earlier JS error stopped Alpine |
| 10 | UI wrong despite correct state | Template expression error — check `x-text`/`x-show`/`x-model` |
| 11 | SSE drops, no reconnect | Load balancer/proxy timeout — increase timeout |
| 12 | `debug()` entries missing | Both `APP_DEBUG=true` AND `GALE_DEBUG=true` required |
| 13 | CSRF 419 on every request | Reload page, check session lifetime |
| 14 | Console log spam | Set `Alpine.gale.configure({ debug: { logLevel: 'info' } })` |
| 15 | Error overlay blocks UI | Press Escape to dismiss. Fix underlying 5xx. Or `GALE_ERROR_OVERLAY=false` |
