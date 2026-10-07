<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;

/**
 * Preview + Apply for persisted Topic grouping proposals.
 *
 * Preview and Apply share TopicGroupingApplyPlanBuilder.
 * Apply mutates via TopicReclusterService::persistResolvedClusters only.
 * Never calls semantic HTTP.
 */
final class TopicGroupingApplyService
{
    /**
     * @param  (callable(int): string)|null  $inputHashResolver  test hook
     * @param  (callable(int, list<array<string, mixed>>): array<string, mixed>)|null  $persistClusters  test hook
     */
    public function __construct(
        private readonly TopicGroupingApplyPlanBuilder $planBuilder = new TopicGroupingApplyPlanBuilder,
        private readonly TopicGroupingProposalHydrator $hydrator = new TopicGroupingProposalHydrator,
        private readonly ?TopicGroupingAnalysisService $analysis = null,
        private readonly ?TopicReclusterService $recluster = null,
        private readonly mixed $inputHashResolver = null,
        private readonly mixed $persistClusters = null,
    ) {}

    public function preview(int $runId): TopicGroupingApplyResult
    {
        $run = $this->loadRun($runId);
        if ($run === null) {
            return TopicGroupingApplyResult::invalidState('missing');
        }
        if ($run->status === TopicGroupingRunStatus::APPLIED) {
            return TopicGroupingApplyResult::alreadyApplied(['run_id' => $runId]);
        }
        if ($run->status === TopicGroupingRunStatus::DISCARDED) {
            return TopicGroupingApplyResult::invalidState(TopicGroupingRunStatus::DISCARDED);
        }
        if ($run->status === TopicGroupingRunStatus::FAILED) {
            return TopicGroupingApplyResult::invalidState(TopicGroupingRunStatus::FAILED);
        }
        if ($run->status === TopicGroupingRunStatus::APPLY_FAILED) {
            // Allow re-preview after apply failure if proposal payload still present.
        } elseif ($run->status !== TopicGroupingRunStatus::PROPOSAL_READY
            && $run->status !== TopicGroupingRunStatus::STALE) {
            return TopicGroupingApplyResult::invalidState((string) $run->status);
        }

        $freshness = $this->checkInputFreshness($run);
        if ($freshness !== null) {
            return $freshness;
        }

        $proposal = $this->hydrate($run);
        if ($proposal === null) {
            return TopicGroupingApplyResult::failed('empty_proposal', 'proposal_payload missing');
        }

        $plan = $this->planBuilder->build((int) $run->site_id, $proposal);
        $this->persistPreviewPlan($run, $plan);

        return TopicGroupingApplyResult::okWithPlan($plan, [
            'run_id' => (int) $run->id,
            'site_id' => (int) $run->site_id,
            'input_hash' => (string) $run->input_hash,
            'plan_hash' => $plan->planHash,
            'counts' => $plan->counts,
        ]);
    }

