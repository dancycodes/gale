# Changelog

All notable changes to `dancycodes/gale` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-03-03

Production-readiness overhaul of the Gale Laravel package. This release resolves all known
bugs from the 0.x series, hardens security, adds a full suite of missing capabilities, improves
Laravel standards integration, introduces a built-in debug panel, and ships comprehensive
documentation and a Pest test suite.

**Companion package:** requires `dancycodes/alpine-gale` ^0.5.0

### Added

#### Debug Tooling
- `gale()->debug(mixed $value, string $label)` server helper — sends debug values to the
  browser debug panel during development (F-076)
- `gale()->stream()` debug overlay — captures `dd()` and `dump()` output and forwards it to
  the browser error overlay when called inside Gale controllers (F-057)
- Request/response logger — structured logging of all Gale requests and responses with request
  ID correlation; configurable via `config('gale.debug.log_requests')` (F-072)
- Server-Timing response headers — timing data for each stage of Gale request processing;
  visible in browser DevTools Network panel (F-074)
- Error overlay — development-mode overlay captures PHP exceptions, stack traces, and Gale
  request context; replaces blank page on uncaught exceptions (F-075)

#### Core Capabilities
- `gale()->download(path, filename, headers)` — triggers a client-side file download from
  inside a Gale response without leaving the current page (F-039)
- `gale()->flash(key, value)` — delivers flash data to both Laravel session and Alpine `_flash`
  reactive state in a single call; accepts string key/value or associative array (F-061)
- `gale()->patchStore(storeName, data)` — patches a named Alpine store from server responses;
  uses `gale-patch-store` event type (F-051)
- `gale()->dispatch(eventName, detail)` — triggers an Alpine `$dispatch` event on the client
  from a server response; uses `gale-dispatch` event type (F-054)
- `GaleResponse::macro()` — register custom response types as macros on `GaleResponse`;
  usable from any controller via `gale()->myMacro()` (F-064)
- `@morphKey('id')` Blade directive — emits a stable `data-morph-key` attribute for use by
  the Alpine Gale morph engine on dynamic list items (F-048)

#### Security
- State checksum verification — `GaleChecksumMiddleware` validates HMAC-signed state on every
  Gale request; prevents client-side state tampering; opt-out via `WithoutGaleChecksum`
  middleware or `config('gale.security.checksum.enabled', false)` (F-013)
- Input sanitization — server-side sanitization of SSE event data before emission; strips
  disallowed HTML patterns from patch payloads (F-019)
- Redirect security — `GaleRedirect` validates redirect URLs against an allowlist of domains;
  configurable via `config('gale.redirect.allowed_domains')` (F-020)
- Security response headers — `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  and appropriate `Cache-Control` headers on all Gale SSE responses (F-022)
- Authentication state detection — 401 responses dispatch `gale:unauthenticated` browser event
  with request context for client-side redirect handling (F-021)
- CSP compatibility — all Gale-injected scripts moved to external files; no dynamic code
  execution in production; nonce support via `config('gale.security.csp_nonce')` (F-018)

#### Laravel Standards Integration
- `abort()` integration — `abort(403)`, `abort(404)`, `abort(500)`, etc. inside Gale
  controllers now trigger structured client-side error handling instead of breaking the stream;
  debug mode renders the Ignition error page, production mode dispatches `gale:error` (F-059)
- Form Request class integration — Form Request classes work natively with Gale requests;
  authorization failures and validation errors auto-convert to reactive messages (F-063)
- Rate limiting awareness — 429 responses dispatch `gale:rate-limited` event with
  `{ retryAfter }` detail parsed from the `Retry-After` header (F-017)
- Middleware compatibility — `WithoutGaleChecksum` opt-out middleware; Gale middleware
  correctly integrates with Laravel's global and route middleware stacks (F-060)

#### Route Discovery Enhancements
- `#[Middleware('name')]` standalone attribute — repeatable, accepts variadic arguments;
  class-level middleware stacks before method-level middleware (F-067)
- `#[RateLimit(maxAttempts, decayMinutes)]` attribute — translates to `throttle:N,M`
  middleware; `#[RateLimit(limiter: 'name')]` uses named rate limiters (F-067)
- `#[Group(prefix:, middleware:, as:, domain:)]` attribute — controller-level route grouping;
  conflict detection with `#[Prefix]` raises `LogicException` (F-068)
