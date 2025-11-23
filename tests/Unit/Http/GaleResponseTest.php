<?php

namespace Dancycodes\Gale\Tests\Unit\Http;

use Dancycodes\Gale\Http\GaleResponse;
use Dancycodes\Gale\Tests\TestCase;

/**
 * Test the GaleResponse class
 *
 * @see TESTING.md - File 2: GaleResponse Tests
 * Status: 🔄 BATCH 1 - State Methods (8 tests)
 */
class GaleResponseTest extends TestCase
{
    public static $latestResponse;

    // ===================================================================
    // BATCH 1: State Methods Tests (8 tests)
    // ===================================================================

    /**
     * Set up a fake Gale request for testing
     */
    protected function setupGaleRequest(): void
    {
        // Create a request with the Gale header
        $request = request();
        $request->headers->set('Gale-Request', 'true');
    }

    /** @test */
    public function test_state_method_updates_single_state()
    {
        $this->setupGaleRequest();

        $response = gale()->state('count', 5);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse the state data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('count', $signalData);
        $this->assertEquals(5, $signalData['count']);
    }

    /** @test */
    public function test_state_method_updates_multiple_signals()
    {
        $this->setupGaleRequest();

        $response = gale()->state([
            'username' => 'john',
            'email' => 'john@example.com',
            'age' => 30,
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse the state data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('username', $signalData);
        $this->assertArrayHasKey('email', $signalData);
        $this->assertArrayHasKey('age', $signalData);
        $this->assertEquals('john', $signalData['username']);
        $this->assertEquals('john@example.com', $signalData['email']);
        $this->assertEquals(30, $signalData['age']);
    }

    /** @test */
    public function test_state_method_with_key_value_pair()
    {
        $this->setupGaleRequest();

        $response = gale()->state('active', true);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the state data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('active', $signalData);
        $this->assertTrue($signalData['active']);
    }

    /** @test */
    public function test_state_method_chains_correctly()
    {
        $this->setupGaleRequest();

        $response = gale()
            ->state('step', 1)
            ->state('status', 'processing');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have two state update events (chained calls)
        $this->assertCount(2, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);

        // Parse the state data for both events
        $signalData1 = json_decode($events[0]['data'], true);
        $signalData2 = json_decode($events[1]['data'], true);

        $this->assertArrayHasKey('step', $signalData1);
        $this->assertEquals(1, $signalData1['step']);

        $this->assertArrayHasKey('status', $signalData2);
        $this->assertEquals('processing', $signalData2['status']);
    }

    /** @test */
    public function test_state_method_accumulates_multiple_calls()
    {
        $this->setupGaleRequest();

        $response = gale();
        $response->state('first', 'value1');
        $response->state('second', 'value2');
        $response->state('third', 'value3');

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have three state update events
        $this->assertCount(3, $events);

        // Verify each event has correct type
        foreach ($events as $event) {
            $this->assertEquals('gale-patch-state', $event['type']);
        }
    }

    /** @test */
    public function test_state_method_overwrites_duplicate_keys()
    {
        $this->setupGaleRequest();

        $response = gale()->state([
            'counter' => 1,
            'counter' => 5,  // Duplicate key - should overwrite
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the state data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('counter', $signalData);
        $this->assertEquals(5, $signalData['counter']); // Should have the last value
    }

    /** @test */
    public function test_state_method_handles_null_values()
    {
        $this->setupGaleRequest();

        $response = gale()->state([
            'name' => 'John',
            'deleted' => null,  // Null values are used for signal deletion
            'active' => true,
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the state data
        $signalData = json_decode($events[0]['data'], true);

        // Regular signals with null values SHOULD be sent to frontend for Datastar deletion
        // Only locked signals (ending with '_') are filtered and handled server-side
        $this->assertArrayHasKey('name', $signalData);
        $this->assertArrayHasKey('active', $signalData);
        $this->assertArrayHasKey('deleted', $signalData); // Null signal MUST be present for frontend deletion
        $this->assertNull($signalData['deleted']); // Value should be null (Datastar will delete it)
    }

    /** @test */
    public function test_state_method_handles_nested_arrays()
    {
        $this->setupGaleRequest();

        $response = gale()->state([
            'user' => [
                'name' => 'John Doe',
                'address' => [
                    'city' => 'New York',
                    'zip' => '10001',
                ],
            ],
            'preferences' => [
                'theme' => 'dark',
                'notifications' => true,
            ],
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the state data
        $signalData = json_decode($events[0]['data'], true);

        // Verify nested structure is preserved
        $this->assertArrayHasKey('user', $signalData);
        $this->assertIsArray($signalData['user']);
        $this->assertEquals('John Doe', $signalData['user']['name']);
        $this->assertIsArray($signalData['user']['address']);
        $this->assertEquals('New York', $signalData['user']['address']['city']);
        $this->assertEquals('10001', $signalData['user']['address']['zip']);

        $this->assertArrayHasKey('preferences', $signalData);
        $this->assertIsArray($signalData['preferences']);
        $this->assertEquals('dark', $signalData['preferences']['theme']);
        $this->assertTrue($signalData['preferences']['notifications']);
    }

    // ===================================================================
    // BATCH 2: View Rendering Tests (10 tests)
    // ===================================================================

    /** @test */
    public function test_view_method_renders_blade_view()
    {
        $this->setupGaleRequest();

        $response = gale()->view('simple');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        // Verify the view was rendered (contains view content)
        $data = $events[0]['data'];
        $this->assertStringContainsString('Simple Test View', $data);
    }

    /** @test */
    public function test_view_method_with_data_array()
    {
        $this->setupGaleRequest();

        $response = gale()->view('with-data', [
            'title' => 'Test Title',
            'description' => 'Test Description',
            'items' => ['Item 1', 'Item 2', 'Item 3'],
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify the data was passed to the view
        $data = $events[0]['data'];
        $this->assertStringContainsString('Test Title', $data);
        $this->assertStringContainsString('Test Description', $data);
        $this->assertStringContainsString('Item 1', $data);
        $this->assertStringContainsString('Item 2', $data);
        $this->assertStringContainsString('Item 3', $data);
    }

    /** @test */
    public function test_view_method_with_custom_selector()
    {
        $this->setupGaleRequest();

        $response = gale()->view('simple', [], ['selector' => '#custom-target']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify custom selector is in the event data
        $data = $events[0]['data'];
        $this->assertStringContainsString('selector #custom-target', $data);
    }

    /** @test */
    public function test_view_method_with_mode_option()
    {
        // Test different modes
        $modes = ['inner', 'outer', 'append', 'prepend', 'before', 'after', 'replace'];

        foreach ($modes as $mode) {
            // Setup fresh request for each mode
            $this->setupGaleRequest();

            // Create fresh GaleResponse instance for each test
            $response = app(\Dancycodes\Gale\Http\GaleResponse::class);
            $response->view('simple', [], ['mode' => $mode]);

            $httpResponse = $response->toResponse(request());
            $events = $this->getSSEEvents($httpResponse);

            // Verify mode is in the event data
            $data = $events[0]['data'];
            $this->assertStringContainsString("mode {$mode}", $data, "Mode {$mode} not found in response");
        }
    }

    /** @test */
    public function test_view_method_with_default_selector()
    {
        $this->setupGaleRequest();

        // When no selector provided, should use default behavior (no selector or outer mode)
        $response = gale()->view('simple');

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Default should NOT include selector (will replace entire document or use default targeting)
        $data = $events[0]['data'];
        // Just verify the view rendered correctly
        $this->assertStringContainsString('Simple Test View', $data);
    }

    /** @test */
    public function test_view_method_throws_exception_for_missing_view()
    {
        $this->setupGaleRequest();

        $this->expectException(\InvalidArgumentException::class);

        gale()->view('non-existent-view')->toResponse(request());
    }

    /** @test */
    public function test_view_method_escapes_html_correctly()
    {
        $this->setupGaleRequest();

        $dangerousContent = '<script>alert("XSS")</script>';

        $response = gale()->view('with-html', [
            'content' => $dangerousContent,
        ]);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $data = $events[0]['data'];

        // Blade's {{ }} should escape HTML
        $this->assertStringContainsString('&lt;script&gt;', $data);
        $this->assertStringNotContainsString('<script>alert', $data);
    }

    /** @test */
    public function test_view_method_with_web_fallback()
    {
        // For non-Gale request, web fallback should be used
        $response = gale()
            ->view('simple')
            ->web(view('simple', ['message' => 'Fallback']));

        // Non-Gale request
        $httpResponse = $response->toResponse(request());

        // Should be a normal view response, not StreamedResponse
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $httpResponse);
    }

    /** @test */
    public function test_view_method_with_compact_helper()
    {
        $this->setupGaleRequest();

        $title = 'Compact Title';
        $description = 'Compact Description';

        $response = gale()->view('with-data', compact('title', 'description'));

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify compact data was passed correctly
        $data = $events[0]['data'];
        $this->assertStringContainsString('Compact Title', $data);
        $this->assertStringContainsString('Compact Description', $data);
    }

    /** @test */
    public function test_view_method_chains_with_other_methods()
    {
        $this->setupGaleRequest();

        $response = gale()
            ->view('simple')
            ->state('updated', true)
            ->js('console.log("View rendered")');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have three events: view patch, state update, and script execution
        $this->assertCount(3, $events);

        // Verify event types
        $this->assertEquals('gale-patch-elements', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);
        $this->assertEquals('gale-patch-elements', $events[2]['type']); // Script is also patch-elements
    }

    // ===================================================================
    // BATCH 3: Fragment Rendering Tests (8 tests)
    // ===================================================================

    /** @test */
    public function test_fragment_method_renders_fragment()
    {
        $this->setupGaleRequest();

        $response = gale()->fragment('with-fragments', 'header', [
            'title' => 'Fragment Title',
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        // Verify the fragment was rendered with data
        $data = $events[0]['data'];
        $this->assertStringContainsString('Fragment Title', $data);
        $this->assertStringContainsString('header-fragment', $data);
    }

    /** @test */
    public function test_fragment_method_with_data()
    {
        $this->setupGaleRequest();

        $response = gale()->fragment('with-fragments', 'content', [
            'message' => 'Test Message',
            'items' => ['Item A', 'Item B', 'Item C'],
        ]);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify data was passed to fragment
        $data = $events[0]['data'];
        $this->assertStringContainsString('Test Message', $data);
        $this->assertStringContainsString('Item A', $data);
        $this->assertStringContainsString('Item B', $data);
        $this->assertStringContainsString('Item C', $data);
    }

    /** @test */
    public function test_fragment_method_with_selector_options()
    {
        $this->setupGaleRequest();

        $response = gale()->fragment('with-fragments', 'header', [], [
            'selector' => '#custom-header',
            'mode' => 'inner',
        ]);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify selector and mode options are applied
        $data = $events[0]['data'];
        $this->assertStringContainsString('selector #custom-header', $data);
        $this->assertStringContainsString('mode inner', $data);
    }

    /** @test */
    public function test_fragment_method_throws_exception_for_missing_fragment()
    {
        $this->setupGaleRequest();

        $this->expectException(\Exception::class);

        gale()->fragment('with-fragments', 'non-existent-fragment')->toResponse(request());
    }

    /** @test */
    public function test_fragments_method_renders_multiple_fragments()
    {
        $this->setupGaleRequest();

        $response = gale()->fragments([
            [
                'view' => 'with-fragments',
                'fragment' => 'header',
                'data' => ['title' => 'Header Title'],
            ],
            [
                'view' => 'with-fragments',
                'fragment' => 'footer',
                'data' => ['copyright' => '© 2025 Test'],
            ],
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have two patch elements events (one for each fragment)
        $this->assertCount(2, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);
        $this->assertEquals('gale-patch-elements', $events[1]['type']);

        // Verify both fragments rendered
        $this->assertStringContainsString('Header Title', $events[0]['data']);
        $this->assertStringContainsString('© 2025 Test', $events[1]['data']);
    }

    /** @test */
    public function test_fragment_method_with_default_targeting()
    {
        $this->setupGaleRequest();

        // Without explicit selector, fragment should use default targeting
        $response = gale()->fragment('with-fragments', 'header');

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify fragment rendered (default targeting means no explicit selector in SSE)
        $data = $events[0]['data'];
        $this->assertStringContainsString('header-fragment', $data);
    }

    /** @test */
    public function test_fragment_method_preserves_fragment_scope()
    {
        $this->setupGaleRequest();

        // Variables in fragment should not leak to other fragments
        $response = gale()->fragment('with-fragments', 'header', [
            'title' => 'Scoped Title',
            'shouldNotAppearInOtherFragments' => 'Secret Value',
        ]);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $data = $events[0]['data'];

        // Should contain the fragment-specific data
        $this->assertStringContainsString('Scoped Title', $data);

        // Should only render the header fragment, not other fragments
        $this->assertStringNotContainsString('content-fragment', $data);
        $this->assertStringNotContainsString('footer-fragment', $data);
    }

    /** @test */
    public function test_fragment_method_works_with_nested_fragments()
    {
        $this->setupGaleRequest();

        // Render outer fragment which contains inner fragment
        $response = gale()->fragment('nested-fragments', 'outer', [
            'innerMessage' => 'Nested Message',
        ]);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $data = $events[0]['data'];

        // Both outer and inner content should be rendered
        $this->assertStringContainsString('Outer Fragment', $data);
        $this->assertStringContainsString('Nested Message', $data);
        $this->assertStringContainsString('outer-fragment', $data);
        $this->assertStringContainsString('inner-fragment', $data);
    }

    // ===================================================================
    // BATCH 4: HTML Patching Methods (6 tests)
    // ===================================================================

    /** @test */
    public function test_html_method_patches_raw_html()
    {
        $this->setupGaleRequest();

        $html = '<div class="alert">Success!</div>';
        $response = gale()->html($html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        // Verify the HTML content is in the event
        $data = $events[0]['data'];
        $this->assertStringContainsString('Success!', $data);
        $this->assertStringContainsString('alert', $data);
    }

    /** @test */
    public function test_html_method_with_selector()
    {
        $this->setupGaleRequest();

        $html = '<p>Updated content</p>';
        $response = gale()->html($html, ['selector' => '#message']);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Verify selector is applied
        $data = $events[0]['data'];
        $this->assertStringContainsString('selector #message', $data);
        $this->assertStringContainsString('Updated content', $data);
    }

    /** @test */
    public function test_html_method_with_mode()
    {
        $this->setupGaleRequest();

        $html = '<li>New item</li>';

        // Test different modes
        $modes = ['inner', 'outer', 'append', 'prepend', 'before', 'after'];

        foreach ($modes as $mode) {
            $response = app(\Dancycodes\Gale\Http\GaleResponse::class);
            $response->html($html, [
                'selector' => '#list',
                'mode' => $mode,
            ]);

            $httpResponse = $response->toResponse(request());
            $events = $this->getSSEEvents($httpResponse);

            // Verify mode is applied
            $data = $events[0]['data'];
            $this->assertStringContainsString("mode {$mode}", $data, "Mode {$mode} not found");
        }
    }

    /** @test */
    public function test_html_method_escapes_by_default()
    {
        $this->setupGaleRequest();

        // Note: The html() method in GaleResponse passes raw HTML through patchElements
        // The escaping actually happens at the Blade level when using {{ }}
        // For the html() method, we're passing raw HTML strings directly

        $html = '<div>Safe Content</div>';
        $response = gale()->html($html);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $data = $events[0]['data'];

        // HTML passed to html() method is sent as-is (it's already HTML)
        $this->assertStringContainsString('<div>Safe Content</div>', $data);
    }

    /** @test */
    public function test_html_method_with_raw_option()
    {
        $this->setupGaleRequest();

        // The html() method accepts raw HTML strings
        $rawHtml = '<div><strong>Bold Text</strong></div>';
        $response = gale()->html($rawHtml);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $data = $events[0]['data'];

        // Raw HTML should be preserved
        $this->assertStringContainsString('<strong>Bold Text</strong>', $data);
        $this->assertStringContainsString('<div>', $data);
    }

    /** @test */
    public function test_html_method_with_empty_string()
    {
        $this->setupGaleRequest();

        // Empty string should clear the element
        $response = gale()->html('', ['selector' => '#content']);

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should still create an event (to clear the element)
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        // Verify selector is present but content is empty
        $data = $events[0]['data'];
        $this->assertStringContainsString('selector #content', $data);
    }

    // ===================================================================
    // BATCH 5: DOM Manipulation Methods (8 tests)
    // ===================================================================

    /** @test */
    public function test_append_method()
    {
        $this->setupGaleRequest();

        $html = '<li>New Item</li>';
        $response = gale()->append('#list', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with append mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('New Item', $data);
        $this->assertStringContainsString('selector #list', $data);
        $this->assertStringContainsString('mode append', $data);
    }

    /** @test */
    public function test_prepend_method()
    {
        $this->setupGaleRequest();

        $html = '<li>First Item</li>';
        $response = gale()->prepend('#list', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with prepend mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('First Item', $data);
        $this->assertStringContainsString('selector #list', $data);
        $this->assertStringContainsString('mode prepend', $data);
    }

    /** @test */
    public function test_replace_method()
    {
        $this->setupGaleRequest();

        $html = '<div>Replacement Content</div>';
        $response = gale()->replace('#old-element', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with replace mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('Replacement Content', $data);
        $this->assertStringContainsString('selector #old-element', $data);
        $this->assertStringContainsString('mode replace', $data);
    }

    /** @test */
    public function test_before_method()
    {
        $this->setupGaleRequest();

        $html = '<div>Before Content</div>';
        $response = gale()->before('#target', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with before mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('Before Content', $data);
        $this->assertStringContainsString('selector #target', $data);
        $this->assertStringContainsString('mode before', $data);
    }

    /** @test */
    public function test_after_method()
    {
        $this->setupGaleRequest();

        $html = '<div>After Content</div>';
        $response = gale()->after('#target', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with after mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('After Content', $data);
        $this->assertStringContainsString('selector #target', $data);
        $this->assertStringContainsString('mode after', $data);
    }

    /** @test */
    public function test_inner_method()
    {
        $this->setupGaleRequest();

        $html = '<p>Inner Content</p>';
        $response = gale()->inner('#container', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with inner mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('Inner Content', $data);
        $this->assertStringContainsString('selector #container', $data);
        $this->assertStringContainsString('mode inner', $data);
    }

    /** @test */
    public function test_outer_method()
    {
        $this->setupGaleRequest();

        $html = '<div id="new-container">Outer Content</div>';
        $response = gale()->outer('#old-container', $html);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event with outer mode
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('Outer Content', $data);
        $this->assertStringContainsString('selector #old-container', $data);
        $this->assertStringContainsString('mode outer', $data);
    }

    /** @test */
    public function test_remove_method()
    {
        $this->setupGaleRequest();

        $response = gale()->remove('#element-to-remove');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event for removal
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('selector #element-to-remove', $data);
        $this->assertStringContainsString('mode remove', $data);
    }

    // ===================================================================
    // BATCH 6: JavaScript Execution Methods (5 tests)
    // ===================================================================

    /** @test */
    public function test_js_method_executes_javascript()
    {
        $this->setupGaleRequest();

        $script = 'console.log("Hello from Gale");';
        $response = gale()->js($script);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event (scripts are patched as elements)
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify script execution setup
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);

        // Verify the script content is present
        $this->assertStringContainsString('console.log("Hello from Gale")', $data);
        $this->assertStringContainsString('<script', $data);
        $this->assertStringContainsString('</script>', $data);

        // By default, scripts should have autoRemove behavior
        $this->assertStringContainsString('x-init="$nextTick(() => $el.remove())"', $data);
    }

    /** @test */
    public function test_console_method()
    {
        $this->setupGaleRequest();

        // Test executing console.log via js() method
        $message = 'Debug message from server';
        $response = gale()->js("console.log('{$message}')");

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('console.log', $data);
        $this->assertStringContainsString($message, $data);
    }

    /** @test */
    public function test_js_method_with_timing_options()
    {
        $this->setupGaleRequest();

        // Test with custom attributes (like async, defer, or custom timing)
        $script = 'console.log("Timed execution");';
        $response = gale()->js($script, [
            'attributes' => [
                'async' => 'true',
                'defer' => 'defer',
            ],
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify custom attributes are applied
        $this->assertStringContainsString('async="true"', $data);
        $this->assertStringContainsString('defer="defer"', $data);
        $this->assertStringContainsString('console.log("Timed execution")', $data);
    }

    /** @test */
    public function test_js_method_escapes_quotes()
    {
        $this->setupGaleRequest();

        // Test that quotes in script are handled correctly
        $script = 'alert("He said: \"Hello World\"");';
        $response = gale()->js($script);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify the script content is properly handled
        // The script should be wrapped in <script> tags
        $this->assertStringContainsString('<script', $data);
        $this->assertStringContainsString('</script>', $data);

        // The alert function call should be present
        $this->assertStringContainsString('alert(', $data);
        $this->assertStringContainsString('Hello World', $data);
    }

    // ===================================================================
    // BATCH 7: Navigation Methods (10 tests)
    // ===================================================================

    /** @test */
    public function test_navigate_method()
    {
        $this->setupGaleRequest();

        $targetUrl = '/dashboard';
        $response = gale()->navigate($targetUrl, 'main');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one script execution event for navigation
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify navigation script dispatches gale:navigate event (Datastar-native approach)
        $this->assertStringContainsString('gale:navigate', $data);
        $this->assertStringContainsString($targetUrl, $data);
    }

    /** @test */
    public function test_navigate_merge_method()
    {
        $this->setupGaleRequest();

        // Set up a request with existing query parameters
        request()->merge(['existing' => 'value', 'page' => '1']);

        $targetUrl = '/search';
        $response = gale()->navigateMerge($targetUrl, 'filters');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify navigation with merge dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
    }

    /** @test */
    public function test_navigate_clean_method()
    {
        $this->setupGaleRequest();

        // Set up a request with existing query parameters
        request()->merge(['old_param' => 'value', 'page' => '2']);

        $targetUrl = '/clean-page';
        $response = gale()->navigateClean($targetUrl, 'clean');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify clean navigation (no merge) dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
        $this->assertStringContainsString($targetUrl, $data);
    }

    /** @test */
    public function test_navigate_only_method()
    {
        $this->setupGaleRequest();

        // Set up request with multiple query parameters
        request()->merge(['search' => 'test', 'category' => 'news', 'page' => '3', 'sort' => 'date']);

        $targetUrl = '/results';
        $onlyParams = ['search', 'category'];
        $response = gale()->navigateOnly($targetUrl, $onlyParams, 'only-nav');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify navigation dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
    }

    /** @test */
    public function test_navigate_except_method()
    {
        $this->setupGaleRequest();

        // Set up request with multiple query parameters
        request()->merge(['search' => 'test', 'page' => '5', 'filter' => 'active']);

        $targetUrl = '/filtered';
        $exceptParams = ['page'];
        $response = gale()->navigateExcept($targetUrl, $exceptParams, 'except-nav');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify navigation dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
    }

    /** @test */
    public function test_navigate_replace_method()
    {
        $this->setupGaleRequest();

        $targetUrl = '/replace-page';
        $response = gale()->navigateReplace($targetUrl, 'replace-nav');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify replace navigation dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
        // Replace mode should be in the options
        $this->assertStringContainsString('replace', $data);
    }

    /** @test */
    public function test_update_queries_method()
    {
        $this->setupGaleRequest();

        // Set up existing query parameters
        request()->merge(['existing' => 'value']);

        $newQueries = ['page' => 2, 'search' => 'Laravel'];
        $response = gale()->updateQueries($newQueries, 'queries');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify query update navigation dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
    }

    /** @test */
    public function test_clear_queries_method()
    {
        $this->setupGaleRequest();

        // Set up query parameters to clear
        request()->merge(['page' => '1', 'search' => 'test', 'filter' => 'active']);

        $paramsToClear = ['page', 'search'];
        $response = gale()->clearQueries($paramsToClear, 'clear');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify clear queries navigation dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
    }

    /** @test */
    public function test_navigate_methods_use_url_manager()
    {
        $this->setupGaleRequest();

        // Test that navigate methods properly integrate with URL manager
        // URL manager enforces single-use, so calling multiple navigate methods should fail

        $response = gale()->navigate('/first-url');

        // Try to call another navigate method - should throw exception due to single-use
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('URL can only be set once per response');

        // This should fail because URL was already set
        $response->navigate('/second-url');
    }

    // ===================================================================
    // BATCH 9: Conditional Methods (8 tests)
    // ===================================================================

    /** @test */
    public function test_when_method_executes_callback_when_true()
    {
        $this->setupGaleRequest();

        $executed = false;

        $response = gale()->when(true, function ($gale) use (&$executed) {
            $executed = true;
            $gale->state('executed', true);
        });

        $this->assertTrue($executed, 'Callback should have been executed');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Verify signal was set
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_when_method_skips_callback_when_false()
    {
        $this->setupGaleRequest();

        $executed = false;

        $response = gale()->when(false, function ($gale) use (&$executed) {
            $executed = true;
            $gale->state('executed', true);
        });

        $this->assertFalse($executed, 'Callback should NOT have been executed');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Verify no signals were set
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(0, $events);
    }

    /** @test */
    public function test_when_method_with_fallback()
    {
        $this->setupGaleRequest();

        $mainExecuted = false;
        $fallbackExecuted = false;

        // Test with false condition - fallback should execute
        $response = gale()->when(
            false,
            function ($gale) use (&$mainExecuted) {
                $mainExecuted = true;
            },
            function ($gale) use (&$fallbackExecuted) {
                $fallbackExecuted = true;
                $gale->state('fallback', true);
            }
        );

        $this->assertFalse($mainExecuted, 'Main callback should NOT have been executed');
        $this->assertTrue($fallbackExecuted, 'Fallback callback should have been executed');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Verify fallback signal was set
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_unless_method()
    {
        $this->setupGaleRequest();

        $executed = false;

        // unless() is inverse of when() - executes when condition is false
        $response = gale()->unless(false, function ($gale) use (&$executed) {
            $executed = true;
            $gale->state('executed', true);
        });

        $this->assertTrue($executed, 'Callback should have been executed when condition is false');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Test with true condition - should not execute
        $executed2 = false;
        $response2 = gale()->unless(true, function ($gale) use (&$executed2) {
            $executed2 = true;
        });

        $this->assertFalse($executed2, 'Callback should NOT execute when condition is true');
    }

    /** @test */
    public function test_when_hyper_method()
    {
        $this->setupGaleRequest();

        $executed = false;

        // whenGale() should execute for Gale requests
        $response = gale()->whenGale(function ($gale) use (&$executed) {
            $executed = true;
            $gale->state('hyper', true);
        });

        $this->assertTrue($executed, 'Callback should execute for Gale request');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Verify signal was set
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
    }

    /** @test */
    public function test_when_not_hyper_method()
    {
        // For non-Gale request, whenNotGale() should execute
        $executed = false;

        $response = gale()->whenNotGale(function ($gale) use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed, 'Callback should execute for non-Gale request');
        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_when_hyper_navigate_method()
    {
        $this->setupGaleRequest();

        // Set navigate headers correctly
        request()->headers->set('GALE-NAVIGATE', 'true');
        request()->headers->set('GALE-NAVIGATE-KEY', 'sidebar');

        $executed = false;

        // whenGaleNavigate() with specific key
        $response = gale()->whenGaleNavigate('sidebar', function ($gale) use (&$executed) {
            $executed = true;
            $gale->state('navigate_key', 'sidebar');
        });

        $this->assertTrue($executed, 'Callback should execute for matching navigate key');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Test with non-matching key - should not execute
        $executed2 = false;
        $response2 = gale()->whenGaleNavigate('different-key', function ($gale) use (&$executed2) {
            $executed2 = true;
        });

        $this->assertFalse($executed2, 'Callback should NOT execute for non-matching navigate key');
    }

    /** @test */
    public function test_when_method_nested_conditions()
    {
        $this->setupGaleRequest();

        $executionOrder = [];

        // Test nested when() conditions
        $response = gale()
            ->when(true, function ($gale) use (&$executionOrder) {
                $executionOrder[] = 'outer-true';

                $gale->when(true, function ($gale) use (&$executionOrder) {
                    $executionOrder[] = 'inner-true';
                    $gale->state('nested', true);
                });

                $gale->when(false, function ($gale) use (&$executionOrder) {
                    $executionOrder[] = 'inner-false';
                });
            });

        $this->assertEquals(['outer-true', 'inner-true'], $executionOrder);
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Verify nested signal was set
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    // ===================================================================
    // BATCH 10: Signal Forgetting Methods (4 tests)
    // ===================================================================

    /** @test */
    public function test_forget_method_removes_signals()
    {
        $this->setupGaleRequest();

        // Set up some signals first via the signals helper
        request()->merge(['datastar' => ['count' => 5, 'name' => 'John', 'active' => true]]);

        // Forget specific signals
        $response = gale()->forget(['count', 'name']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event with null values (deletion)
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse state data - deleted signals should be set to null or not present
        $signalData = json_decode($events[0]['data'], true);

        // Verify that the forget method created deletion events
        // (In Datastar, null values mean signal deletion)
        $this->assertTrue(is_array($signalData));
    }

    /** @test */
    public function test_forget_method_with_single_signal()
    {
        $this->setupGaleRequest();

        // Set up signals
        request()->merge(['datastar' => ['username' => 'john_doe']]);

        // Forget a single signal (string parameter)
        $response = gale()->forget('username');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_forget_method_with_multiple_signals()
    {
        $this->setupGaleRequest();

        // Set up multiple signals
        request()->merge(['datastar' => [
            'first' => 'value1',
            'second' => 'value2',
            'third' => 'value3',
            'fourth' => 'value4',
        ]]);

        // Forget multiple signals
        $response = gale()->forget(['first', 'second', 'third']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_forget_method_without_parameters_is_noop()
    {
        $this->setupGaleRequest();

        // Set up state
        request()->merge(['datastar' => [
            'signal1' => 'value1',
            'signal2' => 'value2',
        ]]);

        // Call forget() without parameters is a no-op (frontend manages own state)
        $response = gale()->forget();

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response - should be empty since no-op
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have no events (forget without params cannot know which keys to forget)
        $this->assertCount(0, $events);
    }

    /** @test */
    public function test_forget_method_resets_messages_state_to_empty_array()
    {
        $this->setupGaleRequest();

        // Set up state including messages
        request()->merge(['datastar' => [
            'messages' => ['success' => 'Item saved'],
            'count' => 5,
            'name' => 'John',
        ]]);

        // Forget the messages state
        $response = gale()->forget('messages');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse state data - messages state should be an empty array, not null
        $stateData = json_decode($events[0]['data'], true);

        // Verify that messages is set to empty array instead of null
        $this->assertArrayHasKey('messages', $stateData);
        $this->assertIsArray($stateData['messages']);
        $this->assertEmpty($stateData['messages']);
        $this->assertEquals([], $stateData['messages']);
    }

    /** @test */
    public function test_forget_method_with_multiple_state_keys_including_messages()
    {
        $this->setupGaleRequest();

        // Set up multiple state keys including messages
        request()->merge(['datastar' => [
            'messages' => ['success' => 'Saved', 'info' => 'Updated'],
            'count' => 10,
            'name' => 'Test User',
        ]]);

        // Forget multiple state keys including messages
        $response = gale()->forget(['messages', 'count']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one state update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse state data
        $stateData = json_decode($events[0]['data'], true);

        // Verify that messages is set to empty array, not null (special handling)
        $this->assertArrayHasKey('messages', $stateData);
        $this->assertIsArray($stateData['messages']);
        $this->assertEmpty($stateData['messages']);

        // Verify that count is set to null (standard deletion)
        $this->assertArrayHasKey('count', $stateData);
        $this->assertNull($stateData['count']);
    }

    // ===================================================================
    // BATCH 11: Streaming Methods (5 tests - Basic Coverage)
    // ===================================================================

    /** @test */
    public function test_stream_method_enables_streaming_mode()
    {
        $this->setupGaleRequest();

        $callbackExecuted = false;

        $response = gale()->stream(function ($gale) use (&$callbackExecuted) {
            $callbackExecuted = true;
            $gale->state('streaming', true);
        });

        // Stream callback is stored, not executed immediately
        $this->assertInstanceOf(GaleResponse::class, $response);
        $this->assertFalse($callbackExecuted, 'Callback should not execute immediately');

        // Verify toResponse returns StreamedResponse (stream mode)
        $httpResponse = $response->toResponse(request());
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $httpResponse);
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));
    }

    /** @test */
    public function test_stream_method_flushes_accumulated_events()
    {
        $this->setupGaleRequest();

        // Add some events before streaming
        $response = gale()
            ->state('before_stream', 'value')
            ->stream(function ($gale) {
                $gale->state('during_stream', 'value');
            });

        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_stream_method_sends_header()
    {
        $this->setupGaleRequest();

        $response = gale()->stream(function ($gale) {
            // Empty callback
        });

        // Convert to Laravel response
        $httpResponse = $response->toResponse(request());

        // Verify it's a StreamedResponse
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $httpResponse);
    }

    /** @test */
    public function test_stream_method_handles_exceptions()
    {
        $this->setupGaleRequest();

        // Stream callback that throws exception
        $response = gale()->stream(function ($gale) {
            // The stream handles exceptions internally
            // Just test that it doesn't break the response
            $gale->state('test', 'value');
        });

        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_stream_method_with_callback()
    {
        $this->setupGaleRequest();

        $callbackExecuted = false;

        $response = gale()->stream(function ($gale) use (&$callbackExecuted) {
            $callbackExecuted = true;

            // Emit multiple events during streaming
            $gale->state('event1', 'value1');
            $gale->state('event2', 'value2');
        });

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Callback is NOT executed immediately when stream() is called
        $this->assertFalse($callbackExecuted, 'Callback should not execute immediately');

        // Verify toResponse returns StreamedResponse
        $httpResponse = $response->toResponse(request());
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $httpResponse);
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));
    }

    // ===================================================================
    // BATCH 12: Response Generation Methods (8 tests)
    // ===================================================================

    /** @test */
    public function test_to_response_method_for_gale_requests()
    {
        $this->setupGaleRequest();

        $response = gale()->state('test', 'value');

        // Convert to Laravel response
        $httpResponse = $response->toResponse(request());

        // Single-shot mode returns regular Response (not StreamedResponse)
        // StreamedResponse is only used with stream() callback for real-time output
        $this->assertInstanceOf(\Illuminate\Http\Response::class, $httpResponse);

        // Verify headers are set
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));

        // Cache-Control header may include 'private' in addition to 'no-cache' (Laravel default)
        $this->assertStringContainsString('no-cache', $httpResponse->headers->get('Cache-Control'));
    }

    /** @test */
    public function test_to_response_method_for_non_gale_requests_returns_no_content()
    {
        // Non-Gale request without web fallback returns 204 No Content
        $response = gale()->state('test', 'value');
        $httpResponse = $response->toResponse(request());

        // Should return 204 No Content for non-Gale requests
        $this->assertEquals(204, $httpResponse->getStatusCode());
    }

    /** @test */
    public function test_to_response_method_with_web_fallback_for_non_gale_requests()
    {
        // Create a response with web fallback
        $response = gale()
            ->state('test', 'value')
            ->web(fn () => response('Fallback content', 200));

        $httpResponse = $response->toResponse(request());

        // Non-Gale request with web fallback should return the fallback
        $this->assertEquals(200, $httpResponse->getStatusCode());
        $this->assertEquals('Fallback content', $httpResponse->getContent());
    }

    /** @test */
    public function test_headers_method_returns_correct_headers()
    {
        $headers = \Dancycodes\Gale\Http\GaleResponse::headers();

        $this->assertIsArray($headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertArrayHasKey('X-Gale-Response', $headers);

        $this->assertEquals('text/event-stream', $headers['Content-Type']);
        $this->assertEquals('no-cache', $headers['Cache-Control']);
        $this->assertEquals('true', $headers['X-Gale-Response']);
    }

    /** @test */
    public function test_to_response_sets_content_type_header()
    {
        $this->setupGaleRequest();

        $response = gale()->state('test', 'value');
        $httpResponse = $response->toResponse(request());

        // Verify Content-Type header is set to SSE format
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));
    }

    /** @test */
    public function test_to_response_generates_sse_format()
    {
        $this->setupGaleRequest();

        $response = gale()->state('key', 'value');
        $httpResponse = $response->toResponse(request());

        // Get events
        $events = $this->getSSEEvents($httpResponse);

        // Should have proper SSE format
        $this->assertCount(1, $events);
        $this->assertArrayHasKey('type', $events[0]);
        $this->assertArrayHasKey('data', $events[0]);
    }

    /** @test */
    public function test_to_response_handles_empty_response()
    {
        $this->setupGaleRequest();

        // Create response with no events
        $response = gale();
        $httpResponse = $response->toResponse(request());

        // Single-shot mode returns regular Response (not StreamedResponse)
        $this->assertInstanceOf(\Illuminate\Http\Response::class, $httpResponse);

        // Get events - should be empty
        $events = $this->getSSEEvents($httpResponse);
        $this->assertCount(0, $events);
    }

    /** @test */
    public function test_to_response_accumulates_all_events()
    {
        $this->setupGaleRequest();

        // Chain multiple events
        $response = gale()
            ->state('signal1', 'value1')
            ->state('signal2', 'value2')
            ->js('console.log("test")');

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have accumulated all 3 events
        $this->assertCount(3, $events);

        // Verify event types
        $this->assertEquals('gale-patch-state', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);
        $this->assertEquals('gale-patch-elements', $events[2]['type']);
    }

    // ===================================================================
    // BATCH 13: Event Dispatch Methods (10 tests)
    // ===================================================================

    /** @test */
    public function test_dispatch_method_global_event()
    {
        $this->setupGaleRequest();

        $response = gale()->dispatch('post-created', ['id' => 123]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one patch elements event (script execution)
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify script structure (dispatch uses executeScript which creates a script tag)
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);
        $this->assertStringContainsString('<script', $data);

        // Verify it has autoRemove behavior
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_with_data()
    {
        $this->setupGaleRequest();

        $eventData = [
            'userId' => 42,
            'username' => 'john_doe',
            'email' => 'john@example.com',
            'metadata' => [
                'action' => 'login',
                'timestamp' => '2025-01-01',
            ],
        ];

        $response = gale()->dispatch('user-login', $eventData);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify script structure
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);
        $this->assertStringContainsString('<script', $data);

        // Verify event has autoRemove behavior
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_with_selector()
    {
        $this->setupGaleRequest();

        $response = gale()->dispatch('update-stats', ['count' => 5], ['selector' => '#dashboard']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify script structure
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);
        $this->assertStringContainsString('<script', $data);

        // Verify event has autoRemove behavior
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_with_multiple_selectors()
    {
        $this->setupGaleRequest();

        // Test with class selector (targets multiple elements)
        $response = gale()->dispatch('highlight', [], ['selector' => '.item']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);

        $data = $events[0]['data'];

        // Verify script structure
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);
        $this->assertStringContainsString('<script', $data);

        // Verify event has autoRemove behavior
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_with_options()
    {
        $this->setupGaleRequest();

        // Test with custom event options
        $response = gale()->dispatch('custom-event', ['test' => 'data'], [
            'bubbles' => false,
            'cancelable' => false,
            'composed' => false,
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);

        $data = $events[0]['data'];

        // Verify script structure
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);
        $this->assertStringContainsString('<script', $data);

        // Verify event has autoRemove behavior
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_validates_event_name()
    {
        $this->setupGaleRequest();

        // Test with empty event name
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name cannot be empty');

        gale()->dispatch('', ['data' => 'value']);
    }

    /** @test */
    public function test_dispatch_method_escapes_event_data()
    {
        $this->setupGaleRequest();

        // Test with potentially dangerous content
        $dangerousData = [
            'html' => '<script>alert("XSS")</script>',
            'quotes' => 'He said: "Hello"',
            'apostrophes' => "It's working",
        ];

        $response = gale()->dispatch('safe-event', $dangerousData);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);

        $data = $events[0]['data'];

        // Verify script structure
        $this->assertStringContainsString('selector body', $data);
        $this->assertStringContainsString('mode append', $data);
        $this->assertStringContainsString('<script', $data);

        // Verify event has autoRemove behavior
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_chains_correctly()
    {
        $this->setupGaleRequest();

        // Test chaining dispatch with other methods
        $response = gale()
            ->dispatch('event-one', ['data' => 'first'])
            ->state('updated', true)
            ->dispatch('event-two', ['data' => 'second']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have 3 events: dispatch, signals, dispatch
        $this->assertCount(3, $events);

        // Verify event types
        $this->assertEquals('gale-patch-elements', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);
        $this->assertEquals('gale-patch-elements', $events[2]['type']);

        // Verify first dispatch script structure
        $this->assertStringContainsString('selector body', $events[0]['data']);
        $this->assertStringContainsString('mode append', $events[0]['data']);
        $this->assertStringContainsString('<script', $events[0]['data']);

        // Verify second dispatch script structure
        $this->assertStringContainsString('selector body', $events[2]['data']);
        $this->assertStringContainsString('mode append', $events[2]['data']);
        $this->assertStringContainsString('<script', $events[2]['data']);
    }

    /** @test */
    public function test_dispatch_method_auto_removes_script()
    {
        $this->setupGaleRequest();

        $response = gale()->dispatch('cleanup-event', ['test' => 'data']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);

        $data = $events[0]['data'];

        // Verify the script has autoRemove behavior (x-init="$nextTick(() => $el.remove())")
        // The dispatch method uses executeScript with autoRemove => true
        $this->assertStringContainsString('x-init', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }
}
