<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicalMapMode;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicalMapVersionStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicClusterRelationship;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicType;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalLinkSuggestion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicClusterLink;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicalMapBuildRequest;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicalMapBuildResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Phase 3 — xây topical map thật: chọn pillar theo entity (không phải "mỗi intent 1 pillar,
 * TẤT CẢ cluster"), phân tầng subtopic/cluster_group/faq_group theo mode, validate hierarchy
 * trước khi ghi DB, tính coverage, gợi ý internal link (suggest-only) và phát hiện conflict.
 * Luôn persist bản map mới ở status=draft — KHÔNG tự approve.
 *
 * Stage flow (conceptual): preparing_clusters → building_topics → assigning → validating →
 * coverage → links → conflicts → finalize.
 *
 * Builder chỉ ĐỌC kết quả keyword analysis đã có (cluster/keyword đã approved). Builder KHÔNG re-run keyword analysis hay phân loại intent ở đây — việc đó thuộc pipeline Phase 2 riêng, chạy trước khi build topical map.
 */
final class TopicalMapBuilder
{
    public function __construct(
        private readonly KeywordTopicalMapBuildLock $buildLock,
        private readonly PillarTopicSelector $pillarSelector,
        private readonly TopicalMapHierarchyValidator $hierarchyValidator,
        private readonly TopicalCoverageService $coverageService,
        private readonly TopicalInternalLinkSuggestionService $linkSuggestionService,
        private readonly TopicalMapConflictDetector $conflictDetector,
    ) {}

    /**
     * BC wrapper cho caller cũ (BuildTopicalMapHandler) — build ở mode Balanced.
     * Throw RuntimeException('topical_map.<code>') khi build thất bại — khớp với
     * AbstractKeywordIntelligenceHandler::mapRuntimeException() đã xử lý sẵn prefix này.
     */
    public function build(SeoKeywordWorkspace $workspace, ?int $actorId = null): SeoTopicalMapVersion
    {
        $request = new TopicalMapBuildRequest(
            workspaceRef: $workspace->public_ref,
            mode: KeywordTopicalMapMode::Balanced->value,
            actorId: $actorId,
        );

        $result = $this->buildFromRequest($request, $workspace);

        if ($result->mapVersionRef === null) {
            throw new RuntimeException($result->resultCode);
        }

        $version = SeoTopicalMapVersion::query()->where('public_ref', $result->mapVersionRef)->first();
        if (! $version instanceof SeoTopicalMapVersion) {
            throw new RuntimeException($result->resultCode);
        }

        return $version;
    }

    public function buildFromRequest(TopicalMapBuildRequest $request, SeoKeywordWorkspace $workspace): TopicalMapBuildResult
    {
        $mode = KeywordTopicalMapMode::tryFrom($request->mode) ?? KeywordTopicalMapMode::Balanced;
        $modeConfig = $this->modeConfig($mode);
        $maxDepth = $this->resolveMaxDepth($request, $modeConfig);

        $ownerToken = $this->buildLock->acquire($workspace->public_ref);
        if ($ownerToken === null) {
            return $this->failResult(KeywordIntelligenceActionCodes::TOPICAL_MAP_ALREADY_BUILDING);
        }

        try {
            try {
                $this->buildLock->assertAnalysisNotRunning($workspace);
            } catch (RuntimeException) {
                return $this->failResult(KeywordIntelligenceActionCodes::TOPICAL_MAP_ANALYSIS_RUNNING);
            }

            return DB::connection('omi_seo_ai')->transaction(
                fn (): TopicalMapBuildResult => $this->runPipeline($request, $workspace, $mode, $modeConfig, $maxDepth),
            );
        } finally {
            $this->buildLock->release($workspace->public_ref, $ownerToken);
        }
    }

    // -----------------------------------------------------------------
    // Pipeline
    // -----------------------------------------------------------------

    private function runPipeline(
        TopicalMapBuildRequest $request,
        SeoKeywordWorkspace $workspace,
        KeywordTopicalMapMode $mode,
        array $modeConfig,
        int $maxDepth,
    ): TopicalMapBuildResult {
        // Stage: preparing_clusters
        [$eligibleClusters, $refExclusions, $warnings, $reviewedOnlyRefs] = $this->prepareClusters($workspace, $request);

        if ($eligibleClusters->isEmpty()) {
            return $this->failResult(KeywordIntelligenceActionCodes::TOPICAL_MAP_NO_APPROVED_CLUSTERS, $warnings);
        }

        [$autoClusters, $preservedInfo] = $this->partitionPreserved($workspace, $eligibleClusters, $request);
        $excludedClusters = array_merge($refExclusions, $preservedInfo);

        // Stage: building_topics
        $rootCandidate = new TopicCandidate(
            candidateRef: 'root',
            name: (string) $workspace->name,
            slug: Str::slug((string) $workspace->name) ?: 'root-'.$workspace->id,
            topicType: KeywordTopicType::Root,
            parentCandidateRef: null,
        );
        $candidates = [$rootCandidate];
        $clusterRelationships = [];

        if ($autoClusters->isNotEmpty()) {
            $selection = $this->pillarSelector->select($autoClusters, (int) $modeConfig['max_pillars']);
            $pillarGroups = $selection['pillar_groups'];
            if ($selection['overflow_group'] !== null) {
                $pillarGroups[] = $selection['overflow_group'];
            }

            $groupIndex = 0;
            foreach ($pillarGroups as $group) {
                if ($group['clusters'] === []) {
                    continue;
                }
                $groupIndex++;
                $subtree = $this->buildPillarSubtree($group, $groupIndex, $modeConfig, $maxDepth);
                $candidates = array_merge($candidates, $subtree['candidates']);
                $clusterRelationships = array_merge($clusterRelationships, $subtree['cluster_relationships']);
            }
        } else {
            $warnings[] = 'topical_map.all_clusters_preserved_no_new_grouping';
        }

        // Stage: validating
        $hierarchy = $this->validateHierarchy($candidates, $clusterRelationships, $maxDepth);

        if ($hierarchy['status'] === 'invalid') {
            return $this->failResult(KeywordIntelligenceActionCodes::TOPICAL_MAP_HIERARCHY_INVALID, $warnings, $hierarchy['issues']);
        }

        // Stage: assigning
        $clustersByRef = $autoClusters->keyBy('public_ref');
        $persistedTopicsByRef = $this->persistTopics($workspace, $candidates);
        $assignments = $this->persistClusterAssignments($persistedTopicsByRef, $candidates, $clusterRelationships, $clustersByRef);

        // Stage: coverage
        $coverageInput = $this->buildCoverageInput($persistedTopicsByRef, $candidates, $clustersByRef);
        $coverage = $this->coverageService->calculate($coverageInput);

        // Stage: links
        $linkNodes = $this->buildLinkNodes($persistedTopicsByRef, $candidates, $clustersByRef, $clusterRelationships, $reviewedOnlyRefs);
        $suggestions = $this->linkSuggestionService->suggest($linkNodes);

        // Stage: finalize (persist version + suggestions)
        $mapVersion = $this->persistVersion($workspace, $mode, $candidates, $persistedTopicsByRef, $coverage, $excludedClusters, $request->actorId);

        $clusterRefById = $clustersByRef
            ->mapWithKeys(static fn (SeoKeywordCluster $c): array => [(int) $c->id => $c->public_ref])
            ->all();
        $persistedSuggestions = $this->persistLinkSuggestions($workspace, $mapVersion, $suggestions, $clusterRefById);

        // Stage: conflicts
        $conflicts = $this->conflictDetector->detect($candidates, $excludedClusters, $hierarchy['issues'], $request->includeReviewedClusters);

        $workspace->topic_count = SeoKiTopic::query()->where('workspace_id', $workspace->id)->count();
        $workspace->cluster_count = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', KeywordClusterStatus::Excluded->value)
            ->count();
        $workspace->save();

        $isPartial = $hierarchy['status'] === 'needs_review' || $excludedClusters !== [] || $reviewedOnlyRefs !== [];
        $resultCode = $isPartial
            ? KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_PARTIAL
            : KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_COMPLETED;

        $topicsOutput = $this->topicsOutput($persistedTopicsByRef, $candidates);
        $rootTopics = array_values(array_filter(
            $topicsOutput,
            static fn (array $t): bool => $t['topic_type'] === KeywordTopicType::Root->value,
        ));

        return new TopicalMapBuildResult(
            resultCode: $resultCode,
            rootTopics: $rootTopics,
            topics: $topicsOutput,
            assignments: $assignments,
            relationships: $assignments,
            coverageSummary: $coverage,
            linkSuggestions: $persistedSuggestions,
            conflicts: $conflicts,
            warnings: array_values(array_unique(array_merge($warnings, $this->issueMessages($hierarchy['issues'])))),
            confidence: round((float) ($coverage['aggregate']['avg_authority_score'] ?? 0) / 100, 2),
            mapVersionRef: $mapVersion->public_ref,
        );
    }

    // -----------------------------------------------------------------
    // Stage: preparing_clusters
    // -----------------------------------------------------------------

    /**
     * @return array{0: Collection<int, SeoKeywordCluster>, 1: list<array{cluster_ref: string, reason: string}>, 2: list<string>, 3: array<string, bool>}
     */
    private function prepareClusters(SeoKeywordWorkspace $workspace, TopicalMapBuildRequest $request): array
    {
        $warnings = [];
        $excluded = [];
        $reviewedOnlyRefs = [];

        $statuses = [KeywordClusterStatus::Approved->value];
        if ($request->includeReviewedClusters) {
            $statuses[] = KeywordClusterStatus::Reviewed->value;
        }

        $query = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', $statuses)
            ->whereNotNull('primary_keyword_id');

        $requestedIds = null;
        if ($request->approvedClusterRefs !== null) {
            $requestedIds = [];
            foreach ($request->approvedClusterRefs as $ref) {
                try {
                    $requestedIds[] = KeywordIntelligencePublicRef::resolveClusterIdStrict($ref);
                } catch (Throwable) {
                    $warnings[] = 'topical_map.invalid_cluster_ref';
                }
            }
            $query->whereIn('id', $requestedIds === [] ? [-1] : $requestedIds);
        }

        $clusters = $query->orderBy('id')->get();

        if ($requestedIds !== null) {
            $foundIds = $clusters->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            foreach ($request->approvedClusterRefs ?? [] as $ref) {
                try {
                    $id = KeywordIntelligencePublicRef::resolveClusterIdStrict($ref);
                } catch (Throwable) {
                    continue;
                }
                if (! in_array($id, $foundIds, true)) {
                    $excluded[] = ['cluster_ref' => $ref, 'reason' => 'not_eligible_or_cross_workspace'];
                }
            }
        }

        foreach ($clusters as $cluster) {
            if ($cluster->status === KeywordClusterStatus::Reviewed) {
                $reviewedOnlyRefs[$cluster->public_ref] = true;
            }
        }

        return [$clusters, $excluded, $warnings, $reviewedOnlyRefs];
    }

    /**
     * Loại khỏi vòng auto-regroup các cluster đã được "ghim" thủ công (manual) hoặc đã có
     * vị trí draft ổn định từ lần build trước (khi rebuild_draft_assignments=false) — giữ
     * nguyên topic_id hiện có của chúng, không tham gia PillarTopicSelector.
     *
     * @param  Collection<int, SeoKeywordCluster>  $clusters
     * @return array{0: Collection<int, SeoKeywordCluster>, 1: list<array{cluster_ref: string, reason: string}>}
     */
    private function partitionPreserved(SeoKeywordWorkspace $workspace, Collection $clusters, TopicalMapBuildRequest $request): array
    {
        if (! $request->preserveManualTopics && $request->rebuildDraftAssignments) {
            return [$clusters, []];
        }

        $manualTopicIds = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'metadata'])
            ->filter(static fn (SeoKiTopic $topic): bool => (string) (((array) ($topic->metadata ?? []))['source'] ?? '') === 'manual')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $draftTopicIds = $request->rebuildDraftAssignments ? [] : SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', KeywordTopicStatus::Draft->value)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $preservedInfo = [];
        $auto = $clusters->reject(function (SeoKeywordCluster $cluster) use ($request, $manualTopicIds, $draftTopicIds, &$preservedInfo): bool {
            if ($cluster->topic_id === null) {
                return false;
            }

            $isManualCluster = $request->preserveManualTopics && (
                (bool) $cluster->preserve_manual_primary
                || (bool) (((array) ($cluster->metadata ?? []))['topic_manual'] ?? false)
                || in_array((int) $cluster->topic_id, $manualTopicIds, true)
            );

            $isStableDraft = ! $isManualCluster && in_array((int) $cluster->topic_id, $draftTopicIds, true);

            if ($isManualCluster || $isStableDraft) {
                $preservedInfo[] = [
                    'cluster_ref' => $cluster->public_ref,
                    'reason' => $isManualCluster ? 'manual_preserved' : 'draft_assignment_preserved',
                ];

                return true;
            }

            return false;
        })->values();

        return [$auto, $preservedInfo];
    }

    // -----------------------------------------------------------------
    // Stage: building_topics
    // -----------------------------------------------------------------

    /**
     * @param  array{entity: string, clusters: list<SeoKeywordCluster>, score: float}  $group
     * @return array{candidates: list<TopicCandidate>, cluster_relationships: array<string, string>}
     */
    private function buildPillarSubtree(array $group, int $groupIndex, array $modeConfig, int $maxDepth): array
    {
        $pillarRef = "pillar-{$groupIndex}";
        $pillarDepth = 1;
        $entity = $group['entity'];
        $clusters = $group['clusters'];

        $faqClusters = array_values(array_filter($clusters, static fn (SeoKeywordCluster $c): bool => $c->cluster_type === KeywordClusterType::Faq));
        $regularClusters = array_values(array_filter($clusters, static fn (SeoKeywordCluster $c): bool => $c->cluster_type !== KeywordClusterType::Faq));

        $primary = $this->pickPrimaryCluster($regularClusters !== [] ? $regularClusters : $clusters);
        if ($primary instanceof SeoKeywordCluster) {
            $regularClusters = array_values(array_filter($regularClusters, static fn (SeoKeywordCluster $c): bool => $c->id !== $primary->id));
            $faqClusters = array_values(array_filter($faqClusters, static fn (SeoKeywordCluster $c): bool => $c->id !== $primary->id));
        }

        $relationships = [];
        $pillarClusterRefs = [];
        if ($primary instanceof SeoKeywordCluster) {
            $pillarClusterRefs[] = $primary->public_ref;
            $relationships[$primary->public_ref] = KeywordTopicClusterRelationship::Primary->value;
        }

        $candidates = [];
        $maxSubtopics = (int) ($modeConfig['max_subtopics_per_pillar'] ?? 0);
        $maxLeafSize = max(1, (int) ($modeConfig['max_cluster_group_size'] ?? 8));
        $enableFaqGroup = (bool) ($modeConfig['enable_faq_group'] ?? true);

        $subtopicBuckets = [];
        if ($maxSubtopics > 0 && ($pillarDepth + 1) <= $maxDepth && count($regularClusters) > $maxLeafSize) {
            $subtopicBuckets = $this->bucketBySecondaryKey($regularClusters, $entity, $maxSubtopics);
        }

        if (count($subtopicBuckets) >= 2) {
            $subIndex = 0;
            foreach ($subtopicBuckets as $bucketKey => $bucketClusters) {
                $subIndex++;
                $subtopicRef = "{$pillarRef}-sub-{$subIndex}";

                $leaf = $this->planLeafAssignment($bucketClusters, $subtopicRef, $entity, $pillarDepth + 1, $maxDepth, $maxLeafSize);
                $relationships = array_merge($relationships, $leaf['relationships']);

                $candidates[] = new TopicCandidate(
                    candidateRef: $subtopicRef,
                    name: $this->pillarName($entity).' — '.$this->pillarName((string) $bucketKey),
                    slug: Str::slug($entity.'-'.$bucketKey) ?: "subtopic-{$groupIndex}-{$subIndex}",
                    topicType: KeywordTopicType::Subtopic,
                    parentCandidateRef: $pillarRef,
                    clusterRefs: $leaf['direct_cluster_refs'],
                    primaryEntity: $entity,
                    intents: $this->distinctIntents($bucketClusters),
                    funnelStages: $this->distinctFunnelStages($bucketClusters),
                    confidence: $this->groupConfidence($bucketClusters),
                );
                $candidates = array_merge($candidates, $leaf['child_candidates']);
            }
        } else {
            $leaf = $this->planLeafAssignment($regularClusters, $pillarRef, $entity, $pillarDepth, $maxDepth, $maxLeafSize);
            $relationships = array_merge($relationships, $leaf['relationships']);
            $pillarClusterRefs = array_merge($pillarClusterRefs, $leaf['direct_cluster_refs']);
            $candidates = array_merge($candidates, $leaf['child_candidates']);
        }

        $faqPlan = $this->planFaqAssignment($faqClusters, $pillarRef, $entity, $pillarDepth, $maxDepth, $enableFaqGroup);
        $relationships = array_merge($relationships, $faqPlan['relationships']);
        $pillarClusterRefs = array_merge($pillarClusterRefs, $faqPlan['direct_cluster_refs']);
        $candidates = array_merge($candidates, $faqPlan['child_candidates']);

        $pillarCandidate = new TopicCandidate(
            candidateRef: $pillarRef,
            name: $this->pillarName($entity),
            slug: 'pillar-'.(Str::slug($entity) ?: $groupIndex),
            topicType: KeywordTopicType::Pillar,
            parentCandidateRef: 'root',
            clusterRefs: $pillarClusterRefs,
            primaryEntity: $entity,
            intents: $this->distinctIntents($clusters),
            funnelStages: $this->distinctFunnelStages($clusters),
            confidence: $this->groupConfidence($clusters),
            reasonCodes: $primary === null ? ['pillar_without_primary_cluster'] : [],
        );

        array_unshift($candidates, $pillarCandidate);

        return ['candidates' => $candidates, 'cluster_relationships' => $relationships];
    }

    /**
     * @param  list<SeoKeywordCluster>  $clusters
     * @return array{direct_cluster_refs: list<string>, child_candidates: list<TopicCandidate>, relationships: array<string, string>}
     */
    private function planLeafAssignment(array $clusters, string $parentRef, string $parentEntity, int $parentDepth, int $maxDepth, int $maxLeafSize): array
    {
        if ($clusters === []) {
            return ['direct_cluster_refs' => [], 'child_candidates' => [], 'relationships' => []];
        }

        $relationships = [];
        foreach ($clusters as $cluster) {
            $relationships[$cluster->public_ref] = $cluster->cluster_type === KeywordClusterType::Comparison
                ? KeywordTopicClusterRelationship::Comparison->value
                : KeywordTopicClusterRelationship::Supporting->value;
        }

        $hasDepthBudget = ($parentDepth + 1) <= $maxDepth;

        if (! $hasDepthBudget || count($clusters) <= $maxLeafSize) {
            return [
                'direct_cluster_refs' => array_map(static fn (SeoKeywordCluster $c): string => $c->public_ref, $clusters),
                'child_candidates' => [],
                'relationships' => $relationships,
            ];
        }

        $childCandidates = [];
        $chunkIndex = 0;
        foreach (array_chunk($clusters, $maxLeafSize) as $chunk) {
            $chunkIndex++;
            $childCandidates[] = new TopicCandidate(
                candidateRef: "{$parentRef}-grp-{$chunkIndex}",
                name: $this->pillarName($parentEntity).' — Group '.$chunkIndex,
                slug: (Str::slug($parentEntity.'-group-'.$chunkIndex)) ?: "cluster-group-{$chunkIndex}",
                topicType: KeywordTopicType::ClusterGroup,
                parentCandidateRef: $parentRef,
                clusterRefs: array_map(static fn (SeoKeywordCluster $c): string => $c->public_ref, $chunk),
                primaryEntity: $parentEntity,
                confidence: $this->groupConfidence($chunk),
            );
        }

        return ['direct_cluster_refs' => [], 'child_candidates' => $childCandidates, 'relationships' => $relationships];
    }

    /**
     * @param  list<SeoKeywordCluster>  $faqClusters
     * @return array{direct_cluster_refs: list<string>, child_candidates: list<TopicCandidate>, relationships: array<string, string>}
     */
    private function planFaqAssignment(array $faqClusters, string $parentRef, string $parentEntity, int $parentDepth, int $maxDepth, bool $enableFaqGroup): array
    {
        if ($faqClusters === []) {
            return ['direct_cluster_refs' => [], 'child_candidates' => [], 'relationships' => []];
        }

        $relationships = [];
        foreach ($faqClusters as $cluster) {
            $relationships[$cluster->public_ref] = KeywordTopicClusterRelationship::Faq->value;
        }

        $hasDepthBudget = $enableFaqGroup && ($parentDepth + 1) <= $maxDepth;
        if (! $hasDepthBudget) {
            return [
                'direct_cluster_refs' => array_map(static fn (SeoKeywordCluster $c): string => $c->public_ref, $faqClusters),
                'child_candidates' => [],
                'relationships' => $relationships,
            ];
        }

        $candidate = new TopicCandidate(
            candidateRef: "{$parentRef}-faq",
            name: $this->pillarName($parentEntity).' — FAQ',
            slug: (Str::slug($parentEntity.'-faq')) ?: 'faq-group',
            topicType: KeywordTopicType::FaqGroup,
            parentCandidateRef: $parentRef,
            clusterRefs: array_map(static fn (SeoKeywordCluster $c): string => $c->public_ref, $faqClusters),
            primaryEntity: $parentEntity,
            confidence: $this->groupConfidence($faqClusters),
        );

        return ['direct_cluster_refs' => [], 'child_candidates' => [$candidate], 'relationships' => $relationships];
    }

    /**
     * @param  list<SeoKeywordCluster>  $clusters
     * @return array<string, list<SeoKeywordCluster>>
     */
    private function bucketBySecondaryKey(array $clusters, string $entity, int $maxBuckets): array
    {
        $buckets = [];
        foreach ($clusters as $cluster) {
            $key = $this->pillarSelector->secondaryKey($cluster, $entity);
            $buckets[$key][] = $cluster;
        }

        if (count($buckets) <= $maxBuckets) {
            return $buckets;
        }

        uasort($buckets, static fn (array $a, array $b): int => count($b) <=> count($a));

        $top = array_slice($buckets, 0, max(0, $maxBuckets - 1), true);
        $rest = array_slice($buckets, max(0, $maxBuckets - 1), null, true);

        $misc = [];
        foreach ($rest as $bucketClusters) {
            array_push($misc, ...$bucketClusters);
        }
        if ($misc !== []) {
            $top['general'] = array_merge($top['general'] ?? [], $misc);
        }

        return $top;
    }

    /**
     * @param  list<SeoKeywordCluster>  $clusters
     */
    private function pickPrimaryCluster(array $clusters): ?SeoKeywordCluster
    {
        if ($clusters === []) {
            return null;
        }

        usort($clusters, fn (SeoKeywordCluster $a, SeoKeywordCluster $b): int => $this->clusterScore($b) <=> $this->clusterScore($a));

        return $clusters[0];
    }

    private function clusterScore(SeoKeywordCluster $cluster): float
    {
        $score = ((float) ($cluster->relevance_score ?? 50)) * 0.5 + ((float) ($cluster->opportunity_score ?? 50)) * 0.3;
        if ($cluster->cluster_type === KeywordClusterType::Pillar) {
            $score += 20.0;
        }
        $score += min(10, (int) ($cluster->keyword_count ?? 0)) * 0.5;

        return $score;
    }

    /**
     * @param  list<SeoKeywordCluster>  $clusters
     * @return list<string>
     */
    private function distinctIntents(array $clusters): array
    {
        $intents = [];
        foreach ($clusters as $cluster) {
            $value = $cluster->search_intent instanceof KeywordSearchIntent ? $cluster->search_intent->value : null;
            if ($value !== null) {
                $intents[$value] = true;
            }
        }

        return array_keys($intents);
    }

    /**
     * @param  list<SeoKeywordCluster>  $clusters
     * @return list<string>
     */
    private function distinctFunnelStages(array $clusters): array
    {
        $stages = [];
        foreach ($clusters as $cluster) {
            $value = $cluster->funnel_stage instanceof KeywordFunnelStage ? $cluster->funnel_stage->value : null;
            if ($value !== null) {
                $stages[$value] = true;
            }
        }

        return array_keys($stages);
    }

    /**
     * @param  list<SeoKeywordCluster>  $clusters
     */
    private function groupConfidence(array $clusters): float
    {
        if ($clusters === []) {
            return 0.5;
        }

        $sum = 0.0;
        foreach ($clusters as $cluster) {
            $sum += ((float) ($cluster->relevance_score ?? 50) + (float) ($cluster->opportunity_score ?? 50)) / 200.0;
        }

        return round(min(0.95, max(0.2, $sum / count($clusters))), 2);
    }

    private function pillarName(string $entity): string
    {
        $label = str_replace(['-', '_'], ' ', $entity !== '' ? $entity : 'general');

        return Str::title($label);
    }

    // -----------------------------------------------------------------
    // Stage: assigning (persist)
    // -----------------------------------------------------------------

    /**
     * @param  list<TopicCandidate>  $candidates
     * @return array<string, SeoKiTopic>
     */
    private function persistTopics(SeoKeywordWorkspace $workspace, array $candidates): array
    {
        $persisted = [];

        foreach ($candidates as $candidate) {
            $parentTopic = $candidate->parentCandidateRef !== null ? ($persisted[$candidate->parentCandidateRef] ?? null) : null;
            $parentId = $parentTopic instanceof SeoKiTopic ? $parentTopic->id : null;

            $topic = SeoKiTopic::query()
                ->where('workspace_id', $workspace->id)
                ->where('parent_id', $parentId)
                ->where('slug', $candidate->slug)
                ->first();

            if (! $topic instanceof SeoKiTopic) {
                $topic = new SeoKiTopic([
                    'public_ref' => 'pending',
                    'workspace_id' => $workspace->id,
                    'parent_id' => $parentId,
                ]);
            }

            $existingMeta = (array) ($topic->metadata ?? []);
            if (($existingMeta['source'] ?? null) === 'manual') {
                // Manual topic — không ghi đè name/slug/parent do user đã tự chỉnh sửa.
                $persisted[$candidate->candidateRef] = $topic;

                continue;
            }

            $depth = $parentTopic instanceof SeoKiTopic ? $parentTopic->depth + 1 : 0;
            $path = $parentTopic instanceof SeoKiTopic ? trim($parentTopic->path.'/'.$candidate->slug, '/') : $candidate->slug;

            $topic->fill([
                'parent_id' => $parentId,
                'name' => $candidate->name,
                'slug' => $candidate->slug,
                'topic_type' => $candidate->topicType->value,
                'status' => KeywordTopicStatus::Draft->value,
                'depth' => $depth,
                'path' => $path,
                'cluster_count' => count($candidate->clusterRefs),
                'metadata' => array_merge($existingMeta, [
                    'primary_entity' => $candidate->primaryEntity,
                    'intents' => $candidate->intents,
                    'funnel_stages' => $candidate->funnelStages,
                    'confidence' => $candidate->confidence,
                    'reason_codes' => $candidate->reasonCodes,
                ]),
            ]);
            $topic->save();

            if ($topic->public_ref === 'pending') {
                $topic->public_ref = KeywordIntelligencePublicRef::topic((int) $topic->id);
                $topic->save();
            }

            $persisted[$candidate->candidateRef] = $topic;
        }

        return $persisted;
    }

    /**
     * @param  array<string, SeoKiTopic>  $persistedTopicsByRef
     * @param  list<TopicCandidate>  $candidates
     * @param  array<string, string>  $clusterRelationships
     * @param  Collection<string, SeoKeywordCluster>  $clustersByRef
     * @return list<array<string, mixed>>
     */
    private function persistClusterAssignments(array $persistedTopicsByRef, array $candidates, array $clusterRelationships, Collection $clustersByRef): array
    {
        $assignments = [];

        foreach ($candidates as $candidate) {
            $topic = $persistedTopicsByRef[$candidate->candidateRef] ?? null;
            if (! $topic instanceof SeoKiTopic || $candidate->clusterRefs === []) {
                continue;
            }

            foreach ($candidate->clusterRefs as $clusterRef) {
                $cluster = $clustersByRef->get($clusterRef);
                if (! $cluster instanceof SeoKeywordCluster) {
                    continue;
                }

                $relationship = $clusterRelationships[$clusterRef] ?? KeywordTopicClusterRelationship::Supporting->value;
                $this->upsertTopicClusterLink($topic, $cluster, $relationship);

                $cluster->topic_id = $topic->id;
                $cluster->save();

                $assignments[] = [
                    'topic_ref' => $topic->public_ref,
                    'cluster_ref' => $cluster->public_ref,
                    'relationship' => $relationship,
                ];
            }
        }

        return $assignments;
    }

    private function upsertTopicClusterLink(SeoKiTopic $topic, SeoKeywordCluster $cluster, string $relationship): SeoTopicClusterLink
    {
        $link = SeoTopicClusterLink::query()
            ->where('topic_id', $topic->id)
            ->where('cluster_id', $cluster->id)
            ->first();

        if (! $link instanceof SeoTopicClusterLink) {
            $link = new SeoTopicClusterLink([
                'public_ref' => 'pending',
                'topic_id' => $topic->id,
                'cluster_id' => $cluster->id,
                'position' => 0,
            ]);
        }

        $link->relationship = $relationship;
        $link->save();

        if ($link->public_ref === 'pending') {
            $link->public_ref = KeywordIntelligencePublicRef::topicClusterLink((int) $link->id);
            $link->save();
        }

        return $link;
    }

    // -----------------------------------------------------------------
    // Stage: coverage / links input builders
    // -----------------------------------------------------------------

    /**
     * @param  array<string, SeoKiTopic>  $persistedTopicsByRef
     * @param  list<TopicCandidate>  $candidates
     * @param  Collection<string, SeoKeywordCluster>  $clustersByRef
     * @return list<array<string, mixed>>
     */
    private function buildCoverageInput(array $persistedTopicsByRef, array $candidates, Collection $clustersByRef): array
    {
        $input = [];
        foreach ($candidates as $candidate) {
            if ($candidate->topicType === KeywordTopicType::Root) {
                continue;
            }
            $topic = $persistedTopicsByRef[$candidate->candidateRef] ?? null;
            if (! $topic instanceof SeoKiTopic) {
                continue;
            }

            $clusters = [];
            foreach ($candidate->clusterRefs as $ref) {
                $cluster = $clustersByRef->get($ref);
                if (! $cluster instanceof SeoKeywordCluster) {
                    continue;
                }
                $clusters[] = [
                    'cluster_ref' => $cluster->public_ref,
                    'keyword_count' => (int) ($cluster->keyword_count ?? 0),
                    'relevance_score' => $cluster->relevance_score !== null ? (float) $cluster->relevance_score : null,
                    'opportunity_score' => $cluster->opportunity_score !== null ? (float) $cluster->opportunity_score : null,
                    'has_target_article' => $cluster->target_article_ref !== null,
                ];
            }

            $input[] = [
                'topic_ref' => $topic->public_ref,
                'topic_type' => $candidate->topicType->value,
                'name' => $candidate->name,
                'clusters' => $clusters,
            ];
        }

        return $input;
    }

    /**
     * @param  array<string, SeoKiTopic>  $persistedTopicsByRef
     * @param  list<TopicCandidate>  $candidates
     * @param  Collection<string, SeoKeywordCluster>  $clustersByRef
     * @param  array<string, string>  $clusterRelationships
     * @param  array<string, bool>  $reviewedOnlyRefs
     * @return list<array<string, mixed>>
     */
    private function buildLinkNodes(array $persistedTopicsByRef, array $candidates, Collection $clustersByRef, array $clusterRelationships, array $reviewedOnlyRefs): array
    {
        $nodes = [];
        foreach ($candidates as $candidate) {
            if ($candidate->topicType === KeywordTopicType::Root) {
                continue;
            }
            if (! isset($persistedTopicsByRef[$candidate->candidateRef])) {
                continue;
            }

            $clusters = [];
            foreach ($candidate->clusterRefs as $ref) {
                $cluster = $clustersByRef->get($ref);
                if (! $cluster instanceof SeoKeywordCluster) {
                    continue;
                }
                $clusters[] = [
                    'cluster_id' => (int) $cluster->id,
                    'cluster_ref' => $cluster->public_ref,
                    'name' => (string) $cluster->name,
                    'site_id' => $cluster->site_id !== null ? (int) $cluster->site_id : null,
                    'has_content' => $cluster->target_article_ref !== null,
                    'relationship' => $clusterRelationships[$ref] ?? KeywordTopicClusterRelationship::Supporting->value,
                    'cluster_type' => $cluster->cluster_type instanceof KeywordClusterType ? $cluster->cluster_type->value : (string) $cluster->cluster_type,
                    'is_reviewed_only' => (bool) ($reviewedOnlyRefs[$ref] ?? false),
                ];
            }

            $nodes[] = [
                'topic_ref' => $candidate->candidateRef,
                'topic_type' => $candidate->topicType->value,
                'parent_ref' => $candidate->parentCandidateRef,
                'clusters' => $clusters,
            ];
        }

        return $nodes;
    }

    /**
     * @param  array<string, SeoKiTopic>  $persistedTopicsByRef
     * @param  list<TopicCandidate>  $candidates
     * @return list<array<string, mixed>>
     */
    private function topicsOutput(array $persistedTopicsByRef, array $candidates): array
    {
        $output = [];
        foreach ($candidates as $candidate) {
            $topic = $persistedTopicsByRef[$candidate->candidateRef] ?? null;
            if (! $topic instanceof SeoKiTopic) {
                continue;
            }

            $parentTopic = $candidate->parentCandidateRef !== null ? ($persistedTopicsByRef[$candidate->parentCandidateRef] ?? null) : null;

            $output[] = [
                'topic_ref' => $topic->public_ref,
                'parent_ref' => $parentTopic instanceof SeoKiTopic ? $parentTopic->public_ref : null,
                'name' => $topic->name,
                'topic_type' => $candidate->topicType->value,
                'depth' => (int) $topic->depth,
                'cluster_count' => count($candidate->clusterRefs),
                'cluster_refs' => $candidate->clusterRefs,
            ];
        }

        return $output;
    }

    // -----------------------------------------------------------------
    // Stage: finalize (version + link suggestions persistence)
    // -----------------------------------------------------------------

    /**
     * @param  list<TopicCandidate>  $candidates
     * @param  array<string, SeoKiTopic>  $persistedTopicsByRef
     * @param  array{aggregate: array<string, mixed>}  $coverage
     * @param  list<array{cluster_ref: string, reason: string}>  $excludedClusters
     */
    private function persistVersion(
        SeoKeywordWorkspace $workspace,
        KeywordTopicalMapMode $mode,
        array $candidates,
        array $persistedTopicsByRef,
        array $coverage,
        array $excludedClusters,
        ?int $actorId,
    ): SeoTopicalMapVersion {
        $version = (int) (SeoTopicalMapVersion::query()->where('workspace_id', $workspace->id)->max('version') ?? 0) + 1;

        $topicsSnapshot = [];
        foreach ($candidates as $candidate) {
            $topic = $persistedTopicsByRef[$candidate->candidateRef] ?? null;
            if (! $topic instanceof SeoKiTopic) {
                continue;
            }

            $parentTopic = $candidate->parentCandidateRef !== null ? ($persistedTopicsByRef[$candidate->parentCandidateRef] ?? null) : null;

            $topicsSnapshot[] = [
                'topic_ref' => $topic->public_ref,
                'parent_ref' => $parentTopic instanceof SeoKiTopic ? $parentTopic->public_ref : null,
                'name' => $topic->name,
                'topic_type' => $candidate->topicType->value,
                'depth' => (int) $topic->depth,
                'cluster_refs' => $candidate->clusterRefs,
            ];
        }

        $clusterCount = array_sum(array_map(static fn (array $t): int => count($t['cluster_refs']), $topicsSnapshot));

        $mapVersion = new SeoTopicalMapVersion([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id,
            'site_id' => $workspace->site_id,
            'version' => $version,
            'status' => KeywordTopicalMapVersionStatus::Draft->value,
            'mode' => $mode->value,
            'snapshot' => [
                'topics' => $topicsSnapshot,
            ],
            'summary' => [
                'topic_count' => count($topicsSnapshot),
                'cluster_count' => $clusterCount,
                'excluded_cluster_count' => count($excludedClusters),
                'coverage' => $coverage['aggregate'] ?? [],
            ],
            'generated_by' => $actorId,
            'generated_at' => now(),
        ]);
        $mapVersion->save();
        $mapVersion->public_ref = KeywordIntelligencePublicRef::mapVersion((int) $mapVersion->id);
        $mapVersion->save();

        return $mapVersion;
    }

    /**
     * @param  list<array{type: string, source_cluster_id: int, target_cluster_id: int, anchor_text: string, confidence: float, priority: float, reason_codes: list<string>, fingerprint: string}>  $suggestions
     * @param  array<int, string>  $clusterRefById
     * @return list<array<string, mixed>>
     */
    private function persistLinkSuggestions(SeoKeywordWorkspace $workspace, SeoTopicalMapVersion $mapVersion, array $suggestions, array $clusterRefById): array
    {
        $persisted = [];
        $maxSuggestions = $this->configInt('seo-content-ai.keyword_intelligence.topical_map.max_link_suggestions', 500);

        foreach (array_slice($suggestions, 0, max(0, $maxSuggestions)) as $suggestion) {
            $fingerprint = (string) $suggestion['fingerprint'];

            $model = SeoTopicalLinkSuggestion::query()
                ->where('workspace_id', $workspace->id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if (! $model instanceof SeoTopicalLinkSuggestion) {
                $model = new SeoTopicalLinkSuggestion([
                    'public_ref' => 'pending',
                    'workspace_id' => $workspace->id,
                    'tenant_id' => $workspace->tenant_id,
                    'site_id' => $workspace->site_id,
                    'fingerprint' => $fingerprint,
                    'status' => 'draft',
                ]);
            }

            $model->fill([
                'topical_map_version_id' => $mapVersion->id,
                'source_cluster_id' => $suggestion['source_cluster_id'],
                'target_cluster_id' => $suggestion['target_cluster_id'],
                'relationship' => $suggestion['type'],
                'priority' => $suggestion['priority'],
                'confidence' => $suggestion['confidence'],
                'reason_codes' => $suggestion['reason_codes'],
            ]);
            $model->save();

            if ($model->public_ref === 'pending') {
                $model->public_ref = KeywordIntelligencePublicRef::linkSuggestion((int) $model->id);
                $model->save();
            }

            $persisted[] = [
                'suggestion_ref' => $model->public_ref,
                'type' => $suggestion['type'],
                'source_cluster_ref' => $clusterRefById[$suggestion['source_cluster_id']] ?? null,
                'target_cluster_ref' => $clusterRefById[$suggestion['target_cluster_id']] ?? null,
                'anchor_text' => $suggestion['anchor_text'],
                'confidence' => $suggestion['confidence'],
                'priority' => $suggestion['priority'],
            ];
        }

        return $persisted;
    }

    // -----------------------------------------------------------------
    // Stage: validating
    // -----------------------------------------------------------------

    /**
     * TopicalMapHierarchyValidator làm việc trên payload dạng mảng phẳng (topic_ref/parent_ref/
     * topic_type + cluster_ref/topic_ref/relationship) — không phải TopicCandidate object — để
     * dùng chung được cho cả build-time (candidates trong bộ nhớ) lẫn re-validate từ dữ liệu đã
     * persist. Hàm này chuyển đổi cây candidate hiện tại sang đúng payload đó rồi map ngược
     * 'reasons' (list<string> dạng "code:ref") thành 'issues' (list<array{code,severity,message,
     * candidate_ref}>) để TopicalMapConflictDetector/failResult dùng trực tiếp.
     *
     * @param  list<TopicCandidate>  $candidates
     * @param  array<string, string>  $clusterRelationships  cluster_ref => relationship
     * @return array{status: string, issues: list<array{code: string, severity: string, message: string, candidate_ref: string|null}>}
     */
    private function validateHierarchy(array $candidates, array $clusterRelationships, int $maxDepth): array
    {
        $topics = [];
        $assignments = [];

        foreach ($candidates as $candidate) {
            $topics[] = [
                'topic_ref' => $candidate->candidateRef,
                'parent_ref' => $candidate->parentCandidateRef,
                'topic_type' => $candidate->topicType->value,
            ];

            foreach ($candidate->clusterRefs as $clusterRef) {
                $assignments[] = [
                    'cluster_ref' => $clusterRef,
                    'topic_ref' => $candidate->candidateRef,
                    'relationship' => $clusterRelationships[$clusterRef] ?? KeywordTopicClusterRelationship::Supporting->value,
                ];
            }
        }

        $result = $this->hierarchyValidator->validate($topics, $assignments, $maxDepth);

        return [
            'status' => $result['status'],
            'issues' => $this->reasonsToIssues($result['reasons']),
        ];
    }

    /**
     * @param  list<string>  $reasons  dạng "code:candidate_ref" (xem TopicalMapHierarchyValidator)
     * @return list<array{code: string, severity: string, message: string, candidate_ref: string|null}>
     */
    private function reasonsToIssues(array $reasons): array
    {
        $issues = [];

        foreach ($reasons as $reason) {
            [$code, $ref] = array_pad(explode(':', $reason, 2), 2, null);
            $code = (string) $code;

            $severity = match ($code) {
                'cycle_detected', 'cluster_multiple_primary' => 'blocking',
                'orphan_topic' => 'high',
                'max_depth_exceeded' => 'warning',
                default => 'warning',
            };

            $issues[] = [
                'code' => $code,
                'severity' => $severity,
                'message' => str_replace('_', ' ', $code).($ref !== null ? ": {$ref}" : ''),
                'candidate_ref' => $ref,
            ];
        }

        return $issues;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function failResult(string $resultCode, array $warnings = [], array $issues = []): TopicalMapBuildResult
    {
        $conflicts = array_map(static fn (array $issue): array => [
            'code' => $issue['code'],
            'risk' => $issue['severity'],
            'message' => $issue['message'],
            'context' => ['candidate_ref' => $issue['candidate_ref']],
            'fingerprint' => hash('xxh3', $issue['code'].'|'.($issue['candidate_ref'] ?? '')),
        ], $issues);

        return new TopicalMapBuildResult(
            resultCode: $resultCode,
            conflicts: $conflicts,
            warnings: array_values(array_unique(array_merge($warnings, $this->issueMessages($issues)))),
        );
    }

    /**
     * @param  list<array{code: string, severity: string, message: string, candidate_ref: string|null}>  $issues
     * @return list<string>
     */
    private function issueMessages(array $issues): array
    {
        return array_map(static fn (array $issue): string => $issue['code'], $issues);
    }

    private function resolveMaxDepth(TopicalMapBuildRequest $request, array $modeConfig): int
    {
        $modeMax = max(2, (int) ($modeConfig['max_depth'] ?? 4));

        if ($request->maxDepth === null) {
            return $modeMax;
        }

        return max(2, min($request->maxDepth, $modeMax));
    }

    /**
     * @return array{max_depth: int, max_pillars: int, max_subtopics_per_pillar: int, max_cluster_group_size: int, enable_faq_group: bool}
     */
    private function modeConfig(KeywordTopicalMapMode $mode): array
    {
        $defaults = [
            'conservative' => ['max_depth' => 3, 'max_pillars' => 3, 'max_subtopics_per_pillar' => 0, 'max_cluster_group_size' => 12, 'enable_faq_group' => true],
            'balanced' => ['max_depth' => 4, 'max_pillars' => 6, 'max_subtopics_per_pillar' => 4, 'max_cluster_group_size' => 8, 'enable_faq_group' => true],
            'expansive' => ['max_depth' => 5, 'max_pillars' => 10, 'max_subtopics_per_pillar' => 6, 'max_cluster_group_size' => 5, 'enable_faq_group' => true],
        ];

        $key = $mode->value;
        $config = $defaults[$key] ?? $defaults['balanced'];

        if (! function_exists('config')) {
            return $config;
        }

        try {
            $override = (array) config("seo-content-ai.keyword_intelligence.topical_map.modes.{$key}", []);
            $override = array_filter($override, static fn ($value): bool => $value !== null);

            return array_merge($config, $override);
        } catch (Throwable) {
            return $config;
        }
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = (int) config($key, $default);

            return $value > 0 ? $value : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
