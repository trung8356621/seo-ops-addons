<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SystemDateTimeContractTest extends TestCase
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

    public function test_utc_to_system_timezone_vietnamese_format(): void
    {
        $formatted = SystemDateTime::formatDateTime('2026-08-03T07:35:00Z');

        self::assertSame('03/08/2026 14:35', $formatted);
    }

    public function test_english_preset_format(): void
    {
        SystemDateTime::useConfig([
            'timezone' => 'Asia/Ho_Chi_Minh',
            'preset' => 'en',
        ]);

        $formatted = SystemDateTime::formatDateTime('2026-08-03T07:35:00Z');

        self::assertSame('August 3, 2026 2:35 PM', $formatted);
    }

    public function test_system_input_to_utc(): void
    {
        $utc = SystemDateTime::parseSystemInputToUtc('03/08/2026 14:35');

        self::assertSame('2026-08-03T07:35:00+00:00', $utc->toIso8601String());
    }

    public function test_datetime_local_input_to_utc(): void
    {
        $utc = SystemDateTime::parseSystemInputToUtc('2026-08-03T14:35');

        self::assertSame('2026-08-03T07:35:00+00:00', $utc->toIso8601String());
    }

    public function test_changing_timezone_does_not_rewrite_utc_instant(): void
    {
        $iso = '2026-08-03T07:35:00Z';
        $a = SystemDateTime::formatDateTime($iso);

        SystemDateTime::useConfig([
            'timezone' => 'America/New_York',
            'preset' => 'vi',
        ]);
        $b = SystemDateTime::formatDateTime($iso);

        self::assertSame('03/08/2026 14:35', $a);
        self::assertNotSame($a, $b);
        self::assertSame('2026-08-03 07:35', SystemDateTime::formatUtcDebug($iso));
    }

    public function test_null_handling(): void
    {
        self::assertNull(SystemDateTime::formatDateTime(null));
        self::assertNull(SystemDateTime::formatRelative(null));
        self::assertNull(SystemDateTime::toUtc(''));
    }

    public function test_invalid_input_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SystemDateTime::parseSystemInputToUtc('');
    }

    public function test_dst_offset_label_for_new_york(): void
    {
        // 2026-07 summer EDT UTC-04; 2026-01 winter EST UTC-05
        $summer = SystemDateTime::offsetLabel('America/New_York', Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $winter = SystemDateTime::offsetLabel('America/New_York', Carbon::parse('2026-01-15 12:00:00', 'UTC'));

        self::assertSame('UTC−04:00', $summer);
        self::assertSame('UTC−05:00', $winter);
    }

    public function test_frontend_config_shape(): void
    {
        $cfg = SystemDateTime::frontendConfig();

        self::assertSame('Asia/Ho_Chi_Minh', $cfg['timezone']);
        self::assertSame('vi', $cfg['preset']);
        self::assertSame('vi-VN', $cfg['locale']);
        self::assertSame('dd/MM/yyyy', $cfg['date_format']);
        self::assertSame('HH:mm', $cfg['time_format']);
        self::assertSame('h23', $cfg['hour_cycle']);
        self::assertSame(1, $cfg['first_day_of_week']);
    }

    public function test_settings_service_validates_preset_and_timezone(): void
    {
        self::assertTrue(SeoDateTimeSettingsService::isValidTimezone('Asia/Ho_Chi_Minh'));
        self::assertFalse(SeoDateTimeSettingsService::isValidTimezone('UTC+7'));
        self::assertTrue(SeoDateTimeSettingsService::isValidPreset('vi'));
        self::assertTrue(SeoDateTimeSettingsService::isValidPreset('en'));
        self::assertFalse(SeoDateTimeSettingsService::isValidPreset('fr'));

        $svc = SeoDateTimeSettingsService::withDefaults();
        $saved = $svc->save([
            'timezone' => 'Europe/London',
            'preset' => 'en',
        ]);
        self::assertSame('Europe/London', $saved['timezone']);
        self::assertSame('en', $saved['preset']);
    }

    public function test_app_timezone_contract_remains_utc_in_config_key(): void
    {
        // Storage contract: callers must not flip app.timezone dynamically.
        self::assertTrue(method_exists(SystemDateTime::class, 'timezone'));
        self::assertNotSame('UTC', SystemDateTime::timezone());
    }
}
