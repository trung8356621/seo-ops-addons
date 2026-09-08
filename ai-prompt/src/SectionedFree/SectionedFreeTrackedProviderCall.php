<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;

/**
 * Provider-boundary wrapper: every section attempt creates a persistent PromptResult.
 */
final class SectionedFreeTrackedProviderCall
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly SectionedFreeSectionCallRecorder $recorder = new SectionedFreeSectionCallRecorder(),
        private readonly ?PromptResultLinkService $promptResultLinks = null,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $meta
     * @return array{0: string, 1: array<string, mixed>, 2: PromptResult}
     */
    public function call(
        RoutedAiCandidate $routed,
        SeoPrompt $prompt,
        string $sectionPrompt,
        array $variables,
        string $toolType,
        SectionedFreeSectionUnit $unit,
        int $attemptNumber,
        ?int $parentPromptResultId,
        string $runId,
        array $meta = [],
    ): array {
        // Paid fallback is allowed for sectioned shape when Free only is OFF.
        // Free-only filtering is owned by AiRuntimeHealthService / connection paid_locked.

        $child = $this->recorder->beginAttempt(
            $prompt,
            $unit,
            $sectionPrompt,
            $routed,
            $attemptNumber,
            $parentPromptResultId,
            $runId,
            $meta,
        );

        // Audit link immediately — must survive parent failure / later rollbacks of business state.
        $this->linkChildImmediately($child, $unit, $meta);

        $boundary = [
            'provider_call_id' => (int) $child->id,
            'run_id' => $runId,
            'parent_run_id' => $parentPromptResultId,
            'parent_prompt_result_id' => $parentPromptResultId,
            'strategy' => 'sectioned',
            'section_id' => $unit->sectionId,
            'model' => $routed->model,
            'connection_id' => (int) $routed->connection->id,
            'prompt_hash' => hash('sha256', $sectionPrompt),
            'prompt_character_count' => mb_strlen($sectionPrompt),
            'attempt' => $attemptNumber,
        ];

        $started = microtime(true);

        try {
            [$output, $usage] = $this->promptRunner->callProviderForSectionedFree(
                $routed,
                $prompt,
                $sectionPrompt,
                $variables,
                $toolType,
                $unit,
            );
            $wordCount = PromptTextMetrics::wordCount($output);
            $usage = is_array($usage) ? $usage : [];
            $usage['provider_boundary'] = array_merge($boundary, [
                'status' => 'completed',
                'output_word_count' => $wordCount,
                'duration_ms' => (int) max(0, round((microtime(true) - $started) * 1000)),
            ]);
            $usage['prompt_result_id'] = (int) $child->id;
            $usage['provider_call_id'] = (int) $child->id;

            $this->recorder->completeSuccess($child, $output, $wordCount, $usage);

            return [$output, $usage, $child->fresh() ?? $child];
        } catch (\Throwable $exception) {
            $errorCode = null;
            if ($exception instanceof PromptRunException) {
                $errorCode = isset($exception->context['failure_code'])
                    ? (string) $exception->context['failure_code']
                    : null;
            }
            $this->recorder->completeFailure(
                $child,
                $exception->getMessage(),
                $errorCode,
            );

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function linkChildImmediately(
        PromptResult $child,
        SectionedFreeSectionUnit $unit,
        array $meta,
    ): void {
        $articleId = (int) ($meta['article_id'] ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $linker = $this->promptResultLinks ?? app(PromptResultLinkService::class);
        $projectRunId = (int) ($meta['project_run_id'] ?? $meta['run_id'] ?? 0);
        $projectTaskId = (int) ($meta['project_task_id'] ?? $meta['task_id'] ?? 0);
        $nodeId = trim((string) ($meta['node_id'] ?? ''));
        $label = trim((string) (is_array($child->input_snapshot) ? ($child->input_snapshot['display_name'] ?? '') : ''));
        if ($label === '') {
            $label = sprintf('Viết bài — Section %d', $unit->order + 1);
        }

        try {
            $linker->linkPromptResult(
                promptResultId: (int) $child->id,
                articleId: $articleId,
                source: 'sectioned_free_section',
                runId: $projectRunId > 0 ? $projectRunId : null,
                taskId: $projectTaskId > 0 ? $projectTaskId : null,
                workflowNodeId: $nodeId !== '' ? $nodeId : null,
                workflowStepTitle: $label,
                meta: [
                    'section_id' => $unit->sectionId,
                    'section_order' => $unit->order,
                    'generation_strategy' => 'sectioned',
                    'generation_shape' => 'sectioned',
                    'hook_key' => SectionedFreeSectionCallRecorder::HOOK_KEY,
                    'parent_prompt_result_id' => $meta['parent_prompt_result_id'] ?? null,
                ],
            );
        } catch (\Throwable) {
            // History link is best-effort; never abort a live provider attempt.
        }
    }
}
