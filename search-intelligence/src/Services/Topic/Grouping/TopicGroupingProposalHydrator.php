<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Rebuilds TopicGroupingProposal from seo_topic_grouping_runs.proposal_payload.
 * Does not call semantic HTTP.
 */
final class TopicGroupingProposalHydrator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromPayload(array $payload): TopicGroupingProposal
    {
        $groups = [];
        $rawGroups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
        foreach ($rawGroups as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $members = [];
            $rawMembers = is_array($raw['members'] ?? null) ? $raw['members'] : [];
            foreach ($rawMembers as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $ref = (int) ($member['keyword_ref'] ?? 0);
                if ($ref <= 0) {
                    continue;
                }
                $members[] = new TopicGroupingMember(
                    $ref,
                    (string) ($member['text'] ?? ''),
                    isset($member['confidence']) ? (float) $member['confidence'] : null,
                    is_array($member['evidence'] ?? null) ? $member['evidence'] : [],
                );
            }
            $existing = $raw['existing_topic_ref'] ?? null;
            $groups[] = new TopicGroupingGroup(
                (string) ($raw['group_key'] ?? ''),
                (string) ($raw['suggested_label'] ?? ''),
                $members,
                $existing !== null && (int) $existing > 0 ? (int) $existing : null,
                is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
            );
        }

        $unassigned = [];
        $rawUnassigned = is_array($payload['unassigned'] ?? null) ? $payload['unassigned'] : [];
        foreach ($rawUnassigned as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = (int) ($row['keyword_ref'] ?? 0);
            if ($ref <= 0) {
                continue;
            }
            $unassigned[] = new TopicGroupingCandidate($ref, (string) ($row['text'] ?? ''));
        }

        $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $analysisRef = isset($payload['analysis_ref']) ? (string) $payload['analysis_ref'] : null;

        return new TopicGroupingProposal($groups, $unassigned, $meta, $analysisRef !== '' ? $analysisRef : null);
    }
}
