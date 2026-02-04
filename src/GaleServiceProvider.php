<?php

namespace Dancycodes\Gale;

use Dancycodes\Gale\Exceptions\GaleMessageException;
use Dancycodes\Gale\Http\GaleRedirect;
use Dancycodes\Gale\View\Fragment\BladeFragment;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Gale Service Provider
 *
 * Registers and bootstraps the Gale package within the Laravel application container.
 * This provider handles service binding, Request/Response macro registration, Blade
 * directive compilation, validation rule registration, and automatic route discovery.
 *
 * The provider is automatically loaded by Laravel when the package is installed,
 * as specified in the composer.json extra.laravel.providers configuration. It follows
 * Laravel's service provider lifecycle, executing register() during early application
 * bootstrapping and boot() after all providers have been registered.
 *
 * Core responsibilities include:
 * - Binding Gale services as singletons in the container
 * - Registering Request macros for Gale request detection and state access
 * - Registering Response macros for fluent response building
 * - Compiling custom Blade directives (@gale, @galeState, @fragment)
 * - Registering base64 file validation rules
 * - Enabling automatic controller and view route discovery
 *
 *
 * @see \Dancycodes\Gale\Http\GaleResponse
 * @see \Dancycodes\Gale\Routing\Discovery\Discover
 */
class GaleServiceProvider extends ServiceProvider
{
    /**
     * Register package services in the application container
     *
     * Binds all core Gale services as singletons to ensure consistent instances
     * throughout the request lifecycle. This method executes during early application
     * bootstrapping before the boot() method and before the application is fully ready.
     *
     * Services registered:
     * - GaleResponse: SSE response builder
     * - GaleRedirect: Full-page redirect responses
     */
    public function register(): void
    {
        require_once __DIR__ . '/helpers.php';

        // Use scoped() to ensure a fresh GaleResponse instance per request
        // This prevents state corruption across multiple requests in the same process
        // (e.g., in testing or PHP-FPM worker reuse)
        $this->app->scoped('gale.response', function ($app) {
            return new \Dancycodes\Gale\Http\GaleResponse;
        });

        $this->mergeConfigFrom(
            __DIR__ . '/../config/gale.php',
            'gale'
        );

        $this->app->bind(GaleRedirect::class);
    }

    /**
     * Bootstrap package services after container registration
     *
     * Executes after all service providers have completed their register() methods,
     * allowing access to all bound services. Publishes package assets, registers
     * macros, compiles Blade directives, and initializes route discovery.
     *
     * This method handles:
     * - Publishing JavaScript assets to public directory
     * - Publishing configuration files for application customization
     * - Registering Blade directives for reactive templates
     * - Extending Request and Response with Gale-specific macros
     * - Registering custom base64 file validation rules
     * - Enabling automatic route discovery from controllers and views
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Dancycodes\Gale\Console\InstallCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../resources/js' => public_path('vendor/gale/js'),
            __DIR__ . '/../resources/css' => public_path('vendor/gale/css'),
        ], 'gale-assets');

        $this->publishes([
            __DIR__ . '/../config/gale.php' => config_path('gale.php'),
        ], 'gale-config');

        $this->registerBladeDirectives();
        $this->registerFragmentDirectives();
        $this->registerFragmentMacros();
        $this->registerRequestMacros();
        $this->registerResponseMacros();
        $this->registerRouteDiscovery();
    }

    /**
     * Register core Blade directives for Gale functionality
     *
     * Registers essential Blade directives that enable Gale's reactive capabilities:
     * - @gale: Includes the Alpine + Gale JavaScript bundle and CSRF meta tag
     * - @galeState: Injects initial state into Alpine x-data
     * - @ifgale: Conditional directive for Gale vs regular requests
     *
     * These directives compile at view rendering time and are cached with
     * Laravel's compiled Blade templates for optimal performance.
     */
    private function registerBladeDirectives(): void
    {
        // Include Alpine + Gale bundle with CSRF meta tag and transition styles
        Blade::directive('gale', function () {
            return "<?php echo '<meta name=\"csrf-token\" content=\"' . csrf_token() . '\">' . chr(10) . '<link rel=\"stylesheet\" href=\"' . asset('vendor/gale/css/gale.css') . '\">' . chr(10) . '<script type=\"module\" src=\"' . asset('vendor/gale/js/gale.js') . '\"></script>'; ?>";
        });

        // Inject initial state for Alpine x-data (deprecated, use x-data directly)
        Blade::directive('galeState', function ($expression) {
            return "<?php echo '<script>window.galeState = ' . json_encode($expression ?: []) . ';</script>'; ?>";
        });

        // Conditional based on Gale request header
        Blade::if('ifgale', function () {
            return request()->hasHeader('Gale-Request');
        });
    }

