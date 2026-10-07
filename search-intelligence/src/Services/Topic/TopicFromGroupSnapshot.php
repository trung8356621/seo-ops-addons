<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;

/**
 * Cheap aggregate counts for Topic rebuild modal — COUNT only, no hydration.
 */
final class TopicFromGroupSnapshot
{
    /**
     * @return array{
     *     group_count: int,
     *     topic_candidate_count: int,
     *     topic_blocked_count: int
     * }
     */
    public static function forSite(int $siteId): array
    {
        $empty = [
            'group_count' => 0,
            'topic_candidate_count' => 0,
            'topic_blocked_count' => 0,
        ];
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return $empty;
        }

        $conn = DB::connection('omi_seo_ai');
        $groupCount = (int) $conn->table('seo_keyword_groups')
            ->where('site_id', $siteId)
            ->count();

        if (! KeywordGroupSchema::topicCandidateReady()) {
            $memberCount = (int) $conn->table('seo_keyword_group_keywords')
                ->where('site_id', $siteId)
                ->count();

            return [
                'group_count' => $groupCount,
                'topic_candidate_count' => $memberCount,
                'topic_blocked_count' => 0,
            ];
        }

        $candidateCount = (int) $conn->table('seo_keyword_group_keywords')
            ->where('site_id', $siteId)
            ->where('is_topic_candidate', true)
            ->count();
        $blockedCount = (int) $conn->table('seo_keyword_group_keywords')
            ->where('site_id', $siteId)
            ->where('is_topic_candidate', false)
            ->count();

        return [
            'group_count' => $groupCount,
            'topic_candidate_count' => $candidateCount,
            'topic_blocked_count' => $blockedCount,
        ];
    }
}
