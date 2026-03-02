<?php

/**
 * F-112 — PHP Unit: Redirect, Fragment, Middleware
 * Section: BladeFragment + BladeFragmentParser
 *
 * Comprehensive Pest unit tests for the Blade fragment extraction system.
 * Tests cover single fragments, multiple fragments, nested fragments,
 * CRLF normalization, dynamic data, and edge cases.
 * Uses inline Blade template strings for isolation (no actual view files needed
 * for parser tests; BladeFragment.render() tests use existing fixtures).
 *
 * @see packages/dancycodes/gale/src/View/Fragment/BladeFragment.php
 * @see packages/dancycodes/gale/src/View/Fragment/BladeFragmentParser.php
 */

use Dancycodes\Gale\View\Fragment\BladeFragment;
use Dancycodes\Gale\View\Fragment\BladeFragmentParser;
use Dancycodes\Gale\View\Fragment\CloseFragmentElement;
use Dancycodes\Gale\View\Fragment\OpenFragmentElement;
use Illuminate\Support\Facades\View;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Build a BladeFragmentParser with the standard gale directive names.
 */
function makeParser(): BladeFragmentParser
{
    return new BladeFragmentParser('fragment', 'endfragment');
}

// ---------------------------------------------------------------------------
// SECTION 1: BladeFragmentParser — basic parsing
// ---------------------------------------------------------------------------

describe('BladeFragmentParser::parse() — basic detection', function () {
    it('returns two elements (open + close) for a single fragment', function () {
        $parser = makeParser();
        $content = "@fragment('header')\n<h1>Title</h1>\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements)->toHaveCount(2);
        expect($elements[0])->toBeInstanceOf(OpenFragmentElement::class);
        expect($elements[1])->toBeInstanceOf(CloseFragmentElement::class);
    });

    it('correctly extracts the fragment name from the open element', function () {
        $parser = makeParser();
        $content = "@fragment('sidebar')\n<nav>Menu</nav>\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements[0]->name)->toBe('sidebar');
    });

    it('close element startOffset is greater than open element endOffset', function () {
        $parser = makeParser();
        $content = "@fragment('test')\n<p>Content</p>\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements[1]->startOffset)->toBeGreaterThan($elements[0]->endOffset);
    });

    it('returns integer offsets on open and close elements', function () {
        $parser = makeParser();
        $content = "@fragment('test')\n<p>x</p>\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements[0]->startOffset)->toBeInt();
        expect($elements[0]->endOffset)->toBeInt();
        expect($elements[1]->startOffset)->toBeInt();
        expect($elements[1]->endOffset)->toBeInt();
    });
});

// ---------------------------------------------------------------------------
// SECTION 2: BladeFragmentParser — multiple fragments
// ---------------------------------------------------------------------------

describe('BladeFragmentParser::parse() — multiple fragments', function () {
    it('returns 4 elements for two sequential fragments', function () {
        $parser = makeParser();
        $content = "@fragment('first')\nFirst\n@endfragment\n@fragment('second')\nSecond\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements)->toHaveCount(4);
    });

    it('preserves fragment names in order for multiple fragments', function () {
        $parser = makeParser();
        $content = "@fragment('alpha')\nA\n@endfragment\n@fragment('beta')\nB\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements[0]->name)->toBe('alpha');
        expect($elements[2]->name)->toBe('beta');
    });

    it('interleaves Open/Close/Open/Close for sequential fragments', function () {
        $parser = makeParser();
        $content = "@fragment('a')\nA\n@endfragment\n@fragment('b')\nB\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements[0])->toBeInstanceOf(OpenFragmentElement::class);
        expect($elements[1])->toBeInstanceOf(CloseFragmentElement::class);
        expect($elements[2])->toBeInstanceOf(OpenFragmentElement::class);
        expect($elements[3])->toBeInstanceOf(CloseFragmentElement::class);
    });
});

// ---------------------------------------------------------------------------
// SECTION 3: BladeFragmentParser — nested fragments
// ---------------------------------------------------------------------------

describe('BladeFragmentParser::parse() — nested fragments', function () {
    it('detects 4 elements for outer+inner nested fragment', function () {
        $parser = makeParser();
        $content = "@fragment('outer')\n@fragment('inner')\nInner\n@endfragment\nOuter\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements)->toHaveCount(4);
    });

    it('detects outer and inner fragment names correctly', function () {
        $parser = makeParser();
        $content = "@fragment('outer')\n@fragment('inner')\nInner\n@endfragment\nOuter\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements[0]->name)->toBe('outer');
        expect($elements[1]->name)->toBe('inner');
    });
});

// ---------------------------------------------------------------------------
// SECTION 4: BladeFragmentParser — CRLF normalization (BR-F112-03)
// ---------------------------------------------------------------------------

