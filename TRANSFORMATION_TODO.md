# Laravel Hyper Transformation: Datastar → Alpine HYPER

## Overview

This document outlines the complete transformation of Laravel Hyper from Datastar integration to Alpine HYPER integration. The goal is to provide a seamless, Laravel-friendly experience where developers can start coding immediately after installation.

**Key Principles:**
- Simple installation: `composer require dancycodes/hyper`
- Alpine.js with all required plugins built-in (morph, hyper)
- Extensible: Developers can add additional Alpine plugins
- 100% passing test suite maintained throughout transformation
- Production-grade implementation

---

## Phase 1: Core SSE Protocol Changes

### File: `src/Http/HyperResponse.php`

#### SSE Event Type Changes
| Current (Datastar) | New (Alpine HYPER) |
|-------------------|-------------------|
| `datastar-patch-elements` | `hyper-patch-elements` |
| `datastar-patch-signals` | `hyper-patch-state` |
| N/A | `hyper-patch-component` (NEW) |

#### Method Changes
- [ ] Rename protected `updateSignals()` → `patchState()`
- [ ] Keep public `signals()` as facade (calls `patchState()` internally)
- [ ] Add public `state()` method as alias to `signals()`
- [ ] Update `buildSignalsEvent()` → `buildStateEvent()`
- [ ] Update `forgetSignals()` → `forgetState()`
- [ ] Add `patchComponent()` method for component-specific updates
- [ ] Update all SSE event names in output methods

#### Header Detection Changes
- [ ] Update request detection: `Datastar-Request` → `Hyper-Request`
- [ ] Keep existing navigate headers: `HYPER-NAVIGATE`, `HYPER-NAVIGATE-KEY`

---

## Phase 2: Service Provider Updates

### File: `src/HyperServiceProvider.php`

#### Request Macros
- [ ] Update `isHyper()`: Check `Hyper-Request` header instead of `Datastar-Request`
- [ ] Keep `signals()` macro for backward compatibility
- [ ] Add `state()` macro as primary method
- [ ] Keep navigate macros unchanged

#### Blade Directives
- [ ] Update `@hyper` directive:
  - Include Alpine.js core
  - Include @alpinejs/morph plugin
  - Include Alpine HYPER plugin
  - Include CSRF meta tag
- [ ] Keep `@hyperSignals` for backward compatibility
- [ ] Add `@hyperState` as new primary directive
- [ ] Keep `@fragment/@endfragment` unchanged (backend-agnostic)

#### Removed Registrations
- [ ] Remove HyperSignal singleton binding
- [ ] Remove HyperSignalsDirective singleton binding
- [ ] Remove locked signal session handling

---

## Phase 3: Middleware Changes

### File: `src/Http/Middleware/ConvertRedirectsForDatastar.php`

#### Actions
- [ ] Rename file to `ConvertRedirectsForHyper.php`
- [ ] Update class name to `ConvertRedirectsForHyper`
- [ ] Update header check: `hasHeader('Datastar-Request')` → `hasHeader('Hyper-Request')`
- [ ] Update SSE event format:
  - `datastar-patch-elements` → `hyper-patch-elements`
- [ ] Update service provider to use new middleware class name

---

## Phase 4: Remove Datastar-Specific Files

### Files to REMOVE
- [ ] `src/Http/HyperSignal.php` - Signals concept replaced by Alpine x-data
- [ ] `src/Services/HyperSignalsDirective.php` - data-signals → x-data
- [ ] `src/Exceptions/HyperSignalTamperedException.php` - Locked signals concept removed

### Files to KEEP (Backend-Agnostic)
- ✅ `src/Routing/*` - Route discovery system (unchanged)
- ✅ `src/View/Fragment/*` - Fragment rendering (unchanged)
- ✅ `src/Html/*` - HTML building (unchanged)
- ✅ `src/Services/HyperUrlManager.php` - URL management (unchanged)
- ✅ `src/Http/HyperRedirect.php` - Redirect handling (unchanged)
- ✅ `src/Validation/HyperBase64Validator.php` - File validation (unchanged)
- ✅ `src/Exceptions/HyperValidationException.php` - Keep for validation

---

## Phase 5: Helper Functions

### File: `src/helpers.php`

#### Changes
- [ ] Remove `signals()` helper (or deprecate with warning)
- [ ] Keep `hyper()` helper → returns HyperResponse instance
- [ ] Keep `hyperStorage()` helper → returns HyperFileStorage instance
- [ ] Add `state()` helper if needed for request state access

---

## Phase 6: Configuration Updates

### File: `config/hyper.php`

#### Remove
- [ ] `security.log_locked_signal_tampering` - No longer relevant
- [ ] Any locked signal related configuration

#### Keep
- [ ] `route_discovery.enabled`
- [ ] `route_discovery.discover_controllers_in_directory`
- [ ] `route_discovery.discover_views_in_directory`

