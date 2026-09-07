<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Dev/debug report for sectioned_free observability — no secrets.
 */
final class SectionedFreeDebugReportService
{
    /**
     * @return array<string, mixed>
     */
    public function reportForArticle(int $articleId): array
    {
        $article = SeoArticle::query()->find($articleId);
        $links = SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $resultIds = $links->pluck('prompt_result_id')->map(static fn ($id): int => (int) $id)->all();
        $results = $resultIds === []
            ? collect()
            : PromptResult::query()->whereIn('id', $resultIds)->get()->keyBy('id');

        $parents = [];
        $sections = [];
        $wholeArticleCalls = 0;
        $legacyValidator = false;

        foreach ($results as $result) {
            if (! $result instanceof PromptResult) {
                continue;
            }
            $snap = is_array($result->input_snapshot) ? $result->input_snapshot : [];
            if (! empty($snap['sectioned_free_orchestrator'])) {
                $parents[] = $this->summarizeParent($result, $snap);
                if (! empty($snap['legacy_validator_reached'])) {
                    $legacyValidator = true;
                }
            }
            if (! empty($snap['sectioned_free_section'])) {
                $sections[] = $this->summarizeSection($result, $snap);
            }
            $hook = (string) ($snap['hook_key'] ?? $snap['variables']['hook_key'] ?? '');
            if (
                $hook === 'article.content.generate'
                && empty($snap['sectioned_free_orchestrator'])
                && empty($snap['sectioned_free_section'])
            ) {
                $wholeArticleCalls++;
            }
        }

        usort($sections, static function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['section_id'] ?? ''), (string) ($b['section_id'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($a['attempt'] ?? 0)) <=> ((int) ($b['attempt'] ?? 0));
        });

        $latestParent = $parents[0] ?? null;

        return [
            'article_id' => $articleId,
            'article_found' => $article instanceof SeoArticle,
            'strategy_override' => $article?->getAttribute('generation_strategy_override') ?? null,
            'parent_runs' => $parents,
            'latest_parent' => $latestParent,
            'sections' => $sections,
            'sections_planned' => is_array($latestParent)
                ? ($latestParent['sections_planned'] ?? null)
                : null,
            'section_calls_persisted' => count($sections),
            'whole_article_ai_calls' => $wholeArticleCalls,
            'legacy_validator_reached' => $legacyValidator,
            'final_failure_point' => $this->inferFailurePoint($latestParent, $sections),
        ];
    }

    /**
     * @param  array<string, mixed>  $snap
     * @return array<string, mixed>
     */
    private function summarizeParent(PromptResult $result, array $snap): array
    {
        $breadcrumbs = is_array($snap['breadcrumbs'] ?? null) ? $snap['breadcrumbs'] : [];
        $last = $breadcrumbs !== [] ? $breadcrumbs[array_key_last($breadcrumbs)] : null;

        return [
            'prompt_result_id' => (int) $result->id,
            'status' => (string) $result->status,
            'run_id' => $snap['run_id'] ?? null,
            'sections_planned' => $snap['sections_planned'] ?? null,
            'child_prompt_result_ids' => $snap['child_prompt_result_ids'] ?? [],
            'breadcrumbs_last' => is_array($last) ? ($last['event'] ?? null) : null,
            'error_message' => $result->error_message,
            'legacy_validator_reached' => (bool) ($snap['legacy_validator_reached'] ?? false),
            'started_at' => optional($result->started_at)?->toIso8601String(),
            'finished_at' => optional($result->finished_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snap
     * @return array<string, mixed>
     */
    private function summarizeSection(PromptResult $result, array $snap): array
    {
        return [
            'prompt_result_id' => (int) $result->id,
            'section_id' => $snap['section_id'] ?? null,
            'attempt' => $snap['attempt'] ?? $snap['attempt_number'] ?? null,
            'status' => (string) $result->status,
            'model' => $snap['model'] ?? $snap['raw_model_used'] ?? null,
            'connection_id' => $snap['connection_id'] ?? null,
            'words' => $snap['output_word_count'] ?? null,
            'prompt_character_count' => $snap['prompt_character_count'] ?? null,
            'parent_prompt_result_id' => $snap['parent_prompt_result_id'] ?? null,
            'error_code' => $snap['error_code'] ?? null,
            'error_message' => $result->error_message,
            'has_compiled_prompt' => trim((string) ($snap['compiled_prompt'] ?? '')) !== '',
            'has_output' => trim((string) ($result->output_text ?? '')) !== '',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $parent
     * @param  list<array<string, mixed>>  $sections
     */
    private function inferFailurePoint(?array $parent, array $sections): ?string
    {
        if ($parent === null) {
            return $sections === [] ? 'no_sectioned_free_parent_found' : null;
        }
        if (($parent['status'] ?? '') !== 'failed') {
            return null;
        }
        foreach (array_reverse($sections) as $section) {
            if (($section['status'] ?? '') === 'failed') {
                return 'section_failed:'.(string) ($section['section_id'] ?? '?')
                    .' attempt='.(string) ($section['attempt'] ?? '?');
            }
        }

        return (string) ($parent['breadcrumbs_last'] ?? 'parent_failed');
    }

    public function formatText(array $report): string
    {
        $lines = [
            'Article '.(string) ($report['article_id'] ?? '?'),
            '',
            'Strategy override: '.json_encode($report['strategy_override'] ?? null),
            'Resolved strategy: '.(($report['latest_parent'] ?? null) ? 'sectioned_free' : 'unknown/not_found'),
            'Parent run: #'.(string) ($report['latest_parent']['prompt_result_id'] ?? 'n/a'),
            '',
            'Sections planned: '.json_encode($report['sections_planned'] ?? null),
            'Section calls persisted: '.(string) ($report['section_calls_persisted'] ?? 0),
            '',
        ];

        foreach (is_array($report['sections'] ?? null) ? $report['sections'] : [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $lines[] = (string) ($section['section_id'] ?? 'section');
            $lines[] = '  prompt result: #'.(string) ($section['prompt_result_id'] ?? '');
            $lines[] = '  model: '.(string) ($section['model'] ?? '');
            $lines[] = '  attempts: '.(string) ($section['attempt'] ?? '');
            $lines[] = '  status: '.(string) ($section['status'] ?? '');
            $lines[] = '  words: '.json_encode($section['words'] ?? null);
            if (! empty($section['error_message'])) {
                $lines[] = '  error: '.(string) $section['error_message'];
            }
            $lines[] = '';
        }

        $lines[] = 'Whole article AI calls: '.(string) ($report['whole_article_ai_calls'] ?? 0);
        $lines[] = 'Legacy 1000-word validator reached: '.(($report['legacy_validator_reached'] ?? false) ? 'YES' : 'NO');
        $lines[] = 'Final failure point: '.json_encode($report['final_failure_point'] ?? null);

        return implode("\n", $lines);
    }
}
