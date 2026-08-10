<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;

/**
 * Stop / blacklist phrase chung cho primary + fallback link suggestions.
 */
final class LinkSuggestionStopPhraseFilter
{
    public static function isStopPhrase(string $phrase): bool
    {
        $key = KeywordPhraseMatcher::normalize($phrase);
        if ($key === '') {
            return true;
        }

        foreach (self::phrases() as $item) {
            if (KeywordPhraseMatcher::normalize((string) $item) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function phrases(): array
    {
        $primary = config('seo-content-ai.link_suggestions.stop_phrases', []);
        $legacy = config('seo-content-ai.link_suggestions.fallback_stop_phrases', []);

        $merged = [];
        foreach ([is_array($primary) ? $primary : [], is_array($legacy) ? $legacy : []] as $list) {
            foreach ($list as $item) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $merged[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($merged));
    }

    public static function debugEnabled(): bool
    {
        return (bool) config('seo-content-ai.link_suggestions.debug', false);
    }
}
