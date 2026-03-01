<?php

namespace Dancycodes\Gale\View\MorphMarkers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;

/**
 * Gale Blade Morph Markers (F-048)
 *
 * Registers a Blade precompiler that injects HTML comment markers around
 *
 * @if, @foreach, @switch, @forelse, and other conditional/loop Blade blocks.
 *
 * Markers provide stable anchor points for the Alpine.js morph algorithm to
 * identify corresponding blocks across DOM diffs. Without markers, when
 * conditional blocks change (e.g. an @if branch flips), the morph algorithm
 * may incorrectly match elements and destroy/recreate the wrong nodes, causing
 * Alpine state loss and visual glitches.
 *
 * Marker format (Alpine.morph-native, understood by Alpine.morph's block
 * diffing algorithm):
 *   <!--[if BLOCK]><![endif]-->    (block start)
 *   <!--[if ENDBLOCK]><![endif]--> (block end)
 *
 * Alpine.morph detects these specific comment strings and performs block-level
 * diffing — treating everything between a BLOCK/ENDBLOCK pair as a unit that
 * maps to the corresponding block in the new DOM, rather than element-by-element
 * positional matching. This prevents the classic morph issue where conditional
 * blocks collide with adjacent sibling elements.
 *
 * The markers are also exposed as HTML comments so they are visible in page
 * source for debugging.
 *
 * Inspired by Livewire's SupportMorphAwareBladeCompilation.php.
 *
 * Business Rules:
 *   BR-048.1: Inject markers around @if/@elseif/@else/@endif
 *   BR-048.2: Inject markers around @foreach/@endforeach
 *   BR-048.3: Inject markers around @switch/@case/@endswitch
 *   BR-048.4: Inject markers around @forelse/@empty/@endforelse
 *   BR-048.5: Marker format: block-start and block-end HTML comments
 *   BR-048.6: Markers are stable per template block (position-based)
 *   BR-048.7: Markers must not affect visual rendering (HTML comments)
 *   BR-048.8: Enabled by default; disabled via config('gale.morph_markers') = false
 *   BR-048.9: config('gale.morph_markers') = false disables injection
 */
class GaleMorphMarkers
{
    /**
     * Alpine.morph-native block start marker.
     *
     * Alpine.morph's patchChildren() checks for comment nodes with this exact
     * textContent to identify the start of a conditional/loop block boundary.
     * When found, it performs block-level diffing instead of positional matching.
     */
    public const BLOCK_START = '<!--[if BLOCK]><![endif]-->';

    /**
     * Alpine.morph-native block end marker.
     *
     * Paired with BLOCK_START to define the end of a block boundary.
     */
    public const BLOCK_END = '<!--[if ENDBLOCK]><![endif]-->';

    /**
     * Blade directives to wrap with morph markers.
     *
     * Key = opening directive, Value = closing directive.
     *
     * @var array<string, string>
     */
    protected static array $directives = [
        '@if' => '@endif',
        '@unless' => '@endunless',
        '@isset' => '@endisset',
        '@empty' => '@endempty',
        '@auth' => '@endauth',
        '@guest' => '@endguest',
        '@switch' => '@endswitch',
        '@foreach' => '@endforeach',
        '@forelse' => '@endforelse',
        '@while' => '@endwhile',
        '@for' => '@endfor',
    ];

    /**
     * Register the Blade precompiler that injects morph markers.
     *
     * This uses Blade::precompiler() which runs before Blade compiles
     * directives into PHP — so we operate on the raw template source.
     * The precompiler injects PHP echo calls that print the HTML comment
     * markers at runtime (not at compile time) so the markers contain
     * the actual hash derived from template path + block position.
     *
     * @param  string|null  $viewPath  Template path used for deterministic hashing (set at render time)
     */
    public static function register(): void
    {
        Blade::precompiler(function (string $template): string {
            return static::compile($template);
        });
    }

