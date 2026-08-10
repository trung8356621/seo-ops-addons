<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support;

/**
 * Chuẩn hóa giá trị SEO import từ Rank Math (loại template %title%, %sep%, …).
 */
final class RankMathSeoValueNormalizer
{
    public static function normalizeTitle(?string $value): ?string
    {
        $normalized = self::normalizePlainValue($value);

        if ($normalized !== null && self::isBogusSeoTitleLabel($normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Nhãn markdown / placeholder không phải tiêu đề SEO thật (vd. «Meta Description»).
     */
    public static function isBogusSeoTitleLabel(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, [
            'meta description',
            'h1',
            'title',
            'seo title',
            'tiêu đề',
            'tiêu đề seo',
        ], true);
    }

    public static function normalizeSlug(?string $value): ?string
    {
        return self::normalizePlainValue($value);
    }

    private static function normalizePlainValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (self::containsRankMathVariable($value)) {
            return null;
        }

        return $value;
    }

    public static function containsRankMathVariable(string $value): bool
    {
        return preg_match('/\%[a-z0-9_-]+%/i', $value) === 1;
    }
}
