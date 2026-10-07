<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;

/**
 * Plans Topic-owned business/policy state migrations for Apply.
 * Focus Article is keyword-owned — never migrated here.
 */
class TopicGroupingBusinessStatePlanner
{
    /**
     * @param  list<array{topic_id: int|null, name: string, action: string}>  $topicActions
     * @param  array<string, mixed>  $identityMigration
     * @return array{
     *     summary: array<string, int|bool>,
     *     metadata_migrations: list<array{type: string, from_topic_id: int, to_topic_id: int, tag_id: int, source: string}>,
     *     policy_migrations: list<array{type: string, topic_id?: int, from_topic_id?: int, group_key?: string, group_name?: string}>,
     *     metadata_review_required: list<array{topic_id: int, name: string, reason: string, detail?: mixed}>,
     *     hard_block: bool
     * }
     */
    public function plan(
        int $siteId,
        array $topicActions,
        array $identityMigration,
        string $rebuildMode = TopicGroupingRebuildMode::PRESERVE_EXISTING,
    ): array {
        $rebuildMode = TopicGroupingRebuildMode::normalize($rebuildMode);
        if (TopicGroupingRebuildMode::isFullReset($rebuildMode)) {
            return $this->planFullReset($siteId, $topicActions, $identityMigration);
        }

        $dissolveIds = [];
        $reuseIds = [];
        $protectIds = [];
        $names = [];
        foreach ($topicActions as $action) {
            $tid = $action['topic_id'] !== null ? (int) $action['topic_id'] : 0;
            if ($tid <= 0) {
                continue;
            }
            $names[$tid] = (string) $action['name'];
            if ($action['action'] === 'dissolve') {
                $dissolveIds[$tid] = true;
            } elseif ($action['action'] === 'reuse') {
                $reuseIds[$tid] = true;
            } elseif ($action['action'] === 'protect') {
                $protectIds[$tid] = true;
            }
        }

        /** @var array<int, int> $dissolveToSuccessor merge contributor → surviving topic */
        $dissolveToSuccessor = [];
        foreach ($identityMigration['merges'] ?? [] as $merge) {
            if (! is_array($merge)) {
                continue;
            }
            $survivor = (int) ($merge['surviving_topic_id'] ?? 0);
            if ($survivor <= 0) {
                continue;
            }
            foreach ($merge['merged_from'] ?? [] as $from) {
                if (! is_array($from)) {
                    continue;
                }
                $fromId = (int) ($from['topic_id'] ?? 0);
                if ($fromId > 0 && isset($dissolveIds[$fromId]) && ($from['fate'] ?? '') === 'dissolve_candidate') {
                    $dissolveToSuccessor[$fromId] = $survivor;
                }
            }
        }

        $metadataMigrations = [];
        $policyMigrations = [];
        $reviewRequired = [];

        $tagStats = $this->loadTagStats(array_keys($dissolveIds + $reuseIds + $protectIds));
        $mcpTopics = $this->loadMcpExcluded($siteId);

        // Manual tags on dissolve: migrate to merge survivor, else block.
        foreach (array_keys($dissolveIds) as $fromId) {
            $manualTagIds = $tagStats[$fromId]['manual'] ?? [];
            if ($manualTagIds === []) {
                continue;
            }
            $toId = $dissolveToSuccessor[$fromId] ?? 0;
            if ($toId > 0) {
                foreach ($manualTagIds as $tagId) {
                    $metadataMigrations[] = [
                        'type' => 'tag_reassign',
                        'from_topic_id' => $fromId,
                        'to_topic_id' => $toId,
                        'tag_id' => $tagId,
                        'source' => TopicTagAssignmentSource::MANUAL,
                    ];
                }
            } else {
                // Split / no-successor: do not silently copy tags to every child.
                $reviewRequired[] = [
                    'topic_id' => $fromId,
                    'name' => $names[$fromId] ?? '',
                    'reason' => 'manual_tags_would_be_lost',
                    'detail' => ['tag_ids' => $manualTagIds],
                ];
            }
        }

        // MCP exclusion: never silently lose quarantine.
        foreach (array_keys($dissolveIds) as $fromId) {
            if (! isset($mcpTopics[$fromId])) {
                continue;
            }
            $toId = $dissolveToSuccessor[$fromId] ?? 0;
            if ($toId > 0) {
                if (! isset($mcpTopics[$toId])) {
                    $policyMigrations[] = [
                        'type' => 'mcp_exclude',
                        'topic_id' => $toId,
                        'from_topic_id' => $fromId,
                    ];
                }
            } else {
                $reviewRequired[] = [
                    'topic_id' => $fromId,
                    'name' => $names[$fromId] ?? '',
                    'reason' => 'mcp_exclusion_would_be_lost',
                ];
            }
        }

        // Split: retained ID keeps mcp_excluded; new sibling groups must stay excluded via group_key.
        foreach ($identityMigration['splits'] ?? [] as $split) {
            if (! is_array($split)) {
                continue;
            }
            $fromId = (int) ($split['topic_id'] ?? 0);
            if ($fromId <= 0 || ! isset($mcpTopics[$fromId])) {
                continue;
            }
            foreach ($split['other_groups'] ?? [] as $og) {
                if (! is_array($og)) {
                    continue;
                }
                $groupKey = trim((string) ($og['group_key'] ?? ''));
                if ($groupKey === '') {
                    $reviewRequired[] = [
                        'topic_id' => $fromId,
                        'name' => $names[$fromId] ?? '',
                        'reason' => 'mcp_exclusion_split_missing_group_key',
                        'detail' => ['group_name' => (string) ($og['group_name'] ?? '')],
                    ];
                    continue;
                }
                $policyMigrations[] = [
                    'type' => 'mcp_exclude_group',
                    'group_key' => $groupKey,
                    'from_topic_id' => $fromId,
                    // Display/debug only — never used as execution identity.
                    'group_name' => (string) ($og['group_name'] ?? ''),
                ];
            }
        }

        $manualMigrate = count(array_filter(
            $metadataMigrations,
            static fn (array $m): bool => $m['type'] === 'tag_reassign' && $m['source'] === TopicTagAssignmentSource::MANUAL,
        ));
        $focusChanging = (int) ($identityMigration['topics_with_focus_keywords_changing_identity']
            ?? $identityMigration['topics_with_focus_dissolved']
            ?? 0);

        $summary = [
            'manual_topics_preserved' => true,
            'locks_preserved' => true,
            'focus_bindings_keyword_owned' => true,
            'topics_with_focus_keywords_changing_identity' => $focusChanging,
            'manual_tag_migrations' => $manualMigrate,
            'manual_tag_review_required' => count(array_filter(
                $reviewRequired,
                static fn (array $r): bool => $r['reason'] === 'manual_tags_would_be_lost',
            )),
            'mcp_exclusions_preserved' => count(array_intersect_key($mcpTopics, $protectIds + $reuseIds)),
            'mcp_exclusions_on_dissolve' => count(array_filter(
                $reviewRequired,
                static fn (array $r): bool => $r['reason'] === 'mcp_exclusion_would_be_lost',
            )),
            'mcp_exclusion_propagations' => count($policyMigrations),
            'derived_dna_cache_rebuild' => true,
            'hard_downstream_refs' => 0,
        ];

        return [
            'summary' => $summary,
            'metadata_migrations' => $metadataMigrations,
            'policy_migrations' => $policyMigrations,
            'metadata_review_required' => $reviewRequired,
            'hard_block' => $reviewRequired !== [],
        ];
    }