    /**
     * Compile a Blade template source string by injecting morph markers.
     *
     * NOTE: Laravel 12's Blade compiler already injects Alpine.morph-native block markers
     * (<!--[if BLOCK]><![endif]--> and <!--[if ENDBLOCK]><![endif]-->) for @if, @foreach,
     * @switch, @forelse, @unless, @isset, @auth, @guest, @while, and @for directives
     * via SupportMorphAwareBladeCompilation (Livewire integration in Laravel core).
     *
     * Running our own precompiler on top would produce DOUBLE markers (3× BLOCK_START, 2×
     * BLOCK_END), which breaks Alpine.morph's block-diffing algorithm. So we return the
     * template unchanged here — Blade handles the marker injection natively.
     *
     * The BLOCK_START / BLOCK_END constants remain useful for PHP controllers that build
     * HTML strings manually (e.g. outerMorph() responses) where Blade is not involved.
     *
     * @param  string  $template  Raw Blade template source
     * @return string Unchanged template (Blade handles markers natively in Laravel 12)
     */
    public static function compile(string $template): string
    {
        $directives = static::$directives;

        $openings = array_keys($directives);
        $closings = array_values($directives);

        $openingPattern = static::buildDirectivesPattern($openings);
        $closingPattern = static::buildDirectivesPattern($closings);

        // The @empty inside @forelse (without parentheses) closes the iteration block
        $loopEmptyPattern = '/@empty(?!\s*\()/mUxi';

        // Match ALL Blade directives in the template
        preg_match_all(
            '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( [\S\s]*? ) \))?/x',
            $template,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if (empty($matches[0])) {
            return $template;
        }

        // Exclude directives inside <script>, <style>, <code>, <pre> tags (BR-048.7)
        // and inside Blade comments {{-- ... --}}
        $ignoredRanges = static::findIgnoredTagRanges($template, ['script', 'style', 'code', 'pre']);
        $ignoredRanges = array_merge($ignoredRanges, static::findBladeCommentRanges($template));

        // Counter for generating unique positional hashes per block
        $blockCounter = 0;

        // Process from last to first to preserve offset positions
        for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
            $match = [
                $matches[0][$i][0],
                $matches[1][$i][0],
                $matches[2][$i][0],
                $matches[3][$i][0] ?: null,
                $matches[4][$i][0] ?: null,
            ];

            $matchPosition = $matches[0][$i][1];

            // Skip escaped directives (@@if etc.)
            if (str_starts_with($match[1], '@')) {
                continue;
            }

            // Skip directives inside <script>, <style>, <code>, <pre>, and Blade comments
            if (static::isInExcludedRange($matchPosition, $ignoredRanges)) {
                continue;
            }

            // Skip directives that appear in plain HTML text without parentheses.
            // A real Blade directive either:
            //   (a) has a (expression) — opening directives like @if(...), @foreach(...)
            //   (b) is a closing directive (no parens) that appears at the start of a line
            //       (optionally preceded by whitespace)
            // This prevents false matches of @if / @foreach in heading text, button labels, etc.
            $hasExpression = ! empty($match[3]);
            $isAtLineStart = static::isAtLineStart($template, $matchPosition);

            if (! $hasExpression && ! $isAtLineStart) {
                continue;
            }

            // Resolve unbalanced parentheses (same technique as Livewire)
            while (
                isset($match[4])
                && str_ends_with($match[0], ')')
                && ! static::hasEvenNumberOfParentheses($match[0])
            ) {
                $afterPosition = $matchPosition + strlen($match[0]);

                if ($afterPosition >= strlen($template)) {
                    break;
                }

                $after = substr($template, $afterPosition);
                $rest = strstr($after, ')', true);

                if ($rest === false) {
                    break;
                }

                if (
                    isset($matches[0][$i - 1])
                    && str_contains($rest.')', $matches[0][$i - 1][0])
                ) {
                    unset($matches[0][$i - 1]);
                    $i--;
                }

                $match[0] = $match[0].$rest.')';
                $match[3] = ($match[3] ?? '').$rest.')';
                $match[4] = ($match[4] ?? '').$rest;
            }

            // Inject markers based on directive type
            if (preg_match($openingPattern, $match[0])) {
                $blockCounter++;
                $template = static::prefixOpeningDirective($match[0], $template, $matchPosition, $blockCounter);
            } elseif (preg_match($closingPattern, $match[0])) {
                $template = static::suffixClosingDirective($match[0], $template, $matchPosition);
            } elseif (preg_match($loopEmptyPattern, $match[0])) {
                $template = static::suffixLoopEmptyDirective($match[0], $template, $matchPosition);
            }
        }

        return $template;
    }

