<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Orchestrates PrepareSections → GenerateSection × N → deterministic Assemble.
 *
 * Isolation: free-only routing is provided by the injected section executor
 * (PromptRunner wires AiRoutingContext.freeOnly). No global mutable routing state.
 */
final class SectionedFreeArticleGenerator
{
    public function __construct(
        private readonly SectionedFreePrepareSections $prepare = new SectionedFreePrepareSections(),
        private readonly SectionedFreeSectionPromptBuilder $promptBuilder = new SectionedFreeSectionPromptBuilder(),
        private readonly SectionedFreeSectionValidator $validator = new SectionedFreeSectionValidator(),
        private readonly SectionedFreeAssembleArticle $assembler = new SectionedFreeAssembleArticle(),
        private readonly SectionedFreeKeywordSuggester $keywordSuggester = new SectionedFreeKeywordSuggester(),
        private readonly SectionedFreeArtifactSplitter $artifactSplitter = new SectionedFreeArtifactSplitter(),
    ) {}

    /**
     * @param  array<string, mixed>  $articleContext
     * @param  callable(SectionedFreeSectionUnit $unit, string $prompt): array{
     *   output: string,
     *   model?: string|null,
     *   provider?: string|null,
     *   connection_id?: int|null,
     *   attempt_count?: int,
     *   fallback_count?: int,
     *   usage?: array<string, mixed>|null
     * }  $sectionExecutor
     * @return array{
     *   assembled: string,
     *   state: SectionedFreeRunState,
     *   metrics: array<string, mixed>,
     *   usage: array<string, mixed>,
     *   last_model: string,
     *   units: list<SectionedFreeSectionUnit>,
     *   plan: SectionedFreePlan,
     *   vocabulary_persisted: bool
     * }
     */
    public function run(
        array $articleContext,
        callable $sectionExecutor,
        ?SectionedFreeRunState $priorState = null,
        ?string $rerunSectionId = null,
        ?string $runId = null,
    ): array {
        $rawOutline = trim((string) (
            $articleContext['article_outline']
            ?? $articleContext['outline']
            ?? $articleContext['input']
            ?? ''
        ));
        $explicitVocabulary = trim((string) ($articleContext['article_vocabulary'] ?? ''));
        $parts = $this->artifactSplitter->split($rawOutline);
        $outline = $parts['outline_markdown'];
        // Prefer typed vocabulary artifact over regex-split from combined blob.
        $vocabularyRaw = $explicitVocabulary !== ''
            ? $explicitVocabulary
            : $parts['vocabulary_raw'];

        $articleTargetWords = $this->resolveArticleTargetWords($articleContext);
        $writingSplit = (bool) ($articleContext['writing_split_enabled'] ?? false)
            || (($articleContext['pass_mode'] ?? '') === 'multiple_pass');

        if ($writingSplit && ($articleContext['writing_multiple_pass_plan'] ?? null) instanceof SectionedFreePlan) {
            $plan = $articleContext['writing_multiple_pass_plan'];
        } elseif ($writingSplit && is_array($articleContext['structured_outline_rows'] ?? null)) {
            $plan = (new \Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner())
                ->planFromRows($articleContext['structured_outline_rows']);
        } else {
            $plan = $this->prepare->preparePlan($outline, $articleTargetWords);
        }
        $units = $plan->units;

        if (
            ! $writingSplit
            && $plan->minimumUnitsByBudget() > 1
            && $plan->plannedUnitCount() < $plan->minimumUnitsByBudget()
            && ! ($plan->meta['insufficient_outline_material'] ?? false)
        ) {
            throw new PromptRunException(
                'SECTIONED_FREE_PLAN_INVARIANT: planned_unit_count < minimum_units_by_budget without explicit reason.',
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_PLAN_INVARIANT',
                    'plan' => $plan->meta,
                    'retryable' => false,
                ],
            );
        }

        $articleMap = $this->promptBuilder->buildArticleMap($units);
        $state = $priorState ?? new SectionedFreeRunState();
        $state->setRun($runId ?? uniqid('sf_', true));

        $rerunId = $rerunSectionId !== null ? trim($rerunSectionId) : '';
        if ($rerunId !== '') {
            $state->invalidateSection($rerunId);
        }

        foreach ($units as $unit) {
            $state->planSection($unit);
        }

        $providerCalls = 0;
        $lastModel = '';
        $previousSummary = '';
        $promptCharCounts = [];
        $assembleCalled = false;
        /** @var null|callable(SectionedFreeSectionUnit): string $sectionPromptFactory */
        $sectionPromptFactory = is_callable($articleContext['section_prompt_factory'] ?? null)
            ? $articleContext['section_prompt_factory']
            : null;

