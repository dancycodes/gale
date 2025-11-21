<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Test Request Macros Integration
 *
 * @see TESTING.md - File 47: RequestMacros Tests
 * Status: 🔄 IN PROGRESS - 12 test methods
 */
class RequestMacrosTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_is_gale_macro_detects_hyper_requests()
    {
        // Create a Gale request
        $request = Request::create('/', 'GET');
        $request->headers->set('Gale-Request', 'true');
        // Should detect as Gale request
        $this->assertTrue($request->isGale());
    }

    /** @test */
    public function test_is_gale_macro_detects_normal_requests()
    {
        // Create a normal request
        $request = Request::create('/', 'GET');
        // Should NOT detect as Gale request
        $this->assertFalse($request->isGale());
    }

    /** @test */
    public function test_state_macro_returns_all_state()
    {
        // state() without parameters should return all state data
        $response = $this->json('POST', '/', ['count' => 5, 'name' => 'Test'], [
            'Gale-Request' => 'true',
        ]);

        // In the context of the test, we can verify via route
        Route::post('/state-test', function () {
            return response()->json(request()->state());
        });

        $response = $this->json('POST', '/state-test', ['count' => 5, 'name' => 'Test'], [
            'Gale-Request' => 'true',
        ]);
        $response->assertJson(['count' => 5, 'name' => 'Test']);
    }

    /** @test */
    public function test_state_macro_with_key()
    {
        Route::post('/state-key-test', function () {
            return response()->json([
                'count' => request()->state('count'),
                'name' => request()->state('name'),
            ]);
        });

        $response = $this->json('POST', '/state-key-test', ['count' => 5, 'name' => 'Test'], [
            'Gale-Request' => 'true',
        ]);
        $response->assertJson(['count' => 5, 'name' => 'Test']);
    }

    /** @test */
    public function test_state_macro_with_key_and_default()
    {
        Route::post('/state-default-test', function () {
            return response()->json([
                'result' => request()->state('missing', 'default-value'),
            ]);
        });

        $response = $this->json('POST', '/state-default-test', [], [
            'Gale-Request' => 'true',
        ]);
        $response->assertJson(['result' => 'default-value']);
    }

    /** @test */
    public function test_signals_macro_is_alias_for_state()
    {
        // signals() is deprecated but should work as alias for state()
        Route::post('/signals-alias-test', function () {
            return response()->json([
                'count' => request()->signals('count'),
            ]);
        });

        $response = $this->json('POST', '/signals-alias-test', ['count' => 42], [
            'Gale-Request' => 'true',
        ]);
        $response->assertJson(['count' => 42]);
    }

    /** @test */
    public function test_is_gale_navigate_macro_without_key()
    {
        // Create a request without GALE-NAVIGATE header
        $request = Request::create('/', 'GET');
        $this->assertFalse($request->isGaleNavigate());
        // Create a request with GALE-NAVIGATE header
        $navigateRequest = Request::create('/', 'GET');
        $navigateRequest->headers->set('GALE-NAVIGATE', 'true');
        $this->assertTrue($navigateRequest->isGaleNavigate());
    }

    /** @test */
    public function test_is_gale_navigate_macro_with_single_key()
    {
        // Create a request with GALE-NAVIGATE and specific key
        $request = Request::create('/', 'GET');
        $request->headers->set('GALE-NAVIGATE', 'true');
        $request->headers->set('GALE-NAVIGATE-KEY', 'sidebar');
        // Should match the exact key
        $this->assertTrue($request->isGaleNavigate('sidebar'));
        // Should not match different key
        $this->assertFalse($request->isGaleNavigate('header'));
    }

    /** @test */
    public function test_is_gale_navigate_macro_with_multiple_keys()
    {
        // Create a request with GALE-NAVIGATE and multiple keys
        $request = Request::create('/', 'GET');
        $request->headers->set('GALE-NAVIGATE', 'true');
        $request->headers->set('GALE-NAVIGATE-KEY', 'sidebar, header');
        // Should match if any key matches
        $this->assertTrue($request->isGaleNavigate(['sidebar', 'footer']));
        $this->assertTrue($request->isGaleNavigate(['header']));
        // Should not match if no keys match
        $this->assertFalse($request->isGaleNavigate(['footer', 'nav']));
    }

    /** @test */
    public function test_hyper_navigate_key_macro()
    {
        // Request without navigate key
        $request = Request::create('/', 'GET');
        $this->assertNull($request->galeNavigateKey());
        // Request with navigate key
        $navigateRequest = Request::create('/', 'GET');
        $navigateRequest->headers->set('GALE-NAVIGATE-KEY', 'sidebar');
        $this->assertEquals('sidebar', $navigateRequest->galeNavigateKey());
    }

    /** @test */
    public function test_hyper_navigate_keys_macro()
    {
        // Request without navigate keys
        $request = Request::create('/', 'GET');
        $this->assertEquals([], $request->galeNavigateKeys());
        // Request with single key
        $singleKeyRequest = Request::create('/', 'GET');
        $singleKeyRequest->headers->set('GALE-NAVIGATE-KEY', 'sidebar');
        $this->assertEquals(['sidebar'], $singleKeyRequest->galeNavigateKeys());
        // Request with multiple keys
        $multiKeyRequest = Request::create('/', 'GET');
        $multiKeyRequest->headers->set('GALE-NAVIGATE-KEY', 'sidebar, header, footer');
        $this->assertEquals(['sidebar', 'header', 'footer'], $multiKeyRequest->galeNavigateKeys());
    }

    /** @test */
    public function test_macros_available_in_routes()
    {
        // Define a test route that uses the macros
        Route::get('/test-macros', function (Request $request) {
            return response()->json([
                'isGale' => $request->isGale(),
                'isGaleNavigate' => $request->isGaleNavigate(),
                'navigateKey' => $request->galeNavigateKey(),
                'navigateKeys' => $request->galeNavigateKeys(),
            ]);
        });
        // Make a request to the route
        $response = $this->get('/test-macros');
        // Should execute without errors
        $response->assertOk();
        $response->assertJson([
            'isGale' => false,
            'isGaleNavigate' => false,
            'navigateKey' => null,
            'navigateKeys' => [],
        ]);
    }

    /** @test */
    public function test_macros_available_in_middleware()
    {
        // Test that macros are available on the Request class globally
        // This ensures they work in middleware context as well
        // Create a Gale request to test the macros
        $request = Request::create('/', 'GET');
        $request->headers->set('Gale-Request', 'true');
        // Set the request in the container
        $this->app->instance('request', $request);
        // Test that all macros work on the request
        $this->assertTrue($request->isGale());
        $this->assertInstanceOf(GaleSignal::class, $request->signals());
        $this->assertFalse($request->isGaleNavigate());
        $this->assertNull($request->galeNavigateKey());
        $this->assertIsArray($request->galeNavigateKeys());
    }
}
