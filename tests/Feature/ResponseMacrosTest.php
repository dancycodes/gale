<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Http\GaleResponse;
use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Test Response Macros Integration
 *
 * @see TESTING.md - File 48: ResponseMacros Tests
 * Status: 🔄 IN PROGRESS - 3 test methods
 */
class ResponseMacrosTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_hyper_macro_returns_instance()
    {
        // response()->gale() should return GaleResponse instance
        $hyperResponse = response()->gale();
        $this->assertInstanceOf(GaleResponse::class, $hyperResponse);
    }

    /** @test */
    public function test_response_hyper_macro_available()
    {
        // The macro should be callable without errors
        $hyperResponse = response()->gale();
        $this->assertInstanceOf(GaleResponse::class, $hyperResponse);
        // Multiple calls should return the same singleton instance
        $hyper1 = response()->gale();
        $hyper2 = response()->gale();
        $this->assertSame($hyper1, $hyper2);
    }

    /** @test */
    public function test_hyper_macro_in_controller()
    {
        // Test that response()->gale() can be used in a route
        $executed = false;
        Route::get('/test-response-macro', function () use (&$executed) {
            $hyper = response()->gale();
            // Verify it's a GaleResponse instance
            if ($hyper instanceof GaleResponse) {
                $executed = true;
            }

            return $hyper->state(['test' => 'value']);
        });
        // Make a Gale request to the route
        $this->call('GET', '/test-response-macro', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        // Should have executed and returned correct type
        $this->assertTrue($executed, 'response()->gale() did not return GaleResponse in route');
    }
}
