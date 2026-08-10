<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Support\SeoImageResizeMath;
use PHPUnit\Framework\TestCase;

final class SeoImageResizeMathTest extends TestCase
{
    public function test_output_dimensions_width_only(): void
    {
        $result = SeoImageResizeMath::outputDimensions(300, 300, 800, null);

        $this->assertSame(800, $result['width']);
        $this->assertSame(800, $result['height']);
    }

    public function test_progressive_upscale_steps_from_300_to_800(): void
    {
        $steps = SeoImageResizeMath::progressiveUpscaleSteps(300, 300, 800, 800);

        $this->assertNotSame([], $steps);
        $last = $steps[array_key_last($steps)];
        $this->assertSame(800, $last['width']);
        $this->assertSame(800, $last['height']);
        $this->assertGreaterThan(1, count($steps));
    }

    public function test_downscale_returns_single_step_for_small_ratio(): void
    {
        $steps = SeoImageResizeMath::progressiveScaleSteps(1200, 800, 600, 400);

        $this->assertCount(1, $steps);
        $this->assertSame(['width' => 600, 'height' => 400], $steps[0]);
    }

    public function test_large_downscale_uses_multiple_steps(): void
    {
        $steps = SeoImageResizeMath::progressiveScaleSteps(4000, 3000, 800, 600);

        $this->assertGreaterThan(1, count($steps));
        $last = $steps[array_key_last($steps)];
        $this->assertSame(800, $last['width']);
        $this->assertSame(600, $last['height']);
    }
}
