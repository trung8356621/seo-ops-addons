<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsDateTime;
use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class SeoDisplayTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SystemDateTime::useConfig([
            'timezone' => 'Asia/Ho_Chi_Minh',
            'preset' => 'vi',
        ]);
    }

    protected function tearDown(): void
    {
        SystemDateTime::useConfig(null);
        parent::tearDown();
    }

    public function test_format_converts_utc_iso_to_display_timezone(): void
    {
        $formatted = SeoDisplayTimezone::format('2026-07-09T03:28:00+00:00');

        $this->assertSame('09/07/2026 10:28', $formatted);
    }

    public function test_format_schedule_label_uses_display_timezone(): void
    {
        $label = SeoDisplayTimezone::formatScheduleLabel(
            Carbon::parse('2026-07-09T03:28:00Z'),
        );

        $this->assertSame('Th5 9, 2026 at 10:28', $label);
    }

    public function test_settings_page_exists(): void
    {
        self::assertTrue(class_exists(SeoSettingsDateTime::class));
        $ref = new \ReflectionClass(SeoSettingsDateTime::class);
        $prop = $ref->getProperty('slug');
        $prop->setAccessible(true);
        self::assertSame('settings/date-time', $prop->getDefaultValue());
    }
}
