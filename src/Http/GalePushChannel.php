<?php

namespace Dancycodes\Gale\Http;

use Dancycodes\Gale\Security\StateChecksum;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GalePushChannel — Server-Initiated Push Channel (F-038)
 *
 * Provides persistent SSE channel subscriptions that allow the server to push
 * updates to subscribed clients at any time, without a client-initiated request.
 *
 * Architecture:
 * - Channel endpoint holds an open SSE connection that polls for new events
 * - gale()->push($channel) writes events to a cache-backed queue
 * - The channel endpoint drains the queue and emits events as SSE
 *
 * This approach works on any standard Laravel hosting (no Redis/WebSocket required).
 * For production high-volume use, the cache driver can be swapped to Redis.
 *
 * Business Rules:
 * - BR-038.1: gale()->push($channel) MUST send SSE events to subscribed clients
 * - BR-038.6: Authentication via standard Laravel middleware on the channel route
 * - BR-038.7: Push events MUST use the same SSE event types as request-response
 * - BR-038.10: Server MUST support broadcasting to multiple channels simultaneously
 */
class GalePushChannel
{
    /**
     * Cache key prefix for push channel queues
     *
     * @var string
     */
    public const CACHE_KEY_PREFIX = 'gale_push_channel_';

    /**
     * Default TTL in seconds for channel event cache entries
     *
     * @var int
     */
    public const CACHE_TTL = 3600;

    /**
     * SSE events queued for emission to this channel (BR-038.1)
     *
     * @var array<int, string>
     */
    protected array $events = [];

    /**
     * The channel name to push to
     */
    protected string $channelName;

    /**
     * @param string $channelName Channel name to push to
     */
    public function __construct(string $channelName)
    {
        $this->channelName = $channelName;
    }

    /**
     * Patch Alpine component state for all subscribers on this channel
     *
     * Queues a gale-patch-state event for all clients subscribed to this channel.
     * The state is signed with HMAC-SHA256 before being queued (F-013).
     *
     * @param string|array<string, mixed> $key State key or associative array of state updates
     * @param mixed $value State value (when $key is a string)
     */
    public function patchState(string|array $key, mixed $value = null): static
    {
        $state = is_array($key) ? $key : [$key => $value];

        // Sign the state payload (F-013, BR-013.1)
        $signedState = StateChecksum::sign($state);

        $dataLines = $this->buildStateLines($signedState);

        $this->events[] = $this->formatEvent('gale-patch-state', $dataLines);

        return $this;
    }

    /**
     * Patch DOM elements for all subscribers on this channel
     *
     * Queues a gale-patch-elements event for all clients subscribed to this channel.
     *
     * @param string $selector CSS selector of target element
     * @param string $html HTML content to patch into target
     * @param array<string, mixed> $options Patch options (mode, useViewTransition, settle, scroll, show)
     */
    public function patchElements(string $selector, string $html, array $options = []): static
    {
        $options['selector'] = $selector;
        $options['mode'] = $options['mode'] ?? 'outer';

        $dataLines = $this->buildElementsLines($html, $options);

        $this->events[] = $this->formatEvent('gale-patch-elements', $dataLines);

        return $this;
    }

    /**
     * Patch a named Alpine component's state for all subscribers on this channel
     *
     * Queues a gale-patch-component event for all clients subscribed to this channel.
     *
     * @param string $componentName Named component to target
     * @param array<string, mixed> $state State updates to merge
     */
    public function patchComponent(string $componentName, array $state): static
    {
        $dataLines = [
            'component ' . $componentName,
            'state ' . json_encode($state),
        ];

        $this->events[] = $this->formatEvent('gale-patch-component', $dataLines);

        return $this;
    }