    /**
     * Specify services provided by this service provider
     *
     * Declares which services this provider offers to the container, enabling
     * Laravel's deferred service provider optimization. The application will only
     * load this provider when one of the specified services is actually requested,
     * improving bootstrap performance for requests that don't use Gale features.
     *
     * @return array<int, string> Array of service class names and aliases provided
     */
    public function provides(): array
    {
        return [
            'gale.response',
        ];
    }

    /**
     * Register Blade directives for fragment support
     *
     * Implements a dual-registration strategy to prevent race conditions during
     * Blade compiler initialization:
     *
     * 1. Immediate registration: Attempts to register directives if the Blade
     *    compiler has already been resolved in the container
     * 2. Deferred registration: Uses callAfterResolving as a fallback to ensure
     *    directives are registered when compiler becomes available
     *
     * This approach prevents "unexpected end of file" compilation errors that
     * can occur when fragment directives are used before they are registered,
     * particularly in fresh package installations or after cache clearing.
     *
     * The @fragment and @endfragment directives are intentionally no-op directives
     * that serve as markers for the fragment parser to extract view sections.
     */
    private function registerFragmentDirectives(): void
    {
        $registerDirectives = function (BladeCompiler $blade) {
            // Only register if not already registered (prevents double registration)
            if (!isset($blade->getCustomDirectives()['fragment'])) {
                $blade->directive('fragment', static fn () => '');
                $blade->directive('endfragment', static fn () => '');
            }
        };

        // Attempt immediate registration if Blade compiler is already resolved
        if ($this->app->resolved('blade.compiler')) {
            try {
                $registerDirectives($this->app->make('blade.compiler'));
            } catch (\Throwable $e) {
                // Silently fail - callAfterResolving will handle it
            }
        }

        // Also register via callAfterResolving as a safety net
        $this->callAfterResolving('blade.compiler', $registerDirectives);
    }

    /**
     * Register View facade macros for fragment rendering
     *
     * Extends Laravel's View facade with a renderFragment() macro that provides
     * convenient access to Gale's fragment rendering system. This allows fragments
     * to be rendered from anywhere in the application using View::renderFragment().
     *
     * The macro delegates to BladeFragment::render() which extracts and compiles
     * only the specified fragment section from a Blade view, enabling efficient
     * partial updates without rendering the entire view.
     *
     *
     * @see \Dancycodes\Gale\View\Fragment\BladeFragment::render()
     */
    private function registerFragmentMacros(): void
    {
        View::macro('renderFragment', function (string $view, string $fragment, array $data = []) {
            return BladeFragment::render($view, $fragment, $data);
        });
    }