- Convention-based CRUD method auto-discovery — `index`, `show`, `create`, `store`, `edit`,
  `update`, `destroy` methods register without explicit `#[Route]` (F-066)
- Route cache compatibility — attribute-discovered routes compile into the Laravel route cache
  correctly; double-scanning guarded with `app()->routesAreCached()` check (F-069)
- `php artisan gale:routes` Artisan command — lists all Gale-discovered routes in a formatted
  table or JSON output (`--json` flag) (F-070)
- Route attribute audit and fixes — resolved URI collision, duplicate registration, and
  parameter binding edge cases across all attribute classes (F-065)

#### Package Standards
- PHPStan level 9 — zero errors; `phpstan.neon` config in package root; `composer analyse`
  script (F-106)
- Pint formatting — `pint.json` config enforcing PSR-12 + Laravel style across all PHP files;
  `composer format` script (F-107)
- This CHANGELOG file — semantic versioning established; coordinated release strategy with
  `dancycodes/alpine-gale` documented (F-109)

#### Documentation
- `docs/` directory with full table of contents, navigation index, and cross-linked guides
  (F-095)
- Getting Started guide — installation, configuration, and first-component tutorial (F-096)
- Core Concepts guide — dual-mode architecture, event types, and Gale philosophy (F-097)
- Backend API reference — `GaleResponse`, `GaleRedirect`, `BladeFragment`, helpers (F-098)
- Frontend API reference — Alpine magics, directives, events, `Alpine.gale.*` API (F-099)
- Navigation & SPA guide — `x-navigate`, history, prefetching, scroll restoration (F-100)
- Forms, Validation & Uploads guide — form submission, server validation, file uploads (F-101)
- Components, Events & Polling guide — named components, store patching, SSE, polling (F-102)
- Debug & Troubleshooting guide — debug panel, common errors, diagnostic patterns (F-103)

#### Test Suite
- PHP unit tests: `GaleResponse` builder, event serialization, HTTP/SSE mode selection (F-111)
- PHP unit tests: `GaleRedirect`, `BladeFragment`, Gale middleware chain (F-112)
- PHP unit tests: route discovery pipeline, attribute classes, edge cases (F-113)
- PHP feature tests: full HTTP-mode request/response cycle, all event types (F-114)
- PHP feature tests: SSE streaming with `text/event-stream` parsing and event verification
  (F-115)
- PHP feature tests: validation auto-conversion, Form Request classes, custom rules (F-116)

### Changed

#### Breaking Changes

- **`gale()` scope is now per-request** (F-030) — `gale()` returns a fresh `GaleResponse`
  instance per request. Code that stored `$response = gale()` and reused it across multiple
  requests must be updated. Within a single request, repeated calls to `gale()` return the
  same instance.

  _Migration:_ Replace any cross-request `gale()` reuse with fresh per-request calls.

- **`gale:error` event detail format changed** (F-025, F-045) — The `detail` object now
  contains `{ type, status, message, context, recoverable }` instead of the previous flat
  `{ status, message }` shape. The `type` field is one of `network`, `server`, `parse`,
  `timeout`, `abort`, `security`.

  _Migration:_ Update `document.addEventListener('gale:error', e => ...)` handlers to read
  `e.detail.type`, `e.detail.status`, and `e.detail.recoverable`.

- **SSE requires `X-Gale-SSE-Id` header on reconnect** (F-026) — `GaleSSE` enforces one
  active SSE connection per URL. Duplicate connections are automatically closed. Reconnecting
  clients must include the `X-Gale-SSE-Id` header.

  _Migration:_ No action needed for standard `gale()->stream()` usage. Custom SSE clients
  must pass the SSE ID header on reconnect.

- **429 responses use `gale:rate-limited` event** (F-017) — Previously surfaced as a generic
  `gale:error` with `status === 429`. Now dispatched as `gale:rate-limited` with `{ retryAfter }`.

  _Migration:_ Replace `gale:error` handlers that check `e.detail.status === 429` with a
  dedicated `gale:rate-limited` listener.

#### Non-Breaking Changes

- `config/gale.php` expanded with structured sections: `security.*`, `debug.*`, `history.*`,
  `redirect.*`, `offline.*`; all new keys have defaults so existing published configs continue
  to work without changes (F-028)