        foreach ($units as $unit) {
            if ($rerunId !== '' && $unit->sectionId !== $rerunId) {
                if ($state->isCompleted($unit->sectionId)) {
                    $previousSummary = $this->shortSummary(
                        (string) ($state->toArray()['sections'][$unit->sectionId]['output'] ?? ''),
                    );
                }
                continue;
            }

            if ($rerunId === '' && $state->isCompleted($unit->sectionId)) {
                $previousSummary = $this->shortSummary(
                    (string) ($state->toArray()['sections'][$unit->sectionId]['output'] ?? ''),
                );
                continue;
            }

            $state->markRunning($unit->sectionId);
            if ($sectionPromptFactory !== null) {
                $prompt = $sectionPromptFactory($unit);
            } else {
                $ctx = [
                    'title' => $articleContext['title'] ?? '',
                    'primary_keyword' => $articleContext['primary_keyword']
                        ?? $articleContext['keyword']
                        ?? '',
                    'intent' => $articleContext['intent']
                        ?? $articleContext['search_intent']
                        ?? $articleContext['content_intent']
                        ?? '',
                    'language' => $articleContext['language'] ?? 'vi',
                    'article_map' => $articleMap,
                    'suggested_keywords' => $this->keywordSuggester->suggestForSection($unit, $vocabularyRaw),
                    'emit_parent_heading' => $unit->emitParentHeading,
                    'parent_h2' => $unit->parentH2,
                ];
                // MULTIPLE_PASS writing_split: never chain previous generated output.
                if (! $writingSplit && $previousSummary !== '') {
                    $ctx['previous_section_summary'] = $previousSummary;
                }
                $prompt = $this->promptBuilder->build($unit, $ctx);
            }
            $promptCharCounts[$unit->sectionId] = mb_strlen($prompt);

            if ($vocabularyRaw !== '' && str_contains($prompt, $vocabularyRaw)) {
                throw new PromptRunException(
                    'Sectioned free prompt leaked raw TASK_2_VOCABULARY.',
                    0,
                    null,
                    ['failure_code' => 'SECTIONED_FREE_VOCAB_LEAK', 'retryable' => false],
                );
            }
            if ($writingSplit) {
                (new \Omnichannel\Addons\AiPrompt\Support\WritingMultiplePassPromptIsolationGuard())
                    ->assertCompiledSectionPrompt($prompt, $unit, $outline);
            } else {
                (new SectionedFreePromptIsolationGuard())->assertSectionPromptIsIsolated($prompt, [
                    'section_id' => $unit->sectionId,
                    'run_id' => (string) ($state->toArray()['run_id'] ?? ''),
                ]);
            }
            if (
                str_contains($prompt, 'DYNAMIC WORD ALLOCATION')
                || str_contains($prompt, 'target 1000 words')
                || str_contains($prompt, '80% of 1000')
                || str_contains($prompt, ArticleGenerationInputResolver::VOCABULARY_START)
            ) {
                throw new PromptRunException(
                    'Sectioned free prompt contains forbidden whole-article allocation or vocabulary markers.',
                    0,
                    null,
                    ['failure_code' => 'SECTIONED_FREE_PROMPT_CONTRACT', 'retryable' => false],
                );
            }

            try {
                $result = $sectionExecutor($unit, $prompt);
                $output = trim((string) ($result['output'] ?? ''));
                $wordCount = $this->validator->assertAcceptable($output, $unit);
                $attemptCount = max(1, (int) ($result['attempt_count'] ?? 1));
                $fallbackCount = max(0, (int) ($result['fallback_count'] ?? 0));
                $state->markCompleted($unit->sectionId, $output, $wordCount, [
                    'model' => $result['model'] ?? null,
                    'provider' => $result['provider'] ?? null,
                    'connection_id' => isset($result['connection_id']) ? (int) $result['connection_id'] : null,
                    'attempt_count' => $attemptCount,
                    'fallback_count' => $fallbackCount,
                    'first_attempt_success' => $attemptCount === 1 && $fallbackCount === 0,
                    'prompt_character_count' => $promptCharCounts[$unit->sectionId],
                    'target_words' => $unit->preferredTargetWords,
                    'minimum_words' => SectionedFreeSectionValidator::INCOMPLETE_WORD_THRESHOLD,
                    'emit_parent_heading' => $unit->emitParentHeading,
                    'parent_h2' => $unit->parentH2,
                ]);
                $providerCalls += $attemptCount;
                $lastModel = (string) ($result['model'] ?? $lastModel);
                $previousSummary = $this->shortSummary($output);
            } catch (\Throwable $exception) {
                $state->markFailed($unit->sectionId, $exception->getMessage());
                $completed = $this->countCompleted($state);
                throw new PromptRunException(
                    $this->formatSectionFailedMessage($unit->sectionId, $exception->getMessage(), $completed, count($units)),
                    0,
                    $exception,
                    [
                        'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                        'section_id' => $unit->sectionId,
                        'planned_sections' => count($units),
                        'completed_sections' => $completed,
                        'assemble_called' => false,
                        'sectioned_free_state' => $state->toArray(),
                        'plan' => $plan->meta,
                        'retryable' => false,
                        'user_message' => 'Section '.$unit->sectionId.' failed. Assemble was not called.',
                    ],
                );
            }
        }