    /**
     * Apply a previously previewed plan. plan_hash must match rebuild against current business state.
     */
    public function apply(int $runId, string $expectedPlanHash): TopicGroupingApplyResult
    {
        if ($expectedPlanHash === '') {
            return TopicGroupingApplyResult::failed('plan_hash_required', 'plan_hash required');
        }

        $conn = DB::connection('omi_seo_ai');

        try {
            /** @var TopicGroupingApplyResult $preflight */
            $preflight = $conn->transaction(function () use ($runId, $expectedPlanHash) {
                /** @var SeoTopicGroupingRun|null $run */
                $run = SeoTopicGroupingRun::query()->whereKey($runId)->lockForUpdate()->first();
                if ($run === null) {
                    return TopicGroupingApplyResult::invalidState('missing');
                }

                if ($run->status === TopicGroupingRunStatus::APPLIED) {
                    return TopicGroupingApplyResult::alreadyApplied([
                        'run_id' => (int) $run->id,
                        'applied_at' => (string) ($run->applied_at ?? ''),
                    ]);
                }

                if ($run->status === TopicGroupingRunStatus::APPLYING) {
                    return TopicGroupingApplyResult::invalidState(TopicGroupingRunStatus::APPLYING);
                }

                if ($run->status !== TopicGroupingRunStatus::PROPOSAL_READY
                    && $run->status !== TopicGroupingRunStatus::APPLY_FAILED) {
                    return TopicGroupingApplyResult::invalidState((string) $run->status);
                }

                $freshness = $this->checkInputFreshness($run);
                if ($freshness !== null) {
                    return $freshness;
                }

                $proposal = $this->hydrate($run);
                if ($proposal === null) {
                    return TopicGroupingApplyResult::failed('empty_proposal', 'proposal_payload missing');
                }

                $plan = $this->planBuilder->build((int) $run->site_id, $proposal);
                if (! hash_equals($expectedPlanHash, $plan->planHash)) {
                    $run->status = TopicGroupingRunStatus::STALE;
                    $run->apply_error_code = 'plan_hash_mismatch';
                    $run->apply_error_message = 'Business state changed since preview';
                    $run->save();

                    return TopicGroupingApplyResult::stale(
                        'plan_hash_mismatch',
                        'Business state changed since preview — re-preview required',
                        $plan,
                    );
                }

                if ($plan->isHardBlocked()) {
                    $run->apply_error_code = 'business_state_hard_block';
                    $run->apply_error_message = 'Unresolved Topic-owned business state — review required';
                    $run->apply_plan_payload = $this->compactPlanPayload($plan);
                    $run->plan_hash = $plan->planHash;
                    $run->save();

                    return TopicGroupingApplyResult::failed(
                        'business_state_hard_block',
                        'Unresolved Topic-owned business state — review required before Apply',
                        $plan,
                    );
                }

                $run->status = TopicGroupingRunStatus::APPLYING;
                $run->plan_hash = $plan->planHash;
                $run->apply_plan_payload = $this->compactPlanPayload($plan);
                $run->apply_error_code = null;
                $run->apply_error_message = null;
                $run->save();

                TopicReclusterUiState::markApplying((int) $run->site_id, $run);

                // Tag migrations before dissolve deletes assignments.
                $this->executeMetadataMigrations((int) $run->site_id, $plan);

                $written = is_callable($this->persistClusters)
                    ? (array) ($this->persistClusters)((int) $run->site_id, $plan->resolvedClusters)
                    : $this->recluster()->persistResolvedClusters(
                        (int) $run->site_id,
                        $plan->resolvedClusters,
                        false,
                    );

                /** @var array<string, int> $topicIdsByGroupKey */
                $topicIdsByGroupKey = [];
                foreach (($written['topic_ids_by_group_key'] ?? []) as $gk => $tid) {
                    $key = trim((string) $gk);
                    $id = (int) $tid;
                    if ($key !== '' && $id > 0) {
                        $topicIdsByGroupKey[$key] = $id;
                    }
                }

                // MCP exclusion propagation after create/reuse IDs exist — by group_key only.
                $this->executePolicyMigrations((int) $run->site_id, $plan, $topicIdsByGroupKey);

                $run->status = TopicGroupingRunStatus::APPLIED;
                $run->applied_at = now();
                $run->completed_at = now();
                $run->save();

                $metrics = array_merge($written, [
                    'run_id' => (int) $run->id,
                    'site_id' => (int) $run->site_id,
                    'plan_hash' => $plan->planHash,
                    'counts' => $plan->counts,
                    'business_state' => $plan->businessState['summary'] ?? [],
                ]);

                TopicReclusterUiState::markApplied((int) $run->site_id, $run, $metrics);

                return TopicGroupingApplyResult::okWithPlan($plan, $metrics);
            });

            return $preflight;
        } catch (\Throwable $e) {
            $this->markApplyFailed($runId, 'apply_exception', $e->getMessage());

            return TopicGroupingApplyResult::failed('apply_exception', $e->getMessage());
        }
    }

