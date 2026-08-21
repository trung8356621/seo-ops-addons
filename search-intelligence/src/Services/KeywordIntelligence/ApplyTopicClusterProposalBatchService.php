<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalFingerprint;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberStateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchMode;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;
use Throwable;

class ApplyTopicClusterProposalBatchService
{
    public function __construct(
        private readonly KeywordClusterProposalEngine $proposalEngine,
        private readonly TopicClusterProposalMemberStateLoader $memberStateLoader,
        private readonly TopicClusterProposalFingerprint $fingerprintService,
        private readonly TopicClusterClusterKeyGenerator $clusterKeyGenerator,
        private readonly KeywordClusterEligibility $eligibility,
        private readonly KeywordClusterQuery $clusters,
        private readonly TopicClusterApplySideEffects $sideEffects,
    ) {}

    public function apply(ApplyTopicClusterProposalBatchInput $input): ApplyTopicClusterProposalBatchResult
    {
        $mode = $this->normalizeMode($input->mode);
        if ($mode === null) {
            return ApplyTopicClusterProposalBatchResult::invalidSelection($input->mode);
        }

        if (! $this->isAuthorized($input->siteId)) {
            return ApplyTopicClusterProposalBatchResult::unauthorized($mode);
        }

        if ($input->siteId <= 0 || trim($input->previewFingerprint) === '') {
            return ApplyTopicClusterProposalBatchResult::stale($mode);
        }

        $strategy = KeywordClusterProposalStrategy::normalize($input->strategy);
        $preview = $this->proposalEngine->previewForSite($input->siteId, $strategy);

        if (! hash_equals($preview->previewFingerprint, $input->previewFingerprint)) {
            $idempotent = $this->tryIdempotentSelectedBatch($input, $strategy);
            if ($idempotent instanceof ApplyTopicClusterProposalBatchResult) {
                return $idempotent;
            }

            return ApplyTopicClusterProposalBatchResult::stale($mode);
        }

        $resolved = $this->resolveBatchProposals($input, $mode, $preview);
        if (($resolved['status'] ?? '') !== 'ok') {
            return match ($resolved['status'] ?? 'stale') {
                'invalid_selection' => ApplyTopicClusterProposalBatchResult::invalidSelection($mode),
                default => ApplyTopicClusterProposalBatchResult::stale($mode),
            };
        }

        /** @var list<array{
         *     cluster: KeywordClusterProposalCluster,
         *     member_ids: list<int>,
         *     expected_state_hashes: array<int, string>,
         *     cluster_key: string,
         *     proposal_fingerprint: string,
         * }> $plans
         */
        $plans = $resolved['plans'];
        $allMemberIds = $resolved['all_member_ids'];

        try {
            $mutation = DB::connection('omi_seo_ai')->transaction(function () use (
                $input,
                $plans,
                $allMemberIds,
            ): array {
                $rows = SeoKeywordClassification::query()
                    ->whereIn('keyword_id', $allMemberIds)
                    ->orderBy('keyword_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('keyword_id');

                if (count($rows) !== count($allMemberIds)) {
                    throw new RuntimeException('conflict:missing_rows');
                }

                $alreadyAppliedMembers = 0;
                $membersToWrite = 0;
                $proposalAlreadyApplied = 0;

                foreach ($plans as $plan) {
                    $proposalAlready = 0;
                    foreach ($plan['member_ids'] as $keywordId) {
                        $row = $rows->get($keywordId);
                        if (! $row instanceof SeoKeywordClassification) {
                            throw new RuntimeException('conflict:missing_row');
                        }

                        $currentKey = trim((string) ($row->cluster_key ?? ''));
                        if ($currentKey === $plan['cluster_key']) {
                            $alreadyAppliedMembers++;
                            $proposalAlready++;

                            continue;
                        }

                        if ($currentKey !== '') {
                            throw new RuntimeException('conflict:occupied');
                        }

                        if (! $this->eligibility->isProposalCandidate($row)) {
                            throw new RuntimeException('conflict:ineligible');
                        }

                        $liveState = ($this->memberStateLoader->loadForSite($input->siteId, [$keywordId])[$keywordId] ?? null);
                        if ($liveState === null) {
                            throw new RuntimeException('conflict:site_scope');
                        }

                        $expectedHash = $plan['expected_state_hashes'][$keywordId] ?? '';
                        if ($expectedHash === '' || ! hash_equals($expectedHash, $liveState->stateHash())) {
                            throw new RuntimeException('stale:state_changed');
                        }

                        $membersToWrite++;
                    }

                    if ($proposalAlready === count($plan['member_ids'])) {
                        $proposalAlreadyApplied++;
                    } elseif ($proposalAlready > 0) {
                        throw new RuntimeException('conflict:partial_proposal');
                    }
                }

                if ($membersToWrite === 0 && $alreadyAppliedMembers === count($allMemberIds)) {
                    return [
                        'mode' => 'already_applied',
                        'affected' => count($allMemberIds),
                    ];
                }

                if ($alreadyAppliedMembers > 0) {
                    throw new RuntimeException('conflict:partial_batch');
                }

                $written = 0;
                foreach ($plans as $plan) {
                    $affected = SeoKeywordClassification::query()
                        ->whereIn('keyword_id', $plan['member_ids'])
                        ->where(function ($query): void {
                            $query->whereNull('cluster_key')->orWhere('cluster_key', '');
                        })
                        ->update(['cluster_key' => $plan['cluster_key']]);

                    if ($affected !== count($plan['member_ids'])) {
                        throw new RuntimeException('conflict:partial_update');
                    }

                    $written += (int) $affected;
                }

                if ($written !== count($allMemberIds)) {
                    throw new RuntimeException('conflict:count_mismatch');
                }

                return [
                    'mode' => 'applied',
                    'affected' => $written,
                ];
            });
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_starts_with($message, 'stale:')) {
                return ApplyTopicClusterProposalBatchResult::stale($mode);
            }
            if (str_starts_with($message, 'conflict:')) {
                return ApplyTopicClusterProposalBatchResult::conflict($mode);
            }

            return ApplyTopicClusterProposalBatchResult::error($mode);
        } catch (Throwable) {
            return ApplyTopicClusterProposalBatchResult::error($mode);
        }

        $clusterKeys = array_map(static fn (array $plan): string => $plan['cluster_key'], $plans);
        $fingerprints = array_map(static fn (array $plan): string => $plan['proposal_fingerprint'], $plans);
        $keywordCount = count($allMemberIds);

        if (($mutation['mode'] ?? '') === 'already_applied') {
            return ApplyTopicClusterProposalBatchResult::alreadyApplied(
                mode: $mode,
                proposalCount: count($plans),
                keywordCount: $keywordCount,
                clusterKeys: $clusterKeys,
                appliedProposalFingerprints: $fingerprints,
            );
        }

        $this->sideEffects->afterBatchApply(
            siteId: $input->siteId,
            mode: $mode,
            proposalCount: count($plans),
            keywordCount: $keywordCount,
            strategy: $strategy,
            previewFingerprint: $input->previewFingerprint,
            clusterKeys: $clusterKeys,
            plans: $plans,
        );

        return ApplyTopicClusterProposalBatchResult::applied(
            mode: $mode,
            proposalCount: count($plans),
            keywordCount: $keywordCount,
            clusterKeys: $clusterKeys,
            appliedProposalFingerprints: $fingerprints,
        );
    }

