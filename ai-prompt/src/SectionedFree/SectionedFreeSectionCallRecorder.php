<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;

/**
 * Persist one PromptResult per section provider attempt — inspectable in AI History.
 */
final class SectionedFreeSectionCallRecorder
{
    public const HOOK_KEY = 'article.content.section.generate';

    public const DISPLAY_HOOK = 'article.content.section.generate';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function beginAttempt(
        SeoPrompt $prompt,
        SectionedFreeSectionUnit $unit,
        string $sectionPrompt,
        RoutedAiCandidate $candidate,
        int $attemptNumber,
        ?int $parentPromptResultId,
        string $runId,
        array $meta = [],
    ): PromptResult {
        $label = sprintf(
            'Viết bài — Section %d/%s',
            $unit->order + 1,
            $unit->sectionId,
        );

        return PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'user_id' => (int) (auth()->id() ?? 0),
            'site_id' => (int) ($meta['site_id'] ?? 0),
            'status' => 'running',
            'input_snapshot' => [
                'compiled_prompt' => $sectionPrompt,
                'manual_compiled' => true,
                'hook_key' => self::HOOK_KEY,
                'display_hook_key' => self::DISPLAY_HOOK,
                'display_name' => $label,
                'generation_strategy' => ArticleGenerationStrategy::SectionedFree->value,
                'sectioned_free_section' => true,
                'section_id' => $unit->sectionId,
                'section_order' => $unit->order,
                'section_label' => $unit->label,
                'target_words' => $unit->preferredTargetWords,
                'minimum_words' => SectionedFreeSectionValidator::INCOMPLETE_WORD_THRESHOLD,
                'target_min_words' => $unit->targetMinWords,
                'target_max_words' => $unit->targetMaxWords,
                'prompt_character_count' => mb_strlen($sectionPrompt),
                'attempt' => $attemptNumber,
                'attempt_number' => $attemptNumber,
                'parent_prompt_result_id' => $parentPromptResultId,
                'parent_run_id' => $runId,
                'run_id' => $runId,
                'provider' => $candidate->provider,
                'raw_model_used' => $candidate->model,
                'model' => $candidate->model,
                'connection_id' => (int) $candidate->connection->id,
                'connection_name' => (string) ($candidate->connection->name ?? ''),
                'is_free' => $candidate->isFree,
                'credential_source' => 'configured_connection',
                'isolation_mode' => 'free_test',
                'model_tier' => 'free',
                'outline_subtask' => $unit->sectionId,
                'variables' => [
                    'generation_strategy' => ArticleGenerationStrategy::SectionedFree->value,
                    'hook_key' => self::HOOK_KEY,
                    'section_id' => $unit->sectionId,
                ],
            ],
            'started_at' => now(),
        ]);
    }

    public function completeSuccess(
        PromptResult $result,
        string $output,
        int $wordCount,
        ?array $usage = null,
    ): PromptResult {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $snapshot['output_word_count'] = $wordCount;
        $snapshot['status'] = 'completed';

        $result->update([
            'status' => 'completed',
            'output_text' => $output,
            'token_usage' => $usage,
            'finished_at' => now(),
            'input_snapshot' => $snapshot,
            'error_message' => null,
        ]);

        return $result->fresh() ?? $result;
    }

    public function completeFailure(
        PromptResult $result,
        string $errorMessage,
        ?string $errorCode = null,
    ): PromptResult {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $snapshot['status'] = 'failed';
        $snapshot['error_code'] = $errorCode;
        $snapshot['error_message'] = $errorMessage;

        $result->update([
            'status' => 'failed',
            'error_message' => mb_substr($errorMessage, 0, 2000),
            'finished_at' => now(),
            'input_snapshot' => $snapshot,
        ]);

        return $result->fresh() ?? $result;
    }
}
