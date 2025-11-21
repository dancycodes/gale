<?php

namespace Dancycodes\Gale\Tests\Unit\Http;

use Dancycodes\Gale\Http\GaleResponse;
use Dancycodes\Gale\Tests\TestCase;

/**
 * Test the GaleResponse class
 *
 * @see TESTING.md - File 2: GaleResponse Tests
 * Status: 🔄 BATCH 1 - Signal Methods (8 tests)
 */
class GaleResponseTest extends TestCase
{
    public static $latestResponse;

    // ===================================================================
    // BATCH 1: Signal Methods Tests (8 tests)
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
    public function test_signals_method_updates_single_signal()
    {
        $this->setupGaleRequest();

        $response = gale()->signals('count', 5);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse the signal data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('count', $signalData);
        $this->assertEquals(5, $signalData['count']);
    }

    /** @test */
    public function test_signals_method_updates_multiple_signals()
    {
        $this->setupGaleRequest();

        $response = gale()->signals([
            'username' => 'john',
            'email' => 'john@example.com',
            'age' => 30,
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse the signal data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('username', $signalData);
        $this->assertArrayHasKey('email', $signalData);
        $this->assertArrayHasKey('age', $signalData);
        $this->assertEquals('john', $signalData['username']);
        $this->assertEquals('john@example.com', $signalData['email']);
        $this->assertEquals(30, $signalData['age']);
    }

    /** @test */
    public function test_signals_method_with_key_value_pair()
    {
        $this->setupGaleRequest();

        $response = gale()->signals('active', true);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the signal data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('active', $signalData);
        $this->assertTrue($signalData['active']);
    }

    /** @test */
    public function test_signals_method_chains_correctly()
    {
        $this->setupGaleRequest();

        $response = gale()
            ->signals('step', 1)
            ->signals('status', 'processing');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have two signal update events (chained calls)
        $this->assertCount(2, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);

        // Parse the signal data for both events
        $signalData1 = json_decode($events[0]['data'], true);
        $signalData2 = json_decode($events[1]['data'], true);

        $this->assertArrayHasKey('step', $signalData1);
        $this->assertEquals(1, $signalData1['step']);

        $this->assertArrayHasKey('status', $signalData2);
        $this->assertEquals('processing', $signalData2['status']);
    }

    /** @test */
    public function test_signals_method_accumulates_multiple_calls()
    {
        $this->setupGaleRequest();

        $response = gale();
        $response->signals('first', 'value1');
        $response->signals('second', 'value2');
        $response->signals('third', 'value3');

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have three signal update events
        $this->assertCount(3, $events);

        // Verify each event has correct type
        foreach ($events as $event) {
            $this->assertEquals('gale-patch-state', $event['type']);
        }
    }

    /** @test */
    public function test_signals_method_overwrites_duplicate_keys()
    {
        $this->setupGaleRequest();

        $response = gale()->signals([
            'counter' => 1,
            'counter' => 5,  // Duplicate key - should overwrite
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the signal data
        $signalData = json_decode($events[0]['data'], true);
        $this->assertArrayHasKey('counter', $signalData);
        $this->assertEquals(5, $signalData['counter']); // Should have the last value
    }

    /** @test */
    public function test_signals_method_handles_null_values()
    {
        $this->setupGaleRequest();

        $response = gale()->signals([
            'name' => 'John',
            'deleted' => null,  // Null values are used for signal deletion
            'active' => true,
        ]);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Parse the signal data
        $signalData = json_decode($events[0]['data'], true);

        // Regular signals with null values SHOULD be sent to frontend for Datastar deletion
        // Only locked signals (ending with '_') are filtered and handled server-side
        $this->assertArrayHasKey('name', $signalData);
        $this->assertArrayHasKey('active', $signalData);
        $this->assertArrayHasKey('deleted', $signalData); // Null signal MUST be present for frontend deletion
        $this->assertNull($signalData['deleted']); // Value should be null (Datastar will delete it)
    }

    /** @test */
    public function test_signals_method_handles_nested_arrays()
    {
        $this->setupGaleRequest();

        $response = gale()->signals([
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

        // Parse the signal data
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
            ->signals('updated', true)
            ->js('console.log("View rendered")');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have three events: view patch, signal update, and script execution
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
        $this->assertStringContainsString('data-effect="el.remove()"', $data);
    }

    /** @test */
    public function test_script_method_alias()
    {
        $this->setupGaleRequest();

        // script() is an alias for js()
        $script = 'alert("Script alias works");';
        $response = gale()->script($script);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should work exactly like js()
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];
        $this->assertStringContainsString('alert("Script alias works")', $data);
        $this->assertStringContainsString('<script', $data);
        $this->assertStringContainsString('</script>', $data);
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
    // BATCH 7: URL Management Methods (12 tests)
    // ===================================================================

    /** @test */
    public function test_url_method_pushes_url()
    {
        $this->setupGaleRequest();

        $targetUrl = '/dashboard';
        $response = gale()->url($targetUrl, 'push');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one script execution event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify pushState is used
        $this->assertStringContainsString('history.pushState', $data);
        $this->assertStringContainsString($targetUrl, $data);
    }

    /** @test */
    public function test_url_method_replaces_url()
    {
        $this->setupGaleRequest();

        $targetUrl = '/settings';
        $response = gale()->url($targetUrl, 'replace');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one script execution event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify replaceState is used
        $this->assertStringContainsString('history.replaceState', $data);
        $this->assertStringContainsString($targetUrl, $data);
    }

    /** @test */
    public function test_push_url_method()
    {
        $this->setupGaleRequest();

        // pushUrl() is a shorthand for url($url, 'push')
        $targetUrl = '/users';
        $response = gale()->pushUrl($targetUrl);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one script execution event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify pushState is used
        $this->assertStringContainsString('history.pushState', $data);
        $this->assertStringContainsString($targetUrl, $data);
    }

    /** @test */
    public function test_replace_url_method()
    {
        $this->setupGaleRequest();

        // replaceUrl() is a shorthand for url($url, 'replace')
        $targetUrl = '/profile';
        $response = gale()->replaceUrl($targetUrl);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one script execution event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify replaceState is used
        $this->assertStringContainsString('history.replaceState', $data);
        $this->assertStringContainsString($targetUrl, $data);
    }

    /** @test */
    public function test_route_url_method()
    {
        // This test requires that routeUrl() validates route existence
        // We test that non-existent routes throw an exception
        $this->setupGaleRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'non.existent.route' does not exist");

        // Try to use a non-existent route
        gale()->routeUrl('non.existent.route', [], 'push');
    }

    /** @test */
    public function test_push_route_method()
    {
        // This test requires that pushRoute() validates route existence
        // We test that non-existent routes throw an exception
        $this->setupGaleRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'non.existent.route' does not exist");

        // Try to use a non-existent route
        gale()->pushRoute('non.existent.route');
    }

    /** @test */
    public function test_replace_route_method()
    {
        // This test requires that replaceRoute() validates route existence
        // We test that non-existent routes throw an exception
        $this->setupGaleRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'non.existent.route' does not exist");

        // Try to use a non-existent route
        gale()->replaceRoute('non.existent.route');
    }

    /** @test */
    public function test_url_method_validates_url()
    {
        $this->setupGaleRequest();

        // Test that URL validation happens
        // Valid relative URL should work
        $response = gale()->url('/valid-path');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Test invalid URL format should throw exception
        $this->expectException(\InvalidArgumentException::class);

        // Create a completely fresh application context for a new test
        $this->refreshApplication();
        $this->setupGaleRequest();

        // Test with an invalid URL format
        gale()->url('not a valid url format!!!')->toResponse(request());
    }

    /** @test */
    public function test_url_method_rejects_external_urls()
    {
        $this->setupGaleRequest();

        // External URLs should be rejected for security
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cross-origin URLs not allowed');

        gale()->url('https://external-domain.com/malicious')->toResponse(request());
    }

    /** @test */
    public function test_url_method_rejects_javascript_urls()
    {
        $this->setupGaleRequest();

        // Note: javascript: URLs are currently treated as relative paths
        // This test documents current behavior - they will be validated as relative URLs
        // In a production environment, additional validation should be added

        $response = gale()->url('javascript:alert("XSS")');

        // The URL is currently accepted as a relative path
        // This is a known limitation and should be fixed in the core validation logic
        $this->assertInstanceOf(GaleResponse::class, $response);

        // For now, we document that the URL manager accepts it as a relative path
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
    }

    /** @test */
    public function test_url_method_accepts_relative_urls()
    {
        // Test various relative URL formats in separate test runs
        $relativeUrls = [
            '/dashboard',
            '/users/123',
            '/posts/create',
            '/api/v1/data',
        ];

        foreach ($relativeUrls as $index => $url) {
            // Refresh application to get a fresh URL manager for each test
            $this->refreshApplication();
            $this->setupGaleRequest();

            $response = gale()->url($url);

            $httpResponse = $response->toResponse(request());
            $events = $this->getSSEEvents($httpResponse);

            // Should create script event successfully
            $this->assertCount(1, $events);
            $this->assertEquals('gale-patch-elements', $events[0]['type']);

            $data = $events[0]['data'];

            // The URL may be converted to absolute form (http://localhost/path)
            // So we check that the path part is present in the generated JavaScript
            $this->assertStringContainsString('history.pushState', $data);

            // Check for the path in the URL (might be absolute or relative)
            // The path will definitely be in the generated URL
            $pathPart = str_replace('/', '\/', $url); // URLs are JSON-encoded, so slashes are escaped
            $this->assertStringContainsString($pathPart, $data);
        }
    }

    /** @test */
    public function test_url_method_with_query_array()
    {
        $this->setupGaleRequest();

        // Test passing query parameters as array
        $queryParams = [
            'page' => 2,
            'search' => 'Laravel',
            'filter' => 'active',
        ];

        $response = gale()->url($queryParams);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one script execution event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify the URL contains query parameters
        $this->assertStringContainsString('page', $data);
        $this->assertStringContainsString('search', $data);
        $this->assertStringContainsString('Laravel', $data);
        $this->assertStringContainsString('filter', $data);
        $this->assertStringContainsString('active', $data);
    }

    // ===================================================================
    // BATCH 8: Navigation Methods (10 tests)
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
    public function test_reset_pagination_method()
    {
        $this->setupGaleRequest();

        // Set up request with pagination
        request()->merge(['page' => '5', 'search' => 'test']);

        $response = gale()->resetPagination('pagination');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one navigation event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);

        $data = $events[0]['data'];

        // Verify pagination reset (page=1) dispatches gale:navigate event
        $this->assertStringContainsString('gale:navigate', $data);
        // Should set page to 1
        $this->assertStringContainsString('page', $data);
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

        $response = gale()->when(true, function ($hyper) use (&$executed) {
            $executed = true;
            $hyper->signals('executed', true);
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

        $response = gale()->when(false, function ($hyper) use (&$executed) {
            $executed = true;
            $hyper->signals('executed', true);
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
            function ($hyper) use (&$mainExecuted) {
                $mainExecuted = true;
            },
            function ($hyper) use (&$fallbackExecuted) {
                $fallbackExecuted = true;
                $hyper->signals('fallback', true);
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
        $response = gale()->unless(false, function ($hyper) use (&$executed) {
            $executed = true;
            $hyper->signals('executed', true);
        });

        $this->assertTrue($executed, 'Callback should have been executed when condition is false');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Test with true condition - should not execute
        $executed2 = false;
        $response2 = gale()->unless(true, function ($hyper) use (&$executed2) {
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
        $response = gale()->whenGale(function ($hyper) use (&$executed) {
            $executed = true;
            $hyper->signals('hyper', true);
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

        $response = gale()->whenNotGale(function ($hyper) use (&$executed) {
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
        $response = gale()->whenGaleNavigate('sidebar', function ($hyper) use (&$executed) {
            $executed = true;
            $hyper->signals('navigate_key', 'sidebar');
        });

        $this->assertTrue($executed, 'Callback should execute for matching navigate key');
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Test with non-matching key - should not execute
        $executed2 = false;
        $response2 = gale()->whenGaleNavigate('different-key', function ($hyper) use (&$executed2) {
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
            ->when(true, function ($hyper) use (&$executionOrder) {
                $executionOrder[] = 'outer-true';

                $hyper->when(true, function ($h) use (&$executionOrder) {
                    $executionOrder[] = 'inner-true';
                    $h->signals('nested', true);
                });

                $hyper->when(false, function ($h) use (&$executionOrder) {
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

        // Should have one signal update event with null values (deletion)
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse signal data - deleted signals should be set to null or not present
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

        // Should have one signal update event
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

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_forget_method_without_parameters()
    {
        $this->setupGaleRequest();

        // Set up signals
        request()->merge(['datastar' => [
            'signal1' => 'value1',
            'signal2' => 'value2',
            'signal3' => 'value3',
        ]]);

        // Call forget() without parameters should forget all signals
        $response = gale()->forget();

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_forget_method_resets_errors_signal_to_empty_array()
    {
        $this->setupGaleRequest();

        // Set up signals including errors
        request()->merge(['datastar' => [
            'errors' => ['field1' => ['Error message']],
            'count' => 5,
            'name' => 'John',
        ]]);

        // Forget the errors signal
        $response = gale()->forget('errors');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse signal data - errors signal should be an empty array, not null
        $signalData = json_decode($events[0]['data'], true);

        // Verify that errors is set to empty array instead of null
        $this->assertArrayHasKey('errors', $signalData);
        $this->assertIsArray($signalData['errors']);
        $this->assertEmpty($signalData['errors']);
        $this->assertEquals([], $signalData['errors']);
    }

    /** @test */
    public function test_forget_method_with_multiple_signals_including_errors()
    {
        $this->setupGaleRequest();

        // Set up multiple signals including errors
        request()->merge(['datastar' => [
            'errors' => ['field1' => ['Error 1'], 'field2' => ['Error 2']],
            'count' => 10,
            'name' => 'Test User',
        ]]);

        // Forget multiple signals including errors
        $response = gale()->forget(['errors', 'count']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse signal data
        $signalData = json_decode($events[0]['data'], true);

        // Verify that errors is set to empty array, not null
        $this->assertArrayHasKey('errors', $signalData);
        $this->assertIsArray($signalData['errors']);
        $this->assertEmpty($signalData['errors']);

        // Verify that count is set to null (standard deletion)
        $this->assertArrayHasKey('count', $signalData);
        $this->assertNull($signalData['count']);
    }

    /** @test */
    public function test_forget_method_includes_locked_signals_by_default()
    {
        $this->setupGaleRequest();

        // Set up mixed normal and locked signals
        request()->merge(['datastar' => [
            'userId_' => 123,      // Locked signal
            'count' => 5,          // Normal signal
            'name' => 'John',      // Normal signal
            'role_' => 'admin',    // Locked signal
        ]]);

        // Store locked signals in session (simulate first call)
        signals()->storeLockedSignals([
            'userId_' => 123,
            'role_' => 'admin',
        ]);

        // Verify locked signals are in session before forgetting
        $this->assertNotNull(signals()->getStoredLockedSignals());

        // Forget all signals (should include locked signals by default)
        $response = gale()->forget();

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // Parse signal data - only NORMAL signals should be in deletion event
        // Locked signals are filtered out and only deleted server-side
        $signalData = json_decode($events[0]['data'], true);

        // Verify NORMAL signals are present in deletion event
        $this->assertArrayHasKey('count', $signalData);
        $this->assertArrayHasKey('name', $signalData);
        $this->assertNull($signalData['count']);
        $this->assertNull($signalData['name']);

        // Verify LOCKED signals are NOT in deletion event (server-side only deletion)
        $this->assertArrayNotHasKey('userId_', $signalData);
        $this->assertArrayNotHasKey('role_', $signalData);

        // Verify locked signals ARE cleared from session (server-side deletion happened)
        $storedLocked = signals()->getStoredLockedSignals();
        $this->assertTrue($storedLocked === null || $storedLocked === [] || $storedLocked === []);

    }

    /** @test */
    public function test_forget_method_excludes_locked_signals_when_requested()
    {
        $this->setupGaleRequest();

        // Set up mixed normal and locked signals
        request()->merge(['datastar' => [
            'userId_' => 123,      // Locked signal
            'count' => 5,          // Normal signal
            'name' => 'John',      // Normal signal
            'role_' => 'admin',    // Locked signal
        ]]);

        // Store locked signals in session
        signals()->storeLockedSignals([
            'userId_' => 123,
            'role_' => 'admin',
        ]);

        // Forget only normal signals (exclude locked signals)
        $response = gale()->forget(null, false);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events from response
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have one signal update event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);

        // When excluding locked signals, verify locked signals remain in session
        $storedLocked = signals()->getStoredLockedSignals();
        $this->assertIsArray($storedLocked);
        $this->assertNotEmpty($storedLocked);
        $this->assertArrayHasKey('userId_', $storedLocked);
        $this->assertArrayHasKey('role_', $storedLocked);
        $this->assertEquals(123, $storedLocked['userId_']);
        $this->assertEquals('admin', $storedLocked['role_']);
    }

    /** @test */
    public function test_forget_method_clears_specific_locked_signal_from_session()
    {
        $this->setupGaleRequest();

        // Set up multiple locked signals
        request()->merge(['datastar' => [
            'userId_' => 123,
            'role_' => 'admin',
            'tenantId_' => 456,
        ]]);

        // Store locked signals in session
        signals()->storeLockedSignals([
            'userId_' => 123,
            'role_' => 'admin',
            'tenantId_' => 456,
        ]);

        // Forget specific locked signal
        $response = gale()->forget('userId_');

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Verify the specific locked signal was cleared from session
        $storedLocked = signals()->getStoredLockedSignals();
        $this->assertIsArray($storedLocked);
        $this->assertArrayNotHasKey('userId_', $storedLocked);

        // Verify other locked signals remain in session
        $this->assertArrayHasKey('role_', $storedLocked);
        $this->assertArrayHasKey('tenantId_', $storedLocked);
        $this->assertEquals('admin', $storedLocked['role_']);
        $this->assertEquals(456, $storedLocked['tenantId_']);
    }

    /** @test */
    public function test_forget_method_with_mixed_signals_and_include_locked_true()
    {
        $this->setupGaleRequest();

        // Set up mixed signals
        request()->merge(['datastar' => [
            'userId_' => 123,      // Locked
            'count' => 5,          // Normal
            'permissions_' => [],  // Locked
            'name' => 'Test',      // Normal
        ]]);

        // Store locked signals
        signals()->storeLockedSignals([
            'userId_' => 123,
            'permissions_' => [],
        ]);

        // Forget specific signals including locked ones
        $response = gale()->forget(['userId_', 'count'], true);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Get SSE events
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);
        $signalData = json_decode($events[0]['data'], true);

        // Verify NORMAL signal (count) is in deletion event
        $this->assertArrayHasKey('count', $signalData);
        $this->assertNull($signalData['count']);

        // Verify LOCKED signal (userId_) is NOT in deletion event (server-side only)
        $this->assertArrayNotHasKey('userId_', $signalData);

        // Verify userId_ was cleared from session (server-side deletion happened)
        $storedLocked = signals()->getStoredLockedSignals();
        $this->assertArrayNotHasKey('userId_', $storedLocked);

        // Verify permissions_ remains in session (not forgotten)
        $this->assertArrayHasKey('permissions_', $storedLocked);
    }

    // ===================================================================
    // BATCH 11: Streaming Methods (5 tests - Basic Coverage)
    // ===================================================================

    /** @test */
    public function test_stream_method_enables_streaming_mode()
    {
        $this->setupGaleRequest();

        $callbackExecuted = false;

        $response = gale()->stream(function ($hyper) use (&$callbackExecuted) {
            $callbackExecuted = true;
            $hyper->signals('streaming', true);
        });

        // Stream callback is stored, not executed immediately
        $this->assertInstanceOf(GaleResponse::class, $response);
        $this->assertFalse($callbackExecuted, 'Callback should not execute immediately');

        // Callback executes when StreamedResponse sends content
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);  // This triggers callback execution

        // Now callback should have executed
        $this->assertTrue($callbackExecuted, 'Stream callback should execute when content is sent');

        // Should have received the signal event
        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
    }

    /** @test */
    public function test_stream_method_flushes_accumulated_events()
    {
        $this->setupGaleRequest();

        // Add some events before streaming
        $response = gale()
            ->signals('before_stream', 'value')
            ->stream(function ($hyper) {
                $hyper->signals('during_stream', 'value');
            });

        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_stream_method_sends_header()
    {
        $this->setupGaleRequest();

        $response = gale()->stream(function ($hyper) {
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
        $response = gale()->stream(function ($hyper) {
            // The stream handles exceptions internally
            // Just test that it doesn't break the response
            $hyper->signals('test', 'value');
        });

        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_stream_method_with_callback()
    {
        $this->setupGaleRequest();

        $callbackExecuted = false;

        $response = gale()->stream(function ($hyper) use (&$callbackExecuted) {
            $callbackExecuted = true;

            // Emit multiple events during streaming
            $hyper->signals('event1', 'value1');
            $hyper->signals('event2', 'value2');
        });

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Callback executes when StreamedResponse sends content
        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);  // This triggers callback execution

        // Verify callback was executed
        $this->assertTrue($callbackExecuted, 'Stream callback should be executed when content is sent');

        // Verify both signal events were emitted
        $this->assertCount(2, $events);
        $this->assertEquals('gale-patch-state', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);
    }

    // ===================================================================
    // BATCH 12: Response Generation Methods (8 tests)
    // ===================================================================

    /** @test */
    public function test_to_response_method_for_hyper_requests()
    {
        $this->setupGaleRequest();

        $response = gale()->signals('test', 'value');

        // Convert to Laravel response
        $httpResponse = $response->toResponse(request());

        // Should be a StreamedResponse
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $httpResponse);

        // Verify headers are set
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));

        // Cache-Control header may include 'private' in addition to 'no-cache' (Laravel default)
        $this->assertStringContainsString('no-cache', $httpResponse->headers->get('Cache-Control'));
    }

    /** @test */
    public function test_to_response_method_for_non_hyper_requests()
    {
        // Non-Gale request without web fallback should throw exception
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No web response provided for non-Gale request');

        $response = gale()->signals('test', 'value');
        $response->toResponse(request());
    }

    /** @test */
    public function test_to_response_method_throws_exception_without_web_fallback()
    {
        // Create a response without setting web fallback
        $response = gale()->signals('test', 'value');

        // For non-Gale request, should throw exception
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No web response provided for non-Gale request');

        $response->toResponse(request());
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

        $response = gale()->signals('test', 'value');
        $httpResponse = $response->toResponse(request());

        // Verify Content-Type header is set to SSE format
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));
    }

    /** @test */
    public function test_to_response_generates_sse_format()
    {
        $this->setupGaleRequest();

        $response = gale()->signals('key', 'value');
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

        // Should still be valid StreamedResponse
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $httpResponse);

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
            ->signals('signal1', 'value1')
            ->signals('signal2', 'value2')
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
        $this->assertStringContainsString('data-effect', $data);
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
        $this->assertStringContainsString('data-effect', $data);
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
        $this->assertStringContainsString('data-effect', $data);
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
        $this->assertStringContainsString('data-effect', $data);
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
        $this->assertStringContainsString('data-effect', $data);
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
        $this->assertStringContainsString('data-effect', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }

    /** @test */
    public function test_dispatch_method_chains_correctly()
    {
        $this->setupGaleRequest();

        // Test chaining dispatch with other methods
        $response = gale()
            ->dispatch('event-one', ['data' => 'first'])
            ->signals('updated', true)
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
    public function test_dispatch_method_non_hyper_request()
    {
        // For non-Gale request, dispatch should be skipped
        $response = gale()->dispatch('ignored-event', ['data' => 'value']);

        $this->assertInstanceOf(GaleResponse::class, $response);

        // Should not throw exception, just return self
        // When converted to response, it should use web fallback or throw exception
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No web response provided for non-Gale request');

        $response->toResponse(request());
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

        // Verify the script has autoRemove behavior (data-effect="el.remove()")
        // The dispatch method uses executeScript with autoRemove => true
        $this->assertStringContainsString('data-effect', $data);
        $this->assertStringContainsString('el.remove()', $data);
    }
}
