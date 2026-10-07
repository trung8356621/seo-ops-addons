<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;

/**
 * Builds a deterministic Apply Plan from a proposal + CURRENT Laravel Topic state.
 * Does not mutate Topics. Preview and Apply must both use this builder.
 */
final class TopicGroupingApplyPlanBuilder
{
    public function __construct(
        private readonly TopicGroupingProposalMapper $mapper = new TopicGroupingProposalMapper,
        private readonly TopicSeedIdentityResolver $seedIdentity = new TopicSeedIdentityResolver,
        private readonly TopicGroupingIdentityMatcher $identityMatcher = new TopicGroupingIdentityMatcher,
        private readonly TopicGroupingBusinessStatePlanner $businessStatePlanner = new TopicGroupingBusinessStatePlanner,
    ) {}

    public function build(
        int $siteId,
        TopicGroupingProposal $proposal,
        string $rebuildMode = TopicGroupingRebuildMode::PRESERVE_EXISTING,
    ): TopicGroupingApplyPlan {
        $rebuildMode = TopicGroupingRebuildMode::normalize($rebuildMode);

        if (TopicGroupingRebuildMode::isFullReset($rebuildMode)) {
            return $this->buildFullReset($siteId, $proposal);
        }

        return $this->buildPreserveExisting($siteId, $proposal);
    }

    private function buildPreserveExisting(int $siteId, TopicGroupingProposal $proposal): TopicGroupingApplyPlan
    {
        $locked = $this->loadLockedState($siteId);
        $manualIds = $this->loadManualTopicIds($siteId);
        $seedMap = $this->loadSeedIdentityMap($siteId);
        $currentMembership = $this->loadCurrentMembership($siteId);
        $currentTopics = $this->loadCurrentTopics($siteId);

        $businessSnapshotHash = $this->hashBusinessSnapshot(
            $currentTopics,
            $currentMembership,
            $locked,
            $manualIds,
            $this->loadTagAssignmentFingerprint($siteId, array_keys($currentTopics)),
        );

        $clusters = $this->mapper->toReclusterClusters($proposal);
        $clusters = $this->annotateSeeds($clusters, $seedMap);
        $clusters = $this->seedIdentity->apply($clusters, $seedMap);
        // Apply-path identity: seed anchors first, then membership-continuity matcher
        // for ALL remaining unresolved clusters (seeded + discovered Topics).
        // Legacy TopicDiscoveredIdentityResolver remains on TopicReclusterService only.
        $continuityInventory = $this->loadContinuityInventory($siteId, $manualIds, $locked);
        $matched = $this->identityMatcher->apply($clusters, $continuityInventory);
        $clusters = $matched['clusters'];
        $identityDiag = $matched['diagnostics'];

        $desired = $this->desiredMembership($clusters, $locked, $manualIds, $siteId);
        $topicActions = $this->buildTopicActions($clusters, $currentTopics, $manualIds, $locked, $proposal);
        $keywordActions = $this->buildKeywordActions($desired, $currentMembership, $locked, $proposal);

        return $this->finalizePlan(
            $siteId,
            $proposal,
            $clusters,
            $topicActions,
            $keywordActions,
            $currentTopics,
            $identityDiag,
            $businessSnapshotHash,
            TopicGroupingRebuildMode::PRESERVE_EXISTING,
        );
    }

