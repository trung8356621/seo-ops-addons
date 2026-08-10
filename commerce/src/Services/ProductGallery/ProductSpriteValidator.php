<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Omnichannel\Addons\Seo\Support\QuickSplitCanvasValidator;

/**
 * Hybrid sprite validator — image processing only, no AI.
 * Hard fail → do not split. Soft checks only reduce confidence.
 * Khi nghi ngờ → confidence thấp → fallback gốc.
 */
final class ProductSpriteValidator
{
    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param  array{
     *     confidence_threshold?: float,
     *     min_canvas_px?: int,
     *     min_panel_count_ratio?: float,
     *     soft_weights?: array<string, float>
     * }|null  $config
     */
    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;

            return;
        }

        try {
            $block = config('seo-content-ai.product_gallery.sprite_validator', []);
            $this->config = is_array($block) ? $block : [];
        } catch (\Throwable) {
            $this->config = [];
        }
    }

    public static function fromConfig(): self
    {
        return new self(null);
    }

    public function confidenceThreshold(): float
    {
        $raw = $this->config['confidence_threshold'] ?? 0.8;

        return max(0.0, min(1.0, (float) $raw));
    }

    public function validate(string $absolutePath, int $expectedGrid): SpriteValidationResult
    {
        $expectedGrid = PromptPostProcessing::clampGridSize($expectedGrid);
        $expectedPanels = $expectedGrid * $expectedGrid;
        $minCanvas = max(32, (int) ($this->config['min_canvas_px'] ?? 256));

        if ($absolutePath === '' || ! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return SpriteValidationResult::hardFail(
                'Sprite file missing or unreadable.',
                $expectedGrid,
                ['sprite_unreadable'],
                threshold: $this->confidenceThreshold(),
            );
        }

        $size = @getimagesize($absolutePath);
        if (! is_array($size) || (int) ($size[0] ?? 0) < 1 || (int) ($size[1] ?? 0) < 1) {
            return SpriteValidationResult::hardFail(
                'Cannot read sprite image dimensions.',
                $expectedGrid,
                ['sprite_dimensions_unreadable'],
                threshold: $this->confidenceThreshold(),
            );
        }

        $width = (int) $size[0];
        $height = (int) $size[1];

        if ($width < $minCanvas || $height < $minCanvas) {
            return SpriteValidationResult::hardFail(
                sprintf('Canvas too small (%d×%d), min %dpx.', $width, $height, $minCanvas),
                $expectedGrid,
                ['min_canvas'],
                $width,
                $height,
                threshold: $this->confidenceThreshold(),
            );
        }

        $canvasError = QuickSplitCanvasValidator::validate($width, $height, $expectedGrid);
        if ($canvasError !== null) {
            return SpriteValidationResult::hardFail(
                $canvasError['message'],
                $expectedGrid,
                ['invalid_aspect_or_grid', $canvasError['code']],
                $width,
                $height,
                threshold: $this->confidenceThreshold(),
            );
        }

        $cell = QuickSplitCanvasValidator::cellSize($width, $expectedGrid);
        $rectangles = $this->buildExpectedRectangles($expectedGrid, $cell);
        $detectedPanels = count($rectangles);

        if ($detectedPanels < $expectedPanels) {
            return SpriteValidationResult::hardFail(
                sprintf('Detected %d panels, expected %d.', $detectedPanels, $expectedPanels),
                $expectedGrid,
                ['panel_count'],
                $width,
                $height,
                $detectedPanels,
                $this->confidenceThreshold(),
            );
        }

        if ($this->hasSevereOverlap($rectangles)) {
            return SpriteValidationResult::hardFail(
                'Severe panel rectangle overlap.',
                $expectedGrid,
                ['severe_overlap'],
                $width,
                $height,
                $detectedPanels,
                $this->confidenceThreshold(),
            );
        }

        // Zero-area panels (defensive).
        foreach ($rectangles as $rect) {
            if ((int) $rect['w'] < 1 || (int) $rect['h'] < 1) {
                return SpriteValidationResult::hardFail(
                    'Panel area near zero.',
                    $expectedGrid,
                    ['zero_area_panel'],
                    $width,
                    $height,
                    $detectedPanels,
                    $this->confidenceThreshold(),
                );
            }
            if ($rect['x'] < 0 || $rect['y'] < 0
                || ($rect['x'] + $rect['w']) > $width
                || ($rect['y'] + $rect['h']) > $height
            ) {
                return SpriteValidationResult::hardFail(
                    'Crop rectangle outside image bounds.',
                    $expectedGrid,
                    ['crop_out_of_bounds'],
                    $width,
                    $height,
                    $detectedPanels,
                    $this->confidenceThreshold(),
                );
            }
        }

        $soft = $this->scoreSoftChecks($absolutePath, $width, $height, $expectedGrid, $cell, $rectangles);
        $confidence = max(0.0, min(1.0, (float) $soft['confidence']));
        $threshold = $this->confidenceThreshold();
        $valid = $confidence >= $threshold;
        $strategy = $valid
            ? SpriteValidationResult::STRATEGY_FIXED_GRID
            : SpriteValidationResult::STRATEGY_NONE;

        // detected_gutters reserved: only when fixed_grid soft gutter score is weak but
        // detected layout would be strong — Mode 1 keeps fixed_grid as primary; if confidence
        // is high and gutters look clear, still fixed_grid (safer, less false positive).
        if ($valid && (($soft['scores']['gutter_uniformity'] ?? 0) >= 0.9)) {
            $strategy = SpriteValidationResult::STRATEGY_FIXED_GRID;
        }

        $reason = $valid
            ? sprintf('PASS — %d panels, confidence %.2f (%s)', $detectedPanels, $confidence, $strategy)
            : sprintf(
                'FAIL — confidence %.2f < threshold %.2f (%s)',
                $confidence,
                $threshold,
                implode(', ', $soft['flags']) !== '' ? implode(', ', $soft['flags']) : 'soft_checks',
            );

        $reasonCodes = $valid ? ['sprite_ok', $strategy] : array_values(array_merge(['confidence_below_threshold'], $soft['flags']));

        return new SpriteValidationResult(
            valid: $valid,
            hardFailed: false,
            confidence: $confidence,
            threshold: $threshold,
            reason: $reason,
            expectedGrid: $expectedGrid,
            detectedPanels: $detectedPanels,
            rectangles: $rectangles,
            reasonCodes: $reasonCodes,
            softFlags: $soft['flags'],
            softScores: $soft['scores'],
            canvasWidth: $width,
            canvasHeight: $height,
            splitStrategy: $strategy,
        );
    }

    /**
     * @return list<array{x: int, y: int, w: int, h: int, row: int, col: int}>
     */
    private function buildExpectedRectangles(int $grid, int $cell): array
    {
        $rects = [];
        for ($row = 0; $row < $grid; $row++) {
            for ($col = 0; $col < $grid; $col++) {
                $rects[] = [
                    'x' => $col * $cell,
                    'y' => $row * $cell,
                    'w' => $cell,
                    'h' => $cell,
                    'row' => $row,
                    'col' => $col,
                ];
            }
        }

        return $rects;
    }

    /**
     * @param  list<array{x: int, y: int, w: int, h: int, row: int, col: int}>  $rectangles
     */
    private function hasSevereOverlap(array $rectangles): bool
    {
        $count = count($rectangles);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $rectangles[$i];
                $b = $rectangles[$j];
                $overlapW = min($a['x'] + $a['w'], $b['x'] + $b['w']) - max($a['x'], $b['x']);
                $overlapH = min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']);
                if ($overlapW <= 0 || $overlapH <= 0) {
                    continue;
                }
                $overlapArea = $overlapW * $overlapH;
                $minArea = min($a['w'] * $a['h'], $b['w'] * $b['h']);
                if ($minArea > 0 && ($overlapArea / $minArea) > 0.05) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{x: int, y: int, w: int, h: int, row: int, col: int}>  $rectangles
     * @return array{confidence: float, flags: list<string>, scores: array<string, float>}
     */
    private function scoreSoftChecks(
        string $absolutePath,
        int $width,
        int $height,
        int $grid,
        int $cell,
        array $rectangles,
    ): array {
        $weights = is_array($this->config['soft_weights'] ?? null)
            ? $this->config['soft_weights']
            : [];

        $weightGutter = (float) ($weights['gutter_uniformity'] ?? 0.25);
        $weightSquare = (float) ($weights['cell_squareness'] ?? 0.2);
        $weightArea = (float) ($weights['area_uniformity'] ?? 0.2);
        $weightWhite = (float) ($weights['whitespace'] ?? 0.2);
        $weightCrop = (float) ($weights['crop_safety'] ?? 0.15);
        $weightSum = max(0.0001, $weightGutter + $weightSquare + $weightArea + $weightWhite + $weightCrop);

        $scores = [
            'gutter_uniformity' => 1.0,
            'cell_squareness' => 1.0,
            'area_uniformity' => 1.0,
            'whitespace' => 1.0,
            'crop_safety' => 1.0,
        ];
        $flags = [];

        // Expected grid cells are already square when canvas validates — soft check stays near 1.
        $scores['cell_squareness'] = ($cell > 0 && $width === $height) ? 1.0 : 0.6;
        if ($scores['cell_squareness'] < 0.85) {
            $flags[] = 'cell_not_square';
        }

        $areas = array_map(
            static fn (array $r): int => max(1, (int) $r['w'] * (int) $r['h']),
            $rectangles,
        );
        $avgArea = array_sum($areas) / max(1, count($areas));
        $areaVar = 0.0;
        foreach ($areas as $area) {
            $areaVar += abs($area - $avgArea) / $avgArea;
        }
        $areaVar /= max(1, count($areas));
        $scores['area_uniformity'] = max(0.0, 1.0 - min(1.0, $areaVar * 4));
        if ($scores['area_uniformity'] < 0.85) {
            $flags[] = 'area_variance';
        }

        $gutterScore = $this->estimateGutterUniformity($absolutePath, $width, $height, $grid, $cell);
        $scores['gutter_uniformity'] = $gutterScore;
        if ($gutterScore < 0.85) {
            $flags[] = 'uneven_gutter';
        }

        $whiteScore = $this->estimateWhitespaceScore($absolutePath, $width, $height, $grid, $cell);
        $scores['whitespace'] = $whiteScore;
        if ($whiteScore < 0.75) {
            $flags[] = 'whitespace';
        }

        // Crop safety: panels should leave a thin safe margin from outer edge of each cell.
        $edgeMarginRatio = $cell >= 64 ? 0.02 : 0.0;
        $scores['crop_safety'] = $edgeMarginRatio > 0 ? 0.95 : 0.85;
        if ($scores['crop_safety'] < 0.8) {
            $flags[] = 'crop_safety';
        }

        $confidence = (
            $scores['gutter_uniformity'] * $weightGutter
            + $scores['cell_squareness'] * $weightSquare
            + $scores['area_uniformity'] * $weightArea
            + $scores['whitespace'] * $weightWhite
            + $scores['crop_safety'] * $weightCrop
        ) / $weightSum;

        return [
            'confidence' => $confidence,
            'flags' => $flags,
            'scores' => $scores,
        ];
    }

    private function estimateGutterUniformity(
        string $absolutePath,
        int $width,
        int $height,
        int $grid,
        int $cell,
    ): float {
        if ($grid < 2 || $cell < 8 || ! function_exists('imagecreatefromstring')) {
            // No GD — assume neutral, slightly cautious.
            return 0.82;
        }

        $binary = @file_get_contents($absolutePath);
        if (! is_string($binary) || $binary === '') {
            return 0.5;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return 0.5;
        }

        $brightnessSamples = [];
        for ($i = 1; $i < $grid; $i++) {
            $x = min($width - 1, $i * $cell);
            $sum = 0.0;
            $n = 0;
            for ($y = 0; $y < $height; $y += max(1, intdiv($height, 32))) {
                $sum += $this->pixelBrightness($image, $x, $y);
                $n++;
            }
            $brightnessSamples[] = $n > 0 ? $sum / $n : 0.0;

            $yLine = min($height - 1, $i * $cell);
            $sum = 0.0;
            $n = 0;
            for ($xScan = 0; $xScan < $width; $xScan += max(1, intdiv($width, 32))) {
                $sum += $this->pixelBrightness($image, $xScan, $yLine);
                $n++;
            }
            $brightnessSamples[] = $n > 0 ? $sum / $n : 0.0;
        }

        imagedestroy($image);

        if ($brightnessSamples === []) {
            return 0.82;
        }

        $avg = array_sum($brightnessSamples) / count($brightnessSamples);
        $var = 0.0;
        foreach ($brightnessSamples as $sample) {
            $var += abs($sample - $avg);
        }
        $var /= count($brightnessSamples);

        // Bright, similar gutters → high score; noisy/uneven → lower.
        $brightnessBonus = min(1.0, $avg / 200.0);
        $uniformity = max(0.0, 1.0 - min(1.0, $var / 40.0));

        return max(0.35, min(1.0, 0.45 * $brightnessBonus + 0.55 * $uniformity));
    }

    private function estimateWhitespaceScore(
        string $absolutePath,
        int $width,
        int $height,
        int $grid,
        int $cell,
    ): float {
        if (! function_exists('imagecreatefromstring')) {
            return 0.85;
        }

        $binary = @file_get_contents($absolutePath);
        if (! is_string($binary) || $binary === '') {
            return 0.5;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return 0.5;
        }

        $white = 0;
        $total = 0;
        $stepX = max(1, intdiv($width, 48));
        $stepY = max(1, intdiv($height, 48));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $total++;
                if ($this->pixelBrightness($image, $x, $y) >= 245) {
                    $white++;
                }
            }
        }

        imagedestroy($image);

        if ($total < 1) {
            return 0.5;
        }

        $ratio = $white / $total;
        // Healthy sprite sheets often have moderate gutters (~5–35% near-white).
        if ($ratio < 0.02) {
            return 0.55;
        }
        if ($ratio > 0.65) {
            return 0.45;
        }
        if ($ratio >= 0.05 && $ratio <= 0.35) {
            return 1.0;
        }

        return 0.75;
    }

    /** @param  \GdImage|resource  $image */
    private function pixelBrightness(mixed $image, int $x, int $y): float
    {
        $rgb = @imagecolorat($image, $x, $y);
        if (! is_int($rgb)) {
            return 0.0;
        }
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
    }
}
