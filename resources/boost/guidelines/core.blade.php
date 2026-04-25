## Gale (`dancycodes/gale`)

This Laravel app uses **Gale** — a server-driven reactive framework built on Alpine.js. Transport is **HTTP JSON by default** with **SSE streaming as opt-in** (per-action `{ sse: true }`, `gale()->stream()`, or `config('gale.mode')`).

- **Every Gale controller returns `gale()->...`** — never bare `view()`, `response()`, or `response()->json()`. For pages that also need direct URL access, use `gale()->view('name', $data, [], web: true)`.
- **Layout `<head>`** must include `@gale` (the Blade directive that loads the bundled JS/CSS published by `php artisan gale:install`). Do **NOT** `npm install alpine-gale` — it's bundled inside Gale's published assets, not a public npm package.
- **Interactive elements need `x-data`** — Gale state patches target Alpine components.
- **`$request->validate()` auto-converts** to `gale()->state('messages', [...])` (via `GaleMessageException`). Display per-field validation messages with `<span x-message="fieldname">` — **NOT** `<span x-message.from.errors="fieldname">` (that reads from a different state slot, populated only by explicit `gale()->errors([...])`).
- **`x-indicator="varName"`** is element-scoped — it toggles a local boolean on the `x-data` while a `$action` from this element/descendants is in flight. NOT a top-of-page progress bar. Use it for per-button loading state in multi-action components instead of `$fetching` (which is per-`x-data` scope).
- **`redirect()` auto-converts** for Gale requests via `ConvertRedirectForGale` middleware. Standard Laravel redirects work transparently.

@verbatim
<code-snippet name="Minimal Gale controller + Blade" lang="php">
// Controller
public function store(Request $request): mixed
{
    $validated = $request->validate([
        'email' => 'required|email|unique:users',
    ]);

    User::create($validated);

    return gale()
        ->state(['email' => ''])
        ->messages(['_success' => 'User created!']);
}
</code-snippet>

<code-snippet name="Minimal Gale Blade form" lang="blade">
<form x-data="{ email: '' }" @submit.prevent="$action('/users')">
    <input x-name="email" type="email" required>
    <span x-message="email" class="text-red-500"></span>
    <span x-message="_success" class="text-green-600"></span>
    <button type="submit" x-data="{ saving: false }" x-indicator="saving" :disabled="saving">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving...</span>
    </button>
</form>
</code-snippet>
@endverbatim

For anything beyond this — `$action` options, navigation, fragments, push channels, optimistic UI, file uploads, debug panel — invoke the **`gale` skill**. It is the authoritative reference and supersedes any prior Gale knowledge you may have.
