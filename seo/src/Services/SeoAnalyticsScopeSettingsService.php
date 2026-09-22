<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\WpOption;

/**
 * Analytics / Statistics content-scope preferences (Settings → General).
 *
 * Does NOT govern MCP eligibility, sync, publish, or editor surfaces.
 */
final class SeoAnalyticsScopeSettingsService
{
    public const OPTION_KEY = 'seo_analytics_scope_settings';

    public const KEY_EXCLUDE_PAGES_FROM_STATISTICS = 'exclude_pages_from_statistics';

    private const CACHE_KEY = 'seo_analytics_scope_settings.v1';

    /** @var array{exclude_pages_from_statistics: bool}|null */
    private ?array $inMemorySettings = null;

    public static function withDefaults(): self
    {
        $service = new self;
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @return array{exclude_pages_from_statistics: bool}
     */
    public function defaultSettings(): array
    {
        return [
            self::KEY_EXCLUDE_PAGES_FROM_STATISTICS => true,
        ];
    }

    /**
     * Missing option / missing key MUST resolve to true (backward compatible).
     */
    public function excludePagesFromStatistics(): bool
    {
        return $this->getSettings()[self::KEY_EXCLUDE_PAGES_FROM_STATISTICS];
    }

    /**
     * @return array{exclude_pages_from_statistics: bool}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->normalize($this->inMemorySettings);
        }

        if (function_exists('cache')) {
            try {
                /** @var array{exclude_pages_from_statistics?: mixed}|null $cached */
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

    /**
     * @param  array<string, mixed>  $input
     * @return array{exclude_pages_from_statistics: bool}
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
     * @return array{exclude_pages_from_statistics: bool}
     */
    public function normalize(array $input): array
    {
        return [
            self::KEY_EXCLUDE_PAGES_FROM_STATISTICS => $this->resolveExcludePagesFlag($input),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveExcludePagesFlag(array $input): bool
    {
        if (! array_key_exists(self::KEY_EXCLUDE_PAGES_FROM_STATISTICS, $input)) {
            return true;
        }

        $raw = $input[self::KEY_EXCLUDE_PAGES_FROM_STATISTICS];

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }

        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        // Unknown payload → keep backward-compatible default.
        return true;
    }
}
