<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector;
use PHPUnit\Framework\TestCase;

final class ImageOutputModePromptInjectorTest extends TestCase
{
    private ImageOutputModePromptInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->injector = new ImageOutputModePromptInjector;
    }

    public function test_grid_size_three_block(): void
    {
        $prompt = $this->injector->inject('Product views here.', [
            'split_enabled' => true,
            'split_grid_size' => 3,
        ]);

        $this->assertStringContainsString('MODE=SQUARE_SPRITE_SHEET', $prompt);
        $this->assertStringContainsString('GRID_ROWS=3', $prompt);
        $this->assertStringContainsString('GRID_COLUMNS=3', $prompt);
        $this->assertStringContainsString('TOTAL_CELLS=9', $prompt);
        $this->assertStringContainsString('Product views here.', $prompt);
        $this->assertStringStartsWith(ImageOutputModePromptInjector::BEGIN_MARKER, $prompt);
    }

    public function test_grid_size_four_block_not_hardcoded_three(): void
    {
        $prompt = $this->injector->inject('Body', [
            'split_enabled' => true,
            'split_grid_size' => 4,
        ]);

        $this->assertStringContainsString('GRID_ROWS=4', $prompt);
        $this->assertStringContainsString('GRID_COLUMNS=4', $prompt);
        $this->assertStringContainsString('TOTAL_CELLS=16', $prompt);
        $this->assertStringNotContainsString('GRID_ROWS=3', $prompt);
        $this->assertStringNotContainsString('TOTAL_CELLS=9', $prompt);
    }

    public function test_single_image_mode_when_split_disabled(): void
    {
        $prompt = $this->injector->inject("Old text with contact sheet 3x3.\n", [
            'split_enabled' => false,
            'split_grid_size' => 3,
        ]);

        $this->assertStringContainsString('MODE=SINGLE_IMAGE', $prompt);
        $this->assertStringContainsString('TOTAL_CELLS=1', $prompt);
        $this->assertStringNotContainsString('GRID_ROWS=', $prompt);
        $this->assertStringNotContainsString('GRID_COLUMNS=', $prompt);
        $this->assertStringContainsString('Old text with contact sheet 3x3.', $prompt);
    }

    public function test_inject_is_idempotent_and_replaces_mode(): void
    {
        $first = $this->injector->inject('Body', [
            'split_enabled' => true,
            'split_grid_size' => 3,
        ]);
        $second = $this->injector->inject($first, [
            'split_enabled' => true,
            'split_grid_size' => 3,
        ]);

        $this->assertSame(1, substr_count($second, ImageOutputModePromptInjector::BEGIN_MARKER));
        $this->assertSame(1, substr_count($second, 'MODE=SQUARE_SPRITE_SHEET'));

        $switched = $this->injector->inject($second, [
            'split_enabled' => false,
            'split_grid_size' => 3,
        ]);

        $this->assertSame(1, substr_count($switched, ImageOutputModePromptInjector::BEGIN_MARKER));
        $this->assertStringContainsString('MODE=SINGLE_IMAGE', $switched);
        $this->assertStringNotContainsString('MODE=SQUARE_SPRITE_SHEET', $switched);
        $this->assertStringNotContainsString('TOTAL_CELLS=9', $switched);
    }

    public function test_build_block_matches_inject_source(): void
    {
        $config = [
            'split_enabled' => true,
            'split_grid_size' => 3,
        ];

        $block = $this->injector->buildBlock($config);
        $injected = $this->injector->inject('BODY', $config);

        $this->assertStringStartsWith($block, $injected);
        $this->assertSame($block, $this->injector->buildBlock($config));
        $this->assertSame('square_sprite_sheet', $this->injector->auditMeta($config)['output_mode']);
        $this->assertSame('single_image', $this->injector->auditMeta([
            'split_enabled' => false,
            'split_grid_size' => 3,
        ])['output_mode']);
    }

    public function test_does_not_mutate_caller_template_string_reference(): void
    {
        $template = 'Generate product views.';
        $this->injector->inject($template, [
            'split_enabled' => true,
            'split_grid_size' => 3,
        ]);

        $this->assertSame('Generate product views.', $template);
        $this->assertStringNotContainsString(ImageOutputModePromptInjector::BEGIN_MARKER, $template);
    }
}
