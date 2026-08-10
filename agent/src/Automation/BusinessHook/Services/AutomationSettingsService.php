<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use App\Models\WpOption;

final class AutomationSettingsService
{
    public const OPTION_KEY = 'seo_automation_settings';

    public const KEY_EXECUTION_LOG_RETENTION = 'execution_log_retention';

    public const KEY_CUSTOM_RETENTION_DAYS = 'execution_log_retention_custom_days';

    public const RETENTION_FOREVER = 'forever';

    public const RETENTION_30 = '30';

    public const RETENTION_60 = '60';

    public const RETENTION_90 = '90';

    public const RETENTION_CUSTOM = 'custom';

    public const DEFAULT_RETENTION = self::RETENTION_30;

    public const DEFAULT_CUSTOM_DAYS = 30;

    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    /**
     * @return array{execution_log_retention: string, execution_log_retention_custom_days: int}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->normalize($this->inMemorySettings);
        }

        $data = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($data)) {
            $data = [];
        }

        return $this->normalize($data);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $payload = $this->normalize($settings);
        WpOption::set(self::OPTION_KEY, $payload, 'no');
        $this->inMemorySettings = $payload;
    }

    /**
     * @return array{mode: string, days: int|null}
     */
    public function resolveRetention(): array
    {
        $settings = $this->getSettings();
        $mode = $settings[self::KEY_EXECUTION_LOG_RETENTION];

        if ($mode === self::RETENTION_FOREVER) {
            return ['mode' => self::RETENTION_FOREVER, 'days' => null];
        }

        if ($mode === self::RETENTION_CUSTOM) {
            return [
                'mode' => self::RETENTION_CUSTOM,
                'days' => max(1, (int) $settings[self::KEY_CUSTOM_RETENTION_DAYS]),
            ];
        }

        $days = (int) $mode;

        return [
            'mode' => $mode,
            'days' => $days > 0 ? $days : (int) self::DEFAULT_RETENTION,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function retentionOptions(): array
    {
        return [
            self::RETENTION_FOREVER => 'Forever',
            self::RETENTION_30 => '30 days',
            self::RETENTION_60 => '60 days',
            self::RETENTION_90 => '90 days',
            self::RETENTION_CUSTOM => 'Custom',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{execution_log_retention: string, execution_log_retention_custom_days: int}
     */
    private function normalize(array $data): array
    {
        $mode = (string) ($data[self::KEY_EXECUTION_LOG_RETENTION] ?? self::DEFAULT_RETENTION);
        $allowed = array_keys(self::retentionOptions());
        if (! in_array($mode, $allowed, true)) {
            $mode = self::DEFAULT_RETENTION;
        }

        $customDays = (int) ($data[self::KEY_CUSTOM_RETENTION_DAYS] ?? self::DEFAULT_CUSTOM_DAYS);
        if ($customDays < 1) {
            $customDays = self::DEFAULT_CUSTOM_DAYS;
        }

        return [
            self::KEY_EXECUTION_LOG_RETENTION => $mode,
            self::KEY_CUSTOM_RETENTION_DAYS => $customDays,
        ];
    }
}
