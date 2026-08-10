<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;

/**
 * Runtime Image Output Mode block — not stored on prompt template.
 * Idempotent: strip existing marker block then inject exactly one.
 */
final class ImageOutputModePromptInjector
{
    public const BEGIN_MARKER = '[IMAGE_OUTPUT_MODE_BEGIN]';

    public const END_MARKER = '[IMAGE_OUTPUT_MODE_END]';

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows?: int,
     *     split_columns?: int,
     *     expected_panels?: int,
     *     resize_enabled?: bool,
     *     resize_width?: int|null,
     *     resize_height?: int|null,
     * }  $config
     */
    public function inject(string $prompt, array $config): string
    {
        $cleaned = $this->stripExistingBlock($prompt);
        $block = $this->buildBlock($config);
        $cleaned = trim($cleaned);

        return $cleaned === ''
            ? $block
            : $block."\n\n".$cleaned;
    }

    /**
     * Single source for runtime IMAGE_OUTPUT_MODE block (UI preview + provider inject).
     *
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     split_rows?: int,
     *     split_columns?: int,
     *     expected_panels?: int,
     *     resize_enabled?: bool,
     *     resize_width?: int|null,
     *     resize_height?: int|null,
     * }  $config
     */
    public function buildBlock(array $config): string
    {
        return $config['split_enabled']
            ? $this->buildSquareSpriteSheetBlock((int) $config['split_grid_size'])
            : $this->buildSingleImageBlock();
    }

    /**
     * Human-readable summary lines for Prompt Management UI.
     *
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     expected_panels?: int,
     *     resize_enabled?: bool,
     *     resize_width?: int|null,
     *     resize_height?: int|null,
     * }  $config
     * @return array{
     *     mode_label: string,
     *     mode_key: string,
     *     provider_output: int,
     *     grid_label: string|null,
     *     expected_children: int,
     *     auto_split_label: string,
     *     child_images_label: string,
     *     lines: list<string>,
     * }
     */
    public function summarize(array $config): array
    {
        $enabled = (bool) ($config['split_enabled'] ?? false);
        $grid = PromptPostProcessing::clampGridSize((int) ($config['split_grid_size'] ?? PromptPostProcessing::GRID_SIZE_DEFAULT));
        $panels = $grid * $grid;
        $prefix = 'seo-content-ai::filament.prompt.post_processing.';

        if ($enabled) {
            $modeLabel = $this->t($prefix.'runtime_mode_square', 'Square sprite sheet');
            $lines = [
                $this->t($prefix.'runtime_mode_line', 'Mode: :mode', ['mode' => $modeLabel]),
                $this->t($prefix.'runtime_provider_line', 'Provider output: :count image', ['count' => 1]),
                $this->t($prefix.'runtime_grid_line', 'Grid: :n × :n', ['n' => $grid]),
                $this->t($prefix.'runtime_children_line', 'Expected child images: :count', ['count' => $panels]),
                $this->t($prefix.'runtime_split_enabled_line', 'Auto split: Enabled'),
            ];

            return [
                'mode_label' => $modeLabel,
                'mode_key' => 'square_sprite_sheet',
                'provider_output' => 1,
                'grid_label' => sprintf('%d × %d', $grid, $grid),
                'expected_children' => $panels,
                'auto_split_label' => $this->t($prefix.'runtime_enabled', 'Enabled'),
                'child_images_label' => (string) $panels,
                'lines' => $lines,
            ];
        }

        $modeLabel = $this->t($prefix.'runtime_mode_single', 'Single image');
        $lines = [
            $this->t($prefix.'runtime_mode_line', 'Mode: :mode', ['mode' => $modeLabel]),
            $this->t($prefix.'runtime_provider_line', 'Provider output: :count image', ['count' => 1]),
            $this->t($prefix.'runtime_split_disabled_line', 'Auto split: Disabled'),
            $this->t($prefix.'runtime_children_none_line', 'Child images: None'),
        ];

        return [
            'mode_label' => $modeLabel,
            'mode_key' => 'single_image',
            'provider_output' => 1,
            'grid_label' => null,
            'expected_children' => 0,
            'auto_split_label' => $this->t($prefix.'runtime_disabled', 'Disabled'),
            'child_images_label' => $this->t($prefix.'runtime_children_none', 'None'),
            'lines' => $lines,
        ];
    }

    /**
     * Immutable audit payload stored on run input_snapshot.
     *
     * @param  array{
     *     split_enabled: bool,
     *     split_grid_size: int,
     *     expected_panels?: int,
     * }  $config
     * @return array{
     *     output_mode: string,
     *     quick_split_enabled: bool,
     *     grid_size: int,
     *     grid: string|null,
     *     expected_children: int,
     *     snapshot_source: string,
     * }
     */
    public function auditMeta(array $config, string $snapshotSource = 'generation_snapshot'): array
    {
        $summary = $this->summarize($config);

        return [
            'output_mode' => $summary['mode_key'],
            'quick_split_enabled' => (bool) ($config['split_enabled'] ?? false),
            'grid_size' => PromptPostProcessing::clampGridSize((int) ($config['split_grid_size'] ?? PromptPostProcessing::GRID_SIZE_DEFAULT)),
            'grid' => $summary['grid_label'],
            'expected_children' => $summary['expected_children'],
            'snapshot_source' => $snapshotSource,
        ];
    }

    public function stripExistingBlock(string $prompt): string
    {
        $pattern = '/\s*'.preg_quote(self::BEGIN_MARKER, '/')
            .'.*?'
            .preg_quote(self::END_MARKER, '/')
            .'\s*/s';

        $stripped = preg_replace($pattern, "\n\n", $prompt);
        if (! is_string($stripped)) {
            return trim($prompt);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $stripped) ?? $stripped);
    }

    public function buildSquareSpriteSheetBlock(int $gridSize): string
    {
        $n = PromptPostProcessing::clampGridSize($gridSize);
        $total = $n * $n;

        return implode("\n", [
            self::BEGIN_MARKER,
            'MODE=SQUARE_SPRITE_SHEET',
            'OUTPUT_COUNT=1',
            'GRID_ROWS='.$n,
            'GRID_COLUMNS='.$n,
            'TOTAL_CELLS='.$total,
            'CELL_ASPECT_RATIO=1:1',
            'EQUAL_CELL_SIZE=TRUE',
            'OUTER_MARGIN_PX=0',
            'CANVAS_PADDING_PX=0',
            'HORIZONTAL_GAP_PX=0',
            'VERTICAL_GAP_PX=0',
            'EDGE_TO_EDGE_GRID=TRUE',
            '',
            'Generate exactly one final image.',
            '',
            "That single image must contain exactly {$total} equal square cells arranged in exactly {$n} rows and exactly {$n} columns.",
            '',
            'The final canvas is a machine-splittable square sprite sheet.',
            '',
            'Every cell must:',
            '- have identical dimensions;',
            '- occupy exactly one grid coordinate;',
            '- contain one complete scene;',
            '- reach its assigned crop boundaries;',
            '- contain no internal caption, label or frame.',
            '',
            'Do not create:',
            '- extra rows;',
            '- extra columns;',
            '- empty cells;',
            '- merged cells;',
            '- nested grids;',
            '- irregular collages;',
            '- masonry layouts;',
            '- gutters;',
            '- margins;',
            '- padding;',
            '- borders;',
            '- dividers;',
            '- whitespace;',
            '- text describing camera angles.',
            '',
            'The canvas must touch all four outer edges.',
            '',
            "This image will be split automatically at exact {$n} × {$n} coordinates. Any other structure is invalid.",
            self::END_MARKER,
        ]);
    }

    public function buildSingleImageBlock(): string
    {
        return implode("\n", [
            self::BEGIN_MARKER,
            'MODE=SINGLE_IMAGE',
            'OUTPUT_COUNT=1',
            'TOTAL_CELLS=1',
            '',
            'Generate exactly one complete standalone image.',
            '',
            'The final output must be one continuous scene covering the full canvas.',
            '',
            'Do not create:',
            '- a contact sheet;',
            '- a sprite sheet;',
            '- a grid;',
            '- multiple panels;',
            '- multiple frames;',
            '- a collage;',
            '- comparison views;',
            '- separate camera angles inside one canvas;',
            '- borders or dividers between scenes.',
            '',
            'Return one image only.',
            self::END_MARKER,
        ]);
    }

    /**
     * Pure PHPUnit has no translator binding — use English fallback (same pattern as PromptOwnership presenters).
     *
     * @param  array<string, string|int|float>  $replace
     */
    private function t(string $key, string $fallback, array $replace = []): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                $text = $fallback;
                foreach ($replace as $search => $value) {
                    $text = str_replace(':'.$search, (string) $value, $text);
                }

                return $text;
            }

            $translated = __($key, $replace);
            if (is_array($translated)) {
                return $fallback;
            }
            $text = trim((string) $translated);

            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            $text = $fallback;
            foreach ($replace as $search => $value) {
                $text = str_replace(':'.$search, (string) $value, $text);
            }

            return $text;
        }
    }
}
