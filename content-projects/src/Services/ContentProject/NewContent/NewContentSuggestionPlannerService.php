<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeResult;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptChunkLedger;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAllocator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * AI New Content planning → Draft create items.
 * Does NOT call legacy KeywordPersistence or CreateArticlesFromTask writers.
 * Does NOT generate articles / publishing tasks.
 */
final class NewContentSuggestionPlannerService
{
    private int $logicalDiscoveryCalls = 0;

    private ?int $lastDiscoveryPromptResultId = null;

    public function __construct(
        private readonly PromptHookCallerBridge $promptHookBridge,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly NewContentSuggestionContextBuilder $contextBuilder,
        private readonly NewContentSuggestionParser $parser,
        private readonly NewContentSuggestionDedupFilter $dedup,
        private readonly ContentProjectItemAllocator $allocator,
        private readonly ContentProjectPlannerRunService $plannerRuns,
        private readonly SitePrimaryLanguageService $primaryLanguage,
    ) {}

    public function logicalDiscoveryCallCount(): int
    {
        return $this->logicalDiscoveryCalls;
    }

    public function resetLogicalDiscoveryCallCount(): void
    {
        $this->logicalDiscoveryCalls = 0;
        $this->lastDiscoveryPromptResultId = null;
    }

    /**
     * Import Draft create items from an existing planner run's PromptResult.
     * 0 AI calls. Does not mutate historical result_summary on the run.
     *
     * @return array<string, mixed>
     */
    public function importFromExistingRun(SeoProject $project, SeoContentProjectPlannerRun $run, ?int $actorId = null): array
    {
        if (! $project->isDraftPlanning()) {
            throw new InvalidArgumentException('Import AI suggestions into a Draft project.');
        }

        if ((int) $run->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException('Planner run does not belong to this Draft.');
        }

        if ((string) $run->source_type !== SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT) {
            throw new InvalidArgumentException('Only AI New Content planner runs can be imported.');
        }

        $promptResultId = (int) ($run->prompt_result_id ?? 0);
        if ($promptResultId <= 0) {
            throw new InvalidArgumentException('Planner run has no PromptResult to import.');
        }

        $promptResult = PromptResult::query()->find($promptResultId);
        if (! $promptResult instanceof PromptResult) {
            throw new InvalidArgumentException('PromptResult #'.$promptResultId.' was not found.');
        }

        $raw = trim((string) ($promptResult->output_text ?? ''));
        if ($raw === '') {
            throw new InvalidArgumentException('PromptResult #'.$promptResultId.' has empty output.');
        }

        $decoded = NewContentSuggestionStructuredResult::decode($raw);
        if (! $decoded['ok']) {
            throw new InvalidArgumentException(
                'PromptResult #'.$promptResultId.' is not importable JSON ('.(string) ($decoded['code'] ?? 'invalid').'): '
                .(string) ($decoded['error'] ?? 'decode failed'),
            );
        }
        $value = $decoded['value'];

        $snapshot = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
        $site = $this->resolveSiteForRun($project, $snapshot);
        $options = NewContentSuggestionOptions::fromSnapshot($snapshot);
        $language = (string) ($snapshot['primary_language'] ?? $this->requirePrimaryLanguage($site));
        $requested = max(1, (int) ($run->requested_quantity ?: $options['quantity'] ?: 20));

        $context = $this->contextBuilder->build($project, $site, $options, $language);
        $parsed = $this->parser->parse($value, $requested);
        $filtered = $this->dedup->filter(
            $parsed['candidates'],
            $context['planned_fingerprints'],
            $context['rejected_fingerprints'],
            $context['covered_keyword_norms'] ?? $context['existing_keywords'] ?? [],
            is_array($context['planned_keyword_norms'] ?? null) ? $context['planned_keyword_norms'] : [],
        );

        $taskIds = $this->persistCreateItems(
            $project,
            $site,
            $filtered['accepted'],
            NewContentSuggestionOptions::taskPostType((string) ($options['post_type'] ?? $options['content_type'] ?? 'post')),
            (int) $run->getKey(),
            $actorId,
        );

        $breakdown = is_array($filtered['duplicate_breakdown'] ?? null)
            ? $filtered['duplicate_breakdown']
            : ['in_batch' => 0, 'in_batch_keyword' => 0, 'active_draft' => 0, 'covered_content' => 0];

        return [
            'imported' => true,
            'logical_ai_calls' => 0,
            'planner_run_id' => (int) $run->getKey(),
            'prompt_result_id' => $promptResultId,
            'requested' => $requested,
            'parsed' => count($parsed['candidates']),
            'added' => count($taskIds),
            'duplicate_skipped' => (int) $filtered['duplicate_skipped'],
            'rejected_skipped' => (int) $filtered['rejected_skipped'],
            'invalid' => (int) $parsed['invalid'],
            'duplicate_breakdown' => $breakdown,
            'task_ids' => $taskIds,
            'candidates' => array_slice($filtered['results'], 0, 100),
            'message' => sprintf(
                'Imported from PromptResult #%d · %d added · %d duplicates skipped · %d rejected · %d invalid (0 AI calls)',
                $promptResultId,
                count($taskIds),
                (int) $filtered['duplicate_skipped'],
                (int) $filtered['rejected_skipped'],
                (int) $parsed['invalid'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function queueGeneration(SeoProject $project, Site $site, array $options, ?int $actorId): array
    {
        if (! $project->isDraftPlanning()) {
            throw new InvalidArgumentException('Add AI suggestions to a Draft project.');
        }

        $language = $this->requirePrimaryLanguage($site);
        $normalized = NewContentSuggestionOptions::normalize($options);
        $snapshot = NewContentSuggestionOptions::snapshot($normalized, $language, (int) $site->getKey());

        $active = $this->plannerRuns->findActive(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
        );
        if ($active instanceof SeoContentProjectPlannerRun) {
            return [
                'queued' => true,
                'already_active' => true,
                'planner_run_id' => (int) $active->getKey(),
                'requested' => (int) ($active->requested_quantity ?? $normalized['quantity']),
                'status' => (string) (($active->result_summary ?? [])['status'] ?? 'queued'),
            ];
        }

        $run = $this->plannerRuns->recordQueued(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
            $normalized['quantity'],
            $snapshot,
            $actorId,
        );

        return [
            'queued' => true,
            'already_active' => false,
            'planner_run_id' => (int) $run->getKey(),
            'requested' => $normalized['quantity'],
            'status' => SeoContentProjectPlannerRun::STATUS_QUEUED,
            'primary_language' => $language,
            'site_id' => (int) $site->getKey(),
            'options' => $normalized,
        ];
    }

    /**
     * Queue a follow-up run for remaining slots after a partial planner run.
     * Reuses snapshot; quantity = remaining. Existing Draft fingerprints stay excluded via context.
     *
     * @return array<string, mixed>
     */
    public function queueFillRemaining(
        SeoProject $project,
        Site $site,
        SeoContentProjectPlannerRun $partialRun,
        ?int $actorId,
    ): array {
        if (! $project->isDraftPlanning()) {
            throw new InvalidArgumentException('Add AI suggestions to a Draft project.');
        }
        if ((int) $partialRun->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException('Planner run does not belong to this Draft.');
        }

        $summary = is_array($partialRun->result_summary) ? $partialRun->result_summary : [];
        $status = (string) ($summary['status'] ?? '');
        if ($status !== SeoContentProjectPlannerRun::STATUS_PARTIAL) {
            throw new InvalidArgumentException('Only partial planner runs can be filled.');
        }

        $requested = max(0, (int) ($summary['requested'] ?? $partialRun->requested_quantity ?? 0));
        $added = max(0, (int) ($summary['added'] ?? 0));
        $remaining = max(0, (int) ($summary['remaining'] ?? ($requested - $added)));
        if ($remaining <= 0) {
            throw new InvalidArgumentException('No remaining suggestions to fill.');
        }

        $snapshot = is_array($partialRun->configuration_snapshot) ? $partialRun->configuration_snapshot : [];
        $snapshot['quantity'] = $remaining;
        $snapshot['fill_remaining_of_run_id'] = (int) $partialRun->getKey();
        $snapshot['fill_target_total'] = $requested;
        // Quantity-only fill: avoid re-expanding full DNA pool past remaining.
        $snapshot['note_items'] = [];

        return $this->queueGeneration($project, $site, NewContentSuggestionOptions::fromSnapshot($snapshot), $actorId);
    }

    /**
     * Execute a previously queued planner run (job path). Actor must be explicit — no session user lookup.
     *
     * @return array<string, mixed>
     */
    public function executeQueuedRun(int $plannerRunId, int $actorId): array
    {
        $run = SeoContentProjectPlannerRun::query()->find($plannerRunId);
        if (! $run instanceof SeoContentProjectPlannerRun) {
            throw new RuntimeException('Planner run not found.');
        }

        $project = SeoProject::query()->find((int) $run->project_id);
        if (! $project instanceof SeoProject) {
            throw new RuntimeException('Project not found for planner run.');
        }

        $this->resetLogicalDiscoveryCallCount();
        $prior = is_array($run->result_summary) ? $run->result_summary : [];
        if ((string) ($prior['status'] ?? '') === SeoContentProjectPlannerRun::STATUS_CANCELLED) {
            return array_merge($prior, [
                'needs_continuation' => false,
                'status' => SeoContentProjectPlannerRun::STATUS_CANCELLED,
                'message' => (string) ($prior['message'] ?? 'Đã hủy'),
            ]);
        }
        $isContinuation = ! empty($prior['auto_continuation'])
            || in_array((string) ($prior['status'] ?? ''), [
                SeoContentProjectPlannerRun::STATUS_RECOVERING,
                SeoContentProjectPlannerRun::STATUS_WAITING_RETRY,
            ], true);
        if (! $isContinuation) {
            $this->plannerRuns->markStatus($run, SeoContentProjectPlannerRun::STATUS_RUNNING);
        } else {
            $this->plannerRuns->markStatus($run, SeoContentProjectPlannerRun::STATUS_RECOVERING);
        }

        try {
            $summary = $this->executeAgainstRun($project, $run, $actorId > 0 ? $actorId : null);
            $this->plannerRuns->completeRun(
                $run,
                $summary,
                isset($summary['prompt_result_id']) ? (int) $summary['prompt_result_id'] : null,
            );

            return $summary;
        } catch (Throwable $e) {
            $promptResultId = $this->extractPromptResultId($e);
            $safe = $this->failureSummary($run, $e);
            $this->plannerRuns->failRun($run, $safe, $promptResultId);

            return $safe;
        }
    }

    /**
     * Schedule another execution slice for the same logical run (automatic path).
     * Idempotent via ShouldBeUnique on the job + active status gate.
     *
     * @return array{queued: bool, planner_run_id: int, delay_seconds: int, remaining: int}
     */
    public function queueContinuation(SeoContentProjectPlannerRun $run, int $actorId, int $delaySeconds = 2): array
    {
        $run->refresh();
        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        if ((string) ($summary['status'] ?? '') === SeoContentProjectPlannerRun::STATUS_CANCELLED) {
            return [
                'queued' => false,
                'planner_run_id' => (int) $run->getKey(),
                'delay_seconds' => 0,
                'remaining' => max(0, (int) ($summary['remaining'] ?? 0)),
            ];
        }
        $remaining = max(0, (int) ($summary['remaining'] ?? 0));
        $requested = max(0, (int) ($summary['requested'] ?? $run->requested_quantity ?? 0));
        $added = max(0, (int) ($summary['added'] ?? 0));
        if ($remaining <= 0) {
            $remaining = max(0, $requested - $added);
        }
        if ($remaining <= 0) {
            return [
                'queued' => false,
                'planner_run_id' => (int) $run->getKey(),
                'delay_seconds' => 0,
                'remaining' => 0,
            ];
        }

        $summary['auto_continuation'] = true;
        $summary['needs_continuation'] = true;
        $summary['status'] = ($summary['status'] ?? '') === SeoContentProjectPlannerRun::STATUS_WAITING_RETRY
            ? SeoContentProjectPlannerRun::STATUS_WAITING_RETRY
            : SeoContentProjectPlannerRun::STATUS_RECOVERING;
        $summary['continuation_slices_used'] = max(0, (int) ($summary['continuation_slices_used'] ?? 0));
        $run->result_summary = $summary;
        $run->save();

        return [
            'queued' => true,
            'planner_run_id' => (int) $run->getKey(),
            'delay_seconds' => max(0, $delaySeconds),
            'remaining' => $remaining,
            'actor_id' => $actorId,
            'project_id' => (int) $run->project_id,
        ];
    }

    /**
     * Synchronous execute for dry-run preview / tests that inject a fake bridge.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function generateNow(SeoProject $project, Site $site, array $options, ?int $actorId): array
    {
        $language = $this->requirePrimaryLanguage($site);
        $normalized = NewContentSuggestionOptions::normalize($options);
        $snapshot = NewContentSuggestionOptions::snapshot($normalized, $language, (int) $site->getKey());

        $run = $this->plannerRuns->recordQueued(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
            $normalized['quantity'],
            $snapshot,
            $actorId,
        );
        $this->resetLogicalDiscoveryCallCount();
        $this->plannerRuns->markStatus($run, SeoContentProjectPlannerRun::STATUS_RUNNING);

        try {
            $summary = $this->executeAgainstRun($project, $run, $actorId);
            $this->plannerRuns->completeRun($run, $summary, isset($summary['prompt_result_id']) ? (int) $summary['prompt_result_id'] : null);

            return $summary;
        } catch (Throwable $e) {
            $promptResultId = $this->extractPromptResultId($e);
            $safe = $this->failureSummary($run, $e);
            $this->plannerRuns->failRun($run, $safe, $promptResultId);

            return $safe;
        }
    }

    /**
     * One user-visible planner run; may execute multiple internal model-safe batches.
     *
     * @return array<string, mixed>
     */
    private function executeAgainstRun(SeoProject $project, SeoContentProjectPlannerRun $run, ?int $actorId): array
    {
        $snapshot = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
        $site = $this->resolveSiteForRun($project, $snapshot);
        $options = NewContentSuggestionOptions::fromSnapshot($snapshot);
        $language = (string) ($snapshot['primary_language'] ?? $this->requirePrimaryLanguage($site));
        $noteItems = is_array($options['note_items'] ?? null) ? $options['note_items'] : [];
        $requested = max(1, (int) ($run->requested_quantity ?: $options['quantity']));

        $batchPolicy = new NewContentGenerationBatchPolicy;
        $splitter = new NewContentPlanningSlotSplitter;
        $continuation = new NewContentCrossBatchContinuationPolicy;
        $planning = app(ContentPlanningIntelligenceService::class);

        $baseContext = $this->contextBuilder->build($project, $site, $options, $language);
        $planningCtx = $planning->build($project, $site, $options, $language);
        $plannedFingerprints = is_array($baseContext['planned_fingerprints'] ?? null)
            ? $baseContext['planned_fingerprints']
            : [];
        $plannedKeywordNorms = is_array($baseContext['planned_keyword_norms'] ?? null)
            ? $baseContext['planned_keyword_norms']
            : [];
        $rejectedFingerprints = is_array($baseContext['rejected_fingerprints'] ?? null)
            ? $baseContext['rejected_fingerprints']
            : [];
        $coveredNorms = $baseContext['covered_keyword_norms'] ?? $baseContext['existing_keywords'] ?? [];

        $remainingItems = $noteItems;
        $allTaskIds = [];
        $allResults = [];
        $acceptedCompact = [];
        $generatedTotal = 0;
        $validTotal = 0;
        $invalidTotal = 0;
        $duplicateSkipped = 0;
        $rejectedSkipped = 0;
        $breakdown = ['in_batch' => 0, 'in_batch_keyword' => 0, 'active_draft' => 0, 'covered_content' => 0];
        $lastPromptResultId = null;
        $contentType = NewContentSuggestionOptions::normalizeContentType(
            (string) ($options['post_type'] ?? $options['content_type'] ?? 'post'),
        );
        $postType = NewContentSuggestionOptions::taskPostType((string) $options['post_type']);
        $diagnostics = is_array($baseContext['diagnostics'] ?? null) ? $baseContext['diagnostics'] : [];

        $summarySeed = is_array($run->result_summary) ? $run->result_summary : [];
        $isResume = ! empty($summarySeed['auto_continuation'])
            || in_array((string) ($summarySeed['status'] ?? ''), [
                SeoContentProjectPlannerRun::STATUS_RECOVERING,
                SeoContentProjectPlannerRun::STATUS_WAITING_RETRY,
            ], true);
        if ($isResume) {
            $priorTaskIds = is_array($summarySeed['task_ids'] ?? null) ? $summarySeed['task_ids'] : [];
            foreach ($priorTaskIds as $tid) {
                $tid = (int) $tid;
                if ($tid > 0) {
                    $allTaskIds[] = $tid;
                }
            }
            $generatedTotal = max(0, (int) ($summarySeed['generated'] ?? 0));
            $validTotal = max(0, (int) ($summarySeed['valid'] ?? 0));
            $invalidTotal = max(0, (int) ($summarySeed['invalid'] ?? 0));
            $duplicateSkipped = max(0, (int) ($summarySeed['duplicate_skipped'] ?? 0));
            $rejectedSkipped = max(0, (int) ($summarySeed['rejected_skipped'] ?? 0));
            if (is_array($summarySeed['duplicate_breakdown'] ?? null)) {
                foreach (['in_batch', 'in_batch_keyword', 'active_draft', 'covered_content'] as $key) {
                    $breakdown[$key] = max(0, (int) ($summarySeed['duplicate_breakdown'][$key] ?? 0));
                }
            }
            if (is_array($summarySeed['remaining_note_items'] ?? null)) {
                $remainingItems = AuditNoteDnaNormalizer::normalizeNoteItems($summarySeed['remaining_note_items']);
            }
            if (is_array($summarySeed['accepted_compact'] ?? null)) {
                $acceptedCompact = $summarySeed['accepted_compact'];
            }
        }

        $chunkLedger = PromptChunkLedger::fromMetadata([
            'chunk_ledger' => is_array($summarySeed['chunk_ledger'] ?? null) ? $summarySeed['chunk_ledger'] : [],
        ]);
        $chunkLedger->setRun('planner-'.$run->getKey(), 'keyword.discovery.structured');
        foreach ($chunkLedger->acceptedIdentities() as $existingFp) {
            if ($existingFp !== '') {
                $plannedFingerprints[$existingFp] = true;
            }
        }

        $resolvedExecutionProfile = app(PromptExecutionProfileResolver::class)
            ->resolve(null, 'keyword.discovery.structured')
            ->value;
        $this->plannerRuns->markProgress($run, count($allTaskIds), $requested, [
            'chunk_ledger' => $chunkLedger->toArray(),
            // Runtime fingerprint: if live runs still show text.reasoning, queue worker is stale.
            'execution_profile_resolved' => $resolvedExecutionProfile,
            'execution_profile_code_marker' => 'kd_profile_v2_longform',
            'auto_continuation' => $isResume,
        ]);

        $maxRounds = max(1, (int) ceil($requested / NewContentGenerationBatchPolicy::BATCH_FREE_OR_WEAK) + 8);
        $stagnantRounds = 0;
        $batchTrace = is_array($summarySeed['_batch_trace'] ?? null) ? $summarySeed['_batch_trace'] : [];
        $stopReason = null;
        $generationRounds = max(0, (int) ($summarySeed['generation_rounds'] ?? 0));
        $successfulOutputBatches = max(0, (int) ($summarySeed['successful_output_batches'] ?? 0));
        $routingAttemptRounds = max(0, (int) ($summarySeed['routing_attempt_rounds'] ?? 0));
        $recoveryLevel = max(0, (int) ($summarySeed['recovery_level'] ?? 0));
        $escalationsInSlice = 0;
        $continuationSlicesUsed = max(0, (int) ($summarySeed['continuation_slices_used'] ?? 0));
        $providerRetryCycle = max(0, (int) ($summarySeed['provider_retry_cycle'] ?? 0));
        $forcedBatchCap = isset($summarySeed['forced_batch_cap']) ? (int) $summarySeed['forced_batch_cap'] : null;
        $oversampleFactor = (float) ($summarySeed['oversample_factor'] ?? 1.0);
        $needsContinuation = false;
        $continuationDelay = 2;
        $recoveryReason = (string) ($summarySeed['recovery_reason'] ?? NewContentAutoContinuationPolicy::RECOVERY_REASON_TIME_SLICE);
        $sliceStartedAt = microtime(true);

        for ($round = 0; $round < $maxRounds; $round++) {
            $remainingDemand = $this->totalRemainingDemand($remainingItems, $requested - count($allTaskIds));
            if ($remainingDemand <= 0 || count($allTaskIds) >= $requested) {
                $stopReason = NewContentPlannerRunOutcome::STOP_TARGET_MET;
                break;
            }

            $continuationLines = $continuation->instructionLines($acceptedCompact);
            $continuationText = implode("\n", $continuationLines);
            $continuationTokens = app(\Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator::class)
                ->estimate($continuationText);

            $resolved = $batchPolicy->resolveBatchSize(
                $actorId,
                immutableBrief: $planning->renderBrief($planningCtx, array_merge($options, ['quantity' => $remainingDemand])),
                remaining: $remainingDemand,
                continuationTokens: $continuationTokens,
            );
            $batchSize = max(1, (int) $resolved['batch_size']);
            if ($forcedBatchCap !== null && $forcedBatchCap > 0) {
                $batchSize = max(1, min($batchSize, $forcedBatchCap));
            }
            $rawTarget = NewContentAutoContinuationPolicy::oversampleRawTarget(
                $remainingDemand,
                $oversampleFactor,
                max($batchSize, NewContentGenerationBatchPolicy::BATCH_PAID_STANDARD * 2),
            );
            $batchSize = max(1, min($batchSize, $rawTarget));
            $batches = $remainingItems !== []
                ? $splitter->split($remainingItems, $batchSize)
                : [[
                    'batch_index' => 0,
                    'requested' => min($batchSize, $remainingDemand),
                    'note_items' => [],
                ]];
            if ($batches === []) {
                $stopReason = NewContentPlannerRunOutcome::STOP_EMPTY_BATCHES;
                break;
            }

            $batch = $batches[0];
            $batchRequested = max(1, min((int) $batch['requested'], $remainingDemand, $batchSize));
            $batchNoteItems = is_array($batch['note_items'] ?? null) ? $batch['note_items'] : [];

            $batchOptions = $options;
            $batchOptions['quantity'] = $batchRequested;
            $batchOptions['note_items'] = $batchNoteItems;

            $batchBrief = $this->renderBatchBrief(
                $planning,
                $planningCtx,
                $batchOptions,
                $continuationLines,
            );

            $batchAccepted = [];
            $batchError = null;
            $attempts = 0;
            $maxAttempts = 2;
            $roundHadSuccessfulOutput = false;
            while ($attempts < $maxAttempts) {
                $attempts++;
                $routingAttemptRounds++;
                try {
                    $discovery = $this->discoverOnce(
                        seedTopic: $baseContext['seed_topic'],
                        count: $batchRequested,
                        brief: $batchBrief,
                        primaryLanguage: $language,
                        contentType: $contentType,
                        actorId: $actorId,
                        siteId: (int) $site->getKey(),
                        notes: trim((string) ($options['notes'] ?? '')),
                        noteItems: $batchNoteItems,
                    );
                    $lastPromptResultId = $discovery['prompt_result_id'] ?? $lastPromptResultId;
                    $roundHadSuccessfulOutput = true;

                    $parsed = $this->parser->parse($discovery['value'], $batchRequested);
                    $generatedTotal += (int) $parsed['generated'];
                    $validTotal += count($parsed['candidates']);
                    $invalidTotal += (int) $parsed['invalid'];

                    $filtered = $this->dedup->filter(
                        $parsed['candidates'],
                        $plannedFingerprints,
                        $rejectedFingerprints,
                        is_array($coveredNorms) ? $coveredNorms : [],
                        $plannedKeywordNorms,
                    );
                    $duplicateSkipped += (int) $filtered['duplicate_skipped'];
                    $rejectedSkipped += (int) $filtered['rejected_skipped'];
                    foreach (['in_batch', 'in_batch_keyword', 'active_draft', 'covered_content'] as $key) {
                        $breakdown[$key] = (int) ($breakdown[$key] ?? 0)
                            + (int) ($filtered['duplicate_breakdown'][$key] ?? 0);
                    }

                    $batchAccepted = $filtered['accepted'];
                    $taskIds = $this->persistCreateItems(
                        $project,
                        $site,
                        $batchAccepted,
                        $postType,
                        (int) $run->getKey(),
                        $actorId,
                    );
                    $allTaskIds = array_merge($allTaskIds, $taskIds);
                    $allResults = array_merge($allResults, $filtered['results']);

                    foreach ($batchAccepted as $accepted) {
                        $fp = (string) ($accepted['fingerprint'] ?? '');
                        if ($fp !== '') {
                            $chunkLedger->rememberAcceptedIdentity($fp);
                            $plannedFingerprints[$fp] = true;
                        }
                        $kwNorm = NewContentSuggestionIdentity::normalize((string) ($accepted['keyword'] ?? ''));
                        if ($kwNorm !== '') {
                            $plannedKeywordNorms[$kwNorm] = true;
                        }
                    }
                    $acceptedCompact = array_merge(
                        $acceptedCompact,
                        $continuation->compactAccepted($batchAccepted),
                    );
                    if (count($acceptedCompact) > NewContentCrossBatchContinuationPolicy::MAX_FINGERPRINTS) {
                        $acceptedCompact = array_slice(
                            $acceptedCompact,
                            -NewContentCrossBatchContinuationPolicy::MAX_FINGERPRINTS,
                        );
                    }

                    $batchError = null;
                    break;
                } catch (Throwable $e) {
                    $batchError = $e;
                    if ($attempts >= $maxAttempts) {
                        break;
                    }
                }
            }

            $generationRounds++;
            $acceptedCount = count($batchAccepted);
            if ($roundHadSuccessfulOutput) {
                $successfulOutputBatches++;
            }

            $batchChunkId = 'kd-round-'.($round + 1);
            $batchInputHash = hash('sha256', $batchBrief.'|'.$batchRequested);
            if ($batchError === null) {
                $chunkLedger->planChunk($batchChunkId, $batchInputHash, $round);
                $chunkLedger->markCompleted($batchChunkId, json_encode([
                    'accepted' => $acceptedCount,
                    'fingerprints' => array_values(array_filter(array_map(
                        static fn (array $row): string => (string) ($row['fingerprint'] ?? ''),
                        $batchAccepted,
                    ))),
                ], JSON_THROW_ON_ERROR));
            } else {
                $chunkLedger->planChunk($batchChunkId, $batchInputHash, $round);
                $chunkLedger->markFailed($batchChunkId);
            }

            $batchTrace[] = [
                'round' => $round + 1,
                'target' => $batchRequested,
                'requested' => $batchRequested,
                'accepted' => $acceptedCount,
                'total_accepted' => count($allTaskIds),
                'requested_total' => $requested,
                'model_class' => $resolved['model_class'] ?? null,
                'budget' => is_array($resolved['budget'] ?? null) ? $resolved['budget'] : null,
                'continuation_tokens' => $continuationTokens,
                'failed' => $batchError !== null,
                'successful_output' => $roundHadSuccessfulOutput,
                'provider_exhausted' => $batchError !== null,
            ];

            if ($batchError !== null) {
                if ($this->isDeterministicApplicationError($batchError)) {
                    if ($allTaskIds === []) {
                        throw $batchError;
                    }
                    $stopReason = NewContentPlannerRunOutcome::STOP_AUTO_RECOVERY_EXHAUSTED;
                    break;
                }

                if ($this->isRecoverableStructuredOutputError($batchError)) {
                    $decision = NewContentAutoContinuationPolicy::afterTruncatedRepairFailed($recoveryLevel);
                    $escalationsInSlice++;
                    $recoveryLevel = (int) $decision['recovery_level'];
                    $recoveryReason = (string) $decision['recovery_reason'];
                    $forcedBatchCap = $decision['forced_batch_cap'];
                    $oversampleFactor = (float) $decision['oversample_factor'];
                    if ($decision['rotate_coverage'] && $remainingItems !== []) {
                        $remainingItems = NewContentAutoContinuationPolicy::rotateCoverageSlice($remainingItems);
                    }
                    $stagnantRounds = 0;
                    if ($escalationsInSlice >= NewContentAutoContinuationPolicy::MAX_NO_PROGRESS_ESCALATIONS_PER_SLICE) {
                        $needsContinuation = true;
                        $continuationDelay = 2;
                        $stopReason = null;
                        break;
                    }
                    // Fresh smaller batch on same route/profile — not cross-model fallback.
                    continue;
                }

                $providerDecision = NewContentAutoContinuationPolicy::afterProviderTransient($providerRetryCycle);
                if ($providerDecision['action'] === NewContentAutoContinuationPolicy::ACTION_WAIT_RETRY) {
                    $needsContinuation = true;
                    $continuationDelay = (int) $providerDecision['delay_seconds'];
                    $recoveryReason = NewContentAutoContinuationPolicy::RECOVERY_REASON_PROVIDER_TRANSIENT;
                    $providerRetryCycle++;
                    $stopReason = null;
                    break;
                }
                if ($allTaskIds === []) {
                    throw $batchError;
                }
                $stopReason = NewContentPlannerRunOutcome::STOP_PROVIDER_BATCH_FAILED;
                break;
            }

            if ($remainingItems !== []) {
                $remainingItems = $this->reduceRemainingDemand($remainingItems, $batchNoteItems, $acceptedCount);
            }

            $this->plannerRuns->markProgress($run, count($allTaskIds), $requested, [
                '_batch_trace' => $batchTrace,
                'chunk_ledger' => $chunkLedger->toArray(),
                'generation_rounds' => $generationRounds,
                'successful_output_batches' => $successfulOutputBatches,
                'routing_attempt_rounds' => $routingAttemptRounds,
                'task_ids' => $allTaskIds,
                'remaining_note_items' => $remainingItems,
                'accepted_compact' => $acceptedCompact,
                'auto_continuation' => true,
                'recovery_level' => $recoveryLevel,
                'duplicate_skipped' => $duplicateSkipped,
                'rejected_skipped' => $rejectedSkipped,
                'generated' => $generatedTotal,
                'valid' => $validTotal,
                'invalid' => $invalidTotal,
                'duplicate_breakdown' => $breakdown,
            ]);

            if (count($allTaskIds) >= $requested) {
                $stopReason = NewContentPlannerRunOutcome::STOP_TARGET_MET;
                break;
            }

            if (NewContentAutoContinuationPolicy::shouldYieldForTimeBudget(
                microtime(true) - $sliceStartedAt,
                $requested - count($allTaskIds),
            )) {
                $needsContinuation = true;
                $continuationDelay = 2;
                $recoveryReason = NewContentAutoContinuationPolicy::RECOVERY_REASON_TIME_SLICE;
                $stopReason = null;
                break;
            }

            if ($acceptedCount <= 0) {
                $stagnantRounds++;
                if ($stagnantRounds >= NewContentPlannerRunOutcome::MAX_CONSECUTIVE_NO_PROGRESS) {
                    $decision = NewContentAutoContinuationPolicy::afterNoProgress(
                        $recoveryLevel,
                        $escalationsInSlice,
                        $continuationSlicesUsed,
                    );
                    $escalationsInSlice++;
                    $recoveryLevel = (int) $decision['recovery_level'];
                    $recoveryReason = (string) $decision['recovery_reason'];
                    $forcedBatchCap = $decision['forced_batch_cap'];
                    $oversampleFactor = (float) $decision['oversample_factor'];
                    if ($decision['rotate_coverage'] && $remainingItems !== []) {
                        $remainingItems = NewContentAutoContinuationPolicy::rotateCoverageSlice($remainingItems);
                    }
                    if ($decision['action'] === NewContentAutoContinuationPolicy::ACTION_CONTINUE) {
                        $stagnantRounds = 0;
                        continue;
                    }
                    if ($decision['action'] === NewContentAutoContinuationPolicy::ACTION_YIELD_SLICE) {
                        $needsContinuation = true;
                        $continuationDelay = (int) $decision['delay_seconds'];
                        $stopReason = null;
                        break;
                    }
                    $stopReason = NewContentPlannerRunOutcome::STOP_AUTO_RECOVERY_EXHAUSTED;
                    break;
                }
            } else {
                $stagnantRounds = 0;
                $recoveryLevel = 0;
                $forcedBatchCap = null;
                $oversampleFactor = 1.0;
            }
        }

        if (! $needsContinuation
            && count($allTaskIds) < $requested
            && ($stopReason === null || $stopReason === NewContentPlannerRunOutcome::STOP_MAX_ROUNDS)
            && $continuationSlicesUsed < NewContentAutoContinuationPolicy::MAX_CONTINUATION_SLICES
        ) {
            $needsContinuation = true;
            $continuationDelay = 2;
            $recoveryReason = NewContentAutoContinuationPolicy::RECOVERY_REASON_TIME_SLICE;
            $stopReason = null;
        }

        if ($needsContinuation) {
            $continuationSlicesUsed++;
            $continuing = NewContentPlannerRunOutcome::continuing(
                added: count($allTaskIds),
                requested: $requested,
                phase: $recoveryReason === NewContentAutoContinuationPolicy::RECOVERY_REASON_PROVIDER_TRANSIENT
                    ? NewContentAutoContinuationPolicy::PHASE_WAITING_RETRY
                    : NewContentAutoContinuationPolicy::PHASE_RECOVERING,
                recoveryReason: $recoveryReason,
                duplicateSkipped: $duplicateSkipped,
            );

            return [
                'requested' => $requested,
                'generated' => $generatedTotal,
                'valid' => $validTotal,
                'added' => count($allTaskIds),
                'remaining' => $continuing['remaining'],
                'duplicate_skipped' => $duplicateSkipped,
                'rejected_skipped' => $rejectedSkipped,
                'invalid' => $invalidTotal,
                'duplicate_breakdown' => $breakdown,
                'task_ids' => $allTaskIds,
                'remaining_note_items' => $remainingItems,
                'accepted_compact' => $acceptedCompact,
                'planner_run_id' => (int) $run->getKey(),
                'prompt_result_id' => $lastPromptResultId,
                'logical_ai_calls' => $this->logicalDiscoveryCalls,
                'planning_ai_calls' => 0,
                'generation_rounds' => $generationRounds,
                'successful_output_batches' => $successfulOutputBatches,
                'routing_attempt_rounds' => $routingAttemptRounds,
                'status' => $continuing['status'],
                'completion_kind' => $continuing['completion_kind'],
                'stop_reason' => null,
                'recovery_reason' => $continuing['recovery_reason'],
                'recovery_level' => $recoveryLevel,
                'forced_batch_cap' => $forcedBatchCap,
                'oversample_factor' => $oversampleFactor,
                'continuation_slices_used' => $continuationSlicesUsed,
                'provider_retry_cycle' => $providerRetryCycle,
                'needs_continuation' => true,
                'auto_continuation' => true,
                'continuation_delay_seconds' => $continuationDelay,
                'primary_language' => $language,
                'context_flags' => $baseContext['context_flags'],
                'planning_context' => [
                    'principal_keywords_count' => (int) ($diagnostics['principal_keywords_count'] ?? 0),
                    'cluster_count' => (int) ($diagnostics['cluster_count'] ?? 0),
                    'missing_direction_count' => (int) ($diagnostics['missing_direction_count'] ?? 0),
                    'mcp_period' => $diagnostics['mcp_period'] ?? null,
                ],
                'candidates' => array_slice($allResults, 0, 100),
                'chunk_ledger' => $chunkLedger->toArray(),
                'message' => $continuing['message'],
                'user_message' => $continuing['user_message'],
                '_batch_trace' => $batchTrace,
            ];
        }

        if ($stopReason === null) {
            $stopReason = count($allTaskIds) >= $requested
                ? NewContentPlannerRunOutcome::STOP_TARGET_MET
                : NewContentPlannerRunOutcome::STOP_AUTO_RECOVERY_EXHAUSTED;
        }

        $outcome = NewContentPlannerRunOutcome::resolve(
            added: count($allTaskIds),
            requested: $requested,
            stopReason: $stopReason,
            duplicateSkipped: $duplicateSkipped,
            rejectedSkipped: $rejectedSkipped,
            invalid: $invalidTotal,
        );

        return [
            'requested' => $requested,
            'generated' => $generatedTotal,
            'valid' => $validTotal,
            'added' => count($allTaskIds),
            'remaining' => $outcome['remaining'],
            'duplicate_skipped' => $duplicateSkipped,
            'rejected_skipped' => $rejectedSkipped,
            'invalid' => $invalidTotal,
            'duplicate_breakdown' => $breakdown,
            'task_ids' => $allTaskIds,
            'planner_run_id' => (int) $run->getKey(),
            'prompt_result_id' => $lastPromptResultId,
            'logical_ai_calls' => $this->logicalDiscoveryCalls,
            'planning_ai_calls' => 0,
            'generation_rounds' => $generationRounds,
            'successful_output_batches' => $successfulOutputBatches,
            'routing_attempt_rounds' => $routingAttemptRounds,
            'status' => $outcome['status'],
            'completion_kind' => $outcome['completion_kind'],
            'stop_reason' => $outcome['stop_reason'],
            'needs_continuation' => false,
            'auto_continuation' => false,
            'primary_language' => $language,
            'context_flags' => $baseContext['context_flags'],
            'planning_context' => [
                'principal_keywords_count' => (int) ($diagnostics['principal_keywords_count'] ?? 0),
                'cluster_count' => (int) ($diagnostics['cluster_count'] ?? 0),
                'missing_direction_count' => (int) ($diagnostics['missing_direction_count'] ?? 0),
                'mcp_period' => $diagnostics['mcp_period'] ?? null,
            ],
            'candidates' => array_slice($allResults, 0, 100),
            'chunk_ledger' => $chunkLedger->toArray(),
            'message' => $outcome['user_message'] ?? $outcome['message'],
            'user_message' => $outcome['user_message'] ?? $outcome['message'],
            '_batch_trace' => $batchTrace,
        ];
    }

    private function renderBatchBrief(
        ContentPlanningIntelligenceService $planning,
        array $planningCtx,
        array $batchOptions,
        array $continuationLines,
    ): string {
        $brief = rtrim($planning->renderBrief($planningCtx, $batchOptions));
        if ($continuationLines === []) {
            return $brief;
        }

        return $brief."\n".implode("\n", $continuationLines);
    }

    /**
     * @param  list<array<string, mixed>>  $remainingItems
     * @param  list<array<string, mixed>>  $batchNoteItems
     * @return list<array<string, mixed>>
     */
    private function reduceRemainingDemand(array $remainingItems, array $batchNoteItems, int $acceptedCount): array
    {
        $remainingByRef = [];
        foreach ($remainingItems as $item) {
            $ref = (string) ($item['cluster_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $remainingByRef[$ref] = $item;
        }

        $left = max(0, $acceptedCount);
        foreach ($batchNoteItems as $slice) {
            $ref = (string) ($slice['cluster_ref'] ?? '');
            if ($ref === '' || ! isset($remainingByRef[$ref])) {
                continue;
            }
            $batchTarget = max(0, (int) ($slice['target_dna_count'] ?? 0));
            $fulfilled = min($batchTarget, $left);
            $left -= $fulfilled;
            if ($fulfilled <= 0) {
                continue;
            }

            $current = $remainingByRef[$ref];
            $newTarget = max(0, (int) ($current['target_dna_count'] ?? 0) - $fulfilled);
            if ($newTarget <= 0) {
                unset($remainingByRef[$ref]);

                continue;
            }

            $currentDna = is_array($current['dna'] ?? null) ? $current['dna'] : [];
            $specified = 0;
            foreach ($currentDna as $row) {
                $specified += max(0, (int) ($row['slots'] ?? 0));
            }
            $dnaConsume = min($specified, $fulfilled);
            $current['dna'] = $this->consumeDnaSlots($currentDna, $dnaConsume);
            $current['target_dna_count'] = $newTarget;
            $remainingByRef[$ref] = $current;
        }

        return array_values($remainingByRef);
    }

    /**
     * @param  list<array{phrase?: string, slots?: int, source?: string, placement?: string}>  $dna
     * @return list<array{phrase: string, slots: int, source: string, placement: string}>
     */
    private function consumeDnaSlots(array $dna, int $consume): array
    {
        if ($consume <= 0 || $dna === []) {
            return array_values(array_map(
                static fn (array $row): array => [
                    'phrase' => (string) ($row['phrase'] ?? ''),
                    'slots' => max(0, (int) ($row['slots'] ?? 0)),
                    'source' => (string) ($row['source'] ?? 'manual'),
                    'placement' => AuditNoteDnaNormalizer::normalizePlacement($row['placement'] ?? null),
                ],
                $dna,
            ));
        }

        $remaining = [];
        $left = $consume;
        foreach ($dna as $row) {
            $phrase = trim((string) ($row['phrase'] ?? ''));
            $slots = max(0, (int) ($row['slots'] ?? 0));
            $source = (string) ($row['source'] ?? 'manual');
            $placement = AuditNoteDnaNormalizer::normalizePlacement($row['placement'] ?? null);
            if ($phrase === '' || $slots <= 0) {
                continue;
            }
            if ($left <= 0) {
                $remaining[] = ['phrase' => $phrase, 'slots' => $slots, 'source' => $source, 'placement' => $placement];

                continue;
            }
            if ($slots <= $left) {
                $left -= $slots;

                continue;
            }
            $remaining[] = ['phrase' => $phrase, 'slots' => $slots - $left, 'source' => $source, 'placement' => $placement];
            $left = 0;
        }

        return $remaining;
    }

    /**
     * @param  list<array<string, mixed>>  $remainingItems
     */
    private function totalRemainingDemand(array $remainingItems, int $fallback): int
    {
        if ($remainingItems === []) {
            return max(0, $fallback);
        }
        $sum = 0;
        foreach ($remainingItems as $item) {
            $sum += max(0, (int) ($item['target_dna_count'] ?? 0));
        }

        return max(0, $sum);
    }

    /**
     * Discovery + at most one structured-output repair. Primary call remains the planning generation.
     *
     * @param  list<array<string, mixed>>  $noteItems
     * @return array{value: mixed, prompt_result_id: int|null}
     */
    private function discoverOnce(
        string $seedTopic,
        int $count,
        string $brief,
        string $primaryLanguage,
        string $contentType,
        ?int $actorId,
        int $siteId,
        string $notes = '',
        array $noteItems = [],
    ): array {
        $this->logicalDiscoveryCalls++;

        $contentType = in_array($contentType, ['post', 'product'], true) ? $contentType : 'post';
        $quantity = (string) max(1, $count);
        $notesValue = trim($notes);
        $policy = new NewContentAutoDnaPolicy;
        $automationMeta = $policy->appliesTo($noteItems)
            ? $policy->metadata(max(1, $count), $noteItems)
            : [];

        // Hook schema: seed_topic/count/brief/…
        // Content Planning Assistant (Prompt Management): requested_quantity/planning_context/notes/…
        $legacyVariables = [
            'seed_topic' => $seedTopic,
            'count' => $quantity,
            'keyword_count' => $quantity,
            'requested_quantity' => $quantity,
            'brief' => $brief,
            'user_brief' => $brief,
            'planning_context' => $brief,
            'notes' => $notesValue,
            'primary_language' => $primaryLanguage,
            'post_type' => $contentType,
            'content_type' => $contentType,
        ];
        if ($automationMeta !== []) {
            $legacyVariables['_automation_policy'] = json_encode($automationMeta, JSON_UNESCAPED_UNICODE);
            $legacyVariables['_auto_dna_version'] = (string) ($automationMeta['auto_dna_version'] ?? NewContentAutoDnaPolicy::VERSION);
        }

        $first = $this->runDiscoveryPrompt(
            $legacyVariables,
            $seedTopic,
            max(1, $count),
            $brief,
            $primaryLanguage,
            $contentType,
            $actorId,
            $siteId,
            $automationMeta,
        );

        $accepted = $this->acceptStructuredDiscoveryValue($first['value'], max(1, $count));
        if ($accepted !== null) {
            $this->lastDiscoveryPromptResultId = $first['prompt_result_id'];

            return [
                'value' => $accepted,
                'prompt_result_id' => $first['prompt_result_id'],
            ];
        }

        $firstDecoded = NewContentSuggestionStructuredResult::decode($first['value']);
        // Truncated / incomplete JSON — do not import; repair only when enough text exists.
        if (($firstDecoded['code'] ?? '') === NewContentSuggestionStructuredResult::CODE_INCOMPLETE
            && mb_strlen(trim(is_string($first['value']) ? $first['value'] : (string) json_encode($first['value']))) < 40
        ) {
            throw new InvalidArgumentException(
                'Planner structured output incomplete (truncated). '.((string) ($firstDecoded['error'] ?? '')),
            );
        }

        $this->logicalDiscoveryCalls++;
        $invalidRaw = is_string($first['value'])
            ? $first['value']
            : (string) json_encode($first['value'], JSON_UNESCAPED_UNICODE);
        $repairBrief = NewContentSuggestionStructuredResult::repairBrief(
            $invalidRaw,
            $contentType,
            max(1, $count),
        );
        // Preserve cross-batch exclusions — repair previously wiped continuation (run20 PR1117).
        $continuationBlock = NewContentCrossBatchContinuationPolicy::extractBlockFromBrief($brief);
        if ($continuationBlock !== '') {
            $repairBrief = rtrim($repairBrief)."\n\n".$continuationBlock;
        }
        $repairVariables = $legacyVariables;
        $repairVariables['brief'] = $repairBrief;
        $repairVariables['user_brief'] = $repairBrief;
        $repairVariables['planning_context'] = $repairBrief;
        $repairVariables['notes'] = 'REPAIR: return JSON only. Do not add new suggestions.';

        $second = $this->runDiscoveryPrompt(
            $repairVariables,
            $seedTopic,
            max(1, $count),
            $repairBrief,
            $primaryLanguage,
            $contentType,
            $actorId,
            $siteId,
            $automationMeta,
        );

        $repairedAccepted = $this->acceptStructuredDiscoveryValue($second['value'], max(1, $count));
        if ($repairedAccepted === null) {
            $repaired = NewContentSuggestionStructuredResult::decode($second['value']);
            $this->lastDiscoveryPromptResultId = $second['prompt_result_id'] ?? $first['prompt_result_id'];
            throw new InvalidArgumentException(
                'Planner structured output invalid after repair ('.(string) ($repaired['code'] ?? 'invalid').'): '
                .(string) ($repaired['error'] ?? 'decode failed'),
            );
        }

        $this->lastDiscoveryPromptResultId = $second['prompt_result_id'] ?? $first['prompt_result_id'];

        return [
            'value' => $repairedAccepted,
            'prompt_result_id' => $second['prompt_result_id'] ?? $first['prompt_result_id'],
        ];
    }

    /**
     * Decode + importer-schema gate. Returns decoded value or null when repair is warranted.
     */
    private function acceptStructuredDiscoveryValue(mixed $raw, int $requested): mixed
    {
        $decoded = NewContentSuggestionStructuredResult::decode($raw);
        if (! $decoded['ok']) {
            return null;
        }

        $value = $decoded['value'];
        $parsed = $this->parser->parse($value, $requested);
        if (count($parsed['candidates']) > 0) {
            return $value;
        }

        // Valid empty array / empty envelope — not a schema failure.
        if ((int) $parsed['generated'] === 0) {
            return $value;
        }

        // Non-empty payload but zero importable rows → repair formatting once.
        return null;
    }

    /**
     * @param  array<string, mixed>  $legacyVariables
     * @return array{value: mixed, prompt_result_id: int|null}
     */
    private function runDiscoveryPrompt(
        array $legacyVariables,
        string $seedTopic,
        int $count,
        string $brief,
        string $primaryLanguage,
        string $contentType,
        ?int $actorId,
        int $siteId,
        array $automationPolicyMeta = [],
    ): array {
        $context = array_filter([
            'site_id' => $siteId > 0 ? $siteId : null,
            'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'locale' => $primaryLanguage,
            'site_locale' => $primaryLanguage,
        ], static fn (mixed $v): bool => $v !== null);

        $settings = [];
        if ($automationPolicyMeta !== []) {
            $settings['automation_policy'] = $automationPolicyMeta;
        }

        $envelope = PromptHookExecutionInput::fromArray([
            'context' => $context,
            'input' => [
                'seed_topic' => $seedTopic,
                'count' => max(1, $count),
                'brief' => $brief,
                'primary_language' => $primaryLanguage,
                'post_type' => $contentType,
                'content_type' => $contentType,
            ],
            'previous_outputs' => [],
            'settings' => $settings,
        ]);

        $promptResultId = null;

        $value = $this->promptHookBridge->run(
            hookKey: 'keyword.discovery.structured',
            version: '0.1.0',
            envelope: $envelope,
            legacyExecute: function () use ($legacyVariables, &$promptResultId): mixed {
                $promptId = $this->workflowSettings->getProjectKeywordsPromptId();
                if ($promptId === null) {
                    throw new InvalidArgumentException(
                        'Keyword Discovery prompt is not bound. Open Settings → Prompt Management / Workflows and ensure Keyword Discovery is provisioned.',
                    );
                }
                $prompt = SeoPrompt::query()->find($promptId);
                if (! $prompt instanceof SeoPrompt) {
                    throw new InvalidArgumentException('Keyword Discovery prompt record is missing.');
                }

                $result = $this->promptRunner->run($prompt, $legacyVariables);
                $promptResultId = $result->id !== null ? (int) $result->id : null;

                return (string) ($result->output_text ?? '');
            },
            mapHookResult: function (PromptHookRuntimeResult $runtimeResult) use (&$promptResultId): mixed {
                $metaId = $runtimeResult->meta['prompt_result_id'] ?? null;
                if (is_numeric($metaId) && (int) $metaId > 0) {
                    $promptResultId = (int) $metaId;
                }

                return $runtimeResult->output['value'] ?? null;
            },
        );

        return [
            'value' => $value,
            'prompt_result_id' => ($promptResultId !== null && $promptResultId > 0) ? $promptResultId : null,
        ];
    }

    /**
     * @param  list<array{keyword: string, title: string, description: string, product_type?: string, gallery_description?: string, fingerprint: string, suggestion_reason?: string, source_signal?: string}>  $candidates
     * @return list<int>
     */
    /**
     * @param  list<array{keyword: string, title: string, description: string, product_type?: string, gallery_description?: string, fingerprint: string, suggestion_reason?: string, source_signal?: string}>  $candidates
     * @return list<int>
     */
    private function persistCreateItems(
        SeoProject $project,
        Site $site,
        array $candidates,
        string $postType,
        int $plannerRunId,
        ?int $actorId,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $taskIds = [];
        $workingSiteId = (int) $site->getKey();

        DB::connection('omi_seo_ai')->transaction(function () use (
            $project,
            $workingSiteId,
            $candidates,
            $postType,
            $plannerRunId,
            $actorId,
            &$taskIds,
        ): void {
            $session = $this->allocator->begin($project);
            foreach ($candidates as $candidate) {
                $keyword = ContentProjectItemIdentity::normalize($candidate['keyword']);
                $title = ContentProjectItemIdentity::normalize($candidate['title']);
                if (! ContentProjectItemIdentity::isValid($keyword, $title)) {
                    continue;
                }
                // Skip AI context echoes / oversize blobs — never insert into source_content.
                if ($this->isUnsafePlanningIdentity($keyword) || $this->isUnsafePlanningIdentity($title)) {
                    continue;
                }
                // Hard cap to DB column widths (belt-and-suspenders for queue workers).
                if (mb_strlen($keyword) > NewContentSuggestionParser::MAX_KEYWORD_CHARS) {
                    $keyword = mb_substr($keyword, 0, NewContentSuggestionParser::MAX_KEYWORD_CHARS);
                }
                if (mb_strlen($title) > NewContentSuggestionParser::MAX_KEYWORD_CHARS) {
                    $title = mb_substr($title, 0, NewContentSuggestionParser::MAX_KEYWORD_CHARS);
                }
                $sourceContent = $keyword !== '' ? $keyword : $title;
                if (mb_strlen($sourceContent) > NewContentSuggestionParser::MAX_SOURCE_CONTENT_CHARS) {
                    $sourceContent = mb_substr($sourceContent, 0, NewContentSuggestionParser::MAX_SOURCE_CONTENT_CHARS);
                }
                if ($sourceContent === '') {
                    continue;
                }

                // Allocator chooses Draft capacity container — not Site ownership.
                $target = $session->projectWithRemainingCapacity();
                if ($target === null || (int) $target->getKey() <= 0) {
                    continue;
                }

                $reason = trim((string) ($candidate['suggestion_reason'] ?? ''));
                if (mb_strlen($reason) > 200) {
                    $reason = mb_substr($reason, 0, 197).'…';
                }
                $brief = trim((string) ($candidate['description'] ?? ''));
                if (mb_strlen($brief) > 2000) {
                    $brief = mb_substr($brief, 0, 1997).'…';
                }
                $isProduct = $postType === SeoProjectTask::POST_TYPE_PRODUCT;
                $productType = $isProduct ? trim((string) ($candidate['product_type'] ?? '')) : '';
                if (mb_strlen($productType) > 500) {
                    $productType = mb_substr($productType, 0, 497).'…';
                }
                $gallery = $isProduct ? trim((string) ($candidate['gallery_description'] ?? '')) : '';
                if (mb_strlen($gallery) > 4000) {
                    $gallery = mb_substr($gallery, 0, 3997).'…';
                }
                $signal = trim((string) ($candidate['source_signal'] ?? ''));
                $reasonCodes = ['ai_new_content'];
                if ($signal !== '') {
                    $reasonCodes[] = 'source:'.$signal;
                }

                $occupied = $session->occupiedCount($target);
                $task = SeoProjectTask::query()->create([
                    'project_id' => (int) $target->getKey(),
                    'site_id' => $workingSiteId,
                    'type' => SeoProjectTask::TYPE_CREATE,
                    'post_type' => $postType !== '' ? $postType : SeoProjectTask::POST_TYPE_ARTICLE,
                    'source_content' => $sourceContent,
                    'keyword' => $keyword !== '' ? $keyword : null,
                    'title' => $title !== '' ? $title : null,
                    'secondary_description' => $brief !== '' ? $brief : null,
                    'description' => $isProduct && $gallery !== '' ? $gallery : null,
                    'loai_san_pham' => $isProduct && $productType !== '' ? $productType : null,
                    'status' => SeoProjectTask::STATUS_PENDING,
                    'article_id' => null,
                    'target_date' => $target->monthCarbon()->copy()->addDays($occupied)->format('Y-m-d'),
                ]);
                $session->recordAdded($target);
                $taskId = (int) $task->getKey();
                $taskIds[] = $taskId;

                SeoContentProjectItemOrigin::query()->updateOrCreate(
                    ['project_task_id' => $taskId],
                    [
                        'project_id' => (int) $project->getKey(),
                        'planner_run_id' => $plannerRunId > 0 ? $plannerRunId : null,
                        'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
                        'source_article_id' => null,
                        'source_finding_ids' => [],
                        'reason_codes' => $reasonCodes,
                        'source_fingerprint' => $candidate['fingerprint'],
                        'created_at' => now(),
                    ],
                );
            }
            $session->syncTouchedCounters();
        });

        return $taskIds;
    }

    /**
     * Persist-time guard mirroring parser dump detection (queue workers may lag code reload).
     */
    private function isUnsafePlanningIdentity(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        $lower = mb_strtolower($trimmed);
        if (str_contains($lower, 'planned items list includes')
            || str_contains($lower, 'already planned in this draft')
            || str_contains($lower, 'rejected earlier in this draft')
            || str_contains($lower, 'return json array of objects')
            || (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))
        ) {
            return true;
        }

        if (substr_count($trimmed, '"') >= 6 && mb_strlen($trimmed) > 120) {
            return true;
        }

        return mb_strlen($trimmed) > 200;
    }

    /**
     * Resolve working Site for a planner run.
     * Prefer immutable snapshot.site_id; legacy fallback to project.site_id for historical runs.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveSiteForRun(SeoProject $project, array $snapshot): Site
    {
        $snapshotSiteId = (int) ($snapshot['site_id'] ?? 0);
        if ($snapshotSiteId > 0) {
            $site = Site::query()->find($snapshotSiteId);
            if ($site instanceof Site) {
                return $site;
            }

            throw new InvalidArgumentException(
                'Historical planner run site #'.$snapshotSiteId.' was not found.',
            );
        }

        $site = $project->relationLoaded('site') ? $project->site : null;
        if (! $site instanceof Site) {
            $siteId = (int) ($project->site_id ?? 0);
            $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        }
        if (! $site instanceof Site) {
            throw new InvalidArgumentException(
                'Historical planner run has no site_id snapshot and Draft has no legacy site.',
            );
        }

        return $site;
    }

    private function isRecoverableStructuredOutputError(Throwable $e): bool
    {
        if (! $e instanceof InvalidArgumentException) {
            return false;
        }
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'truncated')
            || str_contains($msg, 'incomplete')
            || str_contains($msg, 'invalid after repair')
            || str_contains($msg, 'structured output');
    }

    private function isDeterministicApplicationError(Throwable $e): bool
    {
        if (! $e instanceof InvalidArgumentException) {
            return false;
        }
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'prompt is not bound')
            || str_contains($msg, 'prompt record is missing')
            || str_contains($msg, 'primary language is not configured')
            || str_contains($msg, 'no site_id snapshot');
    }

    private function requirePrimaryLanguage(Site $site): string
    {
        $language = $this->primaryLanguage->resolvePrimaryLanguage($site);
        if ($language === null || trim($language) === '') {
            throw new InvalidArgumentException('Primary language is not configured.');
        }

        return trim($language);
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(SeoContentProjectPlannerRun $run, Throwable $e): array
    {
        $requested = (int) ($run->requested_quantity ?? 0);
        $message = $this->failureMessage($e);
        $errorCode = $e instanceof AiRoutesExhaustedException
            || str_contains($e->getMessage(), AiRoutesExhaustedException::CLASSIFICATION)
            ? AiRoutesExhaustedException::CLASSIFICATION
            : 'generation_failed';

        return [
            'requested' => $requested,
            'generated' => 0,
            'valid' => 0,
            'added' => 0,
            'duplicate_skipped' => 0,
            'rejected_skipped' => 0,
            'invalid' => 0,
            'task_ids' => [],
            'planner_run_id' => (int) $run->getKey(),
            'prompt_result_id' => $this->lastDiscoveryPromptResultId,
            'logical_ai_calls' => $this->logicalDiscoveryCalls > 0 ? 1 : 0,
            'status' => SeoContentProjectPlannerRun::STATUS_FAILED,
            'message' => $message,
            'error_code' => $errorCode,
            'error_class' => $e::class,
            'candidates' => [],
        ];
    }

    private function failureMessage(Throwable $e): string
    {
        if ($e instanceof AiRoutesExhaustedException
            || str_contains($e->getMessage(), AiRoutesExhaustedException::CLASSIFICATION)
        ) {
            if ($e instanceof AiRoutesExhaustedException) {
                $user = trim($e->userMessage());
                if ($user !== '') {
                    return $user;
                }
            }

            return 'AI routes exhausted — all configured providers failed. Check AI Center routing/keys, then retry.';
        }

        if ($e instanceof InvalidArgumentException) {
            return $e->getMessage();
        }

        if ($e instanceof PromptRunException) {
            $user = trim($e->userMessage());
            if ($user !== '' && $user !== 'false' && strtolower($user) !== 'false') {
                return $user;
            }

            $raw = trim($e->getMessage());

            return $raw !== '' && strtolower($raw) !== 'false' ? $raw : 'Generation failed';
        }

        if ($e instanceof PromptHookFailure) {
            return 'Generation failed';
        }

        $raw = trim($e->getMessage());
        if ($raw === '' || strtolower($raw) === 'false') {
            return 'Generation failed';
        }

        // Persist / DB failures must surface — do not mask as provider outage.
        if (str_contains($raw, 'source_content') || str_contains($raw, 'SQLSTATE')) {
            return 'Draft persist failed: '.$raw;
        }

        return mb_strlen($raw) > 240 ? mb_substr($raw, 0, 237).'…' : $raw;
    }

    private function extractPromptResultId(Throwable $e): ?int
    {
        if ($this->lastDiscoveryPromptResultId !== null && $this->lastDiscoveryPromptResultId > 0) {
            return $this->lastDiscoveryPromptResultId;
        }

        if ($e instanceof PromptRunException) {
            $fromContext = (int) ($e->context['prompt_result_id'] ?? 0);
            if ($fromContext > 0) {
                $this->lastDiscoveryPromptResultId = $fromContext;

                return $fromContext;
            }
        }

        if ($e instanceof PromptHookFailure) {
            $id = $e->promptResultId();

            return $id !== null && $id > 0 ? $id : null;
        }

        return null;
    }
}
