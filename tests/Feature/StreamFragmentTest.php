<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamFragmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup Gale request
        request()->headers->set('Gale-Request', 'true');
        request()->headers->set('Accept', 'text/event-stream');
    }

    /** @test */
    public function stream_with_fragment_returns_streamed_response()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content');
        });

        $httpResponse = $response->toResponse(request());

        $this->assertInstanceOf(StreamedResponse::class, $httpResponse);
        $this->assertEquals('text/event-stream', $httpResponse->headers->get('Content-Type'));
    }

    /** @test */
    public function stream_fragment_generates_correct_sse_format()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content');
        });

        $httpResponse = $response->toResponse(request());

        // Use TestCase helper to capture StreamedResponse content
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(1, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);
    }

    /** @test */
    public function stream_fragment_with_mode_option_includes_mode_in_sse()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content', options: ['mode' => 'replace']);
        });

        $httpResponse = $response->toResponse(request());

        // Capture streamed content
        ob_start();
        $httpResponse->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('event: gale-patch-elements', $content);
        $this->assertStringContainsString('data: mode replace', $content);
    }

    /** @test */
    public function stream_with_multiple_operations_sends_multiple_events()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content');
            $gale->state('count', 42);
            $gale->component('cart', ['total' => 100]);
        });

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(3, $events);
        $this->assertEquals('gale-patch-elements', $events[0]['type']);
        $this->assertEquals('gale-patch-state', $events[1]['type']);
        $this->assertEquals('gale-patch-component', $events[2]['type']);
    }

    /** @test */
    public function stream_fragment_with_all_mode_options()
    {
        $modes = ['morph', 'morph_inner', 'replace', 'append', 'prepend', 'before', 'after', 'remove'];

        foreach ($modes as $mode) {
            $response = gale()->stream(function ($gale) use ($mode) {
                $gale->fragment('simple', 'content', options: ['mode' => $mode]);
            });

            $httpResponse = $response->toResponse(request());

            ob_start();
            $httpResponse->sendContent();
            $content = ob_get_clean();

            $this->assertStringContainsString("data: mode {$mode}", $content, "Mode {$mode} not found in SSE output");
        }
    }

    /** @test */
    public function stream_fragment_with_selector_option()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content', options: [
                'selector' => '#target',
                'mode' => 'replace',
            ]);
        });

        $httpResponse = $response->toResponse(request());

        ob_start();
        $httpResponse->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('data: selector #target', $content);
        $this->assertStringContainsString('data: mode replace', $content);
    }

    /** @test */
    public function stream_fragment_with_use_view_transition()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content', options: [
                'useViewTransition' => true,
            ]);
        });

        $httpResponse = $response->toResponse(request());

        ob_start();
        $httpResponse->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('data: useViewTransition true', $content);
    }

    /** @test */
    public function stream_fragment_with_settle_and_limit_options()
    {
        $response = gale()->stream(function ($gale) {
            $gale->fragment('simple', 'content', options: [
                'settle' => 300,
                'limit' => 5,
            ]);
        });

        $httpResponse = $response->toResponse(request());

        ob_start();
        $httpResponse->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('data: settle 300', $content);
        $this->assertStringContainsString('data: limit 5', $content);
    }

    /** @test */
    public function stream_handles_exceptions_gracefully()
    {
        $response = gale()->stream(function ($gale) {
            $gale->state('step', 1);
            throw new \Exception('Test exception');
        });

        $httpResponse = $response->toResponse(request());

        // Should return StreamedResponse even with exception
        $this->assertInstanceOf(StreamedResponse::class, $httpResponse);
    }

    /** @test */
    public function stream_with_progressive_updates()
    {
        $response = gale()->stream(function ($gale) {
            foreach ([20, 40, 60, 80, 100] as $progress) {
                $gale->state('progress', $progress);
            }
        });

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        $this->assertCount(5, $events);
        foreach ($events as $event) {
            $this->assertEquals('gale-patch-state', $event['type']);
        }
    }

    /** @test */
    public function stream_accumulates_events_before_callback()
    {
        $response = gale()
            ->state('before', 'value1')
            ->stream(function ($gale) {
                $gale->state('during', 'value2');
            });

        $httpResponse = $response->toResponse(request());
        $events = $this->getSSEEvents($httpResponse);

        // Should have both events
        $this->assertCount(2, $events);
    }
}
