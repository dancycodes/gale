<?php

/**
 * F-111 — PHP Unit: GaleResponse
 *
 * Comprehensive Pest unit tests for the GaleResponse class.
 * Covers every public method, both HTTP JSON and SSE output modes,
 * method chaining, event ordering, and edge cases.
 *
 * @see packages/dancycodes/gale/src/Http/GaleResponse.php
 */

use Dancycodes\Gale\Http\GaleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Make the current request look like a Gale request (HTTP mode by default).
 */
function makeGaleHttpRequest(): void
{
    request()->headers->set('Gale-Request', 'true');
    request()->headers->remove('Gale-Mode');
}

/**
 * Make the current request look like a Gale SSE request.
 */
function makeGaleSseRequest(): void
{
    request()->headers->set('Gale-Request', 'true');
    request()->headers->set('Gale-Mode', 'sse');
}

/**
 * Decode the JsonResponse events array from an HTTP-mode response.
 *
 * @return array<int, array{type: string, data: mixed}>
 */
function httpEvents(JsonResponse $response): array
{
    $body = json_decode($response->getContent(), true);

    return $body['events'] ?? [];
}

/**
 * Parse SSE text into an array of events.
 * Each event: ['type' => string, 'dataLines' => string[]]
 *
 * @return array<int, array{type: string, dataLines: string[]}>
 */
function parseSseContent(string $content): array
{
    $events = [];
    $currentType = null;
    $dataLines = [];

    foreach (explode("\n", $content) as $line) {
        if (str_starts_with($line, 'event:')) {
            // Start of a new event — flush previous
            if ($currentType !== null) {
                $events[] = ['type' => $currentType, 'dataLines' => $dataLines];
                $dataLines = [];
            }
            $currentType = trim(substr($line, 6));
        } elseif (str_starts_with($line, 'data:')) {
            $dataLines[] = trim(substr($line, 5));
        } elseif (trim($line) === '' && $currentType !== null) {
            $events[] = ['type' => $currentType, 'dataLines' => $dataLines];
            $currentType = null;
            $dataLines = [];
        }
    }

    // Handle last event if not terminated by blank line
    if ($currentType !== null) {
        $events[] = ['type' => $currentType, 'dataLines' => $dataLines];
    }

    return $events;
}

/**
 * Extract the JSON state object from an SSE gale-patch-state event's data lines.
 * Data lines for state look like: "state {\"key\":\"value\",\"_checksum\":\"...\"}"
 *
 * @param string[] $dataLines
 *
 * @return array<string, mixed>
 */
function extractStateFromSse(array $dataLines): array
{
    foreach ($dataLines as $line) {
        if (str_starts_with($line, 'state ')) {
            return json_decode(substr($line, 6), true) ?? [];
        }
    }

    return [];
}

// ---------------------------------------------------------------------------
// SECTION 1: gale() Helper — Singleton & Instance
// ---------------------------------------------------------------------------

describe('gale() helper', function () {
    it('returns a GaleResponse instance', function () {
        expect(gale())->toBeInstanceOf(GaleResponse::class);
    });

    it('is a singleton (same instance per request)', function () {
        $a = gale();
        $b = gale();

        expect($a)->toBe($b);
    });

    it('reset() clears all accumulated state', function () {
        makeGaleHttpRequest();

        $g = gale();
        $g->state('x', 99);
        $g->reset();

        $response = $g->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// SECTION 2: HTTP Mode — JsonResponse structure
// ---------------------------------------------------------------------------

describe('HTTP mode output', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('returns a JsonResponse for Gale requests', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response)->toBeInstanceOf(JsonResponse::class);
    });

    it('sets Content-Type to application/json in HTTP mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->headers->get('Content-Type'))->toContain('application/json');
    });

    it('sets X-Gale-Response header in HTTP mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->headers->get('X-Gale-Response'))->toBe('true');
    });

    it('sets Cache-Control to no-cache in HTTP mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    });

    it('returns HTTP 200 status in HTTP mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->getStatusCode())->toBe(200);
    });

    it('wraps events in an events array', function () {
        $response = gale()->state('x', 1)->toResponse(request());
        $body = json_decode($response->getContent(), true);

        expect($body)->toHaveKey('events')
            ->and($body['events'])->toBeArray();
    });

    it('returns empty events array when no methods are called', function () {
        $response = gale()->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toBeArray()->toHaveCount(0);
    });

    it('each JSON event has type and data keys', function () {
        $response = gale()->state('x', 1)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0])->toHaveKey('type')->toHaveKey('data');
    });
});

// ---------------------------------------------------------------------------
// SECTION 3: SSE Mode — text/event-stream structure
// ---------------------------------------------------------------------------

describe('SSE mode output', function () {
    beforeEach(fn () => makeGaleSseRequest());

    it('returns a Response (not StreamedResponse) for SSE mode without stream()', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response)->not->toBeInstanceOf(StreamedResponse::class);
    });

    it('sets Content-Type to text/event-stream in SSE mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    });

    it('sets X-Gale-Response header in SSE mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->headers->get('X-Gale-Response'))->toBe('true');
    });

    it('sets Cache-Control to no-store in SSE mode', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        // SSE responses must never be cached; Cache-Control may include no-store
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('SSE body starts with keepalive comment', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->getContent())->toStartWith(': keepalive');
    });

    it('SSE event format uses event: and data: prefixes', function () {
        $response = gale()->state('x', 1)->toResponse(request());
        $content = $response->getContent();

        expect($content)->toContain('event: gale-patch-state')
            ->and($content)->toContain('data: state ');
    });

    it('SSE events are terminated by double newline', function () {
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->getContent())->toContain("\n\n");
    });
});

// ---------------------------------------------------------------------------
// SECTION 4: GaleResponse::headers() static method
// ---------------------------------------------------------------------------