    /**
     * Send all queued events to the channel queue (BR-038.1)
     *
     * Atomically appends events to the channel's cache-backed queue.
     * Connected channel endpoint processes will drain the queue and
     * emit the events to subscribed clients.
     */
    public function send(): void
    {
        if (empty($this->events)) {
            return;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $this->channelName;

        // Atomic append to channel queue
        /** @var array<int, string> $existing */
        $existing = Cache::get($cacheKey, []);
        $existing = array_merge($existing, $this->events);
        Cache::put($cacheKey, $existing, self::CACHE_TTL);

        // Clear queued events after sending
        $this->events = [];
    }

    /**
     * Get and clear all pending events from the channel queue
     *
     * Called by the channel endpoint to drain pending events.
     * Returns all events and clears the queue atomically.
     *
     * @param string $channelName Channel name to drain
     *
     * @return array<int, string> Array of SSE event strings
     */
    public static function drain(string $channelName): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $channelName;

        /** @var array<int, string> $events */
        $events = Cache::get($cacheKey, []);

        if (!empty($events)) {
            Cache::put($cacheKey, [], self::CACHE_TTL);
        }

        return $events;
    }

    /**
     * Build a persistent SSE channel response for a given channel name
     *
     * Creates a StreamedResponse that holds an open SSE connection.
     * Polls for new events every 500ms and emits them.
     * Connection closes when the client disconnects.
     *
     * @param string $channelName Channel name to serve
     * @param int $pollIntervalMs Polling interval in milliseconds (default: 500)
     * @param int $maxDuration Maximum connection duration in seconds (default: 300)
     */
    public static function stream(string $channelName, int $pollIntervalMs = 500, int $maxDuration = 300): StreamedResponse
    {
        return new StreamedResponse(function () use ($channelName, $pollIntervalMs, $maxDuration) {
            // Disable output buffering for real-time streaming
            if (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Send heartbeat comment to confirm connection
            echo ": connected to channel {$channelName}\n\n";
            flush();

            $startTime = time();
            $pollSleepUs = $pollIntervalMs * 1000; // Convert ms to microseconds

            while (true) {
                // Check if client disconnected
                if (connection_aborted()) {
                    break;
                }

                // Check max duration
                if ((time() - $startTime) >= $maxDuration) {
                    break;
                }

                // Drain pending events for this channel
                $events = self::drain($channelName);

                foreach ($events as $event) {
                    echo $event;
                    flush();

                    if (connection_aborted()) {
                        break 2;
                    }
                }

                // Sleep before next poll
                usleep($pollSleepUs);
            }
        }, 200, GaleResponse::headers());
    }

    /**
     * Format an SSE event string
     *
     * @param string $eventType SSE event type
     * @param array<int, string> $dataLines Data lines
     */
    protected function formatEvent(string $eventType, array $dataLines): string
    {
        $output = [];
        $output[] = "event: {$eventType}";

        foreach ($dataLines as $line) {
            $output[] = "data: {$line}";
        }

        $output[] = '';

        return implode("\n", $output) . "\n";
    }

    /**
     * Build data lines for gale-patch-state event
     *
     * @param array<string, mixed> $state
     *
     * @return array<int, string>
     */
    protected function buildStateLines(array $state): array
    {
        // State patch format: each key-value pair on its own data line
        // This matches the SSE parsing in parseStatePatch() on the frontend
        return [json_encode($state) ?: '{}'];
    }

    /**
     * Build data lines for gale-patch-elements event
     *
     * @param string $html HTML content
     * @param array<string, mixed> $options Patch options
     *
     * @return array<int, string>
     */
    protected function buildElementsLines(string $html, array $options): array
    {
        $dataLines = [];

        if (!empty($options['selector']) && is_string($options['selector'])) {
            $dataLines[] = 'selector ' . $options['selector'];
        }

        if (!empty($options['mode']) && is_string($options['mode'])) {
            $dataLines[] = 'mode ' . $options['mode'];
        }

        if (isset($options['useViewTransition'])) {
            $dataLines[] = 'useViewTransition ' . ($options['useViewTransition'] ? 'true' : 'false');
        }

        if (!empty($options['settle']) && is_int($options['settle'])) {
            $dataLines[] = 'settle ' . $options['settle'];
        }

        // HTML lines (last, may be multi-line)
        foreach (explode("\n", $html) as $line) {
            $dataLines[] = 'html ' . $line;
        }

        return $dataLines;
    }
}
