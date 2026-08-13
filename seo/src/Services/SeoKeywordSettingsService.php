<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\WpOption;

final class SeoKeywordSettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_keyword_settings';

    public const KEY_CTA_BLACKLIST = 'cta_blacklist';

    /** @var list<string> */
    private const DEFAULT_CTA_BLACKLIST = [
        'tại đây',
        'click vào',
        'yêu cầu mẫu',
        'liên hệ hotline',
        'catalogue mẫu',
        'miễn phí tại đây',
        'yêu cầu catalogue',
        'nhấn vào đây',
        'xem thêm tại đây',
        'liên hệ ngay',
    ];

    public static function withDefaults(): self
    {
        $service = new self;
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @return array{cta_blacklist: list<string>}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->inMemorySettings;
        }

        $data = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($data)) {
            return $this->defaultSettings();
        }

        $blacklist = $this->normalizeKeywords($data[self::KEY_CTA_BLACKLIST] ?? null);
        if ($blacklist !== [] && $this->hasByteCorruptedUtf8Labels($blacklist)) {
            $blacklist = [];
        }

        return [
            self::KEY_CTA_BLACKLIST => $blacklist !== [] ? $blacklist : self::DEFAULT_CTA_BLACKLIST,
        ];
    }

    /**
     * @return list<string>
     */
    public function getCtaBlacklist(): array
    {
        return $this->getSettings()[self::KEY_CTA_BLACKLIST];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $blacklist = $this->normalizeKeywords($settings[self::KEY_CTA_BLACKLIST] ?? null);

        WpOption::set(self::OPTION_KEY, [
            self::KEY_CTA_BLACKLIST => $blacklist !== [] ? $blacklist : self::DEFAULT_CTA_BLACKLIST,
        ], 'no');

        $this->inMemorySettings = null;
    }

    /**
     * @param  array<int, string>|list<string>|string|null  $raw
     * @return list<string>
     */
    public function normalizeBlacklist(mixed $raw): array
    {
        return $this->normalizeKeywords($raw);
    }

    public function keywordsFromTextarea(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return $this->normalizeKeywords($lines);
    }

    /**
     * @param  list<string>  $keywords
     */
    public function keywordsToTextarea(array $keywords): string
    {
        return implode("\n", $this->normalizeKeywords($keywords));
    }

    /**
     * @return array{cta_blacklist: list<string>}
     */
    private function defaultSettings(): array
    {
        return [
            self::KEY_CTA_BLACKLIST => self::DEFAULT_CTA_BLACKLIST,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeKeywords(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $keywords = [];
        $seen = [];

        foreach ($raw as $item) {
            $label = trim(is_string($item) ? $item : (string) $item);
            if ($label === '') {
                continue;
            }

            $dedupeKey = mb_strtolower($label, 'UTF-8');
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $keywords[] = $label;
        }

        return $keywords;
    }

    /**
     * Phát hiện chuỗi bị thay byte UTF-8 bằng '?' (vd. "tại đây" → "t???i ????y").
     *
     * @param  list<string>  $labels
     */
    private function hasByteCorruptedUtf8Labels(array $labels): bool
    {
        foreach ($labels as $label) {
            if (preg_match('/\?\?\?/', $label) === 1) {
                return true;
            }
        }

        return false;
    }
}
