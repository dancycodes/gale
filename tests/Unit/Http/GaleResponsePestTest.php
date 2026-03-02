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

    it('sends gale-execute-script event with window.location.href redirect', function () {
        $response = gale()->redirect('/dashboard')->toResponse(request());
        $events = httpEvents($response);

        expect($events)->toHaveCount(1)
            ->and($events[0]['type'])->toBe('gale-execute-script')
            ->and($events[0]['data']['script'])->toContain('/dashboard');
    });

    it('redirect with external URL is allowed when allow_external config is true', function () {
        config(['gale.redirect.allow_external' => true]);

        $response = gale()->redirect('https://example.com')->toResponse(request());
        $events = httpEvents($response);

        expect($events[0]['data']['script'])->toContain('https://example.com');

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
