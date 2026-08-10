<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anchor text internal link không phải từ khóa thật (URL, domain thuần, ảnh link…).
 */
final class InternalAnchorKeywordFilter
{
    public static function isUsableAnchorPhrase(string $phrase, ?string $href = null): bool
    {
        $phrase = Keyword::decodePhrase($phrase);
        if ($phrase === '') {
            return false;
        }

        if (self::looksLikeUrlOrLinkLabel($phrase)) {
            return false;
        }

        if ($href !== null && $href !== '' && self::anchorTextIsMostlyHref($phrase, $href)) {
            return false;
        }

        return true;
    }

    public static function looksLikeUrlOrLinkLabel(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        if (preg_match('#^\s*https?://#i', $text) === 1) {
            return true;
        }

        if (preg_match('#^\s*www\.#i', $text) === 1) {
            return true;
        }

        if (preg_match('#^\s*(website|url|link)\s*:\s*#iu', $text) === 1) {
            return true;
        }

        if (str_contains($text, '/wp-content/') || str_contains($text, '/uploads/')) {
            return true;
        }

        if (preg_match('~^[a-z0-9][a-z0-9\-]*(\.[a-z0-9\-]+)+\.[a-z]{2,}(/|\?|#|$)~i', $text) === 1) {
            return true;
        }

        if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        $candidate = str_starts_with(strtolower($text), 'http') ? $text : 'https://'.$text;
        if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
            $path = (string) parse_url($candidate, PHP_URL_PATH);
            $host = (string) parse_url($candidate, PHP_URL_HOST);

            if ($host !== '' && ($path !== '' && $path !== '/') && ! str_contains($text, ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyExcludeLinkLikePhrases(Builder $query, string $phraseColumn = 'phrase'): Builder
    {
        return $query
            ->where($phraseColumn, 'not like', 'http://%')
            ->where($phraseColumn, 'not like', 'https://%')
            ->where($phraseColumn, 'not like', 'www.%')
            ->where($phraseColumn, 'not like', 'Website:%')
            ->where($phraseColumn, 'not like', 'website:%')
            ->where($phraseColumn, 'not like', 'URL:%')
            ->where($phraseColumn, 'not like', 'url:%')
            ->where($phraseColumn, 'not like', '%/wp-content/%')
            ->where($phraseColumn, 'not like', '%/uploads/%');
    }

    private static function anchorTextIsMostlyHref(string $anchor, string $href): bool
    {
        $a = self::normalizeComparable($anchor);
        $h = self::normalizeComparable($href);

        if ($a === '' || $h === '') {
            return false;
        }

        if ($a === $h) {
            return true;
        }

        return str_contains($h, $a) || str_contains($a, $h);
    }

    private static function normalizeComparable(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('#^\s*(website|url|link)\s*:\s*#iu', '', $value) ?? $value;
        $value = strtolower($value);
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = preg_replace('#^www\.#', '', $value) ?? $value;

        return rtrim($value, '/');
    }
}