    /**
     * Register Request facade macros for Gale request detection and state access
     *
     * Extends Laravel's Request object with Gale-specific methods:
     *
     * - isGale(): Detects if the current request is a Gale reactive request
     *   by checking for the Gale-Request header
     * - state(): Retrieves state values from the request JSON body
     * - signals(): Deprecated alias for state(), kept for backward compatibility
     * - isGaleNavigate(): Checks if the request is a navigate request and
     *   optionally validates specific navigate keys
     * - galeNavigateKey(): Retrieves the navigate key(s) from request headers
     * - galeNavigateKeys(): Returns navigate keys as an array for multi-key scenarios
     *
     * These macros enable conditional logic and state access throughout the
     * application using familiar Request facade syntax.
     */
    private function registerRequestMacros(): void
    {
        // Check if the request is a Gale request
        Request::macro('isGale', function () {
            return $this->hasHeader('Gale-Request');
        });

        // State access macro - retrieves state from request JSON body
        Request::macro('state', function (?string $key = null, mixed $default = null) {
            $state = $this->json()->all();

            if (is_null($key)) {
                return $state;
            }

            return data_get($state, $key, $default);
        });

        // Check if request is a Gale navigate request
        Request::macro('isGaleNavigate', function (string|array|null $key = null) {
            // First check if this is a navigate request at all
            if (!$this->hasHeader('GALE-NAVIGATE')) {
                return false;
            }

            // If no specific key requested, return true for any navigate request
            if ($key === null) {
                return true;
            }

            // Get the navigate key(s) from header
            $navigateKey = $this->header('GALE-NAVIGATE-KEY', '');

            if (empty($navigateKey)) {
                return false;
            }

            // Parse comma-separated keys
            $navigateKeys = array_map('trim', explode(',', $navigateKey));

            // Handle array of keys to check
            if (is_array($key)) {
                return !empty(array_intersect($key, $navigateKeys));
            }

            // Handle single key
            return in_array($key, $navigateKeys);
        });

        // Get the navigate key(s) from the request
        Request::macro('galeNavigateKey', function () {
            $key = $this->header('GALE-NAVIGATE-KEY', '');

            return empty($key) ? null : $key;
        });

        // Get navigate keys as array
        Request::macro('galeNavigateKeys', function () {
            /** @phpstan-ignore method.notFound (macro defined above) */
            $key = $this->galeNavigateKey();

            return $key ? array_map('trim', explode(',', $key)) : [];
        });

        // Validate state with reactive message response
        // Works like $request->validate() but sends messages via SSE on failure
        // Uses selective clearing - only clears messages for fields being validated
        Request::macro('validateState', function (array $rules, array $customMessages = [], array $attributes = []) {
            /** @phpstan-ignore method.notFound (state macro defined above) */
            $data = $this->state();

            // Get existing messages from request state (for selective clearing)
            /** @phpstan-ignore method.notFound (state macro defined above) */
            $existingMessages = $this->state('messages') ?? [];

            // Ensure existingMessages is an array
            if (!is_array($existingMessages)) {
                $existingMessages = [];
            }

            // Selectively clear fields being validated (handle wildcards for arrays)
            $clearedMessages = $existingMessages;
            foreach (array_keys($rules) as $ruleKey) {
                if (str_contains($ruleKey, '*')) {
                    // Wildcard rule (e.g., 'items.*.name'): build regex pattern
                    // to match all existing message keys like 'items.0.name', 'items.1.name'
                    $pattern = preg_quote($ruleKey, '/');
                    $pattern = str_replace('\*', '\d+', $pattern); // Replace * with \d+ for numeric indices
                    $pattern = '/^' . $pattern . '$/';

                    // Clear ALL existing message keys matching the pattern
                    foreach (array_keys($clearedMessages) as $msgKey) {
                        if (preg_match($pattern, $msgKey)) {
                            $clearedMessages[$msgKey] = '';
                        }
                    }
                } else {
                    // Simple field - clear directly
                    $clearedMessages[$ruleKey] = '';
                }
            }

            // Create validator
            $validator = Validator::make($data, $rules, $customMessages, $attributes);

            if ($validator->fails()) {
                throw new GaleMessageException($validator, $clearedMessages);
            }

            // On success, send cleared messages to frontend (removes old errors)
            gale()->state('messages', $clearedMessages);

            return $validator->validated();
        });
    }