#### Add
- [ ] `alpine.include_morph` (default: true)
- [ ] `alpine.include_persist` (default: false)
- [ ] `alpine.include_focus` (default: false)
- [ ] `alpine.custom_plugins` (default: [])

---

## Phase 7: JavaScript Bundle

### New File: `resources/js/hyper.js`

```javascript
// Alpine HYPER Bundle for Laravel Hyper
import Alpine from 'alpinejs'
import morph from '@alpinejs/morph'
import hyper from 'alpine-hyper'

// Register required plugins
Alpine.plugin(morph)
Alpine.plugin(hyper)

// Expose Alpine globally for developer extensions
window.Alpine = Alpine

// Auto-start Alpine
Alpine.start()
```

### Build Process
- [ ] Add build script for JavaScript bundle
- [ ] Bundle Alpine.js (~40KB gzipped)
- [ ] Bundle @alpinejs/morph (~3KB gzipped)
- [ ] Bundle Alpine HYPER plugin
- [ ] Output to `resources/js/dist/hyper.js`

---

## Phase 8: Test Suite Updates

### Tests to UPDATE (Header/Event Changes)

#### Feature Tests
- [ ] `BladeDirectivesTest.php` - Update directive output expectations
- [ ] `CompleteWorkflowTest.php` - Update header names
- [ ] `HyperServiceProviderTest.php` - Update macro names
- [ ] `NavigationWorkflowTest.php` - Keep (uses HYPER-NAVIGATE already)
- [ ] `RequestMacrosTest.php` - Update header detection tests
- [ ] `ResponseMacrosTest.php` - Keep (unchanged)
- [ ] `RouteDiscoveryIntegrationTest.php` - Keep (unchanged)
- [ ] `FileUploadWorkflowTest.php` - Keep (unchanged)
- [ ] `FragmentRenderingTest.php` - Keep (unchanged)
- [ ] `ValidationIntegrationTest.php` - Keep (unchanged)

#### Unit Tests
- [ ] `HyperResponseTest.php` - Update SSE event name expectations
- [ ] `HyperRedirectTest.php` - Keep (unchanged)
- [ ] `ConvertRedirectsForDatastarTest.php` - Rename and update

### Tests to REMOVE
- [ ] `tests/Unit/Http/HyperSignalTest.php`
- [ ] `tests/Unit/Services/HyperSignalsDirectiveTest.php`
- [ ] `tests/Feature/LockedSignalsWorkflowTest.php`
- [ ] `tests/Feature/SignalFlowTest.php`

### Tests to ADD
- [ ] State management tests (replacing signal tests)
- [ ] Component patch tests (`hyper-patch-component`)
- [ ] Alpine HYPER integration tests

---

## Phase 9: Documentation Updates

### Files to Update in `docs/`
- [ ] Update all references from Datastar to Alpine HYPER
- [ ] Update directive documentation (x-* instead of data-*)
- [ ] Update state management examples (x-data instead of signals)
- [ ] Add Alpine plugin extension guide
- [ ] Update installation instructions

---

## Execution Checklist

### Pre-flight
- [ ] Run existing test suite - confirm 100% passing baseline
- [ ] Backup current package state

### Execution Order
1. [ ] **Phase 1**: Update HyperResponse.php SSE events
2. [ ] **Phase 2**: Update HyperServiceProvider.php macros/directives
3. [ ] **Phase 3**: Rename/update middleware
4. [ ] **Phase 4**: Remove Datastar-specific files
5. [ ] **Phase 5**: Update helpers.php
6. [ ] **Phase 6**: Update config/hyper.php
7. [ ] **Phase 7**: Create JavaScript bundle
8. [ ] **Phase 8**: Update test suite
9. [ ] **Phase 9**: Update documentation

### Post-flight
- [ ] Run full test suite - verify 100% passing
- [ ] Test manual integration with demo application
- [ ] Update package version (breaking change)

---

## API Compatibility Layer

To ease migration for existing users, we may provide a compatibility layer:

```php
// Deprecated - will work but show warning
$response->signals(['count' => 1]);  // → calls state() internally

// New preferred API
$response->state(['count' => 1]);
```

```php
// Deprecated - will work but show warning
request()->signals('key');

// New preferred API
request()->state('key');
```

---

## Breaking Changes Summary

| Component | Change Type | Migration |
|-----------|-------------|-----------|
| SSE Events | Breaking | Update frontend to listen for new event names |
| Request Header | Breaking | Update custom middleware checking `Datastar-Request` |
| Locked Signals | Removed | Use application-level security if needed |
| `@signals` directive | Deprecated | Use Alpine x-data directly |
| `signals()` helper | Deprecated | Use `state()` or `hyper()` |

---

## Success Criteria

1. ✅ **100% test suite passing** - No regression
2. ✅ **Simple installation** - Single composer require
3. ✅ **Zero configuration** - Works out of the box
4. ✅ **Laravel-friendly** - Follows Laravel conventions
5. ✅ **Extensible** - Can add Alpine plugins easily
6. ✅ **Production-ready** - No experimental features