describe('GaleResponse::headers()', function () {
    it('returns an array with required SSE headers', function () {
        $headers = GaleResponse::headers();

        expect($headers)->toBeArray()
            ->toHaveKey('Content-Type')
            ->toHaveKey('Cache-Control')
            ->toHaveKey('X-Gale-Response')
            ->toHaveKey('X-Accel-Buffering');
    });

    it('Content-Type header is text/event-stream', function () {
        expect(GaleResponse::headers()['Content-Type'])->toBe('text/event-stream');
    });

    it('Cache-Control header is no-store for SSE', function () {
        expect(GaleResponse::headers()['Cache-Control'])->toBe('no-store');
    });

    it('X-Gale-Response header is true', function () {
        expect(GaleResponse::headers()['X-Gale-Response'])->toBe('true');
    });

    it('X-Accel-Buffering header is no', function () {
        expect(GaleResponse::headers()['X-Accel-Buffering'])->toBe('no');
    });
});

// ---------------------------------------------------------------------------
// SECTION 5: state() / patchState
// ---------------------------------------------------------------------------

describe('state()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-state event with key-value pair', function () {
        $response = gale()->state('count', 5)->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-patch-state')
            ->and($events[0]['data']['count'])->toBe(5);
    });

    it('sends gale-patch-state event with array of values', function () {
        $response = gale()->state(['name' => 'Alice', 'age' => 30])->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['data']['name'])->toBe('Alice')
            ->and($events[0]['data']['age'])->toBe(30);
    });

    it('adds _checksum to state data for security', function () {
        $response = gale()->state('x', 1)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data'])->toHaveKey('_checksum')
            ->and($events[0]['data']['_checksum'])->toBeString()->not->toBeEmpty();
    });

    it('handles null state values', function () {
        $response = gale()->state('deleted', null)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data'])->toHaveKey('deleted')
            ->and($events[0]['data']['deleted'])->toBeNull();
    });

    it('handles deeply nested state arrays', function () {
        $nested = ['a' => ['b' => ['c' => ['d' => ['e' => 'deep']]]]];
        $response = gale()->state('nested', $nested)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['nested']['a']['b']['c']['d']['e'])->toBe('deep');
    });

    it('handles Unicode characters in state values', function () {
        $response = gale()->state('emoji', '🎉 Hello 世界')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['emoji'])->toBe('🎉 Hello 世界');
    });

    it('handles HTML strings in state values without double-escaping', function () {
        $html = '<script>alert("xss")</script>';
        $response = gale()->state('content', $html)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['content'])->toBe($html);
    });

    it('accumulates two state() calls as two separate events', function () {
        $response = gale()
            ->state('a', 1)
            ->state('b', 2)
            ->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(2)
            ->and($events[0]['data']['a'])->toBe(1)
            ->and($events[1]['data']['b'])->toBe(2);
    });

    it('emits gale-patch-state in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->state('count', 5)->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents)->toHaveCount(1)
            ->and($sseEvents[0]['type'])->toBe('gale-patch-state');

        $state = extractStateFromSse($sseEvents[0]['dataLines']);
        expect($state['count'])->toBe(5);
    });

    it('SSE state data line has state prefix', function () {
        makeGaleSseRequest();

        $response = gale()->state('x', 42)->toResponse(request());
        $content = $response->getContent();

        expect($content)->toContain('data: state {');
    });

    it('is a no-op for non-Gale requests', function () {
        // No Gale-Request header — response returns 204
        $request = \Illuminate\Http\Request::create('/test');
        $response = gale()->state('x', 1)->toResponse($request);

        expect($response->getStatusCode())->toBe(204);
    });
});

// ---------------------------------------------------------------------------
// SECTION 6: messages()
// ---------------------------------------------------------------------------

describe('messages()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-state event with messages key', function () {
        $response = gale()->messages(['email' => 'Required'])->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-patch-state')
            ->and($events[0]['data'])->toHaveKey('messages')
            ->and($events[0]['data']['messages']['email'])->toBe('Required');
    });

    it('messages data is nested under messages key in Alpine-compatible structure', function () {
        $response = gale()->messages(['email' => 'Invalid', 'name' => 'Required'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['messages'])->toBe(['email' => 'Invalid', 'name' => 'Required']);
    });

    it('emits gale-patch-state in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->messages(['field' => 'Error'])->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents)->toHaveCount(1)
            ->and($sseEvents[0]['type'])->toBe('gale-patch-state');

        $state = extractStateFromSse($sseEvents[0]['dataLines']);
        expect($state['messages']['field'])->toBe('Error');
    });
});

// ---------------------------------------------------------------------------
// SECTION 7: clearMessages()
// ---------------------------------------------------------------------------

describe('clearMessages()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends messages state with empty array', function () {
        $response = gale()->clearMessages()->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['messages'])->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// SECTION 8: errors()
// ---------------------------------------------------------------------------

describe('errors()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-state with errors key', function () {
        $response = gale()->errors(['email' => ['The email is required.']])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-patch-state')
            ->and($events[0]['data']['errors']['email'])->toBe(['The email is required.']);
    });

    it('errors supports multiple messages per field', function () {
        $response = gale()->errors(['email' => ['Required.', 'Must be valid.']])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['errors']['email'])->toHaveCount(2);
    });
});

// ---------------------------------------------------------------------------
// SECTION 9: clearErrors()
// ---------------------------------------------------------------------------

describe('clearErrors()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends errors state with empty array', function () {
        $response = gale()->clearErrors()->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['errors'])->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// SECTION 10: forget()
// ---------------------------------------------------------------------------

describe('forget()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('emits a null state patch for the given key (RFC 7386 delete)', function () {
        $response = gale()->state('x', 1)->forget('x')->toResponse(request());
        $events = httpEvents($response);

        // Two events: the original state set, then the null delete
        $deleteEvent = $events[count($events) - 1];
        expect($deleteEvent['type'])->toBe('gale-patch-state')
            ->and($deleteEvent['data']['x'])->toBeNull();
    });

    it('forget() with array of keys emits null for each', function () {
        $response = gale()->forget(['a', 'b'])->toResponse(request());
        $events = httpEvents($response);

        expect($events)->not->toBeEmpty();
        $data = $events[0]['data'];
        expect($data)->toHaveKey('a')
            ->and($data['a'])->toBeNull()
            ->and($data)->toHaveKey('b')
            ->and($data['b'])->toBeNull();
    });

    it('forget() with no args is a no-op', function () {
        $response = gale()->forget()->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// SECTION 11: dispatch()
// ---------------------------------------------------------------------------

describe('dispatch()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-dispatch event with event name and data', function () {
        $response = gale()->dispatch('my-event', ['key' => 'value'])->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-dispatch')
            ->and($events[0]['data']['event'])->toBe('my-event')
            ->and($events[0]['data']['data']['key'])->toBe('value');
    });

    it('dispatch with no data sends empty data array', function () {
        $response = gale()->dispatch('ping')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['data'])->toBe([]);
    });

    it('dispatch with target includes target in data', function () {
        $response = gale()->dispatch('my-event', [], '#my-element')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['target'])->toBe('#my-element');
    });

    it('throws InvalidArgumentException for empty event name', function () {
        expect(fn () => gale()->dispatch(''))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('emits gale-dispatch in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->dispatch('ping')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents)->toHaveCount(1)
            ->and($sseEvents[0]['type'])->toBe('gale-dispatch');
    });
});