    /**
     * Register Response facade macros for Gale response building
     *
     * Extends Laravel's Response factory with a gale() macro that retrieves
     * the singleton GaleResponse instance from the container. This provides
     * a fluent interface for building Server-Sent Events responses.
     *
     * The macro returns the same GaleResponse instance throughout a single
     * request, enabling event accumulation across multiple method calls before
     * the final response is sent.
     *
     *
     * @see \Dancycodes\Gale\Http\GaleResponse
     */
    private function registerResponseMacros(): void
    {
        ResponseFactory::macro('gale', function () {
            return app('gale.response');
        });
    }

    /**
     * Enable automatic route discovery for controllers and views
     *
     * Initializes Gale's route auto-discovery system when enabled in configuration
     * and routes are not cached. Discovery is skipped when routes are cached to
     * prevent runtime overhead in production environments.
     *
     * This method coordinates both controller-based and view-based route discovery,
     * allowing developers to define routes through class attributes or file structure
     * rather than explicit route definitions.
     *
     *
     * @see \Dancycodes\Gale\Routing\Discovery\Discover
     */
    private function registerRouteDiscovery(): void
    {
        if (app()->routesAreCached()) {
            return;
        }

        if (!config('gale.route_discovery.enabled', false)) {
            return;
        }

        $this
            ->registerRoutesForControllers()
            ->registerRoutesForViews();
    }

    /**
     * Register routes discovered from controller classes
     *
     * Scans configured directories for controller classes and automatically registers
     * routes based on PHP 8 attributes. Controllers can use attributes like #[Route],
     * #[Prefix], #[Where], and #[DoNotDiscover] to define routing behavior declaratively.
     *
     * The discovery process reads controller class metadata, extracts route definitions,
     * processes them through transformation pipelines, and registers them with Laravel's
     * Router. This eliminates the need for manual route definitions in route files.
     *
     *
     * @see \Dancycodes\Gale\Routing\Discovery\DiscoverControllers
     */
    private function registerRoutesForControllers(): self
    {
        /** @var array<int, string> $directories */
        $directories = config('gale.route_discovery.discover_controllers_in_directory', []);

        /** @var \Illuminate\Support\Collection<int, string> $directoryCollection */
        $directoryCollection = collect($directories);

        $directoryCollection->each(
            fn (string $directory) => \Dancycodes\Gale\Routing\Discovery\Discover::controllers()->in($directory)
        );

        return $this;
    }

    /**
     * Register routes discovered from Blade view files
     *
     * Scans configured view directories and automatically creates routes based on file
     * structure and naming conventions. Each Blade file becomes a routable endpoint,
     * with the file path determining the URI structure.
     *
     * Supports optional URL prefixes through configuration, enabling organized routing
     * structures like '/admin' prefix for views in resources/views/admin directory.
     * This convention-over-configuration approach simplifies routing for content-heavy
     * applications and prototyping scenarios.
     *
     *
     * @see \Dancycodes\Gale\Routing\Discovery\DiscoverViews
     */
    private function registerRoutesForViews(): self
    {
        /** @var array<int|string, array<int, string>|string> $config */
        $config = config('gale.route_discovery.discover_views_in_directory', []);

        /** @var \Illuminate\Support\Collection<int|string, array<int, string>|string> $configCollection */
        $configCollection = collect($config);

        $configCollection->each(function (array|string $directories, int|string $prefix) {
            if (is_numeric($prefix)) {
                $prefix = '';
            }

            $directories = Arr::wrap($directories);

            foreach ($directories as $directory) {
                Route::prefix($prefix)->group(function () use ($directory, $prefix) {
                    // Pass the prefix to the DiscoverViews::in() method
                    \Dancycodes\Gale\Routing\Discovery\Discover::views()->in($directory, $prefix);
                });
            }
        });

        return $this;
    }
}
