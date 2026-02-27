<?php

namespace Dancycodes\Gale\Tests\Unit\Helpers;

use Dancycodes\Gale\Http\GaleResponse;
use Dancycodes\Gale\Tests\TestCase;

/**
 * Test the gale() helper function
 *
 * @see TESTING.md - File 42: Unit Tests - Helpers
 * Status: ✅ DONE
 */
class GaleHelperTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_hyper_helper_returns_hyper_response_instance()
    {
        $response = gale();

        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_hyper_helper_as_singleton()
    {
        $response1 = gale();
        $response2 = gale();

        $this->assertSame($response1, $response2, 'gale() should return the same instance');
    }

    /** @test */
    public function test_hyper_helper_with_no_arguments()
    {
        $response = gale();

        $this->assertInstanceOf(GaleResponse::class, $response);
        $this->assertIsCallable([$response, 'state']);
        $this->assertIsCallable([$response, 'view']);
        $this->assertIsCallable([$response, 'fragment']);
    }

    /** @test */
    public function test_hyper_helper_callable_syntax()
    {
        $this->assertTrue(function_exists('gale'), 'gale() helper function should exist');
    }
}