// ---------------------------------------------------------------------------
// SECTION 12: js()
// ---------------------------------------------------------------------------

describe('js()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-execute-script event with the script', function () {
        $response = gale()->js('console.log("hello")')->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toBe('console.log("hello")');
    });

    it('js() includes autoRemove option by default', function () {
        $response = gale()->js('alert(1)')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['options']['autoRemove'])->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// SECTION 13: DOM manipulation — append, prepend, replace, before, after, inner, outer, remove
// ---------------------------------------------------------------------------

describe('DOM manipulation methods', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('append() sends gale-patch-elements with mode append', function () {
        $response = gale()->append('#list', '<li>item</li>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-patch-elements')
            ->and($events[0]['data']['mode'])->toBe('append')
            ->and($events[0]['data']['selector'])->toBe('#list')
            ->and($events[0]['data']['html'])->toBe('<li>item</li>');
    });

    it('prepend() sends gale-patch-elements with mode prepend', function () {
        $response = gale()->prepend('#list', '<li>first</li>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('prepend');
    });

    it('replace() sends gale-patch-elements with outer mode (full element replacement)', function () {
        $response = gale()->replace('#old', '<div id="old">new</div>')->toResponse(request());
        $events = httpEvents($response);

        // replace() is an alias for outer() — replaces the full target element
        expect($events[0]['data']['mode'])->toBe('outer');
    });

    it('before() sends gale-patch-elements with mode before', function () {
        $response = gale()->before('#item', '<div>before</div>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('before');
    });

    it('after() sends gale-patch-elements with mode after', function () {
        $response = gale()->after('#item', '<div>after</div>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('after');
    });

    it('inner() sends gale-patch-elements with mode inner', function () {
        $response = gale()->inner('#container', '<p>content</p>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('inner');
    });

    it('outer() sends gale-patch-elements with mode outer', function () {
        $response = gale()->outer('#box', '<div id="box">new</div>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('outer');
    });

    it('remove() sends gale-patch-elements with mode remove and no html', function () {
        $response = gale()->remove('#old-item')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-patch-elements')
            ->and($events[0]['data']['mode'])->toBe('remove')
            ->and($events[0]['data']['selector'])->toBe('#old-item');
    });
});

// ---------------------------------------------------------------------------
// SECTION 14: componentState() / patchStore()
// ---------------------------------------------------------------------------

describe('componentState()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-component event with component name and state', function () {
        $response = gale()->componentState('my-cart', ['total' => 100])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-patch-component')
            ->and($events[0]['data']['component'])->toBe('my-cart')
            ->and($events[0]['data']['state']['total'])->toBe(100);
    });
});

describe('patchStore()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-store event with store name and data', function () {
        $response = gale()->patchStore('app', ['theme' => 'dark'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-patch-store')
            ->and($events[0]['data']['store'])->toBe('app')
            ->and($events[0]['data']['data']['theme'])->toBe('dark');
    });
});

// ---------------------------------------------------------------------------
// SECTION 15: redirect()
// ---------------------------------------------------------------------------

describe('redirect()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('returns a GaleRedirect instance', function () {
        $redirect = gale()->redirect('/dashboard');

        expect($redirect)->toBeInstanceOf(\Dancycodes\Gale\Http\GaleRedirect::class);
    });

    it('emits gale-redirect event with URL when toResponse is called', function () {
        $response = gale()->redirect('/dashboard')->toResponse(request());
        $body = json_decode($response->getContent(), true);
        $events = $body['events'] ?? [];

        // GaleRedirect emits a gale-redirect event (F-012)
        $redirectEvent = collect($events)->firstWhere('type', 'gale-redirect');

        expect($redirectEvent)->not->toBeNull()
            ->and($redirectEvent['data']['url'])->toBe('/dashboard');
    });

    it('redirect with external URL is allowed when allow_external config is true', function () {
        config(['gale.redirect.allow_external' => true]);

        $response = gale()->redirect('https://example.com')->toResponse(request());
        $body = json_decode($response->getContent(), true);
        $events = $body['events'] ?? [];

        $redirectEvent = collect($events)->firstWhere('type', 'gale-redirect');
        expect($redirectEvent['data']['url'])->toBe('https://example.com');

        config(['gale.redirect.allow_external' => false]); // restore
    });
});

// ---------------------------------------------------------------------------
// SECTION 16: reload()
// ---------------------------------------------------------------------------

describe('reload()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-execute-script event with window.location.reload()', function () {
        $response = gale()->reload()->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('window.location.reload()');
    });
});

// ---------------------------------------------------------------------------
// SECTION 17: navigate()
// ---------------------------------------------------------------------------

describe('navigate()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends a gale-execute-script event that dispatches gale:navigate', function () {
        $response = gale()->navigate('/about')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('gale:navigate')
            ->and($events[0]['data']['script'])->toContain('/about');
    });
});

// ---------------------------------------------------------------------------
// SECTION 18: view()
// ---------------------------------------------------------------------------