    public function discard(int $runId): TopicGroupingApplyResult
    {
        $run = $this->loadRun($runId);
        if ($run === null) {
            return TopicGroupingApplyResult::invalidState('missing');
        }
        if ($run->status === TopicGroupingRunStatus::APPLIED) {
            return TopicGroupingApplyResult::alreadyApplied(['run_id' => $runId]);
        }
        if ($run->status === TopicGroupingRunStatus::APPLYING) {
            return TopicGroupingApplyResult::invalidState(TopicGroupingRunStatus::APPLYING);
        }

        $run->status = TopicGroupingRunStatus::DISCARDED;
        $run->completed_at = now();
        $run->save();

        TopicReclusterUiState::markDiscarded((int) $run->site_id, $run);

        return TopicGroupingApplyResult::okWithPlan(
            new TopicGroupingApplyPlan('', [], [], [], [], [], [], [], ''),
            ['run_id' => (int) $run->id, 'status' => TopicGroupingRunStatus::DISCARDED],
        );
    }

    private function hydrate(SeoTopicGroupingRun $run): ?TopicGroupingProposal
    {
        $payload = $run->proposal_payload;
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return $this->hydrator->fromPayload($payload);
    }

    private function checkInputFreshness(SeoTopicGroupingRun $run): ?TopicGroupingApplyResult
    {
        if ($run->input_hash === '' || $run->input_hash === null) {
            return TopicGroupingApplyResult::stale('missing_input_hash', 'Run missing input_hash');
        }

        try {
            $current = is_callable($this->inputHashResolver)
                ? (string) ($this->inputHashResolver)((int) $run->site_id)
                : $this->analysis()->currentInputHash((int) $run->site_id);
        } catch (\Throwable $e) {
            return TopicGroupingApplyResult::failed('input_hash_unavailable', $e->getMessage());
        }

        if (! hash_equals((string) $run->input_hash, $current)) {
            $run->status = TopicGroupingRunStatus::STALE;
            $run->save();

            return TopicGroupingApplyResult::stale(
                'input_hash_mismatch',
                'Keyword/input changed since analysis — re-analyze required',
            );
        }

        return null;
    }

