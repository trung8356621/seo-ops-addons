<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Canonical article language codes for storage (`articles.language`).
 *
 * Storage = machine codes (vi, en, …). UI labels are resolved separately.
 * WordPress/Polylang detection remains in wp-seo-ai; this class only normalizes
 * known aliases/labels into codes and never invents language from content.
 */
final class ArticleLanguageCode
{
    /**
     * Deterministic display labels for local/default UI (not Polylang site names).
     *
     * @return array<string, string> code => label
     */
    public static function defaultLabels(): array
    {
        return [
            'vi' => 'Tiếng Việt',
            'en' => 'English',
        ];
    }

    public static function label(string $code, ?string $fallback = null): string
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return $fallback ?? '—';
        }

        $labels = self::defaultLabels();

        return $labels[$normalized] ?? strtoupper($normalized);
    }

    /**
     * Normalize incoming value to a canonical language code.
     * Returns empty string when input is empty/whitespace.
     * Unknown values are lowercased (truncated to 16) — never guessed from prose.
     */
    public static function normalize(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        // Mojibake / question-mark corruption of known Vietnamese labels — refuse silently
        // (cannot reconstruct code from "Ti??ng Vi??t" alone; callers must use WP/plugin SoT).
        if (str_contains($raw, '?') && self::looksLikeCorruptedLabel($raw)) {
            return '';
        }

        $folded = mb_strtolower($raw);
        $folded = str_replace(['_', ' '], ['-', ' '], $folded);
        $compact = str_replace([' ', '-'], '', $folded);

        $mapped = match (true) {
            $folded === 'vi'
            || $folded === 'vn'
            || str_starts_with(str_replace('-', '_', $folded), 'vi_')
            || $folded === 'tiếng việt'
            || $folded === 'tieng viet'
            || $compact === 'tiếngviệt'
            || $compact === 'tiengviet'
            || $folded === 'vietnamese'
            || $folded === 'viet' => 'vi',

            $folded === 'en'
            || $folded === 'en-us'
            || $folded === 'en-gb'
            || $folded === 'english' => 'en',

            default => null,
        };

        if ($mapped !== null) {
            return $mapped;
        }

        // Already a short code-like token: keep lowercase, strip spaces.
        $code = strtolower(trim(str_replace(['_', ' '], ['-', ''], $raw)));
        if ($code === '') {
            return '';
        }

        return mb_substr($code, 0, 16);
    }

    public static function normalizeForStorage(?string $value, string $default = 'vi'): string
    {
        $normalized = self::normalize($value);

        return $normalized !== '' ? $normalized : $default;
    }

    /**
     * Map a WordPress locale (e.g. vi_VN, en_US) to a primary language code (vi, en).
     * Does not invent a language from domain names or prose.
     */
    public static function fromWordpressLocale(?string $locale): string
    {
        $raw = trim((string) $locale);
        if ($raw === '') {
            return '';
        }

        $normalized = self::normalize($raw);
        if ($normalized !== '' && ! str_contains($normalized, '-')) {
            return $normalized;
        }

        $primary = preg_split('/[_-]/', str_replace(' ', '', $raw), 2)[0] ?? '';
        $primary = self::normalize(is_string($primary) ? $primary : '');

        return $primary;
    }

    public static function isCanonicalCode(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return self::normalize($trimmed) === strtolower($trimmed)
            || self::normalize($trimmed) === $trimmed;
    }

    private static function looksLikeCorruptedLabel(string $raw): bool
    {
        // e.g. Ti???ng Vi???t / Ti??ng Vi??t
        return (bool) preg_match('/t[i?]+\?*ng\s*v[i?]+\?*t/iu', $raw);
    }
}
