<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

/**
 * UI-only phrase formatting — never mutates DB values.
 */
final class KeywordPhrasePresentation
{
    public static function present(?string $phrase): string
    {
        $phrase = trim(preg_replace('/\s+/u', ' ', (string) $phrase) ?? (string) $phrase);
        if ($phrase === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $phrase) ?: [];
        $lastIndex = count($parts) - 1;
        $out = [];
        foreach ($parts as $index => $part) {
            $out[] = self::presentToken($part, $index === 0, $index === $lastIndex);
        }

        return implode(' ', $out);
    }

    private static function presentToken(string $token, bool $isFirst, bool $isLast): string
    {
        if ($token === '') {
            return '';
        }

        if (preg_match('/\p{Ll}/u', $token) && preg_match('/\p{Lu}/u', $token)) {
            return $token;
        }

        if (preg_match('/^[A-Z0-9]{2,3}$/', $token) && ($isLast || preg_match('/\d/', $token))) {
            return $token;
        }

        $lower = mb_strtolower($token, 'UTF-8');
        if ($isFirst) {
            return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
        }

        return $lower;
    }
}