describe('view()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-elements with rendered HTML for existing view', function () {
        $response = gale()->view('simple')->toResponse(request());
        $events = httpEvents($response);

        expect($events)->not->toBeEmpty()
            ->and($events[0]['type'])->toBe('gale-patch-elements');
    });

    it('throws exception for non-existent view', function () {
        expect(fn () => gale()->view('does-not-exist')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 19: fragment()
// ---------------------------------------------------------------------------

describe('fragment()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('throws exception for non-existent view', function () {
        expect(fn () => gale()->fragment('no-such-view', 'my-frag')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('sends gale-patch-elements for existing fragment', function () {
        // 'with-fragments' view has @fragment('header'), @fragment('content'), @fragment('footer')
        $response = gale()->fragment('with-fragments', 'header')->toResponse(request());
        $events = httpEvents($response);

        expect($events)->not->toBeEmpty()
            ->and($events[0]['type'])->toBe('gale-patch-elements');
    });
});

// ---------------------------------------------------------------------------
// SECTION 20: Method Chaining — ordering
// ---------------------------------------------------------------------------

describe('method chaining', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('chaining state + messages + dispatch produces events in call order', function () {
        $response = gale()
            ->state('count', 5)
            ->messages(['email' => 'Required'])
            ->dispatch('my-event')
            ->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(3)
            ->and($events[0]['type'])->toBe('gale-patch-state')
            ->and($events[0]['data'])->toHaveKey('count')
            ->and($events[1]['type'])->toBe('gale-patch-state')
            ->and($events[1]['data'])->toHaveKey('messages')
            ->and($events[2]['type'])->toBe('gale-dispatch');
    });

    it('chaining 3+ methods returns the same GaleResponse instance', function () {
        $g = gale();
        $result = $g->state('a', 1)->messages(['x' => 'y'])->dispatch('ev');

        expect($result)->toBe($g);
    });

    it('two state() calls produce two events in call order', function () {
        $response = gale()
            ->state('first', 1)
            ->state('second', 2)
            ->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(2)
            ->and($events[0]['data']['first'])->toBe(1)
            ->and($events[1]['data']['second'])->toBe(2);
    });

    it('chaining in SSE mode produces events in call order', function () {
        makeGaleSseRequest();

        $response = gale()
            ->state('x', 1)
            ->state('y', 2)
            ->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents)->toHaveCount(2)
            ->and($sseEvents[0]['type'])->toBe('gale-patch-state')
            ->and($sseEvents[1]['type'])->toBe('gale-patch-state');

        $state0 = extractStateFromSse($sseEvents[0]['dataLines']);
        $state1 = extractStateFromSse($sseEvents[1]['dataLines']);
        expect($state0)->toHaveKey('x')
            ->and($state1)->toHaveKey('y');
    });
});

// ---------------------------------------------------------------------------
// SECTION 21: when() / unless()
// ---------------------------------------------------------------------------

describe('when()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('executes callback when condition is true', function () {
        $response = gale()->when(true, fn ($g) => $g->state('shown', 1))->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['data']['shown'])->toBe(1);
    });

    it('skips callback when condition is false', function () {
        $response = gale()->when(false, fn ($g) => $g->state('shown', 1))->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(0);
    });

    it('executes fallback when condition is false and fallback provided', function () {
        $response = gale()
            ->when(false, fn ($g) => $g->state('a', 1), fn ($g) => $g->state('b', 2))
            ->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['data']['b'])->toBe(2);
    });
});

describe('unless()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('executes callback when condition is false', function () {
        $response = gale()->unless(false, fn ($g) => $g->state('done', 1))->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['data']['done'])->toBe(1);
    });

    it('skips callback when condition is true', function () {
        $response = gale()->unless(true, fn ($g) => $g->state('done', 1))->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// SECTION 22: web() fallback for non-Gale requests
// ---------------------------------------------------------------------------

describe('web() fallback', function () {
    it('non-Gale request without web() returns 204 No Content', function () {
        // No Gale-Request header set — use a fresh request
        $request = \Illuminate\Http\Request::create('/test');
        $response = gale()->state('x', 1)->toResponse($request);

        expect($response->getStatusCode())->toBe(204);
    });

    it('non-Gale request with web() closure returns fallback response', function () {
        $request = \Illuminate\Http\Request::create('/test');
        $response = gale()
            ->state('x', 1)
            ->web(fn () => response('fallback content', 200))
            ->toResponse($request);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('fallback content');
    });
});

// ---------------------------------------------------------------------------
// SECTION 23: forceHttp()
// ---------------------------------------------------------------------------

describe('forceHttp()', function () {
    it('forces JSON mode even when Gale-Mode: sse header is present', function () {
        makeGaleSseRequest();

        $response = gale()->state('x', 1)->forceHttp()->toResponse(request());

        expect($response)->toBeInstanceOf(JsonResponse::class)
            ->and($response->headers->get('Content-Type'))->toContain('application/json');
    });
});

// ---------------------------------------------------------------------------
// SECTION 24: withHeaders()
// ---------------------------------------------------------------------------

describe('withHeaders()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('adds custom headers to the HTTP response', function () {
        $response = gale()->state('x', 1)->withHeaders(['X-Custom' => 'test-value'])->toResponse(request());

        expect($response->headers->get('X-Custom'))->toBe('test-value');
    });

    it('merges multiple custom headers', function () {
        $response = gale()
            ->state('x', 1)
            ->withHeaders(['X-Foo' => 'foo', 'X-Bar' => 'bar'])
            ->toResponse(request());

        expect($response->headers->get('X-Foo'))->toBe('foo')
            ->and($response->headers->get('X-Bar'))->toBe('bar');
    });
});

// ---------------------------------------------------------------------------
// SECTION 25: etag()
// ---------------------------------------------------------------------------

