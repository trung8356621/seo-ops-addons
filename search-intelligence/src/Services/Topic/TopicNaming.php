<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

/**
 * Topic name formatting — Unicode phrase is SSOT; presentation only.
 */
final class TopicNaming
{
    public static function canonicalName(string $phrase): string
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
        if ($trimmed === '') {
            return '';
        }

        $presented = KeywordPhrasePresentation::present($trimmed);

        return $presented !== '' ? $presented : $trimmed;
    }
}
