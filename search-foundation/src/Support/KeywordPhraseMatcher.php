<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

/**
 * So khớp cụm từ khóa bỏ qua dấu câu / apostrophe (KID'S CLUB ≈ kids club).
 */
final class KeywordPhraseMatcher
{
    public static function normalize(string $text): string
    {
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');

        $text = preg_replace(
            '/[\x{0027}\x{2018}\x{2019}\x{201B}\x{2032}\x{0060}\x{00B4}\x{02BC}\x{FF07}]+/u',
            '',
            $text,
        ) ?? $text;

        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        $normalizedHaystack = self::normalize($haystack);
        $normalizedNeedle = self::normalize($needle);

        if ($normalizedNeedle === '' || $normalizedHaystack === '') {
            return false;
        }

        return mb_strpos($normalizedHaystack, $normalizedNeedle) !== false;
    }

    public static function countOccurrences(string $haystack, string $needle): int
    {
        $normalizedHaystack = self::normalize($haystack);
        $normalizedNeedle = self::normalize($needle);

        if ($normalizedNeedle === '' || $normalizedHaystack === '') {
            return 0;
        }

        $count = 0;
        $offset = 0;
        $needleLength = mb_strlen($normalizedNeedle);

        while (($position = mb_strpos($normalizedHaystack, $normalizedNeedle, $offset)) !== false) {
            $count++;
            $offset = $position + $needleLength;
        }

        return $count;
    }

    public static function countWords(string $text): int
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
