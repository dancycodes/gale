# Changelog

All notable changes to `dancycodes/gale` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2025-11-23

> **Pre-release Version**: Laravel Gale is currently in active development (0.x.x). The API may change as we gather feedback and refine features.

### Added

- Initial pre-release of Laravel Gale
- **Core Features:**
  - Server-driven reactivity via Server-Sent Events (SSE)
  - RFC 7386 JSON Merge Patch for state updates
  - DOM morphing with 8 modes (morph, morph_inner, replace, prepend, append, before, after, remove)
  - Component registry for named component targeting

- **Alpine.js Integration:**
  - HTTP magics: `$get`, `$post`, `$patch`, `$put`, `$delete`
  - CSRF-protected magics: `$postx`, `$patchx`, `$putx`, `$deletex`
  - SPA navigation: `x-navigate` directive and `$navigate` magic
  - Component registry: `x-component` directive and `$components` magic
  - Server-driven UX: `x-indicator`, `x-loading`, `x-confirm`, `x-poll` directives
  - Message display: `x-message` directive for validation errors
  - Connection state: `$gale` and `$fetching` magics

- **Blade Directives:**
  - `@gale` - Include Alpine.js with Gale plugin and CSRF token
  - `@fragment` / `@endfragment` - Define reusable view sections
  - `@ifgale` - Conditional directive for request type detection

- **Response Builder API:**
  - Fluent `gale()` helper for building SSE responses
  - State updates with `state()` method
  - View rendering with `view()` method
  - Fragment rendering with `fragment()` method
  - HTML patching with `html()` method
  - Component updates with `component()` method
  - Component method invocation with `componentMethod()` method
  - JavaScript execution with `js()` method
  - Navigation with `navigate()` method
  - Conditional logic with `when()` / `unless()` methods

- **Route Discovery:**
  - Automatic route registration from controllers
  - PHP 8 attribute-based routing: `#[Route]`, `#[Prefix]`, `#[Where]`, `#[WithTrashed]`
  - `@DoNotDiscover` attribute for exclusions

- **Developer Experience:**
  - Comprehensive test suite
  - Full PHPDoc documentation
  - Laravel package auto-discovery
  - Zero build step required

### Developer Tools

- `composer test` - Run test suite
- `php artisan vendor:publish --tag=gale-assets` - Publish JavaScript assets
- `php artisan vendor:publish --tag=gale-config` - Publish configuration

[Unreleased]: https://github.com/dancycodes/gale/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dancycodes/gale/releases/tag/v0.1.0
