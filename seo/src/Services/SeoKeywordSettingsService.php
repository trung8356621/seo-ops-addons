<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\WpOption;
use Omnichannel\Addons\Seo\Services\MatchRules\GlobalMatchRuleRegistry;
use Omnichannel\Addons\SearchFoundation\Contracts\GlobalMatchRuleProvider;

final class SeoKeywordSettingsService implements GlobalMatchRuleProvider
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_keyword_settings';

    public const KEY_CTA_BLACKLIST = 'cta_blacklist';

    public function __construct(private readonly ?GlobalMatchRuleRegistry $registry = null) {}

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

        $settings = [];
        foreach ($this->definitions() as $key => $definition) {
            if (! array_key_exists($key, $data)) {
                $settings[$key] = $definition['defaults'];

                continue;
            }
            $values = $this->normalizeKeywords($data[$key]);
            if ($values !== [] && $this->hasByteCorruptedUtf8Labels($values)) {
                $settings[$key] = $definition['defaults'];

                continue;
            }
            $settings[$key] = $values;
        }

        return $settings;
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
        $current = $this->getSettings();
        $normalized = [];
        foreach ($this->definitions() as $key => $definition) {
            if (array_key_exists($key, $settings)) {
                $normalized[$key] = $this->normalizeKeywords($settings[$key]);

                continue;
            }
            $normalized[$key] = $current[$key] ?? $definition['defaults'];
        }
        WpOption::set(self::OPTION_KEY, $normalized, 'no');

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
        return array_map(static fn (array $definition): array => $definition['defaults'], $this->definitions());
    }

    /** @return array<string, array{key:string,label:string,scope:string,description:string,match_mode:string,editable:bool,defaults:list<string>}> */
    public function definitions(): array
    {
        return ($this->registry ?? new GlobalMatchRuleRegistry)->definitions();
    }

    /** @return list<string> */
    public function normalizeRuleValues(mixed $raw): array
    {
        return $this->normalizeKeywords($raw);
    }

    public function globalMatchRules(): array
    {
        return $this->getSettings();
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
