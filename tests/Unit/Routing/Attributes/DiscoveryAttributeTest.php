<?php

namespace Dancycodes\Gale\Tests\Unit\Routing\Attributes;

use Dancycodes\Gale\Routing\Attributes\DiscoveryAttribute;
use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Where;
use Dancycodes\Gale\Routing\Attributes\WithTrashed;
use Dancycodes\Gale\Tests\TestCase;

/**
 * Test DiscoveryAttribute Interface
 *
 * @see TESTING.md - File 21: DiscoveryAttribute Tests
 * Status: ✅ COMPLETE - 6 test methods
 */
class DiscoveryAttributeTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_discovery_attribute_is_an_interface()
    {
        $reflection = new \ReflectionClass(DiscoveryAttribute::class);

        $this->assertTrue($reflection->isInterface());
    }

    /** @test */
    public function test_route_implements_discovery_attribute()
    {
        $route = new Route;

        $this->assertInstanceOf(DiscoveryAttribute::class, $route);
    }

    /** @test */
    public function test_prefix_implements_discovery_attribute()
    {
        $prefix = new Prefix('api');

        $this->assertInstanceOf(DiscoveryAttribute::class, $prefix);
    }

    /** @test */
    public function test_where_implements_discovery_attribute()
    {
        $where = new Where('id', Where::numeric);

        $this->assertInstanceOf(DiscoveryAttribute::class, $where);
    }

    /** @test */
    public function test_with_trashed_implements_discovery_attribute()
    {
        $withTrashed = new WithTrashed;

        $this->assertInstanceOf(DiscoveryAttribute::class, $withTrashed);
    }

    /** @test */
    public function test_do_not_discover_implements_discovery_attribute()
    {
        $doNotDiscover = new DoNotDiscover;

        $this->assertInstanceOf(DiscoveryAttribute::class, $doNotDiscover);
    }
}
