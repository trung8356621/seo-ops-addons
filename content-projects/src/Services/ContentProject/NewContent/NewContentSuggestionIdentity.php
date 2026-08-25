<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Deterministic planning identity for AI New Content suggestions.
 * Does not call AI. Does not persist Keyword Intelligence.
 */
final class NewContentSuggestionIdentity
{
    public static function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function fingerprint(string $keyword, string $title): string
    {
        $keywordNorm = self::normalize($keyword);
        $titleNorm = self::normalize($title);
        if ($titleNorm === '') {
            $titleNorm = $keywordNorm;
        }

        return hash('sha256', 'ai_new_content|'.$keywordNorm.'|'.$titleNorm);
    }

    public static function decisionSourceKey(string $fingerprint): string
    {
        return 'fp:'.$fingerprint;
    }
}