- Gale SSE responses now include `Connection: keep-alive`, `X-Accel-Buffering: no`, and
  correct `Cache-Control` headers for proxy and Nginx compatibility (F-027)
- BladeFragment `@fragment` / `@endfragment` regex now handles `\r\n` (Windows) line endings
  in addition to `\n` (F-011)
- GaleResponse stream now validates SSE output format; raises exception in debug mode, logs
  and emits structured error event in production (F-009)
- GaleRedirect validates domains on redirect; unrecognized external domains redirect to `/`
  in production; raise exception in debug mode (F-010)

### Fixed

- Memory leaks — Alpine component teardown removes all event listeners, `MutationObserver`
  instances, and `AbortController` signals registered by Gale modules (F-001)
- Morph race condition — concurrent morph operations on the same element are serialized;
  newer morph cancels the pending one (F-002)
- Navigate click handler collision — direct `x-navigate` handlers call `stopPropagation()`
  preventing double-navigation when nested inside a delegated container (F-003)
- `EventBatch.isEmpty()` missing method — `EventBatch` now exposes `isEmpty()` used internally
  to guard empty batch processing (F-004)
- CSRF retry chain timeout — the 419 retry chain now fails fast when the CSRF refresh
  endpoint itself returns 419; previously caused an infinite hang (F-005)
- Serialize shared object detection — state serializer detects circular references and shared
  objects, preventing infinite loops and duplicate encoded data (F-006)
- Navigate modifier parsing — `x-navigate.replace`, `.push`, `.nohistory` modifiers correctly
  parsed on all Alpine directive binding paths (F-007)
- Navigate empty string params — URL params with empty string values (`?q=`) preserved during
  navigation serialization; previously stripped (F-008)
- 401 response handling — 401 responses handled before the generic error path;
  `gale:unauthenticated` event dispatched with correct context (F-012)
- `$request->validate()` auto-convert — `ValidationException` reliably converts to reactive
  messages for all Gale request types (F-062)

### Security

- XSS protection in DOM patching — DOMParser-based sanitizer strips dangerous attributes and
  tags from all `gale-patch-elements` payloads; Alpine directives (`x-*`, `@*`) always
  permitted; configurable allowlist via `config('gale.security.xss')` (F-014)
- CSRF token management hardening — token refreshed proactively before expiry; retry chain
  bounded to prevent infinite loops; `SameSite=Strict` enforced on the CSRF cookie (F-015)
- Secure state serialization — client-sent state validated against schema; unknown keys
  stripped; numeric overflow and prototype pollution blocked (F-016)

---

## [0.1.0] - 2025-11-23

> **Pre-release Version**: Laravel Gale was in active development during this phase. The API
> stabilized in 0.5.0.

### Added

- Initial pre-release of Laravel Gale
- **Core Features:** server-driven reactivity via SSE, RFC 7386 JSON Merge Patch state updates,
  DOM morphing with 8 modes (`morph`, `morph_inner`, `replace`, `prepend`, `append`, `before`,
  `after`, `remove`), component registry for named component targeting
- **Alpine.js Integration:** HTTP magics (`$get`, `$post`, `$patch`, `$put`, `$delete`),
  CSRF-protected magics (`$postx`, `$patchx`, `$putx`, `$deletex`), SPA navigation
  (`x-navigate`, `$navigate`), component registry (`x-component`, `$components`),
  server-driven UX directives (`x-indicator`, `x-loading`, `x-confirm`, `x-poll`),
  message display (`x-message`), connection state (`$gale`, `$fetching`)
- **Blade Directives:** `@gale`, `@fragment` / `@endfragment`, `@ifgale`
- **Response Builder API:** `gale()` helper with `state()`, `view()`, `fragment()`, `html()`,
  `component()`, `componentMethod()`, `js()`, `navigate()`, `when()`, `unless()` methods
- **Route Discovery:** PHP 8 attribute-based routing (`#[Route]`, `#[Prefix]`, `#[Where]`),
  `#[NoAutoDiscovery]` exclusion attribute, `GaleServiceProvider` auto-discovery
- **Developer Tools:** `GaleSSE` application helper, `GaleBase64Validator` custom rule,
  test dashboard at `/gale-test`

[Unreleased]: https://github.com/dancycodes/gale/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/dancycodes/gale/compare/v0.1.0...v0.5.0
[0.1.0]: https://github.com/dancycodes/gale/releases/tag/v0.1.0
