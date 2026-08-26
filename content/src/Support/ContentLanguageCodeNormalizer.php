<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Canonical content-language normalizer (SSOT alias of {@see ArticleLanguageCode}).
 *
 * External systems may send locales (`vi_VN`, `en-US`); business layer stores ISO 639-1.
 */
final class ContentLanguageCodeNormalizer
{
    public static function normalize(?string $value): ?string
    {
        $normalized = ArticleLanguageCode::normalize($value);

        return $normalized !== '' ? $normalized : null;
    }

    public static function normalizeOrEmpty(?string $value): string
    {
        return ArticleLanguageCode::normalize($value);
    }

    public static function fromLocale(?string $locale): ?string
    {
        $code = ArticleLanguageCode::fromWordpressLocale($locale);

        return $code !== '' ? $code : null;
    }

    public static function isCanonical(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed !== strtolower($trimmed)) {
            return false;
        }

        return ArticleLanguageCode::normalize($trimmed) === $trimmed
            && ! str_contains($trimmed, '_')
            && ! str_contains($trimmed, '-');
    }

    /**
     * Deterministic alias map for safe data repair only.
     *
     * @return array<string, string> raw lowercase alias => canonical code
     */
    public static function repairAliasMap(): array
    {
        return [
            'vi' => 'vi',
            'vn' => 'vi',
            'vi_vn' => 'vi',
            'vi-vn' => 'vi',
            'en' => 'en',
            'en_us' => 'en',
            'en-us' => 'en',
            'en_gb' => 'en',
            'en-gb' => 'en',
        ];
    }

    /**
     * Repair a known deterministic alias. Returns null when unknown (do not guess).
     */
    public static function repairKnownAlias(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $key = strtolower(str_replace(' ', '', $raw));
        $map = self::repairAliasMap();
        if (isset($map[$key])) {
            return $map[$key];
        }

        $normalized = self::normalize($raw);
        if ($normalized !== null && isset($map[$normalized])) {
            return $normalized;
        }

        // Accept already-canonical short codes that normalize cleanly without region.
        if ($normalized !== null && self::isCanonical($normalized) && strlen($normalized) === 2) {
            return $normalized;
        }

        return null;
    }
}