describe('BladeFragmentParser — CRLF normalization', function () {
    it('parses fragments in content with CRLF line endings', function () {
        $parser = makeParser();
        $content = "@fragment('test')\r\n<p>Content</p>\r\n@endfragment";

        $elements = $parser->parse($content);

        expect($elements)->toHaveCount(2);
        expect($elements[0]->name)->toBe('test');
    });

    it('parses fragments in content with legacy CR line endings', function () {
        $parser = makeParser();
        $content = "@fragment('test')\r<p>Content</p>\r@endfragment";

        $elements = $parser->parse($content);

        expect($elements)->toHaveCount(2);
    });

    it('CRLF content and LF content produce same fragment name extraction', function () {
        $parser = makeParser();
        $lf = "@fragment('normalize')\nContent\n@endfragment";
        $crlf = "@fragment('normalize')\r\nContent\r\n@endfragment";

        $lfElements = $parser->parse($lf);
        $crlfElements = $parser->parse($crlf);

        expect($lfElements[0]->name)->toBe($crlfElements[0]->name);
    });
});

// ---------------------------------------------------------------------------
// SECTION 5: BladeFragmentParser — edge cases
// ---------------------------------------------------------------------------

describe('BladeFragmentParser — edge cases', function () {
    it('returns empty array for content without any directives', function () {
        $parser = makeParser();

        $elements = $parser->parse('<div>Regular HTML</div>');

        expect($elements)->toBeEmpty();
    });

    it('returns empty array for empty string', function () {
        $parser = makeParser();

        $elements = $parser->parse('');

        expect($elements)->toBeEmpty();
    });

    it('skips escaped @@ directives', function () {
        $parser = makeParser();
        $content = "@@fragment('escaped')\nContent\n@@endfragment";

        $elements = $parser->parse($content);

        expect($elements)->toBeEmpty();
    });

    it('supports double-quoted fragment names', function () {
        $parser = makeParser();
        $content = '@fragment("double")\nContent\n@endfragment';

        $elements = $parser->parse($content);

        expect($elements)->toHaveCount(2);
        expect($elements[0]->name)->toBe('double');
    });

    it('returns empty array when only one directive is present (unclosed)', function () {
        $parser = makeParser();
        $content = "@fragment('unclosed')\nContent without close";

        $elements = $parser->parse($content);

        // Parser requires at least 2 matches for a valid pair
        expect($elements)->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// SECTION 6: BladeFragment::render() — with fixture views
// ---------------------------------------------------------------------------

describe('BladeFragment::render() — fixture-based tests', function () {
    beforeEach(function () {
        View::addLocation(__DIR__ . '/../../Fixtures/views');
    });

    it('renders a named fragment from a view', function () {
        $output = BladeFragment::render('fragments-test', 'header');

        expect($output)->toContain('<h1>');
        expect($output)->toContain('Default Title');
    });

    it('renders fragment with dynamic data substituting defaults', function () {
        $output = BladeFragment::render('fragments-test', 'header', ['title' => 'Custom Title']);

        expect($output)->toContain('Custom Title');
        expect($output)->not->toContain('Default Title');
    });

    it('renders only the requested fragment, not surrounding fragments', function () {
        $output = BladeFragment::render('fragments-test', 'content');

        expect($output)->toContain('Default message');
        // Should not contain elements from other fragments
        expect($output)->not->toContain('<h1>');
        expect($output)->not->toContain('<footer>');
    });

    it('renders the footer fragment with a year variable', function () {
        $output = BladeFragment::render('fragments-test', 'footer', ['year' => 2025]);

        expect(trim($output))->toStartWith('<footer>');
        expect(trim($output))->toEndWith('</footer>');
        expect($output)->toContain('2025');
    });

    it('renders nested outer fragment including inner fragment content', function () {
        $output = BladeFragment::render('fragments-test', 'nested-outer');

        expect($output)->toContain('class="outer"');
        expect($output)->toContain('class="inner"');
    });

    it('compiles Blade conditionals inside fragment', function () {
        $output = BladeFragment::render('fragments-test', 'with-blade', [
            'show' => true,
            'items' => ['Apple', 'Banana'],
        ]);

        expect($output)->toContain('Conditional content');
        expect($output)->toContain('<li>Apple</li>');
        expect($output)->toContain('<li>Banana</li>');
    });

    it('Blade @if evaluates to false when show=false', function () {
        $output = BladeFragment::render('fragments-test', 'with-blade', [
            'show' => false,
            'items' => [],
        ]);

        expect($output)->not->toContain('Conditional content');
        expect($output)->not->toContain('<li>');
    });

    it('throws RuntimeException for non-existent fragment name', function () {
        expect(fn () => BladeFragment::render('fragments-test', 'does-not-exist'))
            ->toThrow(\RuntimeException::class, 'No fragment called "does-not-exist"');
    });

    it('throws InvalidArgumentException for non-existent view', function () {
        expect(fn () => BladeFragment::render('nonexistent-view', 'header'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns a string', function () {
        $output = BladeFragment::render('fragments-test', 'content');

        expect($output)->toBeString();
    });
});

// ---------------------------------------------------------------------------
// SECTION 7: CRLF normalization end-to-end via BladeFragment
// ---------------------------------------------------------------------------

describe('BladeFragment — CRLF normalization end-to-end', function () {
    it('BladeFragmentParser correctly handles CRLF template strings', function () {
        $parser = makeParser();
        // Simulate a Windows-line-ending Blade template
        $windowsContent = "@fragment('crlf-test')\r\n<p>Windows line endings</p>\r\n@endfragment";

        $elements = $parser->parse($windowsContent);

        // Should extract the fragment correctly regardless of CRLF
        expect($elements)->toHaveCount(2);
        expect($elements[0]->name)->toBe('crlf-test');
    });
});
