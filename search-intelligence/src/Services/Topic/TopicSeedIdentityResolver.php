<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Reconcile proposal Topics onto existing topic_id via seed membership anchors.
 *
 * Identity is NOT name / folded name / keyword-id-hash. Seed keyword_id → topic_id only.
 */
final class TopicSeedIdentityResolver
{
    /**
     * @param  list<array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>  $clusters
     * @param  array<int, int>  $seedKeywordToTopicId  keyword_id → existing topic_id (site-scoped seeds)
     * @return list<array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>
     */
    public function apply(array $clusters, array $seedKeywordToTopicId): array
    {
        if ($seedKeywordToTopicId === []) {
            return $clusters;
        }

        /** @var array<int, true> $claimed */
        $claimed = [];
        foreach ($clusters as $cluster) {
            if ($cluster['topic_id'] !== null) {
                $claimed[(int) $cluster['topic_id']] = true;
            }
        }

        foreach ($clusters as $index => $cluster) {
            if ($cluster['topic_id'] !== null) {
                continue;
            }

            $reuseId = null;
            foreach ($cluster['members'] as $member) {
                if (! ($member['is_seed'] ?? false)) {
                    continue;
                }
                $keywordId = (int) $member['keyword_id'];
                if ($keywordId <= 0 || ! isset($seedKeywordToTopicId[$keywordId])) {
                    continue;
                }
                $candidate = (int) $seedKeywordToTopicId[$keywordId];
                if ($candidate <= 0 || isset($claimed[$candidate])) {
                    continue;
                }
                $reuseId = $candidate;
                break;
            }

            if ($reuseId === null) {
                continue;
            }

            $clusters[$index]['topic_id'] = $reuseId;
            $claimed[$reuseId] = true;
        }

        return $clusters;
    }
}
