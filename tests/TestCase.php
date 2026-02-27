<?php

namespace Dancycodes\Gale\Tests;

use Dancycodes\Gale\GaleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Latest response from Orchestra Testbench
     *
     * @var \Illuminate\Testing\TestResponse|null
     */
    public static $latestResponse;

    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup can go here
    }

    /**
     * Get package providers
     */
    protected function getPackageProviders($app): array
    {
        return [
            GaleServiceProvider::class,
        ];
    }

    /**
     * Define environment setup
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default configuration
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Register test views
        $app['config']->set('view.paths', [
            __DIR__ . '/Fixtures/views',
        ]);

        // Load test routes
        if (file_exists(__DIR__ . '/Fixtures/routes/test.php')) {
            $app['router']->middleware('web')
                ->group(__DIR__ . '/Fixtures/routes/test.php');
        }
    }

    /**
     * Helper to create a fake Gale request
     */
    protected function makeGaleRequest($uri = '/', $method = 'GET', $data = [])
    {
        return $this->call($method, $uri, $data, [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
    }

    /**
     * Helper to create request with state
     */
    protected function makeRequestWithState($uri, $state, $method = 'POST')
    {
        return $this->json($method, $uri, $state, [
            'Gale-Request' => 'true',
        ]);
    }

    /**
     * Helper to create request with signals (deprecated - use makeRequestWithState)
     */
    protected function makeRequestWithSignals($uri, $signals, $method = 'POST')
    {
        return $this->makeRequestWithState($uri, $signals, $method);
    }

    /**
     * Assert that response contains SSE event
     */
    protected function assertHasSSEEvent($response, $eventType)
    {
        $content = $response->getContent();
        $this->assertStringContainsString("event: {$eventType}", $content);
    }

    /**
     * Get SSE events from response
     */
    protected function getSSEEvents($response): array
    {
        // For StreamedResponse, we need to capture the output
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            // Use callback-based output buffering to capture even flushed content
            $content = '';
            $callback = function ($buffer) use (&$content) {
                $content .= $buffer;

                return ''; // Don't output anything
            };

            ob_start($callback, 1); // Chunk size of 1 to capture immediately

            try {
                $response->sendContent();

                // End our callback buffer
                while (ob_get_level() > 0) {
                    ob_end_flush(); // This will call our callback
                }
            } catch (\Throwable $e) {
                // Clean up on error
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                throw $e;
            }
        } else {
            $content = $response->getContent();
        }
        $lines = explode("\n", $content);

        $events = [];
        $currentEvent = null;
        $dataLines = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event:')) {
                // Save previous event if exists
                if ($currentEvent) {
                    $currentEvent['data'] = $this->parseSSEData($dataLines);
                    $events[] = $currentEvent;
                    $dataLines = [];
                }
                $currentEvent = ['type' => trim(substr($line, 6))];
            } elseif (str_starts_with($line, 'data:')) {
                // Collect data lines
                $dataLines[] = trim(substr($line, 5));
            } elseif (trim($line) === '' && $currentEvent) {
                // Empty line marks end of event
                $currentEvent['data'] = $this->parseSSEData($dataLines);
                $events[] = $currentEvent;
                $currentEvent = null;
                $dataLines = [];
            }
        }

        // Handle last event if not closed
        if ($currentEvent) {
            $currentEvent['data'] = $this->parseSSEData($dataLines);
            $events[] = $currentEvent;
        }

        return $events;
    }

    /**
     * Parse SSE data lines for state
     */
    protected function parseSSEData(array $dataLines): string
    {
        // For state events, extract the JSON from "state {json}" lines
        $stateData = [];
        foreach ($dataLines as $line) {
            if (str_starts_with($line, 'state ')) {
                $stateData[] = substr($line, 6); // Remove "state " prefix
            }
            // Also check for legacy "signals " prefix
            if (str_starts_with($line, 'signals ')) {
                $stateData[] = substr($line, 8); // Remove "signals " prefix
            }
        }

        // If we have state data lines, join them (for multi-line JSON)
        if (!empty($stateData)) {
            return implode('', $stateData);
        }

        // Otherwise, return all data lines joined
        return implode("\n", $dataLines);
    }
}
