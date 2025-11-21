<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Test Navigation Workflows
 *
 * @see TESTING.md - File 53: NavigationWorkflow Tests
 * Status: 🔄 IN PROGRESS - 18 test methods
 */
class NavigationWorkflowTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_navigate_method_updates_url()
    {
        Route::get('/navigate-test', function () {
            return gale()->navigate('/dashboard');
        });
        $response = $this->call('GET', '/navigate-test', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_preserves_signals()
    {
        Route::get('/navigate-preserve', function () {
            return gale()
                ->signals(['count' => 5])
                ->navigate('/next-page');
        });
        $response = $this->call('GET', '/navigate-preserve', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_merge_merges_query_params()
    {
        Route::get('/navigate-merge', function () {
            return gale()->navigateMerge('/search?q=test');
        });
        $response = $this->call('GET', '/navigate-merge', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_clean_removes_query_params()
    {
        Route::get('/navigate-clean', function () {
            return gale()->navigateClean('/page');
        });
        $response = $this->call('GET', '/navigate-clean', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_only_keeps_specific_params()
    {
        Route::get('/navigate-only', function () {
            return gale()->navigateOnly('/filter', ['category', 'sort']);
        });
        $response = $this->call('GET', '/navigate-only', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_except_removes_specific_params()
    {
        Route::get('/navigate-except', function () {
            return gale()->navigateExcept('/list', ['page']);
        });
        $response = $this->call('GET', '/navigate-except', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_with_route_helper()
    {
        Route::get('/target', function () {
            return 'Target';
        })->name('target.page');
        Route::get('/navigate-route', function () {
            return gale()->navigate(route('target.page'));
        });
        $response = $this->call('GET', '/navigate-route', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_with_hash()
    {
        Route::get('/navigate-hash', function () {
            return gale()->navigate('/page#section');
        });
        $response = $this->call('GET', '/navigate-hash', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_with_custom_key()
    {
        Route::get('/navigate-key', function () {
            return gale()
                ->signals(['updated' => true])
                ->navigate('/dashboard', 'sidebar');
        });
        $response = $this->call('GET', '/navigate-key', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_header_sent()
    {
        Route::get('/check-navigate', function () {
            return gale()->navigate('/test');
        });
        $response = $this->call('GET', '/check-navigate', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_key_header_sent()
    {
        Route::get('/check-navigate-key', function () {
            return gale()->navigate('/test', 'content');
        });
        $response = $this->call('GET', '/check-navigate-key', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_is_hyper_navigate_detection()
    {
        Route::get('/detect-navigate', function () {
            $isNavigate = request()->isGaleNavigate();

            return gale()->signals(['isNavigate' => $isNavigate]);
        });
        // Without navigate header
        $response1 = $this->call('GET', '/detect-navigate', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response1->assertOk();
        // With navigate header
        $response2 = $this->call('GET', '/detect-navigate', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
            'HTTP_GALE_NAVIGATE' => 'true',
        ]);
        $response2->assertOk();
    }

    /** @test */
    public function test_navigate_with_back_button()
    {
        // Simulate navigation history
        Route::get('/page1', function () {
            return gale()->signals(['page' => 1]);
        });
        Route::get('/page2', function () {
            return gale()->navigate('/page1');
        });
        $response = $this->call('GET', '/page2', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_with_forward_button()
    {
        Route::get('/forward', function () {
            return gale()->navigate('/next');
        });
        $response = $this->call('GET', '/forward', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_history_integration()
    {
        Route::get('/history-test', function () {
            return gale()
                ->signals(['visited' => true])
                ->navigate('/target');
        });
        $response = $this->call('GET', '/history-test', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_with_form_submission()
    {
        Route::post('/form-navigate', function () {
            return gale()
                ->signals(['submitted' => true])
                ->navigate('/success');
        });
        $signals = json_encode(['name' => 'Test']);
        $response = $this->call('POST', '/form-navigate', [
            'datastar' => $signals,
        ], [], [], ['HTTP_GALE_REQUEST' => 'true']);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_with_redirects()
    {
        Route::get('/redirect-navigate', function () {
            return gale()->navigate('/final-destination');
        });
        $response = $this->call('GET', '/redirect-navigate', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_navigate_performance()
    {
        Route::get('/perf-navigate', function () {
            return gale()
                ->signals(['data' => range(1, 100)])
                ->navigate('/next');
        });
        $startTime = microtime(true);
        $response = $this->call('GET', '/perf-navigate', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;
        $response->assertOk();
        $this->assertLessThan(1000, $executionTime, 'Navigation took too long');
    }
}
