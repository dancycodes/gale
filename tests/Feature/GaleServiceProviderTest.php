<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Http\GaleRedirect;
use Dancycodes\Gale\Http\GaleResponse;
use Dancycodes\Gale\Http\GaleSignal;
use Dancycodes\Gale\GaleServiceProvider;
use Dancycodes\Gale\Services\GaleFileStorage;
use Dancycodes\Gale\Services\GaleSignalsDirective;
use Dancycodes\Gale\Services\GaleUrlManager;
use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

/**
 * Test the GaleServiceProvider class
 *
 * @see TESTING.md - File 45: GaleServiceProvider Tests
 * Status: 🔄 IN PROGRESS - 20 test methods
 */
class GaleServiceProviderTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_service_provider_registers_hyper_response()
    {
        // The gale.response singleton should be registered
        $this->assertTrue($this->app->bound('gale.response'));
        // It should return a GaleResponse instance
        $instance1 = $this->app->make('gale.response');
        $this->assertInstanceOf(GaleResponse::class, $instance1);
        // It should be a singleton (same instance)
        $instance2 = $this->app->make('gale.response');
        $this->assertSame($instance1, $instance2);
    }

    /** @test */
    public function test_service_provider_registers_hyper_signal()
    {
        // GaleSignal should be bound as singleton
        $this->assertTrue($this->app->bound(GaleSignal::class));
        // It should return a GaleSignal instance
        $instance = $this->app->make(GaleSignal::class);
        $this->assertInstanceOf(GaleSignal::class, $instance);
        // Check alias
        $this->assertTrue($this->app->bound('gale.signals'));
        $aliasInstance = $this->app->make('gale.signals');
        $this->assertInstanceOf(GaleSignal::class, $aliasInstance);
    }

    /** @test */
    public function test_service_provider_registers_hyper_storage()
    {
        // GaleFileStorage should be bound as singleton
        $this->assertTrue($this->app->bound(GaleFileStorage::class));
        // It should return a GaleFileStorage instance
        $instance = $this->app->make(GaleFileStorage::class);
        $this->assertInstanceOf(GaleFileStorage::class, $instance);
        // Check alias
        $this->assertTrue($this->app->bound('gale.storage'));
        $aliasInstance = $this->app->make('gale.storage');
        $this->assertInstanceOf(GaleFileStorage::class, $aliasInstance);
    }

    /** @test */
    public function test_service_provider_registers_url_manager()
    {
        // GaleUrlManager should be bound as singleton
        $this->assertTrue($this->app->bound(GaleUrlManager::class));
        // It should return a GaleUrlManager instance
        $instance = $this->app->make(GaleUrlManager::class);
        $this->assertInstanceOf(GaleUrlManager::class, $instance);
    }

    /** @test */
    public function test_service_provider_registers_signals_directive()
    {
        // GaleSignalsDirective service should be bound
        $this->assertTrue($this->app->bound('gale.signals.directive'));
        // It should return a GaleSignalsDirective instance
        $instance = $this->app->make('gale.signals.directive');
        $this->assertInstanceOf(GaleSignalsDirective::class, $instance);
    }

    /** @test */
    public function test_service_provider_merges_config()
    {
        // The hyper config should be available
        $this->assertNotNull(config('gale'));
        // Check some default config values
        $this->assertIsArray(config('gale.route_discovery'));
        $this->assertIsBool(config('gale.route_discovery.enabled'));
    }

    /** @test */
    public function test_service_provider_publishes_assets()
    {
        // Clear any existing published assets
        $assetsPath = public_path('vendor/gale/js');
        if (File::exists($assetsPath)) {
            File::deleteDirectory(dirname($assetsPath));
        }
        // Run the publish command
        Artisan::call('vendor:publish', [
            '--tag' => 'gale-assets',
            '--force' => true,
        ]);
        // Check that assets were published
        $this->assertTrue(File::exists($assetsPath));
        // Cleanup
        if (File::exists($assetsPath)) {
            File::deleteDirectory(dirname($assetsPath));
        }
    }

    /** @test */
    public function test_service_provider_publishes_config()
    {
        // Clear any existing published config
        $configPath = config_path('gale.php');
        if (File::exists($configPath)) {
            File::delete($configPath);
        }
        // Run the publish command
        Artisan::call('vendor:publish', [
            '--tag' => 'gale-config',
            '--force' => true,
        ]);
        // Check that config was published
        $this->assertTrue(File::exists($configPath));
        // Cleanup
        if (File::exists($configPath)) {
            File::delete($configPath);
        }
    }

    /** @test */
    public function test_service_provider_loads_helpers()
    {
        // Helper functions should be available
        $this->assertTrue(function_exists('gale'));
        $this->assertTrue(function_exists('signals'));
        $this->assertTrue(function_exists('galeStorage'));
    }

    /** @test */
    public function test_service_provider_registers_validation_rules()
    {
        // All base64 validation rules should be available
        // Use invalid base64 data that will fail validation
        $invalidBase64 = 'invalid!!!base64';
        // Test b64image - should fail for invalid base64
        $testValidator = Validator::make(
            ['file' => $invalidBase64],
            ['file' => 'b64image']
        );
        $this->assertFalse($testValidator->passes());
        // Test b64file - should fail for invalid base64
        $testValidator = Validator::make(
            ['file' => $invalidBase64],
            ['file' => 'b64file']
        );
        $this->assertFalse($testValidator->passes());
        // Test b64max - should fail for invalid base64
        $testValidator = Validator::make(
            ['file' => $invalidBase64],
            ['file' => 'b64max:10']
        );
        $this->assertFalse($testValidator->passes());
        // Test b64min - should fail for invalid base64
        $testValidator = Validator::make(
            ['file' => $invalidBase64],
            ['file' => 'b64min:10']
        );
        $this->assertFalse($testValidator->passes());
        // Test b64mimes - should fail for invalid base64
        $testValidator = Validator::make(
            ['file' => $invalidBase64],
            ['file' => 'b64mimes:png,jpg']
        );
        $this->assertFalse($testValidator->passes());
    }

    /** @test */
    public function test_service_provider_registers_blade_directives()
    {
        // @gale directive
        $hyperDirective = Blade::compileString('@gale');
        $this->assertStringContainsString('csrf-token', $hyperDirective);
        $this->assertStringContainsString('vendor/gale/js/gale.js', $hyperDirective);
        // @ifgale directive
        $ifhyperDirective = Blade::compileString('@ifgale test @endifgale');
        $this->assertStringContainsString('if', $ifhyperDirective);
    }

    /** @test */
    public function test_service_provider_registers_request_macros()
    {
        $request = Request::create('/', 'GET');
        // isGale macro should exist and work
        $this->assertTrue(Request::hasMacro('isGale'));
        $this->assertFalse($request->isGale());
        // signals macro should exist and work
        $this->assertTrue(Request::hasMacro('signals'));
        $signals = $request->signals();
        $this->assertInstanceOf(GaleSignal::class, $signals);
        // isGaleNavigate macro should exist and work
        $this->assertTrue(Request::hasMacro('isGaleNavigate'));
        $this->assertFalse($request->isGaleNavigate());
        // galeNavigateKey macro should exist and work
        $this->assertTrue(Request::hasMacro('galeNavigateKey'));
        $this->assertNull($request->galeNavigateKey());
        // galeNavigateKeys macro should exist and work
        $this->assertTrue(Request::hasMacro('galeNavigateKeys'));
        $this->assertIsArray($request->galeNavigateKeys());
    }

    /** @test */
    public function test_service_provider_registers_response_macros()
    {
        // hyper macro should be available on ResponseFactory
        $response = response()->gale();
        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_service_provider_registers_view_macros()
    {
        // renderFragment macro should exist
        $this->assertTrue(View::hasMacro('renderFragment'));
    }

    /** @test */
    public function test_service_provider_boots_route_discovery_when_enabled()
    {
        // Enable route discovery
        Config::set('gale.route_discovery.enabled', true);
        Config::set('gale.route_discovery.discover_controllers_in_directory', []);
        Config::set('gale.route_discovery.discover_views_in_directory', []);
        // Re-register the service provider
        $provider = new GaleServiceProvider($this->app);
        $provider->boot();
        // This test just verifies the boot process doesn't throw errors
        $this->assertTrue(true);
    }

    /** @test */
    public function test_service_provider_skips_route_discovery_when_disabled()
    {
        // Disable route discovery
        Config::set('gale.route_discovery.enabled', false);
        // Re-register the service provider
        $provider = new GaleServiceProvider($this->app);
        $provider->boot();
        // This test just verifies the boot process doesn't throw errors
        $this->assertTrue(true);
    }

    /** @test */
    public function test_service_provider_provides_correct_services()
    {
        $provider = new GaleServiceProvider($this->app);
        $provides = $provider->provides();
        // Should list the services it provides
        $this->assertIsArray($provides);
        $this->assertContains(GaleSignal::class, $provides);
        $this->assertContains('gale.signals', $provides);
    }

    /** @test */
    public function test_service_provider_registers_hyper_redirect()
    {
        // GaleRedirect should be bound
        $this->assertTrue($this->app->bound(GaleRedirect::class));
        // It should be creatable when providing required parameters
        $hyperResponse = new GaleResponse;
        $instance = new GaleRedirect('/test-url', $hyperResponse);
        $this->assertInstanceOf(GaleRedirect::class, $instance);
    }

    /** @test */
    public function test_service_provider_registers_fragment_directives()
    {
        // @fragment directive
        $fragmentDirective = Blade::compileString('@fragment("test") content @endfragment');
        // The directives should compile without errors
        $this->assertIsString($fragmentDirective);
    }

    /** @test */
    public function test_helpers_return_correct_instances()
    {
        // gale() helper should return GaleResponse singleton
        $hyper1 = gale();
        $hyper2 = gale();
        $this->assertInstanceOf(GaleResponse::class, $hyper1);
        $this->assertSame($hyper1, $hyper2);
        // signals() helper should return GaleSignal instance
        $signals = signals();
        $this->assertInstanceOf(GaleSignal::class, $signals);
        // galeStorage() helper should return GaleFileStorage instance
        $storage = galeStorage();
        $this->assertInstanceOf(GaleFileStorage::class, $storage);
    }
}
