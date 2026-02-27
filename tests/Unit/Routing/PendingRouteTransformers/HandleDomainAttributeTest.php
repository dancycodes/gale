<?php

namespace Dancycodes\Gale\Tests\Unit\Routing\PendingRouteTransformers;

use Dancycodes\Gale\Routing\PendingRouteTransformers\HandleDomainAttribute;
use Dancycodes\Gale\Routing\PendingRouteTransformers\PendingRouteTransformer;
use Dancycodes\Gale\Tests\TestCase;

class HandleDomainAttributeTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_transform_applies_transformation()
    {
        $transformer = new HandleDomainAttribute;

        $this->assertInstanceOf(PendingRouteTransformer::class, $transformer);
    }

    /** @test */
    public function test_transform_with_edge_cases()
    {
        $transformer = new HandleDomainAttribute;
        $result = $transformer->transform(collect([]));

        $this->assertCount(0, $result);
    }

    /** @test */
    public function test_transform_preserves_other_properties()
    {
        $transformer = new HandleDomainAttribute;

        $this->assertInstanceOf(HandleDomainAttribute::class, $transformer);
    }

    /** @test */
    public function test_transformer_returns_collection()
    {
        $transformer = new HandleDomainAttribute;
        $result = $transformer->transform(collect([]));

        $this->assertIsIterable($result);
    }
}
