<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupTopicCandidatePolicy;
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
     *     topic_no_focus_count: int,
     *     topic_blocked_count: int
     * }
     */
    public static function forSite(int $siteId): array
    {
        $empty = [
            'group_count' => 0,
            'topic_candidate_count' => 0,
            'topic_no_focus_count' => 0,
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
                'topic_no_focus_count' => 0,
                'topic_blocked_count' => 0,
            ];
        }

        $select = ['keyword_id', 'is_topic_candidate'];
        if (KeywordGroupSchema::topicCandidateOverrideReady()) {
            $select[] = 'topic_candidate_override';
        }

        $rows = $conn->table('seo_keyword_group_keywords')
            ->where('site_id', $siteId)
            ->get($select);

        if ($rows->isEmpty()) {
            return [
                'group_count' => $groupCount,
                'topic_candidate_count' => 0,
                'topic_no_focus_count' => 0,
                'topic_blocked_count' => 0,
            ];
        }

        $keywordIds = $rows->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $focusMap = KeywordGroupSchema::topicCandidateOverrideReady()
            ? app(TopicLinkedArticleCounter::class)->focusArticleIdMapForKeywords($siteId, $keywordIds)
            : [];

        $candidate = 0;
        $noFocus = 0;
        $blocked = 0;
        $overrideReady = KeywordGroupSchema::topicCandidateOverrideReady();

        foreach ($rows as $row) {
            $kid = (int) ($row->keyword_id ?? 0);
            if ($overrideReady) {
                $raw = $row->topic_candidate_override ?? null;
                $override = $raw === null ? null : (bool) $raw;
                $hasFocus = isset($focusMap[$kid]);
                if ($override === false) {
                    $blocked++;
                } elseif ($override === null && ! $hasFocus) {
                    $noFocus++;
                } elseif (KeywordGroupTopicCandidatePolicy::effectiveCandidate($hasFocus, $override)) {
                    $candidate++;
                }
            } else {
                if ((bool) ($row->is_topic_candidate ?? true)) {
                    $candidate++;
                } else {
                    $blocked++;
                }
            }
        }

        return [
            'group_count' => $groupCount,
            'topic_candidate_count' => $candidate,
            'topic_no_focus_count' => $noFocus,
            'topic_blocked_count' => $blocked,
        ];
    }
}
