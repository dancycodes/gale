# Installation & Setup

How to install, configure, and upgrade dancycodes/gale in a Laravel application.

## Prerequisites

- **Laravel** 11, 12, or 13
- **PHP** `^8.3` (matches `dancycodes/gale` composer requirement)
- **No existing Alpine.js installation** — Gale bundles Alpine.js v3 + the Morph plugin. Remove any prior `import 'alpinejs'` lines, npm dependencies, or `<script src=".../alpine.js">` CDN tags. Two Alpines = breakage.

## Step-by-step Install

```bash
# 1. Add Gale to your project
composer require dancycodes/gale

# 2. Publish JS/CSS assets to public/vendor/gale/
php artisan gale:install
```

`gale:install` does the following (verified at `packages/dancycodes/gale/src/Console/InstallCommand.php:35-66`):

1. Calls `vendor:publish --tag=gale-assets --force` — copies the bundled JS/CSS to:
   - `public/vendor/gale/js/gale.js` (Alpine v3 + Morph + alpine-gale plugin, all in one)
   - `public/vendor/gale/css/gale.css`
2. Prints a setup hint reminding you to add `@gale` to your layout `<head>`.
3. Warns to remove any existing Alpine.js if present.

```bash
# 3. (Optional) Publish the config file to customize settings
php artisan vendor:publish --tag=gale-config
# → publishes config/gale.php
```

## Add `@gale` to Your Layout

The `@gale` Blade directive injects:

- `<meta name="csrf-token" content="...">` for `$action` to read.
- `<link rel="stylesheet" href="/vendor/gale/css/gale.css?id=...">` (cache-busted via `gale-manifest.json`).
- `<script type="module" src="/vendor/gale/js/gale.js?id=...">` (the Alpine + Gale bundle).
- A few `window.GALE_*` globals for runtime config (XSS sanitization flags, redirect security config, debug flag, optional CSP nonce).

Place it in your master layout's `<head>`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>

    @gale
</head>
<body>
    @yield('content')
</body>
</html>
```

## CSP Nonce (optional)

If you serve a Content Security Policy with a nonce, pass it to `@gale`:

```blade
@gale(['nonce' => $nonce])
```

This adds the nonce to every `<script>` and `<style>` tag injected by Gale. The same nonce is exposed to the runtime as `window.GALE_CSP_NONCE` so server-pushed `gale()->js(...)` scripts can include it too. Alternatively configure once globally:

```php
// config/gale.php
'csp_nonce' => 'auto',
```

## DO NOT `npm install alpine-gale`

> **Important:** alpine-gale is a **private/internal** module — it ships **bundled inside** the
> `dancycodes/gale` Composer package. Running `npm install alpine-gale` is incorrect for two
> reasons:
>
> 1. The npm name is **not** how end users get the JS. The JS is shipped via `gale:install` →
>    `public/vendor/gale/js/gale.js` and loaded by the `@gale` Blade directive.
> 2. The npm package version exists only as a **build artifact** for monorepo tag synchronization.
>    The published assets in the Composer package are the canonical distribution.
>
> If an agent suggests `npm install alpine-gale` or `import gale from 'alpine-gale'`, that
> agent is wrong. The correct path for end users is **always** `composer require dancycodes/gale`
> → `php artisan gale:install` → `@gale` in `<head>`.

## Verify Installation

1. Visit any page that uses your layout.
2. Open DevTools → Network tab.
3. Look for two requests:
   - `GET /vendor/gale/css/gale.css?id=<hash>` → 200
   - `GET /vendor/gale/js/gale.js?id=<hash>` → 200
4. In DevTools → Console, type `Alpine.gale` — you should see an object with methods like `configure`, `getConfig`, `registerComponent`.
5. View page source — confirm `<meta name="csrf-token" ...>` is present in `<head>`.

If any of those fail, run `php artisan gale:install --force` to re-publish assets and clear browser cache (the `?id=<hash>` query string busts cache automatically when the bundle changes).

## Upgrading

When a new Gale release ships:

```bash
composer update dancycodes/gale
php artisan gale:install --force
```

The `--force` flag tells Laravel's publisher to overwrite the existing files in `public/vendor/gale/`. The cache-busting `?id=<hash>` query string updates automatically because `gale-manifest.json` is also re-published.

## Configuration File

Common keys in `config/gale.php` (see `references/config-security.md` for the full list):

| Key | Default | Use |
|-----|---------|-----|
| `mode` | `'http'` | Default response mode (`'http'` or `'sse'`) |
| `debug_panel` | `null` (→ `APP_DEBUG`) | Enable in-browser debug panel |
| `debug` | `false` | Enable `dd()`/`dump()` interception for Gale requests |
| `morph_markers` | `true` | Stable morph anchors |
| `sanitize_html` | `true` | XSS-sanitize HTML in element patches |
| `redirect.allowed_domains` | `[]` | Whitelist external redirect domains |

Set via env in `.env`:

```env
GALE_MODE=http
GALE_DEBUG_PANEL=true
GALE_DEBUG=false
```

## Troubleshooting

### "Two Alpines" error / `Alpine.gale` is undefined

Your layout has both `@gale` and a separate Alpine import (CDN script, npm `import 'alpinejs'`, or Vite `Alpine.start()`). Remove the other one. `@gale` provides Alpine.

### Assets 404 after deploy

Run `php artisan gale:install --force` on the server, or publish via your CI:

```bash
composer install --no-dev
php artisan gale:install --force
```

### Stale JS after upgrade

The `?id=<hash>` query string busts CDN/proxy caches automatically. If you serve assets via a CDN that ignores query strings, configure it to vary on the query string, or empty its cache after each release.
