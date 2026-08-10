<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;


use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
/**
 * Validate provider canvas before square N×N Quick Split.
 */
final class QuickSplitCanvasValidator
{
    /**
     * @return array{code: string, message: string}|null  null = ok
     */
    public static function validate(int $width, int $height, int $gridSize): ?array
    {
        $gridSize = PromptPostProcessing::clampGridSize($gridSize);

        if ($width < 2 || $height < 2) {
            return [
                'code' => 'QUICK_SPLIT_INVALID_CANVAS',
                'message' => self::invalidCanvasMessage($gridSize, $width, $height),
            ];
        }

        if ($width !== $height) {
            return [
                'code' => 'QUICK_SPLIT_INVALID_CANVAS',
                'message' => self::invalidCanvasMessage($gridSize, $width, $height),
            ];
        }

        if ($width % $gridSize !== 0 || $height % $gridSize !== 0) {
            return [
                'code' => 'QUICK_SPLIT_DIMENSION_NOT_DIVISIBLE',
                'message' => sprintf(
                    'Quick Split failed: canvas %d × %d is not divisible by grid size %d. The original image was preserved.',
                    $width,
                    $height,
                    $gridSize,
                ),
            ];
        }

        $cellWidth = intdiv($width, $gridSize);
        $cellHeight = intdiv($height, $gridSize);
        if ($cellWidth !== $cellHeight || $cellWidth < 1) {
            return [
                'code' => 'QUICK_SPLIT_CELL_NOT_SQUARE',
                'message' => sprintf(
                    'Quick Split failed: expected square cells for a %d × %d grid, but cell size would be %d × %d. The original image was preserved.',
                    $gridSize,
                    $gridSize,
                    $cellWidth,
                    $cellHeight,
                ),
            ];
        }

        return null;
    }

    public static function cellSize(int $canvasSize, int $gridSize): int
    {
        return intdiv($canvasSize, PromptPostProcessing::clampGridSize($gridSize));
    }

    private static function invalidCanvasMessage(int $gridSize, int $width, int $height): string
    {
        return sprintf(
            'Quick Split failed: expected a %d × %d square grid, but the generated canvas was %d × %d. The original image was preserved.',
            $gridSize,
            $gridSize,
            $width,
            $height,
        );
    }
}