    /**
     * full_reset: semantic proposal is sole Topic-structure authority.
     * Bypasses seed/discovered/continuity identity and manual/lock identity protection.
     */
    private function buildFullReset(int $siteId, TopicGroupingProposal $proposal): TopicGroupingApplyPlan
    {
        $emptyLocked = [
            'locked_topic_ids' => [],
            'preserved_topic_ids' => [],
            'locked_keyword_ids' => [],
            'locked_memberships_by_topic' => [],
            'topics' => [],
        ];
        $currentMembership = $this->loadCurrentMembership($siteId);
        $currentTopics = $this->loadCurrentTopics($siteId);

        $businessSnapshotHash = $this->hashBusinessSnapshot(
            $currentTopics,
            $currentMembership,
            $emptyLocked,
            [],
            $this->loadTagAssignmentFingerprint($siteId, array_keys($currentTopics)),
        );

        $clusters = $this->mapper->toReclusterClusters($proposal);
        foreach ($clusters as $i => $cluster) {
            $clusters[$i]['topic_id'] = null;
            $clusters[$i]['is_locked'] = false;
            foreach ($cluster['members'] as $j => $member) {
                $clusters[$i]['members'][$j]['is_locked'] = false;
            }
        }

        $identityDiag = [
            'reused' => 0,
            'one_to_one' => [],
            'splits' => [],
            'merges' => [],
            'ambiguous' => [],
            'no_successor' => [],
            'matches' => [],
            'thresholds' => [],
        ];

        $desired = $this->desiredMembershipFullReset($clusters);
        $topicActions = $this->buildTopicActionsFullReset($clusters, $currentTopics, $proposal);
        $keywordActions = $this->buildKeywordActions($desired, $currentMembership, $emptyLocked, $proposal);

        return $this->finalizePlan(
            $siteId,
            $proposal,
            $clusters,
            $topicActions,
            $keywordActions,
            $currentTopics,
            $identityDiag,
            $businessSnapshotHash,
            TopicGroupingRebuildMode::FULL_RESET,
        );
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  list<array{topic_id: int|null, name: string, action: string, member_count: int, group_key: string|null, mean_similarity: float|null, min_similarity: float|null, cohesion: float|null, low_confidence_count: int, warning: string|null}>  $topicActions
     * @param  list<array{keyword_id: int, text: string, from_topic_id: int|null, to_topic_id: int|null, action: string, similarity: float|null, confidence: float|null, protected: bool}>  $keywordActions
     * @param  array<int, array{id: int, name: string, source: string, is_locked: bool}>  $currentTopics
     * @param  array<string, mixed>  $identityDiag
     */
    private function finalizePlan(
        int $siteId,
        TopicGroupingProposal $proposal,
        array $clusters,
        array $topicActions,
        array $keywordActions,
        array $currentTopics,
        array $identityDiag,
        string $businessSnapshotHash,
        string $rebuildMode,
    ): TopicGroupingApplyPlan {
        $counts = [
            'topics_reused' => count(array_filter($topicActions, static fn (array $a): bool => $a['action'] === 'reuse')),
            'topics_created' => count(array_filter($topicActions, static fn (array $a): bool => $a['action'] === 'create')),
            'topics_dissolved' => count(array_filter($topicActions, static fn (array $a): bool => $a['action'] === 'dissolve')),
            'topics_protected' => count(array_filter($topicActions, static fn (array $a): bool => $a['action'] === 'protect')),
            'keywords_kept' => count(array_filter($keywordActions, static fn (array $a): bool => $a['action'] === 'keep')),
            'keywords_assigned' => count(array_filter($keywordActions, static fn (array $a): bool => $a['action'] === 'assign')),
            'keywords_moved' => count(array_filter($keywordActions, static fn (array $a): bool => $a['action'] === 'move')),
            'keywords_unassigned' => count(array_filter($keywordActions, static fn (array $a): bool => $a['action'] === 'unassign')),
            'keywords_protected' => count(array_filter($keywordActions, static fn (array $a): bool => $a['protected'])),
            'semantic_groups' => count($proposal->groups),
            'semantic_unassigned' => count($proposal->unassigned),
            'low_confidence_members' => (int) ($proposal->metadata['low_confidence_member_count']
                ?? $proposal->metadata['diagnostics']['low_confidence_member_count']
                ?? 0),
            'effective_topics_after' => count(array_filter(
                $topicActions,
                static fn (array $a): bool => in_array($a['action'], ['reuse', 'create', 'protect'], true),
            )),
            'identity_one_to_one' => count($identityDiag['one_to_one'] ?? []),
            'identity_splits' => count($identityDiag['splits'] ?? []),
            'identity_merges' => count($identityDiag['merges'] ?? []),
            'identity_ambiguous' => count($identityDiag['ambiguous'] ?? []),
            'identity_no_successor' => count($identityDiag['no_successor'] ?? []),
            'identity_continuity_reused' => (int) ($identityDiag['reused'] ?? 0),
        ];
        if (TopicGroupingRebuildMode::isFullReset($rebuildMode)) {
            $counts['existing_topics'] = count($currentTopics);
            $counts['rebuild_mode'] = TopicGroupingRebuildMode::FULL_RESET;
        }

        $identityMigration = $this->buildIdentityMigration(
            $siteId,
            $currentTopics,
            $proposal,
            $topicActions,
            $identityDiag,
            $counts,
        );
        $identityMigration['rebuild_mode'] = $rebuildMode;
        $businessState = $this->businessStatePlanner->plan($siteId, $topicActions, $identityMigration, $rebuildMode);
        $warnings = $this->buildWarnings($proposal, $topicActions, $counts, $identityMigration, $businessState);
        if (TopicGroupingRebuildMode::isFullReset($rebuildMode)) {
            array_unshift($warnings, 'full_reset:Apply sẽ thay thế toàn bộ cấu trúc Topic hiện tại.');
        }

        $protectedTopics = [];
        foreach ($topicActions as $action) {
            if ($action['action'] === 'protect') {
                $protectedTopics[] = [
                    'topic_id' => (int) ($action['topic_id'] ?? 0),
                    'name' => (string) $action['name'],
                    'reason' => (string) ($action['warning'] ?? 'protected'),
                ];
            }
        }
        $protectedKeywords = [];
        foreach ($keywordActions as $action) {
            if ($action['protected']) {
                $protectedKeywords[] = [
                    'keyword_id' => (int) $action['keyword_id'],
                    'reason' => (string) $action['action'],
                ];
            }
        }

        $planHash = $this->hashPlan(
            $counts,
            $topicActions,
            $keywordActions,
            $businessSnapshotHash,
            $identityMigration,
            $businessState,
            $rebuildMode,
        );

        return new TopicGroupingApplyPlan(
            $planHash,
            $counts,
            $topicActions,
            $keywordActions,
            $protectedTopics,
            $protectedKeywords,
            $warnings,
            $clusters,
            $businessSnapshotHash,
            $identityMigration,
            $businessState,
            $rebuildMode,
        );
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @return array<int, array{topic_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     */
    private function desiredMembershipFullReset(array $clusters): array
    {
        /** @var array<int, array{topic_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}> $desired */
        $desired = [];
        foreach ($clusters as $cluster) {
            $sentinel = -1 * (abs(crc32($cluster['name'])) % 100000000 + 1);
            foreach ($cluster['members'] as $member) {
                $keywordId = (int) $member['keyword_id'];
                if ($keywordId <= 0) {
                    continue;
                }
                $desired[$keywordId] = [
                    'topic_id' => $sentinel,
                    'source' => (string) ($member['source'] !== '' ? $member['source'] : 'semantic'),
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => false,
                ];
            }
        }

        return $desired;
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array<int, array{id: int, name: string, source: string, is_locked: bool}>  $currentTopics
     * @return list<array{topic_id: int|null, name: string, action: string, member_count: int, group_key: string|null, mean_similarity: float|null, min_similarity: float|null, cohesion: float|null, low_confidence_count: int, warning: string|null}>
     */
    private function buildTopicActionsFullReset(
        array $clusters,
        array $currentTopics,
        TopicGroupingProposal $proposal,
    ): array {
        $actions = [];
        $metaByLabel = [];
        foreach ($proposal->groups as $group) {
            $metaByLabel[$group->suggestedLabel] = $group;
        }

        foreach ($clusters as $cluster) {
            $memberCount = count($cluster['members']);
            $group = $metaByLabel[$cluster['name']] ?? null;
            $lowConf = 0;
            if ($group !== null) {
                foreach ($group->members as $m) {
                    if ($m->confidence !== null && $m->confidence < 0.35) {
                        $lowConf++;
                    }
                }
            }
            $actions[] = [
                'topic_id' => null,
                'name' => $cluster['name'],
                'action' => 'create',
                'member_count' => $memberCount,
                'group_key' => $group?->groupKey,
                'mean_similarity' => isset($group?->metadata['mean_similarity']) ? (float) $group->metadata['mean_similarity'] : null,
                'min_similarity' => isset($group?->metadata['min_similarity']) ? (float) $group->metadata['min_similarity'] : null,
                'cohesion' => isset($group?->metadata['cohesion']) ? (float) $group->metadata['cohesion'] : null,
                'low_confidence_count' => $lowConf,
                'warning' => 'full_reset_new_identity',
            ];
        }

        foreach ($currentTopics as $tid => $topic) {
            $actions[] = [
                'topic_id' => $tid,
                'name' => $topic['name'],
                'action' => 'dissolve',
                'member_count' => 0,
                'group_key' => null,
                'mean_similarity' => null,
                'min_similarity' => null,
                'cohesion' => null,
                'low_confidence_count' => 0,
                'warning' => 'full_reset_replace',
            ];
        }

        usort($actions, static function (array $a, array $b): int {
            return [$a['action'], (string) $a['name'], (int) ($a['topic_id'] ?? 0)]
                <=> [$b['action'], (string) $b['name'], (int) ($b['topic_id'] ?? 0)];
        });

        return $actions;
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array<int, int>  $seedMap
     * @return list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>
     */
    private function annotateSeeds(array $clusters, array $seedMap): array
    {
        foreach ($clusters as $i => $cluster) {
            foreach ($cluster['members'] as $j => $member) {
                $kid = (int) $member['keyword_id'];
                if (isset($seedMap[$kid])) {
                    $clusters[$i]['members'][$j]['is_seed'] = true;
                    if (($clusters[$i]['members'][$j]['source'] ?? '') === '') {
                        $clusters[$i]['members'][$j]['source'] = 'seed';
                    }
                }
            }
        }

        return $clusters;
    }

    /**
     * Mirrors TopicReclusterService::persistClusters desired membership (read-only).
     *
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array{locked_topic_ids: array<int, true>, preserved_topic_ids: array<int, true>, locked_keyword_ids: array<int, true>, locked_memberships_by_topic: array<int, list<array{keyword_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>>, topics: list<array{topic_id: int, name: string, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>}  $locked
     * @param  list<int>  $manualIds
     * @return array<int, array{topic_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     */
    private function desiredMembership(array $clusters, array $locked, array $manualIds, int $siteId): array
    {
        /** @var array<int, true> $manualIdSet */
        $manualIdSet = [];
        foreach ($manualIds as $id) {
            $manualIdSet[(int) $id] = true;
        }

        /** @var array<int, array{topic_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}> $desired */
        $desired = [];

        foreach ($locked['locked_memberships_by_topic'] as $topicId => $members) {
            foreach ($members as $member) {
                $desired[(int) $member['keyword_id']] = [
                    'topic_id' => (int) $topicId,
                    'source' => (string) $member['source'],
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => true,
                ];
            }
        }

        foreach ($clusters as $cluster) {
            $topicId = $cluster['topic_id'];
            // For plan preview, unresolved create uses temporary negative sentinel keyed by name hash.
            if ($topicId === null) {
                $topicId = -1 * (abs(crc32($cluster['name'])) % 100000000 + 1);
            }
            $isManual = isset($manualIdSet[(int) ($cluster['topic_id'] ?? 0)]);
            $isFullyLocked = isset($locked['locked_topic_ids'][(int) ($cluster['topic_id'] ?? 0)]);

            if ($isFullyLocked || $isManual) {
                if ($cluster['topic_id'] !== null) {
                    $existing = SeoTopicKeyword::query()
                        ->where('site_id', $siteId)
                        ->where('topic_id', (int) $cluster['topic_id'])
                        ->get(['keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);
                    foreach ($existing as $row) {
                        $desired[(int) $row->keyword_id] = [
                            'topic_id' => (int) $cluster['topic_id'],
                            'source' => (string) $row->source,
                            'is_seed' => (bool) $row->is_seed,
                            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                            'is_locked' => (bool) $row->is_locked,
                        ];
                    }
                }
                if ($isManual) {
                    continue;
                }
            }

            foreach ($cluster['members'] as $member) {
                $keywordId = (int) $member['keyword_id'];
                if ($keywordId <= 0) {
                    continue;
                }
                if (isset($locked['locked_keyword_ids'][$keywordId])) {
                    $owner = $desired[$keywordId]['topic_id'] ?? null;
                    if ($owner !== null && $owner !== (int) $topicId && $cluster['topic_id'] !== null && $owner !== (int) $cluster['topic_id']) {
                        continue;
                    }
                }
                if ($isFullyLocked && isset($desired[$keywordId])) {
                    continue;
                }
                $desired[$keywordId] = [
                    'topic_id' => (int) ($cluster['topic_id'] ?? $topicId),
                    'source' => (string) ($member['source'] !== '' ? $member['source'] : 'semantic'),
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => (bool) $member['is_locked'],
                ];
            }
        }

        return $desired;
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array<int, array{id: int, name: string, source: string, is_locked: bool}>  $currentTopics
     * @param  list<int>  $manualIds
     * @param  array{locked_topic_ids: array<int, true>}  $locked
     * @return list<array{topic_id: int|null, name: string, action: string, member_count: int, group_key: string|null, mean_similarity: float|null, min_similarity: float|null, cohesion: float|null, low_confidence_count: int, warning: string|null}>
     */
    private function buildTopicActions(
        array $clusters,
        array $currentTopics,
        array $manualIds,
        array $locked,
        TopicGroupingProposal $proposal,
    ): array {
        $keep = [];
        $actions = [];
        $metaByLabel = [];
        foreach ($proposal->groups as $group) {
            $metaByLabel[$group->suggestedLabel] = $group;
        }

        foreach ($clusters as $cluster) {
            $tid = $cluster['topic_id'];
            $memberCount = count($cluster['members']);
            $group = $metaByLabel[$cluster['name']] ?? null;
            $lowConf = 0;
            if ($group !== null) {
                foreach ($group->members as $m) {
                    if ($m->confidence !== null && $m->confidence < 0.35) {
                        $lowConf++;
                    }
                }
            }
            $warning = null;
            if ($group !== null) {
                $min = isset($group->metadata['min_similarity']) ? (float) $group->metadata['min_similarity'] : null;
                $cohesion = isset($group->metadata['cohesion']) ? (float) $group->metadata['cohesion'] : null;
                if ($memberCount >= 80) {
                    $warning = 'large_group';
                } elseif ($min !== null && $min < 0.72) {
                    $warning = 'low_min_similarity';
                } elseif ($cohesion !== null && $cohesion < 0.75) {
                    $warning = 'low_cohesion';
                } elseif ($lowConf >= 5) {
                    $warning = 'many_low_confidence';
                }
            }

            if ($tid !== null && isset($locked['locked_topic_ids'][$tid])) {
                $actions[] = [
                    'topic_id' => $tid,
                    'name' => $cluster['name'],
                    'action' => 'protect',
                    'member_count' => $memberCount,
                    'group_key' => $group?->groupKey,
                    'mean_similarity' => isset($group?->metadata['mean_similarity']) ? (float) $group->metadata['mean_similarity'] : null,
                    'min_similarity' => isset($group?->metadata['min_similarity']) ? (float) $group->metadata['min_similarity'] : null,
                    'cohesion' => isset($group?->metadata['cohesion']) ? (float) $group->metadata['cohesion'] : null,
                    'low_confidence_count' => $lowConf,
                    'warning' => 'topic_locked',
                ];
                $keep[$tid] = true;
                continue;
            }
            if ($tid !== null && in_array($tid, $manualIds, true)) {
                $actions[] = [
                    'topic_id' => $tid,
                    'name' => $cluster['name'],
                    'action' => 'protect',
                    'member_count' => $memberCount,
                    'group_key' => $group?->groupKey,
                    'mean_similarity' => null,
                    'min_similarity' => null,
                    'cohesion' => null,
                    'low_confidence_count' => 0,
                    'warning' => 'manual_topic',
                ];
                $keep[$tid] = true;
                continue;
            }

            if ($tid !== null && isset($currentTopics[$tid])) {
                $actions[] = [
                    'topic_id' => $tid,
                    'name' => $cluster['name'],
                    'action' => 'reuse',
                    'member_count' => $memberCount,
                    'group_key' => $group?->groupKey,
                    'mean_similarity' => isset($group?->metadata['mean_similarity']) ? (float) $group->metadata['mean_similarity'] : null,
                    'min_similarity' => isset($group?->metadata['min_similarity']) ? (float) $group->metadata['min_similarity'] : null,
                    'cohesion' => isset($group?->metadata['cohesion']) ? (float) $group->metadata['cohesion'] : null,
                    'low_confidence_count' => $lowConf,
                    'warning' => $warning,
                ];
                $keep[$tid] = true;
            } else {
                $actions[] = [
                    'topic_id' => $tid,
                    'name' => $cluster['name'],
                    'action' => 'create',
                    'member_count' => $memberCount,
                    'group_key' => $group?->groupKey,
                    'mean_similarity' => isset($group?->metadata['mean_similarity']) ? (float) $group->metadata['mean_similarity'] : null,
                    'min_similarity' => isset($group?->metadata['min_similarity']) ? (float) $group->metadata['min_similarity'] : null,
                    'cohesion' => isset($group?->metadata['cohesion']) ? (float) $group->metadata['cohesion'] : null,
                    'low_confidence_count' => $lowConf,
                    'warning' => $warning,
                ];
            }
        }

        foreach ($currentTopics as $tid => $topic) {
            if (isset($keep[$tid])) {
                continue;
            }
            if (
                isset($locked['locked_topic_ids'][$tid])
                || isset($locked['preserved_topic_ids'][$tid])
                || in_array($tid, $manualIds, true)
            ) {
                $reason = 'protected';
                if (isset($locked['locked_topic_ids'][$tid])) {
                    $reason = 'topic_locked';
                } elseif (in_array($tid, $manualIds, true)) {
                    $reason = 'manual_topic';
                } elseif (isset($locked['preserved_topic_ids'][$tid])) {
                    $reason = 'preserved_inventory';
                }
                $actions[] = [
                    'topic_id' => $tid,
                    'name' => $topic['name'],
                    'action' => 'protect',
                    'member_count' => 0,
                    'group_key' => null,
                    'mean_similarity' => null,
                    'min_similarity' => null,
                    'cohesion' => null,
                    'low_confidence_count' => 0,
                    'warning' => $reason,
                ];
                continue;
            }
            $actions[] = [
                'topic_id' => $tid,
                'name' => $topic['name'],
                'action' => 'dissolve',
                'member_count' => 0,
                'group_key' => null,
                'mean_similarity' => null,
                'min_similarity' => null,
                'cohesion' => null,
                'low_confidence_count' => 0,
                'warning' => null,
            ];
        }

        usort($actions, static function (array $a, array $b): int {
            return [$a['action'], (string) $a['name'], (int) ($a['topic_id'] ?? 0)]
                <=> [$b['action'], (string) $b['name'], (int) ($b['topic_id'] ?? 0)];
        });

        return $actions;
    }

    /**
     * @param  array<int, array{topic_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>  $desired
     * @param  array<int, array{topic_id: int, is_locked: bool, text: string}>  $current
     * @param  array{locked_keyword_ids: array<int, true>}  $locked
     * @return list<array{keyword_id: int, text: string, from_topic_id: int|null, to_topic_id: int|null, action: string, similarity: float|null, confidence: float|null, protected: bool}>
     */
    private function buildKeywordActions(
        array $desired,
        array $current,
        array $locked,
        TopicGroupingProposal $proposal,
    ): array {
        $simByKw = [];
        $confByKw = [];
        $textByKw = [];
        foreach ($proposal->groups as $group) {
            foreach ($group->members as $member) {
                $simByKw[$member->keywordRef] = isset($member->evidence['similarity_score'])
                    ? (float) $member->evidence['similarity_score']
                    : null;
                $confByKw[$member->keywordRef] = $member->confidence;
                $textByKw[$member->keywordRef] = $member->text;
            }
        }
        foreach ($proposal->unassigned as $u) {
            $textByKw[$u->keywordRef] = $u->text;
        }

        $actions = [];
        $seen = [];
        foreach ($desired as $keywordId => $payload) {
            $from = $current[$keywordId]['topic_id'] ?? null;
            $to = (int) $payload['topic_id'];
            $protected = isset($locked['locked_keyword_ids'][$keywordId]) || (bool) $payload['is_locked'];
            if ($from === null) {
                $action = 'assign';
            } elseif ($from === $to) {
                $action = 'keep';
            } else {
                $action = 'move';
            }
            if ($to < 0) {
                // pending create — treat as assign for counts once created
                $action = $from === null ? 'assign' : ($from > 0 ? 'move' : 'assign');
            }
            $actions[] = [
                'keyword_id' => $keywordId,
                'text' => (string) ($textByKw[$keywordId] ?? $current[$keywordId]['text'] ?? ''),
                'from_topic_id' => $from,
                'to_topic_id' => $to > 0 ? $to : null,
                'action' => $action,
                'similarity' => $simByKw[$keywordId] ?? null,
                'confidence' => $confByKw[$keywordId] ?? $payload['confidence'],
                'protected' => $protected,
            ];
            $seen[$keywordId] = true;
        }

        foreach ($current as $keywordId => $row) {
            if (isset($seen[$keywordId])) {
                continue;
            }
            if (isset($locked['locked_keyword_ids'][$keywordId]) || $row['is_locked']) {
                $actions[] = [
                    'keyword_id' => $keywordId,
                    'text' => $row['text'],
                    'from_topic_id' => $row['topic_id'],
                    'to_topic_id' => $row['topic_id'],
                    'action' => 'keep',
                    'similarity' => null,
                    'confidence' => null,
                    'protected' => true,
                ];
                continue;
            }
            $actions[] = [
                'keyword_id' => $keywordId,
                'text' => (string) ($textByKw[$keywordId] ?? $row['text']),
                'from_topic_id' => $row['topic_id'],
                'to_topic_id' => null,
                'action' => 'unassign',
                'similarity' => null,
                'confidence' => null,
                'protected' => false,
            ];
        }

        usort($actions, static fn (array $a, array $b): int => $a['keyword_id'] <=> $b['keyword_id']);

        return $actions;
    }

    /**
     * @param  list<array{topic_id: int|null, name: string, action: string, member_count: int, warning: string|null}>  $topicActions
     * @param  array<string, int|float>  $counts
     * @param  array<string, mixed>  $identityMigration
     * @return list<string>
     */
    /**
     * @param  array<string, mixed>  $businessState
     */
    private function buildWarnings(
        TopicGroupingProposal $proposal,
        array $topicActions,
        array $counts,
        array $identityMigration,
        array $businessState = [],
    ): array {
        $warnings = [];
        $low = (int) ($proposal->metadata['low_confidence_member_count']
            ?? $proposal->metadata['diagnostics']['low_confidence_member_count']
            ?? 0);
        if ($low > 0) {
            $warnings[] = "low_confidence_members:{$low}";
        }
        foreach ($topicActions as $action) {
            if (($action['warning'] ?? null) !== null && in_array($action['warning'], ['large_group', 'low_min_similarity', 'low_cohesion', 'many_low_confidence'], true)) {
                $warnings[] = $action['warning'].':'.$action['name'];
            }
        }

        $kwTotal = max(1, (int) $counts['keywords_kept'] + (int) $counts['keywords_assigned'] + (int) $counts['keywords_moved'] + (int) $counts['keywords_unassigned']);
        $moveRatio = ((int) $counts['keywords_moved'] + (int) $counts['keywords_assigned'] + (int) $counts['keywords_unassigned']) / $kwTotal;
        $existing = max(1, (int) ($identityMigration['existing_topics'] ?? 1));
        $replaceRatio = ((int) $counts['topics_created'] + (int) $counts['topics_dissolved']) / (2 * $existing);
        $unassignedRatio = (int) $counts['semantic_unassigned'] / max(1, (int) $counts['semantic_groups'] + (int) $counts['semantic_unassigned']);

        if ($moveRatio >= 0.4) {
            $warnings[] = 'high_membership_churn:'.round($moveRatio, 3);
        }
        if ($replaceRatio >= 0.5) {
            $warnings[] = 'high_identity_replacement:'.round($replaceRatio, 3);
        }
        if ($unassignedRatio >= 0.15) {
            $warnings[] = 'elevated_unassigned_ratio:'.round($unassignedRatio, 3);
        }
        $focusChanging = (int) ($identityMigration['topics_with_focus_keywords_changing_identity'] ?? 0);
        if ($focusChanging > 0) {
            $warnings[] = "topics_with_focus_keywords_changing_identity:{$focusChanging}";
        }
        $ambiguous = (int) ($counts['identity_ambiguous'] ?? 0);
        if ($ambiguous > 0) {
            $warnings[] = "ambiguous_identity_matches:{$ambiguous}";
        }
        if ((int) ($counts['topics_protected'] ?? 0) > 0 || (int) ($counts['keywords_protected'] ?? 0) > 0) {
            $warnings[] = 'protected_conflicts:'.((int) $counts['topics_protected'] + (int) $counts['keywords_protected']);
        }
        foreach ($businessState['metadata_review_required'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $warnings[] = 'hard_block:'.((string) ($row['reason'] ?? 'review')).':'.((int) ($row['topic_id'] ?? 0));
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<int, array{id: int, name: string, source: string, is_locked: bool}>  $currentTopics
     * @param  list<array<string, mixed>>  $topicActions
     * @param  array<string, mixed>  $identityDiag
     * @param  array<string, int|float>  $counts
     * @return array<string, mixed>
     */
    private function buildIdentityMigration(
        int $siteId,
        array $currentTopics,
        TopicGroupingProposal $proposal,
        array $topicActions,
        array $identityDiag,
        array $counts,
    ): array {
        $focusChanging = 0;
        $focusTopicIds = $this->loadFocusTopicIds($siteId, array_keys($currentTopics));
        foreach ($topicActions as $action) {
            if ($action['action'] !== 'dissolve' || $action['topic_id'] === null) {
                continue;
            }
            if (isset($focusTopicIds[(int) $action['topic_id']])) {
                $focusChanging++;
            }
        }

        $mapping = [];
        foreach ($topicActions as $action) {
            if (! in_array($action['action'], ['reuse', 'create', 'dissolve', 'protect'], true)) {
                continue;
            }
            $mapping[] = [
                'action' => $action['action'],
                'topic_id' => $action['topic_id'],
                'name' => $action['name'],
                'group_key' => $action['group_key'] ?? null,
            ];
        }

        return [
            'existing_topics' => count($currentTopics),
            'semantic_groups' => count($proposal->groups),
            'reused_ids' => (int) $counts['topics_reused'],
            'new_ids' => (int) $counts['topics_created'],
            'dissolved_ids' => (int) $counts['topics_dissolved'],
            'one_to_one' => $identityDiag['one_to_one'],
            'splits' => $identityDiag['splits'],
            'merges' => $identityDiag['merges'],
            'ambiguous' => $identityDiag['ambiguous'],
            'no_successor' => $identityDiag['no_successor'],
            'matches' => $identityDiag['matches'],
            'thresholds' => $identityDiag['thresholds'],
            // Focus is keyword-owned; dissolving Topic identity does not delete Focus bindings.
            'topics_with_focus_keywords_changing_identity' => $focusChanging,
            'identity_mapping' => $mapping,
        ];
    }

    /**
     * Eligible auto Topics for continuity matching (locked/manual excluded).
     *
     * @param  list<int>  $manualIds
     * @param  array{locked_topic_ids: array<int, true>}  $locked
     * @return list<array{topic_id: int, name: string, member_keyword_ids: list<int>, member_count: int, is_locked: bool, has_focus: bool}>
     */
    private function loadContinuityInventory(int $siteId, array $manualIds, array $locked): array
    {
        /** @var array<int, true> $manualSet */
        $manualSet = [];
        foreach ($manualIds as $id) {
            $manualSet[(int) $id] = true;
        }

        $topics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('source', TopicSource::AUTO)
            ->where('is_locked', false)
            ->get(['id', 'name']);
        if ($topics->isEmpty()) {
            return [];
        }

        $topicIds = [];
        foreach ($topics as $topic) {
            $tid = (int) $topic->id;
            if (isset($manualSet[$tid]) || isset($locked['locked_topic_ids'][$tid])) {
                continue;
            }
            $topicIds[] = $tid;
        }
        if ($topicIds === []) {
            return [];
        }

        $memberRows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'keyword_id']);
        /** @var array<int, list<int>> $membersByTopic */
        $membersByTopic = [];
        foreach ($memberRows as $row) {
            $membersByTopic[(int) $row->topic_id][] = (int) $row->keyword_id;
        }
        $focusIds = $this->loadFocusTopicIds($siteId, $topicIds);

        $inventory = [];
        foreach ($topics as $topic) {
            $tid = (int) $topic->id;
            if (! in_array($tid, $topicIds, true)) {
                continue;
            }
            $members = array_values(array_unique($membersByTopic[$tid] ?? []));
            $inventory[] = [
                'topic_id' => $tid,
                'name' => (string) $topic->name,
                'member_keyword_ids' => $members,
                'member_count' => count($members),
                'is_locked' => false,
                'has_focus' => isset($focusIds[$tid]),
            ];
        }

        return $inventory;
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, true>
     */
    private function loadFocusTopicIds(int $siteId, array $topicIds): array
    {
        if ($siteId <= 0 || $topicIds === []) {
            return [];
        }
        try {
            $counter = app(\Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter::class);
            $counts = $counter->countForTopics($siteId, $topicIds);
            $out = [];
            foreach ($counts as $tid => $count) {
                if ((int) $count > 0) {
                    $out[(int) $tid] = true;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, int|float>  $counts
     * @param  list<array<string, mixed>>  $topicActions
     * @param  list<array<string, mixed>>  $keywordActions
     * @param  array<string, mixed>  $identityMigration
     */
    /**
     * @param  array<string, mixed>  $businessState
     */
    private function hashPlan(
        array $counts,
        array $topicActions,
        array $keywordActions,
        string $businessSnapshotHash,
        array $identityMigration = [],
        array $businessState = [],
        string $rebuildMode = TopicGroupingRebuildMode::PRESERVE_EXISTING,
    ): string {
        $payload = [
            'rebuild_mode' => TopicGroupingRebuildMode::normalize($rebuildMode),
            'business_snapshot_hash' => $businessSnapshotHash,
            'counts' => $counts,
            'topics' => array_map(static fn (array $a): array => [
                'action' => $a['action'],
                'topic_id' => $a['topic_id'],
                'name' => $a['name'],
                'member_count' => $a['member_count'],
            ], $topicActions),
            'keywords' => array_map(static fn (array $a): array => [
                'keyword_id' => $a['keyword_id'],
                'action' => $a['action'],
                'from' => $a['from_topic_id'],
                'to' => $a['to_topic_id'],
                'protected' => $a['protected'],
            ], $keywordActions),
            'identity_mapping' => $identityMigration['identity_mapping'] ?? [],
            'metadata_migrations' => $businessState['metadata_migrations'] ?? [],
            'policy_migrations' => $businessState['policy_migrations'] ?? [],
            'metadata_review_required' => $businessState['metadata_review_required'] ?? [],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('failed_to_encode_apply_plan_hash');
        }

        return hash('sha256', $json);
    }

    /**
     * @param  array<int, array{id: int, name: string, source: string, is_locked: bool, mcp_excluded: bool}>  $topics
     * @param  array<int, array{topic_id: int, is_locked: bool, text: string}>  $membership
     * @param  array{locked_topic_ids: array<int, true>, locked_keyword_ids: array<int, true>}  $locked
     * @param  list<int>  $manualIds
     * @param  list<string>  $tagFingerprint  stable "topic_id:tag_id:source" rows
     */
    private function hashBusinessSnapshot(
        array $topics,
        array $membership,
        array $locked,
        array $manualIds,
        array $tagFingerprint = [],
    ): string {
        ksort($topics);
        ksort($membership);
        sort($tagFingerprint);
        $payload = [
            'topics' => $topics,
            'membership' => $membership,
            'locked_topics' => array_keys($locked['locked_topic_ids']),
            'locked_keywords' => array_keys($locked['locked_keyword_ids']),
            'manual' => $manualIds,
            'tag_assignments' => $tagFingerprint,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('failed_to_encode_business_snapshot');
        }

        return hash('sha256', $json);
    }

    /**
     * @param  list<int>  $topicIds
     * @return list<string>
     */
    private function loadTagAssignmentFingerprint(int $siteId, array $topicIds): array
    {
        if ($siteId <= 0 || $topicIds === [] || ! TopicUserTagService::tablesReady()) {
            return [];
        }
        $hasSource = TopicUserTagService::provenanceReady();
        $rows = SeoTopicTagAssignment::query()
            ->whereIn('topic_id', $topicIds)
            ->orderBy('topic_id')
            ->orderBy('tag_id')
            ->get($hasSource ? ['topic_id', 'tag_id', 'source'] : ['topic_id', 'tag_id']);
        $out = [];
        foreach ($rows as $row) {
            $source = $hasSource ? (string) ($row->source ?? 'manual') : 'manual';
            $out[] = ((int) $row->topic_id).':'.((int) $row->tag_id).':'.$source;
        }

        return $out;
    }

    /**
     * @return array{
     *     locked_topic_ids: array<int, true>,
     *     preserved_topic_ids: array<int, true>,
     *     locked_keyword_ids: array<int, true>,
     *     locked_memberships_by_topic: array<int, list<array{keyword_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>>,
     *     topics: list<array{topic_id: int, name: string, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>
     * }
     */
    private function loadLockedState(int $siteId): array
    {
        $lockedTopicIds = [];
        $preservedTopicIds = [];
        $lockedKeywordIds = [];
        $lockedMembershipsByTopic = [];
        $topics = [];

        $lockedTopics = SeoTopic::query()->where('site_id', $siteId)->where('is_locked', true)->get(['id', 'name']);
        foreach ($lockedTopics as $topic) {
            $topicId = (int) $topic->id;
            $lockedTopicIds[$topicId] = true;
            $preservedTopicIds[$topicId] = true;
            $members = [];
            $rows = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->get(['keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);
            foreach ($rows as $row) {
                $keywordId = (int) $row->keyword_id;
                $lockedKeywordIds[$keywordId] = true;
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => '',
                    'source' => (string) $row->source,
                    'is_seed' => (bool) $row->is_seed,
                    'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                    'is_locked' => (bool) $row->is_locked,
                ];
            }
            $topics[] = [
                'topic_id' => $topicId,
                'name' => (string) $topic->name,
                'is_locked' => true,
                'members' => $members,
            ];
        }

        $membershipLocks = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->get(['topic_id', 'keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);
        foreach ($membershipLocks as $row) {
            $topicId = (int) $row->topic_id;
            $keywordId = (int) $row->keyword_id;
            $lockedKeywordIds[$keywordId] = true;
            $preservedTopicIds[$topicId] = true;
            if (isset($lockedTopicIds[$topicId])) {
                continue;
            }
            $lockedMembershipsByTopic[$topicId][] = [
                'keyword_id' => $keywordId,
                'source' => (string) $row->source,
                'is_seed' => (bool) $row->is_seed,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'is_locked' => true,
            ];
        }

        if (TopicMcpExclusionService::columnReady()) {
            $mcpExcluded = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('mcp_excluded', true)
                ->pluck('id');
            foreach ($mcpExcluded as $id) {
                $preservedTopicIds[(int) $id] = true;
            }
        }

        return [
            'locked_topic_ids' => $lockedTopicIds,
            'preserved_topic_ids' => $preservedTopicIds,
            'locked_keyword_ids' => $lockedKeywordIds,
            'locked_memberships_by_topic' => $lockedMembershipsByTopic,
            'topics' => $topics,
        ];
    }

    /** @return list<int> */
    private function loadManualTopicIds(int $siteId): array
    {
        return SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('source', TopicSource::MANUAL)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function loadSeedIdentityMap(int $siteId): array
    {
        $map = [];
        $rows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seed', true)
            ->get(['keyword_id', 'topic_id']);
        foreach ($rows as $row) {
            $map[(int) $row->keyword_id] = (int) $row->topic_id;
        }

        return $map;
    }

    /**
     * @return list<array{topic_id: int, name: string, member_keyword_ids: list<int>, member_count: int, is_locked: bool}>
     */
    private function loadDiscoveredInventory(int $siteId): array
    {
        $autoTopics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('source', TopicSource::AUTO)
            ->where('is_locked', false)
            ->get(['id', 'name', 'is_locked']);
        if ($autoTopics->isEmpty()) {
            return [];
        }
        $topicIds = $autoTopics->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $seedCounts = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->where('is_seed', true)
            ->selectRaw('topic_id, COUNT(*) as seed_cnt')
            ->groupBy('topic_id')
            ->pluck('seed_cnt', 'topic_id');
        $memberRows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'keyword_id']);
        $membersByTopic = [];
        foreach ($memberRows as $row) {
            $membersByTopic[(int) $row->topic_id][] = (int) $row->keyword_id;
        }
        $snapshot = [];
        foreach ($autoTopics as $topic) {
            $topicId = (int) $topic->id;
            if ((int) ($seedCounts[$topicId] ?? 0) > 0) {
                continue;
            }
            $memberIds = array_values(array_unique($membersByTopic[$topicId] ?? []));
            $snapshot[] = [
                'topic_id' => $topicId,
                'name' => (string) $topic->name,
                'member_keyword_ids' => $memberIds,
                'member_count' => count($memberIds),
                'is_locked' => false,
            ];
        }

        return $snapshot;
    }

    /** @return array<int, array{topic_id: int, is_locked: bool, text: string}> */
    private function loadCurrentMembership(int $siteId): array
    {
        $out = [];
        $rows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->get(['keyword_id', 'topic_id', 'is_locked']);
        foreach ($rows as $row) {
            $out[(int) $row->keyword_id] = [
                'topic_id' => (int) $row->topic_id,
                'is_locked' => (bool) $row->is_locked,
                'text' => '',
            ];
        }

        return $out;
    }

    /** @return array<int, array{id: int, name: string, source: string, is_locked: bool, mcp_excluded: bool}> */
    private function loadCurrentTopics(int $siteId): array
    {
        $out = [];
        $cols = ['id', 'name', 'source', 'is_locked'];
        if (TopicMcpExclusionService::columnReady()) {
            $cols[] = 'mcp_excluded';
        }
        $rows = SeoTopic::query()->where('site_id', $siteId)->get($cols);
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'source' => (string) $row->source,
                'is_locked' => (bool) $row->is_locked,
                'mcp_excluded' => (bool) ($row->mcp_excluded ?? false),
            ];
        }

        return $out;
    }
}