    /**
     * full_reset: Topic-owned tags/MCP/locks are disposable; Focus stays keyword-owned.
     * hard_block only for unresolved hard downstream SeoTopic refs (currently none detected).
     *
     * @param  list<array{topic_id: int|null, name: string, action: string}>  $topicActions
     * @param  array<string, mixed>  $identityMigration
     * @return array{
     *     summary: array<string, int|bool|string>,
     *     metadata_migrations: list<array<string, mixed>>,
     *     policy_migrations: list<array<string, mixed>>,
     *     metadata_review_required: list<array<string, mixed>>,
     *     hard_block: bool,
     *     rebuild_mode: string
     * }
     */
    private function planFullReset(int $siteId, array $topicActions, array $identityMigration): array
    {
        $dissolveCount = count(array_filter(
            $topicActions,
            static fn (array $a): bool => $a['action'] === 'dissolve',
        ));
        $createCount = count(array_filter(
            $topicActions,
            static fn (array $a): bool => $a['action'] === 'create',
        ));
        $focusChanging = (int) ($identityMigration['topics_with_focus_keywords_changing_identity']
            ?? $identityMigration['topics_with_focus_dissolved']
            ?? 0);

        // Reserved: if a future hard FK/consumer appears, set hard_downstream_refs + hard_block.
        $hardDownstreamRefs = 0;

        return [
            'summary' => [
                'manual_topics_preserved' => false,
                'locks_preserved' => false,
                'focus_bindings_keyword_owned' => true,
                'topics_with_focus_keywords_changing_identity' => $focusChanging,
                'manual_tag_migrations' => 0,
                'manual_tag_review_required' => 0,
                'mcp_exclusions_preserved' => 0,
                'mcp_exclusions_on_dissolve' => 0,
                'mcp_exclusion_propagations' => 0,
                'derived_dna_cache_rebuild' => true,
                'hard_downstream_refs' => $hardDownstreamRefs,
                'topics_marked_dissolve' => $dissolveCount,
                'topics_marked_create' => $createCount,
                'topic_owned_state_reset' => true,
            ],
            'metadata_migrations' => [],
            'policy_migrations' => [],
            'metadata_review_required' => [],
            'hard_block' => $hardDownstreamRefs > 0,
            'rebuild_mode' => TopicGroupingRebuildMode::FULL_RESET,
        ];
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, array{manual: list<int>, ai: list<int>}>
     */
    private function loadTagStats(array $topicIds): array
    {
        $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds))));
        if ($topicIds === [] || ! TopicUserTagService::tablesReady()) {
            return [];
        }

        $hasSource = TopicUserTagService::provenanceReady();
        $out = [];
        $rows = SeoTopicTagAssignment::query()->whereIn('topic_id', $topicIds)->get(
            $hasSource ? ['topic_id', 'tag_id', 'source'] : ['topic_id', 'tag_id'],
        );
        foreach ($rows as $row) {
            $tid = (int) $row->topic_id;
            $tagId = (int) $row->tag_id;
            if (! isset($out[$tid])) {
                $out[$tid] = ['manual' => [], 'ai' => []];
            }
            $isAi = $hasSource && TopicTagAssignmentSource::isAi((string) ($row->source ?? ''));
            if ($isAi) {
                $out[$tid]['ai'][] = $tagId;
            } else {
                $out[$tid]['manual'][] = $tagId;
            }
        }

        return $out;
    }

    /**
     * @return array<int, true>
     */
    private function loadMcpExcluded(int $siteId): array
    {
        if ($siteId <= 0 || ! TopicMcpExclusionService::columnReady()) {
            return [];
        }
        $out = [];
        $ids = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('mcp_excluded', true)
            ->pluck('id');
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }
}
