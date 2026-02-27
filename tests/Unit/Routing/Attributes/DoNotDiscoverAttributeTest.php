<?php

namespace Dancycodes\Gale\Tests\Unit\Routing\Attributes;

use Dancycodes\Gale\Routing\Attributes\DiscoveryAttribute;
use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Tests\TestCase;

/**
 * Test DoNotDiscover Attribute
 *
 * @see TESTING.md - File 20: DoNotDiscover Tests
 * Status: ✅ COMPLETE - 4 test methods
 */
class DoNotDiscoverAttributeTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_do_not_discover_attribute_implements_discovery_attribute()
    {
        $doNotDiscover = new DoNotDiscover;

        $this->assertInstanceOf(DiscoveryAttribute::class, $doNotDiscover);
    }

    /** @test */
    public function test_do_not_discover_attribute_can_be_instantiated()
    {
        $doNotDiscover = new DoNotDiscover;

        $this->assertInstanceOf(DoNotDiscover::class, $doNotDiscover);
    }

    /** @test */
    public function test_do_not_discover_attribute_has_no_properties()
    {
        $doNotDiscover = new DoNotDiscover;

        $reflection = new \ReflectionClass($doNotDiscover);
        $properties = $reflection->getProperties();

        $this->assertEmpty($properties);
    }

    /** @test */
    public function test_do_not_discover_attribute_can_be_used_as_attribute()
    {
        // Verify that DoNotDiscover has the Attribute annotation
        $reflection = new \ReflectionClass(DoNotDiscover::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes);
    }
}
