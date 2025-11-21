<?php

namespace Dancycodes\Gale\Tests\Feature;

use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/**
 * Test Blade Directives Integration
 *
 * @see TESTING.md - File 46: BladeDirectives Tests
 * Status: ✅ COMPLETE - 12 test methods (covers @gale, @signals, @ifgale, @fragment)
 * Note: @dispatch directive removed - use native Datastar @dispatch action instead
 */
class BladeDirectivesTest extends TestCase
{
    public static $latestResponse;

    protected string $viewsPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temporary views directory for testing
        $this->viewsPath = storage_path('framework/testing/views');
        if (!File::exists($this->viewsPath)) {
            File::makeDirectory($this->viewsPath, 0755, true);
        }
        // Add our test views path to Laravel's view finder
        View::addLocation($this->viewsPath);
    }

    protected function tearDown(): void
    {
        // Clean up test views
        if (File::exists($this->viewsPath)) {
            File::deleteDirectory($this->viewsPath);
        }
        parent::tearDown();
    }

    /** @test */
    public function test_hyper_directive_renders_script_tag()
    {
        $compiled = Blade::compileString('@gale');
        // Should include a script tag
        $this->assertStringContainsString('<script', $compiled);
        $this->assertStringContainsString('</script>', $compiled);
    }

    /** @test */
    public function test_hyper_directive_includes_csrf_token()
    {
        $compiled = Blade::compileString('@gale');
        // Should include CSRF meta tag
        $this->assertStringContainsString('csrf-token', $compiled);
        $this->assertStringContainsString('meta', $compiled);
    }

    /** @test */
    public function test_hyper_directive_uses_correct_asset_path()
    {
        $compiled = Blade::compileString('@gale');
        // Should reference the correct asset path
        $this->assertStringContainsString('vendor/gale/js/gale.js', $compiled);
        $this->assertStringContainsString('type="module"', $compiled);
    }

    /** @test */
    public function test_hyper_state_directive_renders_script()
    {
        // @galeState is the new directive for injecting initial state
        $compiled = Blade::compileString("@galeState(['count' => 0])");
        // Should render a script tag with hyperState
        $this->assertStringContainsString('window.galeState', $compiled);
        $this->assertStringContainsString('script', $compiled);
    }

    /** @test */
    public function test_hyper_signals_directive_is_backward_compatible()
    {
        // @galeSignals should work as alias for @galeState
        $compiled = Blade::compileString("@galeSignals(['count' => 0])");
        // Should render a script tag with hyperState
        $this->assertStringContainsString('window.galeState', $compiled);
        $this->assertStringContainsString('script', $compiled);
    }

    /** @test */
    public function test_ifhyper_directive_for_hyper_requests()
    {
        // Create a test view with @ifgale
        $viewContent = <<<'BLADE'
@ifgale
    <div>Gale content</div>
@endifgale
BLADE;
        File::put($this->viewsPath . '/ifhyper-test.blade.php', $viewContent);
        // Make a Gale request
        $response = $this->call('GET', '/', [], [], [], [
            'HTTP_GALE_REQUEST' => 'true',
        ]);
        // Render the view with Gale request context
        request()->headers->set('Gale-Request', 'true');
        $rendered = View::make('ifhyper-test')->render();
        // Should show Gale content
        $this->assertStringContainsString('Gale content', $rendered);
    }

    /** @test */
    public function test_ifhyper_directive_for_normal_requests()
    {
        // Create a test view with @ifgale
        $viewContent = <<<'BLADE'
@ifgale
    <div>Gale content</div>
@endifgale
BLADE;
        File::put($this->viewsPath . '/ifhyper-normal.blade.php', $viewContent);
        // Make a normal request (clear any Gale headers)
        request()->headers->remove('Gale-Request');
        $rendered = View::make('ifhyper-normal')->render();
        // Should NOT show Gale content
        $this->assertStringNotContainsString('Gale content', $rendered);
    }

    /** @test */
    public function test_ifhyper_with_else_block()
    {
        // Create a test view with @ifgale and @else
        $viewContent = <<<'BLADE'
@ifgale
    <div>Gale content</div>
@else
    <div>Regular content</div>
@endifgale
BLADE;
        File::put($this->viewsPath . '/ifhyper-else.blade.php', $viewContent);
        // Test with normal request
        request()->headers->remove('Gale-Request');
        $rendered = View::make('ifhyper-else')->render();
        // Should show regular content
        $this->assertStringContainsString('Regular content', $rendered);
        $this->assertStringNotContainsString('Gale content', $rendered);
    }

    /** @test */
    public function test_fragment_directive_in_view()
    {
        // Create a test view with @fragment
        $viewContent = <<<'BLADE'
@fragment('test-fragment')
    <div>Fragment content</div>
@endfragment
BLADE;
        File::put($this->viewsPath . '/fragment-test.blade.php', $viewContent);
        // Should compile without errors
        $rendered = View::make('fragment-test')->render();
        // Fragment content should still be visible in full view
        $this->assertStringContainsString('Fragment content', $rendered);
    }

    /** @test */
    public function test_endfragment_directive_in_view()
    {
        // Create a test view with multiple fragments
        $viewContent = <<<'BLADE'
@fragment('fragment-one')
    <div>First fragment</div>
@endfragment
@fragment('fragment-two')
    <div>Second fragment</div>
@endfragment
BLADE;
        File::put($this->viewsPath . '/multi-fragment.blade.php', $viewContent);
        // Should compile without errors
        $rendered = View::make('multi-fragment')->render();
        // Both fragments should be visible
        $this->assertStringContainsString('First fragment', $rendered);
        $this->assertStringContainsString('Second fragment', $rendered);
    }

    /** @test */
    public function test_nested_directives()
    {
        // Create a test view with nested directives
        $viewContent = <<<'BLADE'
@ifgale
    @fragment('hyper-fragment')
        <div @signals(['nested' => true])>
            Nested content
        </div>
    @endfragment
@endifgale
BLADE;
        File::put($this->viewsPath . '/nested-directives.blade.php', $viewContent);
        // Test with Gale request
        request()->headers->set('Gale-Request', 'true');
        $rendered = View::make('nested-directives')->render();
        // Should compile and render correctly
        $this->assertStringContainsString('Nested content', $rendered);
    }

    /** @test */
    public function test_signals_directive_with_local_signal()
    {
        // Test that local signals (prefix underscore) use literal variable name
        $viewContent = <<<'BLADE'
@php
    $_editing = true;  // Variable name includes underscore
    $visible = false;
@endphp
<div @signals($_editing, $visible)>
    Content
</div>
BLADE;
        File::put($this->viewsPath . '/signals-local.blade.php', $viewContent);
        $rendered = View::make('signals-local')->render();
        // Should render data-signals with _editing and visible
        $this->assertStringContainsString('data-signals', $rendered);
        $this->assertStringContainsString('_editing', $rendered);
        $this->assertStringContainsString('visible', $rendered);
    }

    /** @test */
    public function test_signals_directive_with_locked_signal()
    {
        // Test that locked signals (suffix underscore) use literal variable name
        $viewContent = <<<'BLADE'
@php
    $userId_ = 123;  // Variable name includes trailing underscore
    $name = 'John';
@endphp
<div @signals($userId_, $name)>
    Content
</div>
BLADE;
        File::put($this->viewsPath . '/signals-locked.blade.php', $viewContent);
        $rendered = View::make('signals-locked')->render();
        // Should render data-signals with userId_ and name
        $this->assertStringContainsString('data-signals', $rendered);
        $this->assertStringContainsString('userId_', $rendered);
        $this->assertStringContainsString('name', $rendered);
    }

    /** @test */
    public function test_signals_directive_with_spread_and_underscores()
    {
        // Test that spread with underscored variables works correctly
        $viewContent = <<<'BLADE'
@php
    $_uiState = ['modal' => false, 'sidebar' => true];
    $permissions_ = ['canEdit' => true, 'canDelete' => false];
@endphp
<div @signals(...$_uiState, ...$permissions_)>
    Content
</div>
BLADE;
        File::put($this->viewsPath . '/signals-spread-underscore.blade.php', $viewContent);
        $rendered = View::make('signals-spread-underscore')->render();
        // Should render data-signals with spread values
        $this->assertStringContainsString('data-signals', $rendered);
        $this->assertStringContainsString('modal', $rendered);
        $this->assertStringContainsString('canEdit', $rendered);
    }

    /** @test */
    public function test_signals_directive_with_compact()
    {
        // Test that compact() function works correctly
        $viewContent = <<<'BLADE'
@php
    $username = 'john_doe';
    $email = 'john@example.com';
    $age = 25;
@endphp
<div @signals(compact('username', 'email', 'age'))>
    Content
</div>
BLADE;
        File::put($this->viewsPath . '/signals-compact.blade.php', $viewContent);
        $rendered = View::make('signals-compact')->render();
        // Should render data-signals with compacted variables
        $this->assertStringContainsString('data-signals', $rendered);
        $this->assertStringContainsString('username', $rendered);
        $this->assertStringContainsString('john_doe', $rendered);
        $this->assertStringContainsString('email', $rendered);
        $this->assertStringContainsString('john@example.com', $rendered);
    }

    /** @test */
    public function test_signals_directive_mixed_patterns()
    {
        // Test mixing regular, local, locked signals with compact and spread
        $viewContent = <<<'BLADE'
@php
    $name = 'John';
    $_loading = false;
    $role_ = 'admin';
    $meta = ['version' => '1.0'];
@endphp
<div @signals($name, $_loading, $role_, ...$meta, compact('name'))>
    Content
</div>
BLADE;
        File::put($this->viewsPath . '/signals-mixed.blade.php', $viewContent);
        $rendered = View::make('signals-mixed')->render();
        // Should render all patterns correctly
        $this->assertStringContainsString('data-signals', $rendered);
        $this->assertStringContainsString('name', $rendered);
        $this->assertStringContainsString('_loading', $rendered);
        $this->assertStringContainsString('role_', $rendered);
        $this->assertStringContainsString('version', $rendered);
    }

    /** @test */
    public function test_directive_compilation_does_not_error()
    {
        // Test that all directives compile without throwing exceptions
        $directives = [
            '@gale',
            '@signals(["test" => "value"])',
            '@ifgale content @endifgale',
            '@fragment("test") content @endfragment',
        ];
        foreach ($directives as $directive) {
            try {
                $compiled = Blade::compileString($directive);
                $this->assertIsString($compiled);
            } catch (\Exception $e) {
                $this->fail("Directive '{$directive}' failed to compile: " . $e->getMessage());
            }
        }
    }
}