    protected function isAuthorized(int $siteId): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures()
            && SeoAccessControl::canMutateInSeoPanel()
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    /**
     * @return array{
     *     status: 'ok'|'stale'|'invalid_selection',
     *     plans?: list<array{
     *         cluster: KeywordClusterProposalCluster,
     *         member_ids: list<int>,
     *         expected_state_hashes: array<int, string>,
     *         cluster_key: string,
     *         proposal_fingerprint: string,
     *     }>,
     *     all_member_ids?: list<int>,
     * }
     */
    private function resolveBatchProposals(
        ApplyTopicClusterProposalBatchInput $input,
        string $mode,
        KeywordClusterProposalResult $preview,
    ): array {
        $clusters = $mode === ApplyTopicClusterProposalBatchMode::ALL_READY
            ? $this->readyClustersFromPreview($preview)
            : $this->selectedReadyClustersFromPreview($input, $preview);

        if ($clusters === null) {
            return ['status' => 'invalid_selection'];
        }

        if ($clusters === []) {
            return ['status' => 'invalid_selection'];
        }

        $unionMemberIds = [];
        $seenFingerprints = [];
        $plans = [];
        /** @var array<string, true> $reservedKeys */
        $reservedKeys = [];

        foreach ($clusters as $cluster) {
            if ($cluster->finalStatus !== KeywordClusterProposalCluster::FINAL_READY) {
                return ['status' => 'invalid_selection'];
            }

            if ($cluster->proposalFingerprint === '' || isset($seenFingerprints[$cluster->proposalFingerprint])) {
                return ['status' => 'invalid_selection'];
            }
            $seenFingerprints[$cluster->proposalFingerprint] = true;

            $resolved = $this->buildResolvedProposal($cluster, $preview->memberStates);
            if ($resolved === null) {
                return ['status' => 'stale'];
            }

            foreach ($resolved['member_ids'] as $keywordId) {
                if (isset($unionMemberIds[$keywordId])) {
                    return ['status' => 'invalid_selection'];
                }
                $unionMemberIds[$keywordId] = true;
            }

            $clusterKey = $this->clusterKeyGenerator->generate(
                siteId: $input->siteId,
                representativeLabel: $cluster->representativeLabel,
                sortedKeywordIds: $resolved['member_ids'],
                reservedKeys: $reservedKeys,
            );
            $reservedKeys[$clusterKey] = true;

            $plans[] = [
                ...$resolved,
                'cluster_key' => $clusterKey,
                'proposal_fingerprint' => $cluster->proposalFingerprint,
            ];
        }

        $allMemberIds = array_keys($unionMemberIds);
        sort($allMemberIds, SORT_NUMERIC);

        return [
            'status' => 'ok',
            'plans' => $plans,
            'all_member_ids' => $allMemberIds,
        ];
    }

