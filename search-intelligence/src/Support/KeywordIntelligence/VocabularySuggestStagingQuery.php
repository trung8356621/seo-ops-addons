<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * SEO Audit staging pool for Vocabulary-generated candidates.
 * Not part of Keyword Intelligence Dictionary (active inventory).
 */
final class VocabularySuggestStagingQuery
{
    /**
     * Canonical read path for SEO Audit Vocabulary Suggest.
     *
     * @return Builder<Keyword>
     */
    public static function forSite(int $siteId): Builder
    {
        $query = Keyword::query()
            ->where('type', Keyword::TYPE_SUGGEST)
            ->where('source', KeywordSourceNormalizer::AI_GENERATED);

        if ($siteId > 0) {
            $query->forSite($siteId);
        }

        return $query->orderByDesc('id');
    }
}
