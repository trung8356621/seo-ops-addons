<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use PHPUnit\Framework\TestCase;

final class SeoWatermarkSettingTest extends TestCase
{
    public function test_is_configured_for_apply_when_type_is_text(): void
    {
        $setting = new SeoWatermarkSetting([
            'type' => SeoWatermarkSetting::TYPE_TEXT,
            'text_content' => '© Test',
        ]);

        $this->assertTrue($setting->isConfiguredForApply());
    }

    public function test_is_not_configured_when_type_none_and_no_overlay(): void
    {
        $setting = new SeoWatermarkSetting([
            'type' => SeoWatermarkSetting::TYPE_NONE,
            'design_config' => [],
        ]);

        $this->assertFalse($setting->isConfiguredForApply());
    }
}