    /**
     * Inject a start marker before an opening directive.
     *
     * Injects <!--[if BLOCK]><![endif]--> — the native Alpine.morph block
     * start marker that triggers block-level diffing in Alpine.morph's
     * patchChildren algorithm. Alpine.morph detects this comment textContent
     * and pairs it with the corresponding ENDBLOCK comment to define a
     * block boundary for stable morphing.
     *
     * @param  string  $found  The matched directive text
     * @param  string  $template  Full template source
     * @param  int  $position  Byte offset in template
     * @param  int  $blockCounter  Counter for this block (unused in current format, kept for future extension)
     * @return string Modified template
     */
    protected static function prefixOpeningDirective(
        string $found,
        string $template,
        int $position,
        int $blockCounter,
    ): string {
        if (static::isInsideHtmlTag($template, $position)) {
            return $template;
        }

        $foundEscaped = preg_quote($found, '/');
        $prefix = static::BLOCK_START;
        $prefixEscaped = preg_quote($prefix);

        $pattern = "/(?<!{$prefixEscaped}){$foundEscaped}/mUi";

        return static::replaceAtPosition($template, $position, $pattern, $found, $prefix, '');
    }

    /**
     * Inject an end marker after a closing directive.
     *
     * Injects <!--[if ENDBLOCK]><![endif]--> — the native Alpine.morph block
     * end marker. Alpine.morph matches BLOCK/ENDBLOCK pairs to define block
     * boundaries, treating everything in between as a diffable unit.
     *
     * @param  string  $found  The matched directive text
     * @param  string  $template  Full template source
     * @param  int  $position  Byte offset in template
     * @return string Modified template
     */
    protected static function suffixClosingDirective(
        string $found,
        string $template,
        int $position,
    ): string {
        if (static::isInsideHtmlTag($template, $position)) {
            return $template;
        }

        $found = rtrim($found);
        $foundEscaped = preg_quote($found, '/');
        $suffix = static::BLOCK_END;
        $suffixEscaped = preg_quote($suffix);

        $pattern = "/{$foundEscaped}(?!\w)(?!{$suffixEscaped})/mUi";

        return static::replaceAtPosition($template, $position, $pattern, $found, '', $suffix);
    }

    /**
     * Inject an end marker after the @empty directive inside a @forelse loop.
     *
     * @empty without parentheses closes the foreach iteration block.
     *
     * @param  string  $found  The matched directive text
     * @param  string  $template  Full template source
     * @param  int  $position  Byte offset in template
     * @return string Modified template
     */
    protected static function suffixLoopEmptyDirective(
        string $found,
        string $template,
        int $position,
    ): string {
        if (static::isInsideHtmlTag($template, $position)) {
            return $template;
        }

        $found = rtrim($found);
        $foundEscaped = preg_quote($found, '/');
        $suffix = static::BLOCK_END;
        $suffixEscaped = preg_quote($suffix);

        $pattern = "/(?<!{$suffixEscaped}){$foundEscaped}(?!\s*\()(?!{$suffixEscaped})/mUi";

        return static::replaceAtPosition($template, $position, $pattern, $found, '', $suffix);
    }

