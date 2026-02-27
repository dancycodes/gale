<?php

namespace Dancycodes\Gale\Tests\Unit\Routing\Attributes;

use Dancycodes\Gale\Routing\Attributes\DiscoveryAttribute;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Tests\TestCase;

/**
 * Test Prefix Attribute
 *
 * @see TESTING.md - File 17: PrefixAttribute Tests
 * Status: ✅ COMPLETE - 5 test methods
 */
class PrefixAttributeTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_prefix_attribute_implements_discovery_attribute()
    {
        $prefix = new Prefix('api');

        $this->assertInstanceOf(DiscoveryAttribute::class, $prefix);
    }

    /** @test */
    public function test_prefix_attribute_stores_prefix()
    {
        $prefix = new Prefix('admin');

        $this->assertEquals('admin', $prefix->prefix);
    }

    /** @test */
    public function test_prefix_attribute_with_slashes()
    {
        $prefix = new Prefix('/api/v1');

        $this->assertEquals('/api/v1', $prefix->prefix);
    }

    /** @test */
    public function test_prefix_attribute_with_empty_string()
    {
        $prefix = new Prefix('');

        $this->assertEquals('', $prefix->prefix);
    }

    /** @test */
    public function test_prefix_attribute_can_be_used_as_attribute()
    {
        // Verify that Prefix has the Attribute annotation
        $reflection = new \ReflectionClass(Prefix::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes);
    }
}
