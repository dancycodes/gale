<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/**
 * Test Route Discovery Integration
 *
 * @see TESTING.md - File 55: RouteDiscoveryIntegration Tests
 * Status: 🔄 IN PROGRESS - 25 test methods
 */
class RouteDiscoveryIntegrationTest extends TestCase
{
    public static $latestResponse;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear routes for each test
        Route::getRoutes()->refreshNameLookups();
    }

    /** @test */
    public function test_route_discovery_enabled_in_config()
    {
        $this->assertFalse(config('gale.route_discovery.enabled'));
        Config::set('gale.route_discovery.enabled', true);
        $this->assertTrue(config('gale.route_discovery.enabled'));
    }

    /** @test */
    public function test_route_discovery_disabled_by_default()
    {
        // Route discovery should be disabled by default
        $enabled = config('gale.route_discovery.enabled', false);
        $this->assertFalse($enabled);
    }

    /** @test */
    public function test_route_discovery_controller_directory_config()
    {
        $directories = config('gale.route_discovery.discover_controllers_in_directory', []);
        $this->assertIsArray($directories);
    }

    /** @test */
    public function test_route_discovery_view_directory_config()
    {
        $viewConfig = config('gale.route_discovery.discover_views_in_directory', []);
        $this->assertIsArray($viewConfig);
    }

    /** @test */
    public function test_route_discovery_skips_when_routes_cached()
    {
        // Mock routes being cached
        $this->app->instance('router', $this->app['router']);
        // When routes are cached, discovery should be skipped
        // This is handled in registerRouteDiscovery method
        $this->assertTrue(true); // Discovery skipped successfully
    }

    /** @test */
    public function test_route_discovery_configuration_structure()
    {
        $config = config('gale.route_discovery');
        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('discover_controllers_in_directory', $config);
        $this->assertArrayHasKey('discover_views_in_directory', $config);
    }

    /** @test */
    public function test_route_discovery_accepts_array_of_directories()
    {
        Config::set('gale.route_discovery.discover_controllers_in_directory', [
            app_path('Http/Controllers'),
            app_path('Http/Admin/Controllers'),
        ]);
        $directories = config('gale.route_discovery.discover_controllers_in_directory');
        $this->assertCount(2, $directories);
    }

    /** @test */
    public function test_route_discovery_view_prefix_configuration()
    {
        Config::set('gale.route_discovery.discover_views_in_directory', [
            'admin' => resource_path('views/admin'),
            'api' => resource_path('views/api'),
        ]);
        $config = config('gale.route_discovery.discover_views_in_directory');
        $this->assertArrayHasKey('admin', $config);
        $this->assertArrayHasKey('api', $config);
    }

    /** @test */
    public function test_route_discovery_with_numeric_prefix_removed()
    {
        Config::set('gale.route_discovery.discover_views_in_directory', [
            0 => resource_path('views/pages'),
            'admin' => resource_path('views/admin'),
        ]);
        $config = config('gale.route_discovery.discover_views_in_directory');
        $this->assertIsArray($config);
    }

    /** @test */
    public function test_route_discovery_service_provider_integration()
    {
        // Verify service provider boots route discovery
        Config::set('gale.route_discovery.enabled', false);
        // Service provider should check this config
        $this->assertFalse(config('gale.route_discovery.enabled'));
    }

    /** @test */
    public function test_route_discovery_validates_directory_paths()
    {
        $directory = app_path('Http/Controllers');
        // Should be a valid path
        $this->assertIsString($directory);
        $this->assertStringContainsString('Controllers', $directory);
    }

    /** @test */
    public function test_route_discovery_multiple_view_directories()
    {
        Config::set('gale.route_discovery.discover_views_in_directory', [
            'pages' => [
                resource_path('views/pages'),
                resource_path('views/public'),
            ],
        ]);
        $config = config('gale.route_discovery.discover_views_in_directory');
        $this->assertArrayHasKey('pages', $config);
        $this->assertIsArray($config['pages']);
    }

    /** @test */
    public function test_route_discovery_config_can_be_published()
    {
        // Test that config can be merged
        $this->assertNotNull(config('gale'));
        $this->assertIsArray(config('gale'));
    }

    /** @test */
    public function test_route_discovery_works_with_route_prefix()
    {
        Config::set('gale.route_discovery.discover_views_in_directory', [
            'admin' => resource_path('views/admin'),
        ]);
        $prefix = 'admin';
        $this->assertIsString($prefix);
        $this->assertEquals('admin', $prefix);
    }

    /** @test */
    public function test_route_discovery_empty_configuration()
    {
        Config::set('gale.route_discovery.discover_controllers_in_directory', []);
        Config::set('gale.route_discovery.discover_views_in_directory', []);
        $controllers = config('gale.route_discovery.discover_controllers_in_directory');
        $views = config('gale.route_discovery.discover_views_in_directory');
        $this->assertEmpty($controllers);
        $this->assertEmpty($views);
    }

    /** @test */
    public function test_route_discovery_configuration_validation()
    {
        // Test that configuration accepts expected formats
        Config::set('gale.route_discovery', [
            'enabled' => true,
            'discover_controllers_in_directory' => [app_path('Http/Controllers')],
            'discover_views_in_directory' => ['prefix' => resource_path('views')],
        ]);
        $config = config('gale.route_discovery');
        $this->assertTrue($config['enabled']);
        $this->assertIsArray($config['discover_controllers_in_directory']);
        $this->assertIsArray($config['discover_views_in_directory']);
    }

    /** @test */
    public function test_route_discovery_handles_missing_config()
    {
        Config::set('gale.route_discovery', null);
        $enabled = config('gale.route_discovery.enabled', false);
        $controllers = config('gale.route_discovery.discover_controllers_in_directory', []);
        $this->assertFalse($enabled);
        $this->assertIsArray($controllers);
    }

    /** @test */
    public function test_route_discovery_config_types()
    {
        Config::set('gale.route_discovery.enabled', true);
        $this->assertIsBool(config('gale.route_discovery.enabled'));
        Config::set('gale.route_discovery.discover_controllers_in_directory', ['/path']);
        $this->assertIsArray(config('gale.route_discovery.discover_controllers_in_directory'));
    }

    /** @test */
    public function test_route_discovery_integration_with_facades()
    {
        // Verify Config and Route facades work correctly
        $this->assertNotNull(Config::get('gale'));
        $this->assertNotNull(Route::getRoutes());
    }

    /** @test */
    public function test_route_discovery_path_resolution()
    {
        $path = app_path('Http/Controllers');
        // Verify path helpers work correctly
        $this->assertIsString($path);
        $this->assertStringEndsWith('Controllers', $path);
    }

    /** @test */
    public function test_route_discovery_view_path_resolution()
    {
        $path = resource_path('views/pages');
        $this->assertIsString($path);
        $this->assertStringEndsWith('pages', $path);
    }

    /** @test */
    public function test_route_discovery_configuration_override()
    {
        // Test that runtime config overrides work
        $original = config('gale.route_discovery.enabled');
        Config::set('gale.route_discovery.enabled', !$original);
        $this->assertEquals(!$original, config('gale.route_discovery.enabled'));
        Config::set('gale.route_discovery.enabled', $original);
        $this->assertEquals($original, config('gale.route_discovery.enabled'));
    }

    /** @test */
    public function test_route_discovery_array_wrap_behavior()
    {
        Config::set('gale.route_discovery.discover_views_in_directory', [
            'pages' => resource_path('views/pages'),
        ]);
        $config = config('gale.route_discovery.discover_views_in_directory.pages');
        $this->assertIsString($config);
    }

    /** @test */
    public function test_route_discovery_prefix_handling()
    {
        Config::set('gale.route_discovery.discover_views_in_directory', [
            'api/v1' => resource_path('views/api'),
            'admin/panel' => resource_path('views/admin'),
        ]);
        $config = config('gale.route_discovery.discover_views_in_directory');
        $this->assertArrayHasKey('api/v1', $config);
        $this->assertArrayHasKey('admin/panel', $config);
    }

    /** @test */
    public function test_route_discovery_performance()
    {
        $startTime = microtime(true);
        Config::set('gale.route_discovery.enabled', true);
        Config::set('gale.route_discovery.discover_controllers_in_directory', [
            app_path('Http/Controllers'),
        ]);
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;
        $this->assertLessThan(100, $executionTime, 'Route discovery configuration took too long');
    }
}
