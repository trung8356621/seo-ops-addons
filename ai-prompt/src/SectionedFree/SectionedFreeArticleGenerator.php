<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
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
        $rawOutline = trim((string) ($articleContext['outline'] ?? $articleContext['input'] ?? ''));
        $parts = $this->artifactSplitter->split($rawOutline);
        $outline = $parts['outline_markdown'];
        $vocabularyRaw = $parts['vocabulary_raw'];

        $units = $this->prepare->prepare($outline);
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
            ];
            if ($previousSummary !== '') {
                $ctx['previous_section_summary'] = $previousSummary;
            }
            $prompt = $this->promptBuilder->build($unit, $ctx);
            $promptCharCounts[$unit->sectionId] = mb_strlen($prompt);

            // Hard isolation asserts — fail closed in tests / runtime.
            if ($vocabularyRaw !== '' && str_contains($prompt, $vocabularyRaw)) {
                throw new PromptRunException(
                    'Sectioned free prompt leaked raw TASK_2_VOCABULARY.',
                    0,
                    null,
                    ['failure_code' => 'SECTIONED_FREE_VOCAB_LEAK', 'retryable' => false],
                );
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
                ]);
                $providerCalls += $attemptCount;
                $lastModel = (string) ($result['model'] ?? $lastModel);
                $previousSummary = $this->shortSummary($output);
            } catch (\Throwable $exception) {
                $state->markFailed($unit->sectionId, $exception->getMessage());
                throw new PromptRunException(
                    'Sectioned free failed at '.$unit->sectionId.': '.$exception->getMessage(),
                    0,
                    $exception,
                    [
                        'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                        'section_id' => $unit->sectionId,
                        'sectioned_free_state' => $state->toArray(),
                        'retryable' => false,
                        'user_message' => 'Section '.$unit->sectionId.' failed. Other successful sections were kept.',
                    ],
                );
            }
        }

        $rows = $state->sectionsSorted();
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== SectionedFreeRunState::STATUS_COMPLETED) {
                throw new PromptRunException(
                    'Sectioned free incomplete: section '.($row['section_id'] ?? '?').' not completed.',
                    0,
                    null,
                    [
                        'failure_code' => 'SECTIONED_FREE_INCOMPLETE',
                        'sectioned_free_state' => $state->toArray(),
                        'retryable' => false,
                    ],
                );
            }
        }

        $assembled = $this->assembler->assemble($rows);
        $assembledWords = $this->validator->countWords($assembled);
        $metrics = $this->buildMetrics($units, $rows, $assembledWords, $providerCalls, $promptCharCounts);
        $metrics['vocabulary_persisted'] = $parts['vocabulary_persisted'];
        $metrics['vocabulary_injected_into_writer'] = false;
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
                'provider_calls' => $providerCalls,
                'assembled_word_count' => $assembledWords,
                'assemble_mode' => 'deterministic_concat',
                'whole_article_rewrite' => false,
                'vocabulary_injected_into_writer' => false,
            ],
            'last_model' => $lastModel,
            'units' => $units,
            'vocabulary_persisted' => $parts['vocabulary_persisted'],
        ];
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
        array $promptCharCounts = [],
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

        $generatedTotal = array_sum(array_map(
            static fn (array $r): int => (int) ($r['word_count'] ?? 0),
            $rows,
        ));

        return [
            'generation_strategy' => ArticleGenerationStrategy::SectionedFree->value,
            'outline_section_count' => $outlineNodeCount,
            'generation_unit_count' => count($units),
            'requested_target_words' => $targetWords,
            'generated_total_word_count' => $generatedTotal,
            'per_section_word_counts' => $perSection,
            'total_attempts' => $totalAttempts > 0 ? $totalAttempts : $providerCalls,
            'first_attempt_success_count' => $firstAttemptSuccess,
            'fallback_count' => $fallbackCount,
            'models_used' => array_keys($models),
            'final_assembled_word_count' => $assembledWords,
            'exceeds_1000_words' => $assembledWords >= 1000,
            'prompt_character_counts' => $promptCharCounts,
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