    private function persistPreviewPlan(SeoTopicGroupingRun $run, TopicGroupingApplyPlan $plan): void
    {
        $run->plan_hash = $plan->planHash;
        $run->apply_plan_payload = $this->compactPlanPayload($plan);
        $run->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function compactPlanPayload(TopicGroupingApplyPlan $plan): array
    {
        return [
            'plan_hash' => $plan->planHash,
            'business_snapshot_hash' => $plan->businessSnapshotHash,
            'counts' => $plan->counts,
            'topic_actions' => $plan->topicActions,
            'keyword_actions' => array_values(array_filter(
                $plan->keywordActions,
                static fn (array $a): bool => $a['action'] !== 'keep',
            )),
            'protected_topics' => $plan->protectedTopics,
            'protected_keywords' => $plan->protectedKeywords,
            'warnings' => $plan->warnings,
            'identity_migration' => [
                'existing_topics' => $plan->identityMigration['existing_topics'] ?? null,
                'semantic_groups' => $plan->identityMigration['semantic_groups'] ?? null,
                'reused_ids' => $plan->identityMigration['reused_ids'] ?? null,
                'new_ids' => $plan->identityMigration['new_ids'] ?? null,
                'dissolved_ids' => $plan->identityMigration['dissolved_ids'] ?? null,
                'one_to_one' => array_slice($plan->identityMigration['one_to_one'] ?? [], 0, 40),
                'splits' => array_slice($plan->identityMigration['splits'] ?? [], 0, 30),
                'merges' => array_slice($plan->identityMigration['merges'] ?? [], 0, 30),
                'ambiguous' => array_slice($plan->identityMigration['ambiguous'] ?? [], 0, 20),
                'no_successor' => array_slice($plan->identityMigration['no_successor'] ?? [], 0, 40),
                'topics_with_focus_keywords_changing_identity' => $plan->identityMigration['topics_with_focus_keywords_changing_identity'] ?? 0,
                'thresholds' => $plan->identityMigration['thresholds'] ?? [],
            ],
            'business_state' => [
                'summary' => $plan->businessState['summary'] ?? [],
                'metadata_migrations' => $plan->businessState['metadata_migrations'] ?? [],
                'policy_migrations' => $plan->businessState['policy_migrations'] ?? [],
                'metadata_review_required' => $plan->businessState['metadata_review_required'] ?? [],
                'hard_block' => (bool) ($plan->businessState['hard_block'] ?? false),
            ],
        ];
    }

    private function executeMetadataMigrations(int $siteId, TopicGroupingApplyPlan $plan): void
    {
        $tags = app(TopicUserTagService::class);
        foreach ($plan->businessState['metadata_migrations'] ?? [] as $row) {
            if (! is_array($row) || ($row['type'] ?? '') !== 'tag_reassign') {
                continue;
            }
            $tags->reassignTag(
                $siteId,
                (int) ($row['from_topic_id'] ?? 0),
                (int) ($row['to_topic_id'] ?? 0),
                (int) ($row['tag_id'] ?? 0),
                (string) ($row['source'] ?? 'manual'),
            );
        }
    }

    /**
     * @param  array<string, int>  $topicIdsByGroupKey  actual persistence map (group_key → topic_id)
     */
    private function executePolicyMigrations(
        int $siteId,
        TopicGroupingApplyPlan $plan,
        array $topicIdsByGroupKey,
    ): void {
        if (! TopicMcpExclusionService::columnReady()) {
            return;
        }
        $mcp = app(TopicMcpExclusionService::class);
        foreach ($plan->businessState['policy_migrations'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if ($type === 'mcp_exclude') {
                $topicId = (int) ($row['topic_id'] ?? 0);
                if ($topicId <= 0) {
                    throw new \RuntimeException('mcp_policy_missing_topic_id');
                }
                $mcp->exclude($siteId, $topicId);
                continue;
            }
            if ($type === 'mcp_exclude_group') {
                $groupKey = trim((string) ($row['group_key'] ?? ''));
                if ($groupKey === '') {
                    throw new \RuntimeException('mcp_policy_missing_group_key');
                }
                $topicId = (int) ($topicIdsByGroupKey[$groupKey] ?? 0);
                if ($topicId <= 0) {
                    throw new \RuntimeException('mcp_policy_group_key_unresolved:'.$groupKey);
                }
                $mcp->exclude($siteId, $topicId);
            }
        }
    }

    private function markApplyFailed(int $runId, string $code, string $message): void
    {
        try {
            /** @var SeoTopicGroupingRun|null $run */
            $run = SeoTopicGroupingRun::query()->find($runId);
            if ($run === null) {
                return;
            }
            if ($run->status === TopicGroupingRunStatus::APPLIED) {
                return;
            }
            $run->status = TopicGroupingRunStatus::APPLY_FAILED;
            $run->apply_error_code = $code;
            $run->apply_error_message = mb_substr($message, 0, 2000);
            $run->save();
            TopicReclusterUiState::markApplyFailed((int) $run->site_id, $run, $message, $code);
        } catch (\Throwable) {
            // best-effort diagnostics after rollback
        }
    }

    private function loadRun(int $runId): ?SeoTopicGroupingRun
    {
        if ($runId <= 0 || ! TopicGroupingAnalysisService::runsTableReady()) {
            return null;
        }

        /** @var SeoTopicGroupingRun|null $run */
        $run = SeoTopicGroupingRun::query()->find($runId);

        return $run;
    }

    private function analysis(): TopicGroupingAnalysisService
    {
        return $this->analysis ?? app(TopicGroupingAnalysisService::class);
    }

    private function recluster(): TopicReclusterService
    {
        return $this->recluster ?? app(TopicReclusterService::class);
    }
}
