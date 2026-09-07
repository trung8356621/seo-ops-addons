<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
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

        $boundary = [
            'provider_call_id' => (int) $child->id,
            'run_id' => $runId,
            'parent_run_id' => $parentPromptResultId,
            'parent_prompt_result_id' => $parentPromptResultId,
            'strategy' => 'sectioned_free',
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
}
