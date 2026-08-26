<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Content\Support\ContentLanguageCodeNormalizer;
use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use App\Models\WpOption;

/**
 * Global Default Content Language (Settings → General).
 * Does not overwrite explicit domain primary language.
 */
final class SeoContentLanguageSettingsService
{
    public const OPTION_KEY = 'seo_content_language_settings';

    public const KEY_DEFAULT_CONTENT_LANGUAGE = 'default_content_language';

    private const CACHE_KEY = 'seo_content_language_settings.v1';

    /** @var array{default_content_language: string}|null */
    private ?array $inMemorySettings = null;

    public static function withDefaults(): self
    {
        $service = new self;
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @return array{default_content_language: string}
     */
    public function defaultSettings(): array
    {
        return [
            self::KEY_DEFAULT_CONTENT_LANGUAGE => ContentLanguageRegistry::defaultCode(),
        ];
    }

    /**
     * @return array{default_content_language: string}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->normalize($this->inMemorySettings);
        }

        if (function_exists('cache')) {
            try {
                /** @var array{default_content_language?: string}|null $cached */
                $cached = cache()->get(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $this->normalize($cached);
                }
            } catch (\Throwable) {
                // Pure PHPUnit / cache unbound
            }
        }

        $stored = [];
        try {
            $raw = WpOption::get(self::OPTION_KEY, []);
            if (is_array($raw)) {
                $stored = $raw;
            }
        } catch (\Throwable) {
            $stored = [];
        }

        $normalized = $this->normalize($stored);
        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $normalized;
    }

    public function getDefaultContentLanguage(): string
    {
        return $this->getSettings()[self::KEY_DEFAULT_CONTENT_LANGUAGE];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{default_content_language: string}
     */
    public function save(array $input): array
    {
        $normalized = $this->normalize($input);

        try {
            WpOption::set(self::OPTION_KEY, $normalized);
        } catch (\Throwable) {
            // Tests / unbound DB — keep in memory
        }

        $this->inMemorySettings = $normalized;
        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{default_content_language: string}
     */
    private function normalize(array $input): array
    {
        $code = ContentLanguageCodeNormalizer::normalize(
            isset($input[self::KEY_DEFAULT_CONTENT_LANGUAGE])
                ? (string) $input[self::KEY_DEFAULT_CONTENT_LANGUAGE]
                : null,
        );
        if ($code === null || ! ContentLanguageRegistry::isSupported($code)) {
            $code = ContentLanguageRegistry::defaultCode();
        }

        return [
            self::KEY_DEFAULT_CONTENT_LANGUAGE => $code,
        ];
    }
}