        $rows = $state->sectionsSorted();
        $completed = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === SectionedFreeRunState::STATUS_COMPLETED) {
                $completed++;
            }
        }
        if ($completed !== count($units)) {
            throw new PromptRunException(
                $this->formatSectionFailedMessage('incomplete', 'not all sections completed', $completed, count($units)),
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                    'planned_sections' => count($units),
                    'completed_sections' => $completed,
                    'assemble_called' => false,
                    'sectioned_free_state' => $state->toArray(),
                    'plan' => $plan->meta,
                    'retryable' => false,
                ],
            );
        }

        $assembleCalled = true;
        $assembled = $this->assembler->assemble($rows, $units);
        $parity = $this->assembler->assertWordParity($rows, $assembled);
        $assembledWords = $parity['assembled_words'];

        $this->assertFinalLengthContract(
            $articleTargetWords,
            $assembledWords,
            $units,
            $rows,
            $parity['sum_section_words'],
            $plan,
        );

        $metrics = $this->buildMetrics(
            $units,
            $rows,
            $assembledWords,
            $providerCalls,
            $promptCharCounts,
            $plan,
            $parity['sum_section_words'],
        );
        $metrics['vocabulary_persisted'] = $parts['vocabulary_persisted'];
        $metrics['vocabulary_injected_into_writer'] = false;
        $metrics['assemble_called'] = $assembleCalled;
        $state->setMetrics($metrics);

        return [
            'assembled' => $assembled,
            'state' => $state,
            'metrics' => $metrics,
            'usage' => [
                'generation_strategy' => ArticleGenerationStrategy::SectionedFree->value,
                'isolation_mode' => 'free_test',
                'model_tier' => 'free',
                'sectioned_free' => $metrics,
                'sectioned_free_state' => $state->toArray(),
                'sectioned_free_trace' => $state->traceChildren(),
                'sectioned_free_plan' => $plan->meta,
                'provider_calls' => $providerCalls,
                'assembled_word_count' => $assembledWords,
                'sum_section_words' => $parity['sum_section_words'],
                'assemble_mode' => 'deterministic_concat',
                'whole_article_rewrite' => false,
                'vocabulary_injected_into_writer' => false,
            ],
            'last_model' => $lastModel,
            'units' => $units,
            'plan' => $plan,
            'vocabulary_persisted' => $parts['vocabulary_persisted'],
        ];
    }

    /**
     * @param  array<string, mixed>  $articleContext
     */
    private function resolveArticleTargetWords(array $articleContext): int
    {
        foreach (['article_length', 'target_words', 'target_article_length', 'resolved_article_length'] as $key) {
            if (! array_key_exists($key, $articleContext) || $articleContext[$key] === null || $articleContext[$key] === '') {
                continue;
            }
            $raw = $articleContext[$key];
            if (is_numeric($raw)) {
                return max(0, (int) $raw);
            }
            if (is_string($raw) && preg_match('/(\d+)/', $raw, $m) === 1) {
                return max(0, (int) $m[1]);
            }
        }

        return 0;
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $units
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertFinalLengthContract(
        int $articleTargetWords,
        int $assembledWords,
        array $units,
        array $rows,
        int $sumSectionWords,
        SectionedFreePlan $plan,
    ): void {
        if ($articleTargetWords <= 0) {
            return;
        }

        $minimum = (new ArticleGenerationLengthValidator)->minimumForTarget($articleTargetWords);
        if ($assembledWords >= $minimum) {
            return;
        }

        $perSection = [];
        foreach ($rows as $row) {
            $perSection[] = [
                'section_id' => $row['section_id'] ?? null,
                'words' => (int) ($row['word_count'] ?? 0),
            ];
        }

        throw new PromptRunException(
            "SECTIONED_FREE_FINAL_TOO_SHORT\n"
            .'planned units: '.count($units)."\n"
            .'completed units: '.count($units)."\n"
            .'sum_section_words: '.$sumSectionWords."\n"
            .'final words: '.$assembledWords."\n"
            .'target: '.$articleTargetWords."\n"
            .'minimum: '.$minimum,
            0,
            null,
            [
                'failure_code' => 'SECTIONED_FREE_FINAL_TOO_SHORT',
                'planned_units' => count($units),
                'completed_units' => count($units),
                'per_section_words' => $perSection,
                'sum_section_words' => $sumSectionWords,
                'final_assembled_word_count' => $assembledWords,
                'target_words' => $articleTargetWords,
                'minimum_words' => $minimum,
                'plan' => $plan->meta,
                'retryable' => false,
            ],
        );
    }

    private function formatSectionFailedMessage(
        string $sectionId,
        string $lastError,
        int $completed,
        int $planned,
    ): string {
        return "SECTIONED_FREE_SECTION_FAILED\n"
            ."section: {$sectionId}\n"
            .'last_error: '.$lastError."\n"
            ."completed_sections: {$completed}/{$planned}";
    }

    private function countCompleted(SectionedFreeRunState $state): int
    {
        $n = 0;
        foreach ($state->sectionsSorted() as $row) {
            if (($row['status'] ?? '') === SectionedFreeRunState::STATUS_COMPLETED) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $units
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $promptCharCounts
     * @return array<string, mixed>
     */
    private function buildMetrics(
        array $units,
        array $rows,
        int $assembledWords,
        int $providerCalls,
        array $promptCharCounts,
        SectionedFreePlan $plan,
        int $sumSectionWords,
    ): array {
        $perSection = [];
        $models = [];
        $firstAttemptSuccess = 0;
        $fallbackCount = 0;
        $totalAttempts = 0;
        $outlineNodeCount = 0;
        $targetWords = 0;

        foreach ($units as $unit) {
            $outlineNodeCount += count($unit->outlineNodes);
            $targetWords += $unit->preferredTargetWords;
        }

        foreach ($rows as $row) {
            $sectionId = (string) ($row['section_id'] ?? '');
            $perSection[] = [
                'section_id' => $sectionId,
                'word_count' => (int) ($row['word_count'] ?? 0),
                'output_word_count' => (int) ($row['word_count'] ?? 0),
                'target_words' => (int) ($row['target_words'] ?? 0),
                'minimum_words' => (int) ($row['minimum_words'] ?? SectionedFreeSectionValidator::INCOMPLETE_WORD_THRESHOLD),
                'status' => $row['status'] ?? null,
                'model' => $row['model'] ?? null,
                'provider' => $row['provider'] ?? null,
                'attempt_count' => (int) ($row['attempt_count'] ?? 0),
                'prompt_character_count' => (int) ($row['prompt_character_count']
                    ?? $promptCharCounts[$sectionId]
                    ?? 0),
            ];
            $model = trim((string) ($row['model'] ?? ''));
            if ($model !== '') {
                $models[$model] = true;
            }
            if (! empty($row['first_attempt_success'])) {
                $firstAttemptSuccess++;
            }
            $fallbackCount += (int) ($row['fallback_count'] ?? 0);
            $totalAttempts += (int) ($row['attempt_count'] ?? 0);
        }

        return [
            'generation_strategy' => ArticleGenerationStrategy::SectionedFree->value,
            'article_target_words' => $plan->articleTargetWords(),
            'minimum_units_by_budget' => $plan->minimumUnitsByBudget(),
            'outline_section_count' => $outlineNodeCount,
            'generation_unit_count' => count($units),
            'planned_unit_count' => count($units),
            'completed_unit_count' => count($units),
            'failed_unit_count' => 0,
            'requested_target_words' => $targetWords,
            'generated_total_word_count' => $sumSectionWords,
            'sum_section_words' => $sumSectionWords,
            'per_section_word_counts' => $perSection,
            'total_attempts' => $totalAttempts > 0 ? $totalAttempts : $providerCalls,
            'first_attempt_success_count' => $firstAttemptSuccess,
            'fallback_count' => $fallbackCount,
            'models_used' => array_keys($models),
            'final_assembled_word_count' => $assembledWords,
            'exceeds_1000_words' => $assembledWords >= 1000,
            'prompt_character_counts' => $promptCharCounts,
            'plan' => $plan->meta,
        ];
    }

    private function shortSummary(string $text): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($normalized === '') {
            return '';
        }

        return mb_strlen($normalized) <= 280
            ? $normalized
            : rtrim(mb_substr($normalized, 0, 277)).'...';
    }
}