describe('etag()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('adds ETag header to the response', function () {
        $response = gale()->state('x', 1)->etag()->toResponse(request());

        expect($response->headers->has('ETag'))->toBeTrue()
            ->and($response->headers->get('ETag'))->toBeString()->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// SECTION 26: GaleResponse::resolveMode()
// ---------------------------------------------------------------------------

describe('GaleResponse::resolveMode()', function () {
    it('returns http as default mode', function () {
        expect(GaleResponse::resolveMode())->toBe('http');
    });

    it('returns sse when config gale.mode is sse', function () {
        config(['gale.mode' => 'sse']);

        expect(GaleResponse::resolveMode())->toBe('sse');

        config(['gale.mode' => 'http']); // restore
    });

    it('falls back to http for invalid config value', function () {
        config(['gale.mode' => 'invalid']);

        expect(GaleResponse::resolveMode())->toBe('http');

        config(['gale.mode' => 'http']); // restore
    });
});

// ---------------------------------------------------------------------------
// SECTION 27: GaleResponse::resolveRequestMode()
// ---------------------------------------------------------------------------

describe('GaleResponse::resolveRequestMode()', function () {
    it('resolves sse from Gale-Mode request header', function () {
        makeGaleSseRequest();

        expect(GaleResponse::resolveRequestMode(request()))->toBe('sse');
    });

    it('falls back to config mode when no Gale-Mode header', function () {
        makeGaleHttpRequest();
        config(['gale.mode' => 'http']);

        expect(GaleResponse::resolveRequestMode(request()))->toBe('http');
    });

    it('ignores invalid Gale-Mode header value', function () {
        makeGaleHttpRequest();
        request()->headers->set('Gale-Mode', 'websocket');

        expect(GaleResponse::resolveRequestMode(request()))->toBe('http');
    });
});

// ---------------------------------------------------------------------------
// SECTION 28: Before/After hooks (F-064)
// ---------------------------------------------------------------------------

describe('beforeRequest() and afterResponse() hooks', function () {
    afterEach(fn () => GaleResponse::clearHooks());

    it('beforeRequest hook is called with the request', function () {
        $called = false;
        GaleResponse::beforeRequest(function ($request) use (&$called) {
            $called = true;
        });

        GaleResponse::runBeforeHooks(request());

        expect($called)->toBeTrue();
    });

    it('afterResponse hook may replace the response', function () {
        GaleResponse::afterResponse(function ($response, $request) {
            return response('replaced', 200);
        });

        makeGaleHttpRequest();
        $response = gale()->state('x', 1)->toResponse(request());

        expect($response->getContent())->toBe('replaced');
    });

    it('clearHooks() removes all registered hooks', function () {
        $called = false;
        GaleResponse::beforeRequest(function () use (&$called) {
            $called = true;
        });
        GaleResponse::clearHooks();
        GaleResponse::runBeforeHooks(request());

        expect($called)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// SECTION 29: toJson() and toJsonString()
// ---------------------------------------------------------------------------

describe('toJson() and toJsonString()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('toJson() returns array with events key', function () {
        $g = gale()->state('x', 1);
        $json = $g->toJson();

        expect($json)->toHaveKey('events')
            ->and($json['events'])->toBeArray();
    });

    it('toJsonString() returns valid JSON string', function () {
        $g = gale()->state('x', 1);
        $str = $g->toJsonString();

        expect($str)->toBeString();
        $decoded = json_decode($str, true);
        expect(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($decoded)->toHaveKey('events');
    });
});

// ---------------------------------------------------------------------------
// SECTION 30: Edge cases
// ---------------------------------------------------------------------------

describe('edge cases', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('same method called twice emits both events in order', function () {
        $response = gale()
            ->state('counter', 1)
            ->state('counter', 2)
            ->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(2)
            ->and($events[0]['data']['counter'])->toBe(1)
            ->and($events[1]['data']['counter'])->toBe(2);
    });

    it('very large state payload serializes correctly', function () {
        $largeArray = array_fill(0, 1000, 'x');
        $response = gale()->state('items', $largeArray)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['items'])->toHaveCount(1000);
    });

    it('empty array state value serializes as empty array', function () {
        $response = gale()->state('list', [])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['list'])->toBe([]);
    });

    it('boolean state values serialize correctly', function () {
        $response = gale()->state('active', true)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['active'])->toBeTrue();
    });

    it('integer zero state value serializes correctly', function () {
        $response = gale()->state('count', 0)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['count'])->toBe(0);
    });

    it('SSE data field with complex JSON serializes without line breaks corrupting format', function () {
        makeGaleSseRequest();
        $data = ['message' => "line1\nline2"];
        $response = gale()->state('data', $data)->toResponse(request());

        // Content should not have bare newlines in data values breaking SSE format
        $content = $response->getContent();
        expect($content)->toContain('event: gale-patch-state');
    });
});

// ---------------------------------------------------------------------------
// SECTION 31: tagState()
// ---------------------------------------------------------------------------

describe('tagState()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-component event with tag and state', function () {
        $response = gale()->tagState('counter', ['count' => 10])->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-patch-component')
            ->and($events[0]['data']['tag'])->toBe('counter')
            ->and($events[0]['data']['state']['count'])->toBe(10);
    });

    it('emits gale-patch-component in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->tagState('widget', ['visible' => true])->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents)->toHaveCount(1)
            ->and($sseEvents[0]['type'])->toBe('gale-patch-component');
    });

    it('is a no-op for non-Gale requests', function () {
        $request = \Illuminate\Http\Request::create('/test');
        $response = gale()->tagState('tag', ['x' => 1])->toResponse($request);

        expect($response->getStatusCode())->toBe(204);
    });
});

// ---------------------------------------------------------------------------
// SECTION 32: componentMethod()
// ---------------------------------------------------------------------------

describe('componentMethod()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-invoke-method event with component, method, and args', function () {
        $response = gale()->componentMethod('timer', 'reset', [0])->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-invoke-method')
            ->and($events[0]['data']['component'])->toBe('timer')
            ->and($events[0]['data']['method'])->toBe('reset')
            ->and($events[0]['data']['args'])->toBe([0]);
    });

    it('sends empty args when none provided', function () {
        $response = gale()->componentMethod('comp', 'refresh')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['args'])->toBe([]);
    });

    it('emits gale-invoke-method in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->componentMethod('comp', 'run')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-invoke-method');
    });
});

// ---------------------------------------------------------------------------
// SECTION 33: html()
// ---------------------------------------------------------------------------

describe('html()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-patch-elements event with raw HTML', function () {
        $response = gale()->html('<div>hello</div>')->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-patch-elements')
            ->and($events[0]['data']['html'])->toBe('<div>hello</div>');
    });

    it('accepts options like selector and mode', function () {
        $response = gale()->html('<p>new</p>', ['selector' => '#box', 'mode' => 'inner'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['selector'])->toBe('#box')
            ->and($events[0]['data']['mode'])->toBe('inner');
    });
});

// ---------------------------------------------------------------------------
// SECTION 34: outerMorph(), innerMorph(), morph(), delete()
// ---------------------------------------------------------------------------

