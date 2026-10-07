<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Translates an analysis proposal into the cluster array Topic identity
 * and persistence already understand. Does not write Topic rows.
 */
final class TopicGroupingProposalMapper
{
    /**
     * @return list<array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>
     */
    public function toReclusterClusters(TopicGroupingProposal $proposal): array
    {
        $clusters = [];
        foreach ($proposal->groups as $group) {
            $members = [];
            foreach ($group->members as $member) {
                $members[] = [
                    'keyword_id' => $member->keywordRef,
                    'phrase' => $member->text,
                    'source' => (string) ($member->evidence[TopicGroupingMember::EVIDENCE_SOURCE] ?? ''),
                    'is_seed' => (bool) ($member->evidence[TopicGroupingMember::EVIDENCE_IS_SEED] ?? false),
                    'confidence' => $member->confidence,
                    'is_locked' => (bool) ($member->evidence[TopicGroupingMember::EVIDENCE_IS_LOCKED] ?? false),
                ];
            }

            $topicId = $group->existingTopicRef;
            if ($topicId !== null && $topicId <= 0) {
                $topicId = null;
            }

            $clusters[] = [
                'name' => $group->suggestedLabel,
                'topic_id' => $topicId,
                'is_locked' => (bool) ($group->metadata[TopicGroupingGroup::META_IS_LOCKED] ?? false),
                'members' => $members,
            ];
        }

        return $clusters;
    }
}