    /**
     * @return list<KeywordClusterProposalCluster>|null
     */
    private function readyClustersFromPreview(KeywordClusterProposalResult $preview): ?array
    {
        $ready = [];
        foreach ($preview->proposedClusters as $cluster) {
            if ($cluster->finalStatus !== KeywordClusterProposalCluster::FINAL_READY) {
                continue;
            }
            if ($cluster->proposalFingerprint === '' || $cluster->memberCount < 1) {
                continue;
            }
            $ready[] = $cluster;
        }

        return $ready;
    }

    /**
     * @return list<KeywordClusterProposalCluster>|null
     */
    private function selectedReadyClustersFromPreview(
        ApplyTopicClusterProposalBatchInput $input,
        KeywordClusterProposalResult $preview,
    ): ?array {
        $requested = array_values(array_unique(array_filter(array_map(
            static fn (string $fingerprint): string => trim($fingerprint),
            $input->selectedProposalFingerprints,
        ), static fn (string $fingerprint): bool => $fingerprint !== '')));

        if ($requested === []) {
            return null;
        }

        sort($requested, SORT_STRING);

        $byFingerprint = [];
        foreach ($preview->proposedClusters as $cluster) {
            if ($cluster->proposalFingerprint !== '') {
                $byFingerprint[$cluster->proposalFingerprint] = $cluster;
            }
        }

        $selected = [];
        foreach ($requested as $fingerprint) {
            $cluster = $byFingerprint[$fingerprint] ?? null;
            if (! $cluster instanceof KeywordClusterProposalCluster) {
                return null;
            }
            if ($cluster->finalStatus !== KeywordClusterProposalCluster::FINAL_READY) {
                return null;
            }
            $selected[] = $cluster;
        }

        return $selected;
    }

    /**
     * @param  array<int, \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberState>  $memberStates
     * @return array{
     *     cluster: KeywordClusterProposalCluster,
     *     member_ids: list<int>,
     *     expected_state_hashes: array<int, string>,
     * }|null
     */
    private function buildResolvedProposal(
        KeywordClusterProposalCluster $cluster,
        array $memberStates,
    ): ?array {
        $memberIds = array_map(
            static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
            $cluster->members,
        );
        $memberIds = array_values(array_filter($memberIds, static fn (int $id): bool => $id > 0));
        sort($memberIds, SORT_NUMERIC);

        if ($memberIds === []) {
            return null;
        }

        $expectedStateHashes = [];
        foreach ($memberIds as $keywordId) {
            $state = $memberStates[$keywordId] ?? null;
            if ($state === null) {
                return null;
            }
            $expectedStateHashes[$keywordId] = $state->stateHash();
        }

        return [
            'cluster' => $cluster,
            'member_ids' => $memberIds,
            'expected_state_hashes' => $expectedStateHashes,
        ];
    }

    private function normalizeMode(string $mode): ?string
    {
        $mode = trim($mode);
        if ($mode === ApplyTopicClusterProposalBatchMode::SELECTED) {
            return ApplyTopicClusterProposalBatchMode::SELECTED;
        }
        if ($mode === ApplyTopicClusterProposalBatchMode::ALL_READY) {
            return ApplyTopicClusterProposalBatchMode::ALL_READY;
        }

        return null;
    }

