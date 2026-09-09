<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Topic membership for Internal Link Stage 2.
 *
 * A keyword is "topic" when seo_keyword_classifications.cluster_key is non-empty.
 * Focus / canonical destination is resolved by the caller via KeywordLinkTargetResolver.
 */
final class ArticleInternalLinkTopicMembership
{
    /**
     * @param  list<int>  $keywordIds
     * @return array<int, string> keyword_id => cluster_key
     */
    public function clusterKeysByKeywordId(array $keywordIds): array
    {
        $ids = [];
        foreach ($keywordIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', array_values($ids))
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->get(['keyword_id', 'cluster_key']);

        $map = [];
        foreach ($rows as $row) {
            $kid = (int) $row->keyword_id;
            $key = trim((string) $row->cluster_key);
            if ($kid > 0 && $key !== '') {
                $map[$kid] = $key;
            }
        }

        return $map;
    }

    public function isTopicKeyword(Keyword $keyword, array $clusterKeysByKeywordId): bool
    {
        $kid = (int) $keyword->id;
        if ($kid <= 0) {
            return false;
        }

        return trim((string) ($clusterKeysByKeywordId[$kid] ?? '')) !== '';
    }
}
