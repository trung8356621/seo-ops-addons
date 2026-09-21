<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;

/**
 * Shared focus-keyword destination relevance (exact vs strong overlap).
 * Used by LIKE fallback + ranked site index scoring.
 */
final class InternalLinkFocusRelevance
{
    public const REASON_FOCUS_KEYWORD = 'focus_keyword';

    public const REASON_FOCUS_OVERLAP = 'focus_overlap';

    /**
     * @return array{score: int, reason: string}|null
     */
    public static function score(string $phrase, string $focusKeyword): ?array
    {
        $phraseNorm = KeywordPhraseMatcher::normalize($phrase);
        $focusNorm = KeywordPhraseMatcher::normalize($focusKeyword);
        if ($phraseNorm === '' || $focusNorm === '') {
            return null;
        }

        if ($focusNorm === $phraseNorm) {
            return [
                'score' => LinkSuggestionScoreScale::FOCUS_KEYWORD,
                'reason' => self::REASON_FOCUS_KEYWORD,
            ];
        }

        $overlap = self::overlapScore($phraseNorm, $focusNorm);
        if ($overlap >= LinkSuggestionScoreScale::FOCUS_OVERLAP) {
            return [
                'score' => LinkSuggestionScoreScale::FOCUS_OVERLAP,
                'reason' => self::REASON_FOCUS_OVERLAP,
            ];
        }

        return null;
    }

    /**
     * Strong focus-keyword overlap (entity/material tokens) — not exact equality.
     * Returns 0 when overlap is too weak to outrank title/slug paths.
     */
    public static function overlapScore(string $phraseNormOrRaw, string $focusNormOrRaw): int
    {
        $phraseNorm = KeywordPhraseMatcher::normalize($phraseNormOrRaw);
        $focusNorm = KeywordPhraseMatcher::normalize($focusNormOrRaw);
        if ($phraseNorm === '' || $focusNorm === '' || $phraseNorm === $focusNorm) {
            return 0;
        }

        $phraseTokens = self::contentfulTokens($phraseNorm);
        if ($phraseTokens === []) {
            return 0;
        }

        $focusTokens = self::contentfulTokens($focusNorm);
        $focusSet = array_fill_keys($focusTokens, true);
        $overlap = [];

        foreach ($phraseTokens as $token) {
            if (isset($focusSet[$token])) {
                $overlap[$token] = true;
                continue;
            }
            foreach ($focusTokens as $focusToken) {
                if (mb_strlen($token) < 4 && preg_match('/\d/u', $token) !== 1) {
                    continue;
                }
                if (str_contains($focusToken, $token) || str_contains($token, $focusToken)) {
                    $overlap[$token] = true;
                    break;
                }
            }
        }

        if ($overlap === []) {
            return 0;
        }

        $hasLongOrCoded = false;
        foreach (array_keys($overlap) as $token) {
            if (mb_strlen((string) $token) >= 5 || preg_match('/\d/u', (string) $token) === 1) {
                $hasLongOrCoded = true;
                break;
            }
        }

        $need = max(1, (int) ceil(count($phraseTokens) * 0.5));
        if ($hasLongOrCoded || count($overlap) >= $need) {
            return LinkSuggestionScoreScale::FOCUS_OVERLAP;
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function contentfulTokens(string $norm): array
    {
        $tokens = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) <= 1) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }
}
