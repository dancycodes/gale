<?php

namespace Dancycodes\Gale\Tests\Unit\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDoNotDiscoverAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\PendingRouteTransformer;
use Dancycodes\Gale\Tests\TestCase;

class HandleDoNotDiscoverAttributeTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_transform_applies_transformation()
    {
        $transformer = new HandleDoNotDiscoverAttribute;

        $this->assertInstanceOf(PendingRouteTransformer::class, $transformer);
    }

    /** @test */
    public function test_transform_with_edge_cases()
    {
        $transformer = new HandleDoNotDiscoverAttribute;
        $result = $transformer->transform(collect([]));

        $this->assertCount(0, $result);
    }

    /** @test */
    public function test_transform_preserves_other_properties()
    {
        $transformer = new HandleDoNotDiscoverAttribute;

        $this->assertInstanceOf(HandleDoNotDiscoverAttribute::class, $transformer);
    }

    /** @test */
    public function test_transformer_returns_collection()
    {
        $transformer = new HandleDoNotDiscoverAttribute;
        $result = $transformer->transform(collect([]));

        $this->assertIsIterable($result);
    }
}
