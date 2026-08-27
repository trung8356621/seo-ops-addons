<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberStateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalResult;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;
use Throwable;

class ApplyTopicClusterProposalService
{
    public function __construct(
        private readonly KeywordClusterProposalEngine $proposalEngine,
        private readonly TopicClusterProposalMemberStateLoader $memberStateLoader,
        private readonly TopicClusterClusterKeyGenerator $clusterKeyGenerator,
        private readonly KeywordClusterEligibility $eligibility,
        private readonly KeywordClusterQuery $clusters,
        private readonly TopicClusterApplySideEffects $sideEffects,
        private readonly TopicClusterPostApplyService $postApply,
    ) {}

    public function apply(ApplyTopicClusterProposalInput $input): ApplyTopicClusterProposalResult
    {
        if (! $this->isAuthorized($input->siteId)) {
            return ApplyTopicClusterProposalResult::unauthorized();
        }

        if ($input->siteId <= 0
            || trim($input->previewFingerprint) === ''
            || trim($input->proposalFingerprint) === ''
        ) {
            return ApplyTopicClusterProposalResult::stale();
        }

        $strategy = KeywordClusterProposalStrategy::normalize($input->strategy);
        $resolved = $this->resolveProposal($input, $strategy);
        $outcome = $resolved['outcome'] ?? 'stale';
        if ($outcome === 'conflict') {
            return ApplyTopicClusterProposalResult::conflict();
        }
        if ($outcome !== 'resolved') {
            return ApplyTopicClusterProposalResult::stale();
        }

        /** @var KeywordClusterProposalCluster $cluster */
        $cluster = $resolved['cluster'];
        $memberIds = $resolved['member_ids'];
        $expectedStateHashes = $resolved['expected_state_hashes'];

        $clusterKey = $this->postApply->resolveClusterKey(
            siteId: $input->siteId,
            representativeLabel: $cluster->representativeLabel,
            memberIds: $memberIds,
            generator: $this->clusterKeyGenerator,
        );

        try {
            $mutation = DB::connection('omi_seo_ai')->transaction(function () use (
                $input,
                $memberIds,
                $expectedStateHashes,
                $clusterKey,
            ): array {
                $rows = SeoKeywordClassification::query()
                    ->whereIn('keyword_id', $memberIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('keyword_id');

                if (count($rows) !== count($memberIds)) {
                    throw new RuntimeException('conflict:missing_rows');
                }

                $alreadyApplied = 0;
                foreach ($memberIds as $keywordId) {
                    $row = $rows->get($keywordId);
                    if (! $row instanceof SeoKeywordClassification) {
                        throw new RuntimeException('conflict:missing_row');
                    }

                    $currentKey = trim((string) ($row->cluster_key ?? ''));
                    if ($currentKey === $clusterKey) {
                        $alreadyApplied++;

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

                    $expectedHash = $expectedStateHashes[$keywordId] ?? '';
                    if ($expectedHash === '' || ! hash_equals($expectedHash, $liveState->stateHash())) {
                        throw new RuntimeException('stale:state_changed');
                    }
                }

                if ($alreadyApplied === count($memberIds)) {
                    return [
                        'mode' => 'already_applied',
                        'affected' => count($memberIds),
                    ];
                }

                $affected = SeoKeywordClassification::query()
                    ->whereIn('keyword_id', $memberIds)
                    ->where(function ($query): void {
                        $query->whereNull('cluster_key')->orWhere('cluster_key', '');
                    })
                    ->update(['cluster_key' => $clusterKey]);

                if ($affected !== (count($memberIds) - $alreadyApplied)) {
                    throw new RuntimeException('conflict:partial_update');
                }

                return [
                    'mode' => 'applied',
                    'affected' => (int) $affected + $alreadyApplied,
                ];
            });
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_starts_with($message, 'stale:')) {
                return ApplyTopicClusterProposalResult::stale();
            }
            if (str_starts_with($message, 'conflict:')) {
                return ApplyTopicClusterProposalResult::conflict();
            }

            return ApplyTopicClusterProposalResult::failed();
        } catch (Throwable) {
            return ApplyTopicClusterProposalResult::failed();
        }

        $qualityState = $cluster->quality?->qualityState;
        if (($mutation['mode'] ?? '') === 'already_applied') {
            return ApplyTopicClusterProposalResult::alreadyApplied(
                clusterKey: $clusterKey,
                representativeLabel: $cluster->representativeLabel,
                affectedKeywordCount: (int) ($mutation['affected'] ?? count($memberIds)),
                keywordIds: $memberIds,
                proposalFingerprint: $input->proposalFingerprint,
            );
        }

        $this->sideEffects->afterApply(
            siteId: $input->siteId,
            clusterKey: $clusterKey,
            representativeLabel: $cluster->representativeLabel,
            affectedKeywordCount: (int) ($mutation['affected'] ?? count($memberIds)),
            strategy: $strategy,
            finalStatus: $cluster->finalStatus,
            qualityState: $qualityState,
            proposalFingerprint: $input->proposalFingerprint,
            keywordIds: $memberIds,
        );

        $this->postApply->afterClusterAssignment(
            siteId: $input->siteId,
            clusterKey: $clusterKey,
            keywordIds: $memberIds,
            representativeLabel: $cluster->representativeLabel,
        );

        return ApplyTopicClusterProposalResult::applied(
            clusterKey: $clusterKey,
            representativeLabel: $cluster->representativeLabel,
            affectedKeywordCount: (int) ($mutation['affected'] ?? count($memberIds)),
            keywordIds: $memberIds,
            proposalFingerprint: $input->proposalFingerprint,
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
     *     outcome: 'resolved'|'stale'|'conflict',
     *     cluster?: KeywordClusterProposalCluster,
     *     member_ids?: list<int>,
     *     expected_state_hashes?: array<int, string>,
     * }
     */
    private function resolveProposal(ApplyTopicClusterProposalInput $input, string $strategy): array
    {
        $preview = $this->proposalEngine->previewForSite($input->siteId, $strategy);
        if (hash_equals($preview->previewFingerprint, $input->previewFingerprint)) {
            foreach ($preview->proposedClusters as $cluster) {
                if (! hash_equals($cluster->proposalFingerprint, $input->proposalFingerprint)) {
                    continue;
                }

                $resolved = $this->buildResolvedProposal($cluster, $preview->memberStates);
                if ($resolved === null) {
                    return ['outcome' => 'stale'];
                }

                $requestedIds = $this->normalizeKeywordIds($input->memberKeywordIds);
                if ($requestedIds !== [] && ! $this->requestedMemberSetIsExactAndInSite(
                    $input->siteId,
                    $requestedIds,
                    $resolved['member_ids'],
                )) {
                    return ['outcome' => 'stale'];
                }

                return ['outcome' => 'resolved', ...$resolved];
            }

            return ['outcome' => 'stale'];
        }

        return $this->resolveVerifiedProposal($input, $strategy, $preview);
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

    /**
     * Preview fingerprint drifted. Frontend member IDs locate occupancy / current
     * engine proposals only — they are never used to reconstruct a proposal.
     *
     * @return array{
     *     outcome: 'resolved'|'stale'|'conflict',
     *     cluster?: KeywordClusterProposalCluster,
     *     member_ids?: list<int>,
     *     expected_state_hashes?: array<int, string>,
     * }
     */
    private function resolveVerifiedProposal(
        ApplyTopicClusterProposalInput $input,
        string $strategy,
        KeywordClusterProposalResult $currentPreview,
    ): array {
        $requestedIds = $this->normalizeKeywordIds($input->memberKeywordIds);
        if ($requestedIds === [] || $this->hasUnknownOrCrossSiteIds($input->siteId, $requestedIds)) {
            return ['outcome' => 'stale'];
        }

        $occupancy = $this->occupancyForRequestedMembers($input->siteId, $requestedIds);
        if ($occupancy === null) {
            return ['outcome' => 'stale'];
        }

        if ($occupancy['already_applied']) {
            $cluster = $this->clusterFromRequestedMembers($input, $requestedIds, $currentPreview);

            return [
                'outcome' => 'resolved',
                ...$this->resolvedWithoutLiveCandidateStates($cluster, $requestedIds),
            ];
        }

        if ($occupancy['conflict']) {
            return ['outcome' => 'conflict'];
        }

        $currentCluster = $this->findCurrentProposalByMemberSet($currentPreview, $requestedIds);
        if ($currentCluster === null) {
            return ['outcome' => 'stale'];
        }

        $resolved = $this->buildResolvedProposal($currentCluster, $currentPreview->memberStates);
        if ($resolved === null) {
            return ['outcome' => 'stale'];
        }

        return ['outcome' => 'resolved', ...$resolved];
    }

    /**
     * @param  list<int>  $requestedIds
     * @return array{already_applied: bool, conflict: bool}|null
     */
    private function occupancyForRequestedMembers(int $siteId, array $requestedIds): ?array
    {
        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $requestedIds)
            ->get()
            ->keyBy('keyword_id');

        if (count($rows) !== count($requestedIds)) {
            return null;
        }

        $keys = [];
        foreach ($requestedIds as $keywordId) {
            $row = $rows->get($keywordId);
            if (! $row instanceof SeoKeywordClassification) {
                return null;
            }
            $keys[$keywordId] = trim((string) ($row->cluster_key ?? ''));
        }

        $nonEmpty = array_values(array_unique(array_filter(
            $keys,
            static fn (string $key): bool => $key !== '',
        )));

        if ($nonEmpty === []) {
            return ['already_applied' => false, 'conflict' => false];
        }

        if (count($nonEmpty) === 1 && ! in_array('', $keys, true)) {
            $existingIds = $this->clusters->memberKeywordIds($siteId, $nonEmpty[0]);
            sort($existingIds, SORT_NUMERIC);
            if ($existingIds === $requestedIds) {
                return ['already_applied' => true, 'conflict' => false];
            }
        }

        return ['already_applied' => false, 'conflict' => true];
    }

    /**
     * @param  list<int>  $requestedIds
     */
    private function clusterFromRequestedMembers(
        ApplyTopicClusterProposalInput $input,
        array $requestedIds,
        KeywordClusterProposalResult $currentPreview,
    ): KeywordClusterProposalCluster {
        $current = $this->findCurrentProposalByMemberSet($currentPreview, $requestedIds);
        if ($current instanceof KeywordClusterProposalCluster) {
            return $current;
        }

        $members = [];
        foreach ($requestedIds as $keywordId) {
            $members[] = [
                'keyword_id' => $keywordId,
                'phrase' => '',
                'seo_intent' => '',
            ];
        }

        return new KeywordClusterProposalCluster(
            representativeLabel: $input->representativeLabel,
            representativeKeywordId: $input->representativeKeywordId,
            cohesion: 0.0,
            minSimilarity: 0.0,
            memberCount: count($requestedIds),
            members: $members,
            finalStatus: $input->finalStatus,
        );
    }

    /**
     * @param  list<int>  $sortedMemberIds
     */
    private function findCurrentProposalByMemberSet(
        KeywordClusterProposalResult $preview,
        array $sortedMemberIds,
    ): ?KeywordClusterProposalCluster {
        foreach ($preview->proposedClusters as $cluster) {
            if ($this->sortedMemberIds($cluster) === $sortedMemberIds) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $requestedIds
     * @param  list<int>  $resolvedIds
     */
    private function requestedMemberSetIsExactAndInSite(int $siteId, array $requestedIds, array $resolvedIds): bool
    {
        return $requestedIds === $resolvedIds
            && ! $this->hasUnknownOrCrossSiteIds($siteId, $requestedIds);
    }

    /**
     * @param  list<int>  $keywordIds
     */
    private function hasUnknownOrCrossSiteIds(int $siteId, array $keywordIds): bool
    {
        $allowed = array_fill_keys(KeywordClusterSiteScope::keywordIds($siteId), true);
        foreach ($keywordIds as $keywordId) {
            if (! isset($allowed[$keywordId])) {
                return true;
            }
        }

        $states = $this->memberStateLoader->loadForSite($siteId, $keywordIds);

        return count($states) !== count($keywordIds);
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array{
     *     cluster: KeywordClusterProposalCluster,
     *     member_ids: list<int>,
     *     expected_state_hashes: array<int, string>,
     * }
     */
    private function resolvedWithoutLiveCandidateStates(
        KeywordClusterProposalCluster $cluster,
        array $keywordIds,
    ): array {
        $hashes = [];
        foreach ($keywordIds as $keywordId) {
            $hashes[$keywordId] = '';
        }

        return [
            'cluster' => $cluster,
            'member_ids' => $keywordIds,
            'expected_state_hashes' => $hashes,
        ];
    }

    /**
     * @return list<int>
     */
    private function sortedMemberIds(KeywordClusterProposalCluster $cluster): array
    {
        return $this->normalizeKeywordIds(array_map(
            static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
            $cluster->members,
        ));
    }

    /**
     * @param  list<int>  $keywordIds
     * @return list<int>
     */
    private function normalizeKeywordIds(array $keywordIds): array
    {
        $ids = array_values(array_filter(
            $keywordIds,
            static fn (int $id): bool => $id > 0,
        ));
        sort($ids, SORT_NUMERIC);

        return array_values(array_unique($ids));
    }
}