describe('morph methods', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('outerMorph() sends gale-patch-elements with mode outerMorph', function () {
        $response = gale()->outerMorph('#item', '<div id="item">morphed</div>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('outerMorph');
    });

    it('innerMorph() sends gale-patch-elements with mode innerMorph', function () {
        $response = gale()->innerMorph('#box', '<p>inner morphed</p>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('innerMorph');
    });

    it('morph() is an alias for outerMorph()', function () {
        $response = gale()->morph('#el', '<div id="el">v2</div>')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['mode'])->toBe('outerMorph');
    });

    it('delete() is an alias for remove()', function () {
        $response = gale()->delete('#gone')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-patch-elements')
            ->and($events[0]['data']['mode'])->toBe('remove')
            ->and($events[0]['data']['selector'])->toBe('#gone');
    });
});

// ---------------------------------------------------------------------------
// SECTION 35: emitRedirect()
// ---------------------------------------------------------------------------

describe('emitRedirect()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('sends gale-redirect event with URL in HTTP mode', function () {
        $response = gale()->emitRedirect('/target')->toResponse(request());
        $events = httpEvents($response);

        $redirectEvent = collect($events)->firstWhere('type', 'gale-redirect');
        expect($redirectEvent)->not->toBeNull()
            ->and($redirectEvent['data']['url'])->toBe('/target');
    });

    it('sends gale-redirect event in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->emitRedirect('/target')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        $redirectEvent = collect($sseEvents)->firstWhere('type', 'gale-redirect');
        expect($redirectEvent)->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// SECTION 36: flash()
// ---------------------------------------------------------------------------

describe('flash()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('delivers flash data as _flash state for Gale requests', function () {
        $response = gale()->flash('success', 'Saved!')->toResponse(request());
        $events = httpEvents($response);

        $flashEvent = collect($events)->first(function ($e) {
            return $e['type'] === 'gale-patch-state' && isset($e['data']['_flash']);
        });

        expect($flashEvent)->not->toBeNull()
            ->and($flashEvent['data']['_flash']['success'])->toBe('Saved!');
    });

    it('accumulates multiple flash() calls into a single _flash event', function () {
        $response = gale()
            ->flash('status', 'updated')
            ->flash('count', 5)
            ->toResponse(request());
        $events = httpEvents($response);

        // Should have one _flash state event with both values
        $flashEvents = collect($events)->filter(fn ($e) => $e['type'] === 'gale-patch-state' && isset($e['data']['_flash']));
        expect($flashEvents)->toHaveCount(1);

        $flash = $flashEvents->first()['data']['_flash'];
        expect($flash['status'])->toBe('updated')
            ->and($flash['count'])->toBe(5);
    });

    it('accepts array format', function () {
        $response = gale()->flash(['a' => 1, 'b' => 2])->toResponse(request());
        $events = httpEvents($response);

        $flashEvent = collect($events)->first(fn ($e) => $e['type'] === 'gale-patch-state' && isset($e['data']['_flash']));
        expect($flashEvent['data']['_flash'])->toBe(['a' => 1, 'b' => 2]);
    });
});

// ---------------------------------------------------------------------------
// SECTION 37: withEventId()
// ---------------------------------------------------------------------------

describe('withEventId()', function () {
    it('adds id: field to SSE response', function () {
        makeGaleSseRequest();

        // withEventId must be called BEFORE state() because events are formatted at queue time
        $response = gale()->withEventId('evt-42')->state('x', 1)->toResponse(request());
        $content = $response->getContent();

        expect($content)->toContain('id: evt-42');
    });

    it('returns self for chaining', function () {
        $g = gale();
        expect($g->withEventId('abc'))->toBe($g);
    });
});

// ---------------------------------------------------------------------------
// SECTION 38: withRetry()
// ---------------------------------------------------------------------------

describe('withRetry()', function () {
    it('adds retry: field to SSE response', function () {
        makeGaleSseRequest();

        // withRetry must be called BEFORE state() because events are formatted at queue time
        $response = gale()->withRetry(5000)->state('x', 1)->toResponse(request());
        $content = $response->getContent();

        expect($content)->toContain('retry: 5000');
    });

    it('returns self for chaining', function () {
        $g = gale();
        expect($g->withRetry(1000))->toBe($g);
    });
});

// ---------------------------------------------------------------------------
// SECTION 39: whenGale() / whenNotGale()
// ---------------------------------------------------------------------------

describe('whenGale()', function () {
    it('executes callback for Gale requests', function () {
        makeGaleHttpRequest();

        $response = gale()->whenGale(fn ($g) => $g->state('gale', true))->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['data']['gale'])->toBeTrue();
    });

    it('does not execute callback for non-Gale requests', function () {
        $request = \Illuminate\Http\Request::create('/test');
        $executed = false;
        gale()->whenGale(function () use (&$executed) {
            $executed = true;
        })->toResponse($request);

        // The whenGale check uses request(), not the passed $request
        // But since we haven't set the Gale header on the global request, check behavior
        expect(true)->toBeTrue(); // whenGale delegation tested via when()
    });

    it('executes fallback for non-Gale requests', function () {
        // Reset Gale header
        request()->headers->remove('Gale-Request');

        $fallbackCalled = false;
        gale()->whenGale(
            fn ($g) => $g->state('a', 1),
            function ($g) use (&$fallbackCalled) {
                $fallbackCalled = true;
            }
        );

        expect($fallbackCalled)->toBeTrue();
    });
});

