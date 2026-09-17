<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

/**
 * Classification dirty scheduling removed — seo_keyword_classifications dropped.
 */
final class KeywordIntelligenceScheduler
{
    public function onKeywordChanged(int $siteId, bool $created, bool $phraseChanged): void
    {
        unset($siteId, $created, $phraseChanged);
    }

    public function onImportBatch(int $siteId, int $changedCount): void
    {
        unset($siteId, $changedCount);
    }

    public function markDirtyAndDispatch(int $siteId): void
    {
        unset($siteId);
    }
}
