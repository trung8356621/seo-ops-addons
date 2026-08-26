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
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAllocator;
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

        $decoded = json_decode($raw, true);
        $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;

        $site = $this->resolveSite($project);
        $snapshot = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
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
    public function queueGeneration(SeoProject $project, array $options, ?int $actorId): array
    {
        if (! $project->isDraftPlanning()) {
            throw new InvalidArgumentException('Add AI suggestions to a Draft project.');
        }

        $site = $this->resolveSite($project);
        $language = $this->requirePrimaryLanguage($site);
        $normalized = NewContentSuggestionOptions::normalize($options);
        $snapshot = NewContentSuggestionOptions::snapshot($normalized, $language);

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
            'options' => $normalized,
        ];
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
        $this->plannerRuns->markStatus($run, SeoContentProjectPlannerRun::STATUS_RUNNING);

        try {
            $summary = $this->executeAgainstRun($project, $run, $actorId > 0 ? $actorId : null);
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
     * Synchronous execute for dry-run preview / tests that inject a fake bridge.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function generateNow(SeoProject $project, array $options, ?int $actorId): array
    {
        $site = $this->resolveSite($project);
        $language = $this->requirePrimaryLanguage($site);
        $normalized = NewContentSuggestionOptions::normalize($options);
        $snapshot = NewContentSuggestionOptions::snapshot($normalized, $language);

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
     * @return array<string, mixed>
     */
    private function executeAgainstRun(SeoProject $project, SeoContentProjectPlannerRun $run, ?int $actorId): array
    {
        $site = $this->resolveSite($project);
        $snapshot = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
        $options = NewContentSuggestionOptions::fromSnapshot($snapshot);
        $language = (string) ($snapshot['primary_language'] ?? $this->requirePrimaryLanguage($site));
        $requested = max(1, (int) ($run->requested_quantity ?: $options['quantity']));

        $context = $this->contextBuilder->build($project, $site, $options, $language);
        $discovery = $this->discoverOnce(
            seedTopic: $context['seed_topic'],
            count: $requested,
            brief: $context['brief'],
            primaryLanguage: $language,
            contentType: NewContentSuggestionOptions::normalizeContentType((string) ($options['post_type'] ?? $options['content_type'] ?? 'post')),
            actorId: $actorId,
            siteId: (int) $site->getKey(),
            notes: trim((string) ($options['notes'] ?? '')),
        );

        $parsed = $this->parser->parse($discovery['value'], $requested);
        $filtered = $this->dedup->filter(
            $parsed['candidates'],
            $context['planned_fingerprints'],
            $context['rejected_fingerprints'],
            $context['covered_keyword_norms'] ?? $context['existing_keywords'] ?? [],
            is_array($context['planned_keyword_norms'] ?? null) ? $context['planned_keyword_norms'] : [],
        );

        $taskIds = $this->persistCreateItems(
            $project,
            $filtered['accepted'],
            NewContentSuggestionOptions::taskPostType((string) $options['post_type']),
            (int) $run->getKey(),
            $actorId,
        );

        $results = $filtered['results'];
        $diagnostics = is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [];
        $breakdown = is_array($filtered['duplicate_breakdown'] ?? null)
            ? $filtered['duplicate_breakdown']
            : ['in_batch' => 0, 'in_batch_keyword' => 0, 'active_draft' => 0, 'covered_content' => 0];

        return [
            'requested' => $requested,
            'generated' => (int) $parsed['generated'],
            'valid' => count($parsed['candidates']),
            'added' => count($taskIds),
            'duplicate_skipped' => (int) $filtered['duplicate_skipped'],
            'rejected_skipped' => (int) $filtered['rejected_skipped'],
            'invalid' => (int) $parsed['invalid'],
            'duplicate_breakdown' => $breakdown,
            'task_ids' => $taskIds,
            'planner_run_id' => (int) $run->getKey(),
            'prompt_result_id' => $discovery['prompt_result_id'],
            'logical_ai_calls' => 1,
            'planning_ai_calls' => 0,
            'status' => SeoContentProjectPlannerRun::STATUS_COMPLETED,
            'primary_language' => $language,
            'context_flags' => $context['context_flags'],
            'planning_context' => [
                'principal_keywords_count' => (int) ($diagnostics['principal_keywords_count'] ?? 0),
                'cluster_count' => (int) ($diagnostics['cluster_count'] ?? 0),
                'missing_direction_count' => (int) ($diagnostics['missing_direction_count'] ?? 0),
                'mcp_period' => $diagnostics['mcp_period'] ?? null,
            ],
            'candidates' => array_slice($results, 0, 100),
            'message' => sprintf(
                '%d requested · %d added · %d duplicates skipped · %d rejected skipped · %d invalid',
                $requested,
                count($taskIds),
                (int) $filtered['duplicate_skipped'],
                (int) $filtered['rejected_skipped'],
                (int) $parsed['invalid'],
            ),
        ];
    }

    /**
     * Exactly one logical discovery call. AI Resilience may fallback internally.
     *
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
    ): array {
        $this->logicalDiscoveryCalls++;

        $contentType = in_array($contentType, ['post', 'product'], true) ? $contentType : 'post';
        $quantity = (string) max(1, $count);
        $notesValue = trim($notes);

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

        $context = array_filter([
            'site_id' => $siteId > 0 ? $siteId : null,
            'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'locale' => $primaryLanguage,
            'site_locale' => $primaryLanguage,
        ], static fn (mixed $v): bool => $v !== null);

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
            'settings' => [],
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

        $this->lastDiscoveryPromptResultId = ($promptResultId !== null && $promptResultId > 0)
            ? $promptResultId
            : null;

        return [
            'value' => $value,
            'prompt_result_id' => $promptResultId,
        ];
    }

    /**
     * @param  list<array{keyword: string, title: string, description: string, product_type?: string, gallery_description?: string, fingerprint: string, suggestion_reason?: string, source_signal?: string}>  $candidates
     * @return list<int>
     */
    private function persistCreateItems(
        SeoProject $project,
        array $candidates,
        string $postType,
        int $plannerRunId,
        ?int $actorId,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $taskIds = [];

        DB::connection('omi_seo_ai')->transaction(function () use (
            $project,
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
                    'site_id' => (int) ($target->site_id ?? $project->site_id ?? 0),
                    'type' => SeoProjectTask::TYPE_CREATE,
                    'post_type' => $postType !== '' ? $postType : SeoProjectTask::POST_TYPE_ARTICLE,
                    'source_content' => $keyword !== '' ? $keyword : $title,
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

    private function resolveSite(SeoProject $project): Site
    {
        $site = $project->relationLoaded('site') ? $project->site : null;
        if (! $site instanceof Site) {
            $siteId = (int) ($project->site_id ?? 0);
            $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        }
        if (! $site instanceof Site) {
            throw new InvalidArgumentException('Project domain is required.');
        }

        return $site;
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