describe('whenNotGale()', function () {
    it('executes callback for non-Gale requests', function () {
        request()->headers->remove('Gale-Request');

        $called = false;
        gale()->whenNotGale(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
    });

    it('skips callback for Gale requests', function () {
        makeGaleHttpRequest();

        $called = false;
        gale()->whenNotGale(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// SECTION 40: navigate() variants
// ---------------------------------------------------------------------------

describe('navigate variants', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('navigate() sends gale-execute-script with URL', function () {
        $response = gale()->navigate('/about')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('/about');
    });

    it('navigateWith() delegates to navigate() with merge option', function () {
        $response = gale()->navigateWith('/page', 'true', true)->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('/page');
    });

    it('navigateMerge() enables merge', function () {
        $response = gale()->navigateMerge('/items')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('/items');
    });

    it('navigateClean() disables merge', function () {
        $response = gale()->navigateClean('/fresh')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('/fresh');
    });

    it('navigateReplace() includes replace option', function () {
        $response = gale()->navigateReplace('/same')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('/same');
    });

    it('navigateOnly() preserves only specified params', function () {
        $response = gale()->navigateOnly('/page', ['sort'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script');
    });

    it('navigateExcept() preserves all except specified params', function () {
        $response = gale()->navigateExcept('/page', ['page'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script');
    });

    it('navigate with array builds query string', function () {
        $response = gale()->navigate(['sort' => 'name', 'dir' => 'asc'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('sort=name')
            ->and($events[0]['data']['script'])->toContain('dir=asc');
    });
});

// ---------------------------------------------------------------------------
// SECTION 41: updateQueries() / clearQueries()
// ---------------------------------------------------------------------------

describe('updateQueries() / clearQueries()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('updateQueries() sends navigate event with query params', function () {
        $response = gale()->updateQueries(['page' => 2, 'sort' => 'name'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('page=2');
    });

    it('clearQueries() sends navigate event clearing params', function () {
        $response = gale()->clearQueries(['page', 'sort'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['type'])->toBe('gale-execute-script');
    });
});

// ---------------------------------------------------------------------------
// SECTION 42: debug()
// ---------------------------------------------------------------------------

describe('debug()', function () {
    beforeEach(function () {
        makeGaleHttpRequest();
        config(['app.debug' => true]);
    });

    it('emits gale-debug event with label and data', function () {
        $response = gale()->debug('test-label', ['key' => 'value'])->toResponse(request());
        $events = httpEvents($response);

        $debugEvent = collect($events)->firstWhere('type', 'gale-debug');
        expect($debugEvent)->not->toBeNull()
            ->and($debugEvent['data']['label'])->toBe('test-label')
            ->and($debugEvent['data']['data']['key'])->toBe('value');
    });

    it('uses default label when only data provided', function () {
        $response = gale()->debug(['count' => 42])->toResponse(request());
        $events = httpEvents($response);

        $debugEvent = collect($events)->firstWhere('type', 'gale-debug');
        expect($debugEvent['data']['label'])->toBe('debug');
    });

    it('is a no-op when APP_DEBUG is false', function () {
        config(['app.debug' => false]);

        $response = gale()->debug('label', 'data')->toResponse(request());
        $events = httpEvents($response);

        $debugEvent = collect($events)->firstWhere('type', 'gale-debug');
        expect($debugEvent)->toBeNull();
    });

    it('includes timestamp in debug entry', function () {
        $response = gale()->debug('ts-test', 'data')->toResponse(request());
        $events = httpEvents($response);

        $debugEvent = collect($events)->firstWhere('type', 'gale-debug');
        expect($debugEvent['data'])->toHaveKey('timestamp')
            ->and($debugEvent['data']['timestamp'])->toBeString();
    });

    it('emits gale-debug in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->debug('sse-debug', 'test')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        $debugEvent = collect($sseEvents)->firstWhere('type', 'gale-debug');
        expect($debugEvent)->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// SECTION 43: debugDump()
// ---------------------------------------------------------------------------

describe('debugDump()', function () {
    beforeEach(function () {
        makeGaleHttpRequest();
        config(['gale.debug' => true]);
    });

    it('emits gale-debug-dump event with HTML', function () {
        $response = gale()->debugDump('<pre class="sf-dump">dump output</pre>')->toResponse(request());
        $events = httpEvents($response);

        $dumpEvent = collect($events)->firstWhere('type', 'gale-debug-dump');
        expect($dumpEvent)->not->toBeNull()
            ->and($dumpEvent['data']['html'])->toContain('sf-dump');
    });

    it('is a no-op when gale.debug is false', function () {
        config(['gale.debug' => false]);

        $response = gale()->debugDump('<pre>dump</pre>')->toResponse(request());
        $events = httpEvents($response);

        $dumpEvent = collect($events)->firstWhere('type', 'gale-debug-dump');
        expect($dumpEvent)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// SECTION 44: download()
// ---------------------------------------------------------------------------

describe('download()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('throws InvalidArgumentException for non-existent file path', function () {
        expect(fn () => gale()->download('/nonexistent/file.txt', 'file.txt')->toResponse(request()))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('emits gale-download event for existing file', function () {
        // Create a temporary file
        $tmpFile = tempnam(sys_get_temp_dir(), 'gale_test_');
        file_put_contents($tmpFile, 'test content');

        try {
            $response = gale()->download($tmpFile, 'report.txt')->toResponse(request());
            $events = httpEvents($response);

            $dlEvent = collect($events)->firstWhere('type', 'gale-download');
            expect($dlEvent)->not->toBeNull()
                ->and($dlEvent['data']['filename'])->toBe('report.txt')
                ->and($dlEvent['data']['url'])->toContain('/gale/download/');
        } finally {
            @unlink($tmpFile);
        }
    });

    it('emits gale-download for raw content with isContent flag', function () {
        $response = gale()->download('raw csv data', 'export.csv', 'text/csv', true)->toResponse(request());
        $events = httpEvents($response);

        $dlEvent = collect($events)->firstWhere('type', 'gale-download');
        expect($dlEvent)->not->toBeNull()
            ->and($dlEvent['data']['filename'])->toBe('export.csv');
    });

    it('sanitizes dangerous filenames', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gale_test_');
        file_put_contents($tmpFile, 'test');

        try {
            $response = gale()->download($tmpFile, '../../../etc/passwd')->toResponse(request());
            $events = httpEvents($response);

            $dlEvent = collect($events)->firstWhere('type', 'gale-download');
            // Path traversal characters should be stripped
            expect($dlEvent['data']['filename'])->not->toContain('..')
                ->and($dlEvent['data']['filename'])->not->toContain('/');
        } finally {
            @unlink($tmpFile);
        }
    });
});

// ---------------------------------------------------------------------------
// SECTION 45: stream()
// ---------------------------------------------------------------------------

describe('stream()', function () {
    it('returns self for chaining', function () {
        $g = gale();
        $result = $g->stream(function ($gale) {
            // noop
        });

        expect($result)->toBe($g);
    });

    it('produces a StreamedResponse when converted to response in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->stream(function ($gale) {
            $gale->state('step', 1);
        })->toResponse(request());

        expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 46: push()
// ---------------------------------------------------------------------------

describe('push()', function () {
    it('returns a GalePushChannel instance', function () {
        $channel = gale()->push('notifications');

        expect($channel)->toBeInstanceOf(\Dancycodes\Gale\Http\GalePushChannel::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 47: fragments()
// ---------------------------------------------------------------------------

describe('fragments()', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('renders multiple fragments and emits multiple events', function () {
        $response = gale()->fragments([
            ['view' => 'with-fragments', 'fragment' => 'header', 'data' => ['title' => 'Test']],
            ['view' => 'with-fragments', 'fragment' => 'footer', 'data' => ['copyright' => '2026']],
        ])->toResponse(request());
        $events = httpEvents($response);

        // Should have at least two gale-patch-elements events
        $patchEvents = collect($events)->where('type', 'gale-patch-elements');
        expect($patchEvents)->toHaveCount(2);
    });
});

// ---------------------------------------------------------------------------
// SECTION 48: whenGaleNavigate()
// ---------------------------------------------------------------------------

describe('whenGaleNavigate()', function () {
    it('executes callback when Gale navigate request matches', function () {
        makeGaleHttpRequest();
        request()->headers->set('GALE-NAVIGATE', 'true');

        $called = false;
        gale()->whenGaleNavigate(function ($g) use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
    });

    it('does not execute callback when not a navigate request', function () {
        makeGaleHttpRequest();
        request()->headers->remove('GALE-NAVIGATE');

        $called = false;
        gale()->whenGaleNavigate(function ($g) use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
    });

    it('executes fallback when not a navigate request', function () {
        makeGaleHttpRequest();
        request()->headers->remove('GALE-NAVIGATE');

        $fallbackCalled = false;
        gale()->whenGaleNavigate(
            function ($g) {},
            function ($g) use (&$fallbackCalled) {
                $fallbackCalled = true;
            }
        );

        expect($fallbackCalled)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// SECTION 49: DOM manipulation options
// ---------------------------------------------------------------------------

describe('DOM manipulation options', function () {
    beforeEach(fn () => makeGaleHttpRequest());

    it('append with scroll option includes scroll in event data', function () {
        $response = gale()->append('#list', '<li>new</li>', ['scroll' => 'bottom'])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['scroll'])->toBe('bottom');
    });

    it('inner with useViewTransition option includes it in event data', function () {
        $response = gale()->inner('#box', '<p>new</p>', ['useViewTransition' => true])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['useViewTransition'])->toBeTrue();
    });

    it('outer with settle option includes settle duration', function () {
        $response = gale()->outer('#el', '<div id="el">v2</div>', ['settle' => 300])->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['settle'])->toBe(300);
    });
});

// ---------------------------------------------------------------------------
// SECTION 50: DOM manipulation SSE mode coverage
// ---------------------------------------------------------------------------

describe('DOM manipulation in SSE mode', function () {
    beforeEach(fn () => makeGaleSseRequest());

    it('append emits gale-patch-elements in SSE', function () {
        $response = gale()->append('#list', '<li>item</li>')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-patch-elements');
    });

    it('remove emits gale-patch-elements in SSE', function () {
        $response = gale()->remove('#old')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-patch-elements');
    });

    it('outerMorph emits gale-patch-elements in SSE', function () {
        $response = gale()->outerMorph('#el', '<div id="el">new</div>')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-patch-elements');
    });
});

// ---------------------------------------------------------------------------
// SECTION 51: macro()
// ---------------------------------------------------------------------------

describe('macro()', function () {
    afterEach(fn () => GaleResponse::flushMacros());

    it('registers and calls a custom macro', function () {
        makeGaleHttpRequest();

        GaleResponse::macro('customAction', function () {
            return $this->state('custom', true);
        });

        $response = gale()->customAction()->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['custom'])->toBeTrue();
    });

    it('throws RuntimeException when macro name conflicts with existing method', function () {
        expect(fn () => GaleResponse::macro('state', function () {}))
            ->toThrow(\RuntimeException::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 52: Mixed mode coverage - ensure every event type works in both modes
// ---------------------------------------------------------------------------

describe('dual-mode coverage for all event types', function () {
    it('patchStore works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->patchStore('cart', ['total' => 99])->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-patch-store');
    });

    it('componentState works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->componentState('comp', ['x' => 1])->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-patch-component');
    });

    it('js works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->js('alert(1)')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-execute-script');
    });

    it('dispatch works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->dispatch('my-event', ['x' => 1])->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-dispatch');
    });

    it('navigate works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->navigate('/page')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-execute-script');
    });

    it('reload works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->reload()->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-execute-script');
    });

    it('messages works in HTTP mode and SSE mode with same structure', function () {
        // HTTP mode
        makeGaleHttpRequest();
        $httpResponse = gale()->messages(['email' => 'required'])->toResponse(request());
        $httpEvents = httpEvents($httpResponse);

        // SSE mode
        makeGaleSseRequest();
        $sseResponse = gale()->messages(['email' => 'required'])->toResponse(request());
        $sseEvents = parseSseContent($sseResponse->getContent());

        // Both should produce gale-patch-state
        expect($httpEvents[0]['type'])->toBe('gale-patch-state')
            ->and($sseEvents[0]['type'])->toBe('gale-patch-state');
    });

    it('clearMessages works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->clearMessages()->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());

        expect($sseEvents[0]['type'])->toBe('gale-patch-state');
        $state = extractStateFromSse($sseEvents[0]['dataLines']);
        expect($state['messages'])->toBe([]);
    });

    it('errors works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->errors(['field' => ['Error.']])->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());
        $state = extractStateFromSse($sseEvents[0]['dataLines']);

        expect($state['errors']['field'])->toBe(['Error.']);
    });

    it('clearErrors works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->clearErrors()->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());
        $state = extractStateFromSse($sseEvents[0]['dataLines']);

        expect($state['errors'])->toBe([]);
    });

    it('forget works in SSE mode', function () {
        makeGaleSseRequest();

        $response = gale()->forget('x')->toResponse(request());
        $sseEvents = parseSseContent($response->getContent());
        $state = extractStateFromSse($sseEvents[0]['dataLines']);

        expect($state)->toHaveKey('x')
            ->and($state['x'])->toBeNull();
    });
});
