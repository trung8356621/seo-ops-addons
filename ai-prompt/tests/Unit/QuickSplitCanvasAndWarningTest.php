<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\PromptManualGridWarning;
use Omnichannel\Addons\Seo\Support\QuickSplitCanvasValidator;
use PHPUnit\Framework\TestCase;

final class QuickSplitCanvasAndWarningTest extends TestCase
{
    public function test_valid_1536_square_grid_three(): void
    {
        $this->assertNull(QuickSplitCanvasValidator::validate(1536, 1536, 3));
        $this->assertSame(512, QuickSplitCanvasValidator::cellSize(1536, 3));
    }

    public function test_valid_2048_square_grid_four(): void
    {
        $this->assertNull(QuickSplitCanvasValidator::validate(2048, 2048, 4));
        $this->assertSame(512, QuickSplitCanvasValidator::cellSize(2048, 4));
    }

    public function test_non_square_canvas_fails(): void
    {
        $result = QuickSplitCanvasValidator::validate(1536, 1024, 3);

        $this->assertNotNull($result);
        $this->assertSame('QUICK_SPLIT_INVALID_CANVAS', $result['code']);
        $this->assertStringContainsString('1536 × 1024', $result['message']);
    }

    public function test_not_divisible_fails(): void
    {
        $result = QuickSplitCanvasValidator::validate(1000, 1000, 3);

        $this->assertNotNull($result);
        $this->assertSame('QUICK_SPLIT_DIMENSION_NOT_DIVISIBLE', $result['code']);
    }

    public function test_manual_grid_warning_when_split_off(): void
    {
        $warnings = PromptManualGridWarning::detect(
            'Create a contact sheet with 3×3 panels.',
            splitEnabled: false,
            gridSize: 3,
        );

        $this->assertNotSame([], $warnings);
        $this->assertStringContainsString('Quick Split is off', $warnings[0]);
    }

    public function test_manual_grid_mismatch_when_split_on(): void
    {
        $warnings = PromptManualGridWarning::detect(
            "Use a 4×4 layout.\n1. Front\n2. Side\n3. Back\n4. Top\n5. A\n6. B\n7. C\n8. D\n9. E",
            splitEnabled: true,
            gridSize: 3,
        );

        $joined = implode("\n", $warnings);
        $this->assertStringContainsString('4×4', $joined);
        $this->assertStringContainsString('9 numbered', $joined);
        $this->assertStringContainsString('3×3', $joined);
    }
}