    private function tryIdempotentSelectedBatch(
        ApplyTopicClusterProposalBatchInput $input,
        string $strategy,
    ): ?ApplyTopicClusterProposalBatchResult {
        if ($input->mode !== ApplyTopicClusterProposalBatchMode::SELECTED) {
            return null;
        }

        $requested = array_values(array_unique(array_filter(array_map(
            static fn (string $fingerprint): string => trim($fingerprint),
            $input->selectedProposalFingerprints,
        ), static fn (string $fingerprint): bool => $fingerprint !== '')));
        sort($requested, SORT_STRING);

        if ($requested === []) {
            return null;
        }

        $matched = [];
        $clusterKeys = [];
        $keywordCount = 0;

        foreach ($this->distinctClusterKeysForSite($input->siteId) as $clusterKey) {
            $memberIds = $this->clusters->memberKeywordIds($input->siteId, $clusterKey);
            sort($memberIds, SORT_NUMERIC);
            if ($memberIds === []) {
                continue;
            }

            $memberStates = $this->memberStateLoader->loadForSite($input->siteId, $memberIds);
            if (count($memberStates) !== count($memberIds)) {
                return null;
            }

            $fingerprint = $this->matchFingerprintForMemberGroup(
                siteId: $input->siteId,
                strategy: $strategy,
                previewFingerprint: $input->previewFingerprint,
                memberIds: $memberIds,
                memberStates: $memberStates,
                requestedFingerprints: $requested,
            );

            if ($fingerprint === null) {
                continue;
            }

            if (isset($matched[$fingerprint])) {
                return null;
            }

            $matched[$fingerprint] = true;
            $clusterKeys[] = $clusterKey;
            $keywordCount += count($memberIds);
        }

        if (count($matched) === count($requested)) {
            return ApplyTopicClusterProposalBatchResult::alreadyApplied(
                mode: ApplyTopicClusterProposalBatchMode::SELECTED,
                proposalCount: count($requested),
                keywordCount: $keywordCount,
                clusterKeys: $clusterKeys,
                appliedProposalFingerprints: $requested,
            );
        }

        if ($matched !== []) {
            return ApplyTopicClusterProposalBatchResult::conflict(ApplyTopicClusterProposalBatchMode::SELECTED);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function distinctClusterKeysForSite(int $siteId): array
    {
        $allowedIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($allowedIds === []) {
            return [];
        }

        return SeoKeywordClassification::query()
            ->whereIn('keyword_id', $allowedIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->distinct()
            ->orderBy('cluster_key')
            ->pluck('cluster_key')
            ->map(static fn (mixed $key): string => trim((string) $key))
            ->filter(static fn (string $key): bool => $key !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberState>  $memberStates
     * @param  list<string>  $requestedFingerprints
     */
    private function matchFingerprintForMemberGroup(
        int $siteId,
        string $strategy,
        string $previewFingerprint,
        array $memberIds,
        array $memberStates,
        array $requestedFingerprints,
    ): ?string {
        $qualityStates = [
            KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
            KeywordClusterQualityMetrics::STATE_COMPACT,
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA,
        ];

        foreach ($memberIds as $representativeId) {
            $phrase = (string) (Keyword::query()->whereKey($representativeId)->value('phrase') ?? '');
            $members = [];
            foreach ($memberIds as $keywordId) {
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => (string) (Keyword::query()->whereKey($keywordId)->value('phrase') ?? ''),
                    'seo_intent' => $memberStates[$keywordId]->seoIntent ?? '',
                ];
            }

            foreach ($qualityStates as $qualityState) {
                $quality = $qualityState === KeywordClusterQualityMetrics::STATE_ACCEPTABLE
                    ? null
                    : new KeywordClusterQualityMetrics(
                        memberCount: count($memberIds),
                        averageSimilarity: 0.0,
                        minimumSimilarity: 0.0,
                        p25Similarity: 0.0,
                        medianSimilarity: 0.0,
                        representativeAverageSimilarity: 0.0,
                        representativeMinSimilarity: 0.0,
                        coreMemberCount: count($memberIds),
                        borderlineMemberCount: 0,
                        qualityState: $qualityState,
                        representativeSimilarities: [],
                    );

                $cluster = new KeywordClusterProposalCluster(
                    representativeLabel: $phrase !== '' ? $phrase : 'Topic cluster',
                    representativeKeywordId: $representativeId,
                    cohesion: 0.0,
                    minSimilarity: 0.0,
                    memberCount: count($memberIds),
                    members: $members,
                    quality: $quality,
                    finalStatus: KeywordClusterProposalCluster::FINAL_READY,
                );

                $fingerprint = $this->fingerprintService->proposalFingerprint(
                    siteId: $siteId,
                    strategy: $strategy,
                    previewFingerprint: $previewFingerprint,
                    cluster: $cluster,
                    memberStates: $memberStates,
                );

                if (in_array($fingerprint, $requestedFingerprints, true)) {
                    return $fingerprint;
                }
            }
        }

        return null;
    }
}