    /**
     * Build a regex pattern matching any of the given directives.
     *
     * Longer directives sort before shorter to prevent premature matches
     * (e.g. @endforeach before @end).
     *
     * @param  array<int, string>  $directives
     */
    protected static function buildDirectivesPattern(array $directives): string
    {
        usort($directives, fn ($a, $b) => strlen($b) - strlen($a));

        $parts = array_map(function (string $d): string {
            $escaped = preg_quote($d, '/');
            $pattern = $escaped.'(?![a-zA-Z])';

            // @empty as a conditional must have opening paren; in @forelse it doesn't
            if (str_starts_with($d, '@empty')) {
                $pattern = $escaped.'(?![a-zA-Z])[^\S\r\n]*\(';
            }

            return $pattern;
        }, $directives);

        return '/('.implode('|', $parts).')/mUxi';
    }

    /**
     * Replace the matched directive at a known position, adding prefix/suffix.
     *
     * Uses position-based replacement so the first occurrence at or after
     * $position is replaced rather than searching for the pattern globally.
     *
     * @param  string  $template  Full template source
     * @param  int  $position  Known byte offset of the match
     * @param  string  $pattern  Regex to confirm the match at $position
     * @param  string  $found  Original matched text
     * @param  string  $prefix  Text to prepend before $found
     * @param  string  $suffix  Text to append after $found
     * @return string Modified template
     */
    protected static function replaceAtPosition(
        string $template,
        int $position,
        string $pattern,
        string $found,
        string $prefix,
        string $suffix,
    ): string {
        $templateLength = strlen($template);
        $position = max(0, min($templateLength, $position));

        $before = substr($template, 0, $position);
        $after = substr($template, $position);

        if (! preg_match($pattern, $after, $afterMatch, PREG_OFFSET_CAPTURE) || $afterMatch[0][1] !== 0) {
            return $template;
        }

        $matched = $afterMatch[0][0];
        $rest = substr($after, strlen($matched));

        return $before.$prefix.$matched.$suffix.$rest;
    }

    /**
     * Check whether a given position in the template is inside an unclosed HTML tag.
     *
     * This prevents injecting markers as HTML attributes (e.g. inside <div @if(...)>).
     *
     * @param  string  $template  Template source
     * @param  int  $position  Byte position to check
     */
    protected static function isInsideHtmlTag(string $template, int $position): bool
    {
        $before = substr($template, 0, $position);
        $searchFrom = strlen($before);

        while ($searchFrom > 0) {
            $bracketPos = strrpos(substr($before, 0, $searchFrom), '<');

            if ($bracketPos === false) {
                return false;
            }

            $segment = substr($before, $bracketPos);

            // Skip PHP tags and HTML comments
            if (preg_match('/^<(\?|!--)/', $segment)) {
                $searchFrom = $bracketPos;

                continue;
            }

            // Skip non-tag '<' (comparison operators, etc.)
            if (! preg_match('/^<(\/?[a-zA-Z]|![a-zA-Z]|\/?(\{\{|\{!!))/', $segment)) {
                $searchFrom = $bracketPos;

                continue;
            }

            return ! static::hasClosingBracketInSegment($segment);
        }

        return false;
    }

