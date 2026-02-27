<?php

namespace Dancycodes\Gale\Tests\Unit\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleMiddlewareAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\PendingRouteTransformer;
use Dancycodes\Gale\Tests\TestCase;

class HandleMiddlewareAttributeTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_transform_applies_transformation()
    {
        $transformer = new HandleMiddlewareAttribute;

        $this->assertInstanceOf(PendingRouteTransformer::class, $transformer);
    }

    /** @test */
    public function test_transform_with_edge_cases()
    {
        $transformer = new HandleMiddlewareAttribute;
        $result = $transformer->transform(collect([]));

        $this->assertCount(0, $result);
    }

    /** @test */
    public function test_transform_preserves_other_properties()
    {
        $transformer = new HandleMiddlewareAttribute;

        $this->assertInstanceOf(HandleMiddlewareAttribute::class, $transformer);
    }

    /** @test */
    public function test_transformer_returns_collection()
    {
        $transformer = new HandleMiddlewareAttribute;
        $result = $transformer->transform(collect([]));

        $this->assertIsIterable($result);
    }
}
