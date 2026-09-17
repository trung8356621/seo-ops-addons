<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * Topic membership for Internal Link Stage 2.
 *
 * Legacy cluster_key / classification membership is retired.
 * Always reports no topic membership so the link pipeline skips topic filters.
 */
final class ArticleInternalLinkTopicMembership
{
    /**
     * @param  list<int>  $keywordIds
     * @return array<int, string> keyword_id => cluster_key
     */
    public function clusterKeysByKeywordId(array $keywordIds): array
    {
        return [];
    }

    public function isTopicKeyword(Keyword $keyword, array $clusterKeysByKeywordId): bool
    {
        return false;
    }
}