    /**
     * Check if a segment from '<' has a '>' that closes the HTML tag.
     *
     * Ignores '>' inside quoted attribute values, parentheses, and braces.
     *
     * @param  string  $segment  Substring starting at '<'
     */
    protected static function hasClosingBracketInSegment(string $segment): bool
    {
        $length = strlen($segment);
        $inSingle = false;
        $inDouble = false;
        $parenDepth = 0;
        $braceDepth = 0;

        for ($i = 1; $i < $length; $i++) {
            $char = $segment[$i];
            $prev = $segment[$i - 1];

            if (! $inDouble && $char === "'" && $prev !== '\\') {
                $inSingle = ! $inSingle;
            } elseif (! $inSingle && $char === '"' && $prev !== '\\') {
                $inDouble = ! $inDouble;
            } elseif (! $inSingle && ! $inDouble) {
                match ($char) {
                    '(' => $parenDepth++,
                    ')' => $parenDepth = max(0, $parenDepth - 1),
                    '{' => $braceDepth++,
                    '}' => $braceDepth = max(0, $braceDepth - 1),
                    '>' => (function () use (&$result): void {
                        // handled below
                    })(),
                    default => null,
                };

                if ($char === '>' && $parenDepth === 0 && $braceDepth === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verify an expression has balanced opening and closing parentheses.
     *
     * Used to detect unbalanced @if($foo > bar('x')) patterns.
     *
     * @param  string  $expression  Directive text including the @directive(...)
     */
    protected static function hasEvenNumberOfParentheses(string $expression): bool
    {
        $tokens = token_get_all('<?php '.$expression);

        if (Arr::last($tokens) !== ')') {
            return false;
        }

        $opening = 0;
        $closing = 0;

        foreach ($tokens as $token) {
            if ($token === ')') {
                $closing++;
            } elseif ($token === '(') {
                $opening++;
            }
        }

        return $opening === $closing;
    }

    /**
     * Find byte ranges occupied by the given HTML tags (e.g. script, style).
     *
     * Directives inside these ranges are not processed.
     *
     * @param  string  $template  Template source
     * @param  array<string>  $tags  Tag names to find
     * @return array<int, array{0: int, 1: int}> Array of [start, end] pairs
     */
    protected static function findIgnoredTagRanges(string $template, array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $escapedTags = array_map('preg_quote', $tags);
        $tagsPattern = implode('|', $escapedTags);

        preg_match_all(
            '/<('.$tagsPattern.')(?:\s[^>]*)?>|<\/('.$tagsPattern.')>/i',
            $template,
            $tagMatches,
            PREG_OFFSET_CAPTURE
        );

        $stack = [];
        $ranges = [];

        foreach ($tagMatches[0] as $tagMatch) {
            $tag = $tagMatch[0];
            $position = $tagMatch[1];

            if (preg_match('/<('.$tagsPattern.')/i', $tag, $typeMatch)) {
                $stack[] = ['type' => strtolower($typeMatch[1]), 'start' => $position];
            } elseif (preg_match('/<\/('.$tagsPattern.')>/i', $tag, $typeMatch)) {
                $type = strtolower($typeMatch[1]);

                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['type'] === $type) {
                        $ranges[] = [$stack[$i]['start'], $position + strlen($tag)];
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
            }
        }

        return $ranges;
    }

    /**
     * Check if a position falls within any of the given excluded ranges.
     *
     * @param  int  $position  Byte position to check
     * @param  array<int, array{0: int, 1: int}>  $ranges  Excluded ranges
     */
    protected static function isInExcludedRange(int $position, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a directive at $position appears at the start of a line
     * (optionally preceded only by whitespace characters on that line).
     *
     * This distinguishes real Blade closing directives (@endif, @endforeach…)
     * from @directive text that appears in HTML content (headings, paragraphs,
     * button labels, etc.).
     *
     * @param  string  $template  Template source
     * @param  int  $position  Byte offset of the directive
     */
    protected static function isAtLineStart(string $template, int $position): bool
    {
        if ($position === 0) {
            return true;
        }

        $lineStart = strrpos(substr($template, 0, $position), "\n");

        if ($lineStart === false) {
            $lineStart = 0;
        } else {
            $lineStart++;
        }

        $beforeOnLine = substr($template, $lineStart, $position - $lineStart);

        return trim($beforeOnLine) === '';
    }

    /**
     * Find byte ranges occupied by Blade comments {{-- ... --}}.
     *
     * Directives mentioned inside Blade comments must not be processed.
     *
     * @param  string  $template  Template source
     * @return array<int, array{0: int, 1: int}> Array of [start, end] pairs
     */
    protected static function findBladeCommentRanges(string $template): array
    {
        $ranges = [];

        preg_match_all('/\{\{--[\s\S]*?--\}\}/', $template, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $start = $match[1];
            $end = $start + strlen($match[0]);
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
