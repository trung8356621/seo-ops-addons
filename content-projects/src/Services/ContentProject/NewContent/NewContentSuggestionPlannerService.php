<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
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

        $this->plannerRuns->markStatus($run, SeoContentProjectPlannerRun::STATUS_RUNNING);

        try {
            $summary = $this->executeAgainstRun($project, $run, $actorId > 0 ? $actorId : null);
            $this->plannerRuns->completeRun($run, $summary, isset($summary['prompt_result_id']) ? (int) $summary['prompt_result_id'] : null);

            return $summary;
        } catch (Throwable $e) {
            $safe = $this->failureSummary($run, $e);
            $this->plannerRuns->failRun($run, $safe, $this->extractPromptResultId($e));

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
        $this->plannerRuns->markStatus($run, SeoContentProjectPlannerRun::STATUS_RUNNING);

        try {
            $summary = $this->executeAgainstRun($project, $run, $actorId);
            $this->plannerRuns->completeRun($run, $summary, isset($summary['prompt_result_id']) ? (int) $summary['prompt_result_id'] : null);

            return $summary;
        } catch (Throwable $e) {
            $safe = $this->failureSummary($run, $e);
            $this->plannerRuns->failRun($run, $safe, $this->extractPromptResultId($e));

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
            actorId: $actorId,
            siteId: (int) $site->getKey(),
        );

        $parsed = $this->parser->parse($discovery['value'], $requested);
        $filtered = $this->dedup->filter(
            $parsed['candidates'],
            $context['planned_fingerprints'],
            $context['rejected_fingerprints'],
            $context['covered_keyword_norms'] ?? $context['existing_keywords'],
        );

        $taskIds = $this->persistCreateItems(
            $project,
            $filtered['accepted'],
            $options['post_type'],
            (int) $run->getKey(),
            $actorId,
        );

        $results = $filtered['results'];
        $diagnostics = is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [];

        return [
            'requested' => $requested,
            'generated' => (int) $parsed['generated'],
            'valid' => count($parsed['candidates']),
            'added' => count($taskIds),
            'duplicate_skipped' => (int) $filtered['duplicate_skipped'],
            'rejected_skipped' => (int) $filtered['rejected_skipped'],
            'invalid' => (int) $parsed['invalid'],
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
        ?int $actorId,
        int $siteId,
    ): array {
        $this->logicalDiscoveryCalls++;

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
                'count' => $count,
                'brief' => $brief,
                'primary_language' => $primaryLanguage,
            ],
            'previous_outputs' => [],
            'settings' => [],
        ]);

        $promptResultId = null;

        $value = $this->promptHookBridge->run(
            hookKey: 'keyword.discovery.structured',
            version: '0.1.0',
            envelope: $envelope,
            legacyExecute: function () use ($seedTopic, $count, $brief, $primaryLanguage, &$promptResultId): mixed {
                $promptId = $this->workflowSettings->getProjectKeywordsPromptId();
                if ($promptId === null) {
                    throw new InvalidArgumentException(
                        'Keyword discovery prompt is not bound. Configure SEO → Settings → Workflow.',
                    );
                }
                $prompt = SeoPrompt::query()->find($promptId);
                if (! $prompt instanceof SeoPrompt) {
                    throw new InvalidArgumentException('Keyword discovery prompt is missing.');
                }

                $result = $this->promptRunner->run($prompt, [
                    'keyword_count' => (string) $count,
                    'user_brief' => $brief,
                    'seed_topic' => $seedTopic,
                    'primary_language' => $primaryLanguage,
                ]);
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
            'prompt_result_id' => $promptResultId,
        ];
    }

    /**
     * @param  list<array{keyword: string, title: string, description: string, fingerprint: string, suggestion_reason?: string, source_signal?: string}>  $candidates
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

                $reason = trim((string) ($candidate['suggestion_reason'] ?? $candidate['description'] ?? ''));
                if (mb_strlen($reason) > 200) {
                    $reason = mb_substr($reason, 0, 197).'…';
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
                    'keyword' => $keyword !== '' ? $keyword : null,
                    'title' => $title !== '' ? $title : null,
                    'description' => $reason !== '' ? $reason : null,
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
        $message = 'AI providers unavailable';
        if ($e instanceof AiRoutesExhaustedException
            || str_contains($e->getMessage(), AiRoutesExhaustedException::CLASSIFICATION)
        ) {
            $message = 'AI providers unavailable';
        } elseif ($e instanceof InvalidArgumentException) {
            $message = $e->getMessage();
        } elseif ($e instanceof PromptRunException || $e instanceof PromptHookFailure) {
            $message = 'Generation failed';
        }

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
            'logical_ai_calls' => $this->logicalDiscoveryCalls > 0 ? 1 : 0,
            'status' => SeoContentProjectPlannerRun::STATUS_FAILED,
            'message' => $message,
            'error_code' => $e instanceof AiRoutesExhaustedException
                ? AiRoutesExhaustedException::CLASSIFICATION
                : 'generation_failed',
            'candidates' => [],
        ];
    }

    private function extractPromptResultId(Throwable $e): ?int
    {
        if ($e instanceof PromptHookFailure) {
            $id = $e->promptResultId();

            return $id !== null && $id > 0 ? $id : null;
        }

        return null;
    }
}
