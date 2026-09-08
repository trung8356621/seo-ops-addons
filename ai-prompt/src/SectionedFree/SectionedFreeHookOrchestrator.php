<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingOwnerResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategyResolver;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Illuminate\Support\Str;

/**
 * Hook-path orchestrator for sectioned_free — branches BEFORE whole-article compile.
 * Persists parent + per-attempt child PromptResults for AI History observability.
 */
final class SectionedFreeHookOrchestrator
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly AiModelRouterService $aiModelRouter,
        private readonly PromptExecutionProfileResolver $profileResolver,
        private readonly ArticleGenerationStrategyResolver $strategyResolver = new ArticleGenerationStrategyResolver(),
        private readonly SectionedFreeArticleGenerator $generator = new SectionedFreeArticleGenerator(),
        private readonly ?SectionedFreeTrackedProviderCall $trackedCall = null,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $contextExtras
     * @return array<string, mixed>
     */
    public function execute(
        SeoPrompt $prompt,
        array $variables,
        array $contextExtras,
        string $hookKey,
        string $hookVersion,
    ): array {
        $started = (int) round(microtime(true) * 1000);
        $strategyReceived = (string) (
            $variables['generation_strategy']
            ?? $variables['_item_generation_strategy']
            ?? $variables['resolved_generation_strategy']
            ?? ''
        );
        $strategy = $this->strategyResolver->resolve($variables);
        if (! $strategy->isSectioned()) {
            throw new PromptRunException('SectionedFreeHookOrchestrator invoked without sectioned generation shape.');
        }

        $variables = $this->strategyResolver->stamp($variables, ArticleGenerationStrategy::Sectioned);
        $correlationId = (string) ($contextExtras['correlation_id'] ?? Str::uuid()->toString());
        $toolType = ImageToolType::fromMixed($prompt->tools ?? 'default')->value;
        $profile = $this->profileResolver->resolve($prompt, $hookKey, $toolType);
        $siteId = isset($contextExtras['site_id']) ? (int) $contextExtras['site_id'] : 0;

        $runId = trim((string) ($variables['_execution_run_id'] ?? ''));
        if ($runId === '') {
            $runId = uniqid('sf_', true);
        }

        $articleId = (int) ($contextExtras['article_id'] ?? $variables['article_id'] ?? 0);
        $projectRunId = (int) ($contextExtras['project_run_id'] ?? $contextExtras['run_id'] ?? 0);
        $projectTaskId = (int) ($contextExtras['project_task_id'] ?? $contextExtras['task_id'] ?? 0);
        $workflowNodeId = trim((string) ($contextExtras['node_id'] ?? ''));

        $breadcrumbs = new SectionedFreeBreadcrumbBag();
        $breadcrumbs->push('strategy_received', ['value' => $strategyReceived !== '' ? $strategyReceived : null]);
        $breadcrumbs->push('strategy_resolved', ['value' => $strategy->value]);
        $breadcrumbs->push('branch_entered', ['class' => self::class]);

        $parentResult = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => (int) (auth()->id() ?? 0),
            'site_id' => $siteId,
            'status' => 'running',
            'input_snapshot' => array_merge(
                \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategySnapshot::fromVariables($variables)
                    ->toExecutionSnapshot($projectTaskId > 0 ? $projectTaskId : null),
                [
                    'variables' => [
                        'generation_strategy' => ArticleGenerationStrategy::Sectioned->value,
                        'resolved_generation_strategy' => ArticleGenerationStrategy::Sectioned->value,
                        '_item_generation_strategy' => ArticleGenerationStrategy::Sectioned->value,
                        'generation_shape' => ArticleGenerationStrategy::Sectioned->value,
                        'generation_shape_source' => $variables['generation_shape_source']
                            ?? \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY,
                        'primary_model' => $variables['primary_model'] ?? null,
                        'primary_model_id' => $variables['primary_model_id'] ?? null,
                        'primary_is_free' => $variables['primary_is_free'] ?? null,
                        'free_only_policy' => $variables['free_only_policy'] ?? false,
                        'strategy_override' => null,
                        'strategy_resolved' => ArticleGenerationStrategy::Sectioned->value,
                        'strategy_source' => $variables['generation_shape_source']
                            ?? \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY,
                        'isolation_mode' => 'sectioned_generation',
                        'hook_key' => $hookKey,
                        'article_id' => $articleId > 0 ? $articleId : null,
                    ],
                    'compiled_prompt' => "Strategy: sectioned\nParent orchestrator running…",
                    'manual_compiled' => true,
                    'sectioned_free_orchestrator' => true,
                    'generation_strategy' => ArticleGenerationStrategy::Sectioned->value,
                    'generation_shape' => ArticleGenerationStrategy::Sectioned->value,
                    'strategy_override' => null,
                    'strategy_resolved' => ArticleGenerationStrategy::Sectioned->value,
                    'strategy_source' => $variables['generation_shape_source']
                        ?? \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY,
                    'run_id' => $runId,
                    'hook_key' => $hookKey,
                    'display_name' => 'Viết bài — Sectioned (orchestrator)',
                    'article_id' => $articleId > 0 ? $articleId : null,
                    'project_run_id' => $projectRunId > 0 ? $projectRunId : null,
                    'project_task_id' => $projectTaskId > 0 ? $projectTaskId : null,
                    'workflow_node_id' => $workflowNodeId !== '' ? $workflowNodeId : null,
                    // Parent has many AI calls — do not attach a single fake model.
                    'suppress_single_model_display' => true,
                ],
            ),
            'started_at' => now(),
        ]);
        $parentId = (int) $parentResult->id;

        $this->linkPromptResultImmediately(
            $parentId,
            $articleId,
            $projectRunId,
            $projectTaskId,
            $workflowNodeId,
            'Viết bài — Sectioned (orchestrator)',
            'sectioned_orchestrator',
            ['generation_strategy' => ArticleGenerationStrategy::Sectioned->value, 'generation_shape' => 'sectioned'],
        );

        SectionedFreeExecutionGuard::enter([
            'run_id' => $runId,
            'parent_prompt_result_id' => $parentId,
        ]);

        /** @var list<int> $childPromptResultIds */
        $childPromptResultIds = [];
        $tracked = $this->trackedCall ?? new SectionedFreeTrackedProviderCall($this->promptRunner);
        $plannedSectionCount = 0;

        try {
            $freeContext = new AiRoutingContext(
                userId: app(AiRoutingOwnerResolver::class)->resolve(
                    explicitUserId: null,
                    prompt: $prompt,
                    connection: $prompt->aiConnection,
                ),
                legacyConnection: $prompt->aiConnection,
                allowLegacyFallback: true,
                usageModeOverride: null,
                allowedFamilyKeys: null,
                costPolicy: AiCostPolicyScope::current(),
                preferredModelId: isset($variables['_item_model_override_id'])
                    ? (int) $variables['_item_model_override_id']
                    : (isset($variables['primary_model_id']) ? (int) $variables['primary_model_id'] : null),
                requirePreferredModel: false,
                itemGenerationMode: isset($variables['_item_generation_mode'])
                    ? (string) $variables['_item_generation_mode']
                    : null,
                hookKey: $hookKey,
                freeOnly: false,
                isolationMode: 'sectioned_generation',
                generationStrategy: ArticleGenerationStrategy::Sectioned->value,
            );

            $articleContext = [
                'title' => (string) ($variables['post_title'] ?? $variables['title'] ?? $variables['article_title'] ?? ''),
                'primary_keyword' => (string) ($variables['focus_keyword'] ?? $variables['keyword'] ?? $variables['primary_keyword'] ?? ''),
                'intent' => (string) ($variables['search_intent'] ?? $variables['intent'] ?? $variables['content_intent'] ?? ''),
                'language' => (string) ($variables['language'] ?? $variables['locale'] ?? 'vi'),
                // Prefer typed outline artifact — never the normal whole-article compiled input blob.
                'outline' => (string) (
                    $variables['article_outline']
                    ?? $variables['outline']
                    ?? $variables['article_writing_raw_input']
                    ?? $variables['input']
                    ?? ''
                ),
                'article_outline' => (string) ($variables['article_outline'] ?? ''),
                'article_vocabulary' => (string) ($variables['article_vocabulary'] ?? ''),
                'input' => (string) (
                    $variables['article_outline']
                    ?? $variables['outline']
                    ?? $variables['article_writing_raw_input']
                    ?? $variables['input']
                    ?? ''
                ),
                'article_length' => $variables['article_length']
                    ?? $variables['target_article_length']
                    ?? $variables['resolved_article_length']
                    ?? null,
                'target_words' => $variables['article_length']
                    ?? $variables['target_article_length']
                    ?? $variables['resolved_article_length']
                    ?? null,
            ];

            $priorState = SectionedFreeRunState::fromArray(
                is_array($variables['_sectioned_free_state'] ?? null) ? $variables['_sectioned_free_state'] : null,
            );
            $rerunSectionId = trim((string) ($variables['_sectioned_free_rerun_section_id'] ?? ''));

            $sectionAttemptCounters = [];
            $sectionChildIds = [];

            $sectionExecutor = function (
                SectionedFreeSectionUnit $unit,
                string $sectionPrompt,
            ) use (
                $prompt,
                $variables,
                $toolType,
                $profile,
                $freeContext,
                $tracked,
                $parentId,
                $runId,
                $siteId,
                $articleId,
                $projectRunId,
                $projectTaskId,
                $workflowNodeId,
                $breadcrumbs,
                &$childPromptResultIds,
                &$sectionAttemptCounters,
                &$sectionChildIds,
                &$plannedSectionCount,
            ): array {
                $breadcrumbs->push('section_generation_started', [
                    'section_id' => $unit->sectionId,
                    'section_order' => $unit->order,
                    'prompt_character_count' => mb_strlen($sectionPrompt),
                    'target_words' => $unit->preferredTargetWords,
                ]);

                [$output, $usage, $candidate, $fallbackCount, $reasons, $routingAttempts] = $this->aiModelRouter->executeWithProfile(
                    $profile->value,
                    $freeContext,
                    function (RoutedAiCandidate $routed) use (
                        $prompt,
                        $variables,
                        $toolType,
                        $sectionPrompt,
                        $unit,
                        $tracked,
                        $parentId,
                        $runId,
                        $siteId,
                        $articleId,
                        $projectRunId,
                        $projectTaskId,
                        $workflowNodeId,
                        $breadcrumbs,
                        &$childPromptResultIds,
                        &$sectionAttemptCounters,
                        &$sectionChildIds,
                        &$plannedSectionCount,
                    ): array {
                        if (! AiConnectionCredential::isUsable($routed->connection->api_key ?? null)) {
                            throw new PromptRunException(
                                'NO_AI_CONNECTION: configured connection has no usable API key.',
                                0,
                                null,
                                [
                                    'failure_code' => 'NO_AI_CONNECTION',
                                    'connection_id' => (int) $routed->connection->id,
                                    'retryable' => false,
                                    'auto_create_credential' => false,
                                ],
                            );
                        }

                        $sectionAttemptCounters[$unit->sectionId] = (int) ($sectionAttemptCounters[$unit->sectionId] ?? 0) + 1;
                        $attemptNumber = $sectionAttemptCounters[$unit->sectionId];

                        [$out, $callUsage, $child] = $tracked->call(
                            $routed,
                            $prompt,
                            $sectionPrompt,
                            $variables,
                            $toolType,
                            $unit,
                            $attemptNumber,
                            $parentId,
                            $runId,
                            [
                                'site_id' => $siteId,
                                'article_id' => $articleId,
                                'project_run_id' => $projectRunId,
                                'project_task_id' => $projectTaskId,
                                'run_id' => $projectRunId,
                                'task_id' => $projectTaskId,
                                'node_id' => $workflowNodeId,
                                'section_count' => max(1, $plannedSectionCount),
                                'parent_prompt_result_id' => $parentId,
                            ],
                        );

                        $childId = (int) $child->id;
                        $childPromptResultIds[] = $childId;
                        $sectionChildIds[$unit->sectionId][] = $childId;
                        $breadcrumbs->push('section_provider_call_created', [
                            'section_id' => $unit->sectionId,
                            'prompt_result_id' => $childId,
                            'attempt' => $attemptNumber,
                            'model' => $routed->model,
                            'connection_id' => (int) $routed->connection->id,
                        ]);

                        return [$out, $callUsage];
                    },
                );
                unset($reasons);

                $wordCount = \Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics::wordCount($output);
                $breadcrumbs->push('section_generation_completed', [
                    'section_id' => $unit->sectionId,
                    'words' => $wordCount,
                    'model' => $candidate->model,
                    'attempt_count' => max(1, $fallbackCount + 1),
                    'prompt_result_ids' => $sectionChildIds[$unit->sectionId] ?? [],
                ]);

                return [
                    'output' => $output,
                    'model' => $candidate->model,
                    'provider' => $candidate->provider,
                    'connection_id' => (int) $candidate->connection->id,
                    'attempt_count' => max(1, $fallbackCount + 1),
                    'fallback_count' => $fallbackCount,
                    'prompt_result_ids' => $sectionChildIds[$unit->sectionId] ?? [],
                    'usage' => array_merge(is_array($usage) ? $usage : [], [
                        'routing_attempts' => $routingAttempts ?? [],
                        'section_id' => $unit->sectionId,
                        'prompt_character_count' => mb_strlen($sectionPrompt),
                        'generation_strategy' => ArticleGenerationStrategy::Sectioned->value,
                        'generation_shape' => ArticleGenerationStrategy::Sectioned->value,
                        'credential_source' => 'configured_connection',
                        'prompt_result_ids' => $sectionChildIds[$unit->sectionId] ?? [],
                    ]),
                ];
            };

            // Pre-plan for breadcrumbs (same prepare as generator).
            $prepare = new SectionedFreePrepareSections();
            $outlineParts = (new SectionedFreeArtifactSplitter())->split($articleContext['outline']);
            $articleTarget = 0;
            foreach (['article_length', 'target_words'] as $k) {
                if (isset($articleContext[$k]) && is_numeric($articleContext[$k])) {
                    $articleTarget = (int) $articleContext[$k];
                    break;
                }
            }
            $planPreview = $prepare->preparePlan($outlineParts['outline_markdown'], $articleTarget);
            $plannedSectionCount = $planPreview->plannedUnitCount();
            $breadcrumbs->push('sections_planned', [
                'count' => $planPreview->plannedUnitCount(),
                'minimum_units_by_budget' => $planPreview->minimumUnitsByBudget(),
                'article_target_words' => $planPreview->articleTargetWords(),
                'section_ids' => array_map(static fn (SectionedFreeSectionUnit $u): string => $u->sectionId, $planPreview->units),
                'plan' => $planPreview->meta,
            ]);
            $this->patchParentSnapshot($parentResult, $breadcrumbs, $childPromptResultIds);

            $result = $this->generator->run(
                $articleContext,
                $sectionExecutor,
                $priorState,
                $rerunSectionId !== '' ? $rerunSectionId : null,
                $runId,
            );

            $breadcrumbs->push('assemble_started');
            $breadcrumbs->push('assemble_completed', [
                'words' => (int) ($result['metrics']['final_assembled_word_count'] ?? 0),
            ]);

            $assembledWords = (int) ($result['metrics']['final_assembled_word_count'] ?? 0);
            $unitCount = (int) ($result['metrics']['generation_unit_count'] ?? 0);
            $providerCalls = (int) ($result['usage']['provider_calls'] ?? 0);
            $summaryPrompt = $this->orchestratorSummaryPrompt($result['metrics'], $childPromptResultIds);

            $parentResult->update([
                'status' => 'completed',
                'output_text' => $result['assembled'],
                'token_usage' => array_merge($result['usage'], [
                    'child_prompt_result_ids' => $childPromptResultIds,
                    'breadcrumbs' => $breadcrumbs->all(),
                ]),
                'finished_at' => now(),
                'input_snapshot' => array_merge(
                    is_array($parentResult->input_snapshot) ? $parentResult->input_snapshot : [],
                    [
                        'compiled_prompt' => $summaryPrompt,
                        'sectioned_free_trace' => $result['usage']['sectioned_free_trace'] ?? [],
                        'sectioned_free_metrics' => $result['metrics'],
                        'child_prompt_result_ids' => $childPromptResultIds,
                        'breadcrumbs' => $breadcrumbs->all(),
                        'sections_planned' => $planPreview->plannedUnitCount(),
                        'minimum_units_by_budget' => $planPreview->minimumUnitsByBudget(),
                        'article_target_words' => $planPreview->articleTargetWords(),
                        'legacy_whole_article_calls' => 0,
                        'legacy_validator_reached' => false,
                    ],
                ),
                'error_message' => null,
            ]);

            $durationMs = max(0, (int) round(microtime(true) * 1000) - $started);

            return [
                'output' => $result['assembled'],
                'raw' => $result['assembled'],
                'value' => $result['assembled'],
                'correlation_id' => $correlationId,
                'prompt_result_id' => $parentId,
                'prompt_result_ids' => array_values(array_unique(array_merge([$parentId], $childPromptResultIds))),
                'child_prompt_result_ids' => $childPromptResultIds,
                'provider' => 'sectioned_free',
                'model' => $result['last_model'],
                'usage' => $result['usage'],
                'duration_ms' => $durationMs,
                'execution_source' => 'sectioned_free_orchestrator',
                'hook_key' => $hookKey,
                'hook_version' => $hookVersion,
                'audit_fingerprint' => null,
                'actual_word_count' => $assembledWords,
                'minimum_acceptable_words' => null,
                'target_article_length' => null,
                'length_validation_result' => 'sectioned_free_skip_whole_article_threshold',
                'generation_unit_count' => $unitCount,
                'provider_call_count' => $providerCalls,
                'sectioned_free_metrics' => $result['metrics'],
                'breadcrumbs' => $breadcrumbs->all(),
                'run_id' => $runId,
            ];
        } catch (\Throwable $exception) {
            $failurePayload = $this->buildSectionFailurePayload($exception, $childPromptResultIds, $breadcrumbs);
            $breadcrumbs->push('failed', $failurePayload);

            $parentResult->update([
                'status' => 'failed',
                'error_message' => mb_substr((string) ($failurePayload['message'] ?? $exception->getMessage()), 0, 2000),
                'finished_at' => now(),
                'token_usage' => [
                    'child_prompt_result_ids' => $childPromptResultIds,
                    'breadcrumbs' => $breadcrumbs->all(),
                    'sectioned_free_failure' => $failurePayload,
                ],
                'input_snapshot' => array_merge(
                    is_array($parentResult->input_snapshot) ? $parentResult->input_snapshot : [],
                    [
                        'child_prompt_result_ids' => $childPromptResultIds,
                        'breadcrumbs' => $breadcrumbs->all(),
                        'sectioned_free_failure' => $failurePayload,
                        'legacy_validator_reached' => false,
                    ],
                ),
            ]);

            if ($exception instanceof PromptRunException) {
                $ctx = $exception->context;
                $ctx['prompt_result_id'] = $parentId;
                $ctx['prompt_result_ids'] = array_values(array_unique(array_merge([$parentId], $childPromptResultIds)));
                $ctx['child_prompt_result_ids'] = $childPromptResultIds;
                $ctx['sectioned_free_failure'] = $failurePayload;
                throw new PromptRunException(
                    (string) ($failurePayload['message'] ?? $exception->getMessage()),
                    (int) $exception->getCode(),
                    $exception->getPrevious() ?? $exception,
                    $ctx,
                );
            }

            throw new PromptRunException(
                (string) ($failurePayload['message'] ?? $exception->getMessage()),
                0,
                $exception,
                [
                    'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                    'prompt_result_id' => $parentId,
                    'prompt_result_ids' => array_values(array_unique(array_merge([$parentId], $childPromptResultIds))),
                    'child_prompt_result_ids' => $childPromptResultIds,
                    'sectioned_free_failure' => $failurePayload,
                    'retryable' => false,
                ],
            );
        } finally {
            SectionedFreeExecutionGuard::leave();
        }
    }

    /**
     * @param  list<int>  $childIds
     */
    private function patchParentSnapshot(
        PromptResult $parent,
        SectionedFreeBreadcrumbBag $breadcrumbs,
        array $childIds,
    ): void {
        $parent->update([
            'input_snapshot' => array_merge(
                is_array($parent->input_snapshot) ? $parent->input_snapshot : [],
                [
                    'breadcrumbs' => $breadcrumbs->all(),
                    'child_prompt_result_ids' => $childIds,
                ],
            ),
        ]);
    }

    /**
     * @param  list<int>  $childPromptResultIds
     * @return array<string, mixed>
     */
    private function buildSectionFailurePayload(
        \Throwable $exception,
        array $childPromptResultIds,
        SectionedFreeBreadcrumbBag $breadcrumbs,
    ): array {
        $sectionId = null;
        $attempts = null;
        $completed = null;
        $total = null;
        $lastError = $exception->getMessage();

        if ($exception instanceof PromptRunException) {
            $sectionId = isset($exception->context['section_id'])
                ? (string) $exception->context['section_id']
                : null;
            $state = is_array($exception->context['sectioned_free_state'] ?? null)
                ? $exception->context['sectioned_free_state']
                : [];
            $sections = is_array($state['sections'] ?? null) ? $state['sections'] : [];
            $total = count($sections);
            $completed = 0;
            foreach ($sections as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['status'] ?? '') === SectionedFreeRunState::STATUS_COMPLETED) {
                    $completed++;
                }
                if ($sectionId !== null && ($row['section_id'] ?? '') === $sectionId) {
                    $attempts = (int) ($row['attempt_count'] ?? count($childPromptResultIds));
                }
            }
            if (isset($exception->context['failure_code'])) {
                $code = (string) $exception->context['failure_code'];
                if ($code === 'SECTIONED_FREE_SECTION_FAILED' || $code === 'SECTIONED_FREE_INCOMPLETE') {
                    // keep
                }
            }
        }

        $message = 'SECTIONED_FREE_SECTION_FAILED';
        if ($sectionId !== null) {
            $message .= "\nsection: {$sectionId}";
        }
        if ($attempts !== null) {
            $message .= "\nattempts: {$attempts}";
        }
        $message .= "\nlast_error: ".$lastError;
        if ($completed !== null && $total !== null) {
            $message .= "\ncompleted_sections: {$completed}/{$total}";
        }
        if ($childPromptResultIds !== []) {
            $message .= "\nchild_prompt_result_ids: ".implode(',', $childPromptResultIds);
        }

        return [
            'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
            'message' => $message,
            'section_id' => $sectionId,
            'attempts' => $attempts,
            'completed_sections' => $completed,
            'total_sections' => $total,
            'last_error' => $lastError,
            'child_prompt_result_ids' => $childPromptResultIds,
            'last_breadcrumb' => $breadcrumbs->toArray()['last_event'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<int>  $childIds
     */
    private function orchestratorSummaryPrompt(array $metrics, array $childIds): string
    {
        $lines = [
            'Strategy: sectioned_free',
            'Article target: '.(string) ($metrics['article_target_words'] ?? 0).' words',
            'Minimum units by budget: '.(string) ($metrics['minimum_units_by_budget'] ?? 0),
            'Planned: '.(string) ($metrics['planned_unit_count'] ?? $metrics['generation_unit_count'] ?? 0),
            'Completed: '.(string) ($metrics['completed_unit_count'] ?? $metrics['generation_unit_count'] ?? 0),
            'Failed: '.(string) ($metrics['failed_unit_count'] ?? 0),
            '',
        ];

        foreach (is_array($metrics['per_section_word_counts'] ?? null) ? $metrics['per_section_word_counts'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = (string) ($row['section_id'] ?? 'section')
                .' '.(string) ($row['word_count'] ?? $row['output_word_count'] ?? 0)
                .' words';
        }

        $lines[] = '';
        $lines[] = 'Sum section words: '.(string) ($metrics['sum_section_words'] ?? $metrics['generated_total_word_count'] ?? 0);
        $lines[] = 'Assembled words: '.(string) ($metrics['final_assembled_word_count'] ?? 0);
        $lines[] = 'Child prompt_result_ids: '.implode(',', $childIds);
        $lines[] = '';
        $lines[] = 'Parent node is an orchestrator — it does not send a whole-article provider prompt.';
        $lines[] = 'Open child PromptResults to inspect actual section prompts/outputs.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function linkPromptResultImmediately(
        int $promptResultId,
        int $articleId,
        int $projectRunId,
        int $projectTaskId,
        string $workflowNodeId,
        string $title,
        string $source,
        array $meta = [],
    ): void {
        if ($promptResultId <= 0 || $articleId <= 0) {
            return;
        }

        try {
            app(PromptResultLinkService::class)->linkPromptResult(
                promptResultId: $promptResultId,
                articleId: $articleId,
                source: $source,
                runId: $projectRunId > 0 ? $projectRunId : null,
                taskId: $projectTaskId > 0 ? $projectTaskId : null,
                workflowNodeId: $workflowNodeId !== '' ? $workflowNodeId : null,
                workflowStepTitle: $title,
                meta: $meta,
            );
        } catch (\Throwable) {
            // Best-effort audit link.
        }
    }
}
