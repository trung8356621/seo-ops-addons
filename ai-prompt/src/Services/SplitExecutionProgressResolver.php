<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\Support\SplitExecutionProgress;

/**
 * SSOT builder for SPLIT progress — shared by history UI and resume planner.
 */
final class SplitExecutionProgressResolver
{
    /**
     * @param  array<string, mixed>  $snapshot  PromptResult input_snapshot / step payload
     * @param  array<string, mixed>|null  $tokenUsage
     * @param  list<array<string, mixed>>  $childRows  nested section calls (optional)
     */
    public function fromOrchestratorPayload(
        array $snapshot,
        ?array $tokenUsage = null,
        array $childRows = [],
        ?string $parentStatus = null,
    ): SplitExecutionProgress {
        $tokenUsage = is_array($tokenUsage) ? $tokenUsage : [];
        $failure = is_array($snapshot['sectioned_free_failure'] ?? null)
            ? $snapshot['sectioned_free_failure']
            : (is_array($tokenUsage['sectioned_free_failure'] ?? null) ? $tokenUsage['sectioned_free_failure'] : null);

        $runState = is_array($snapshot['sectioned_free_state'] ?? null)
            ? $snapshot['sectioned_free_state']
            : (is_array($tokenUsage['sectioned_free_state'] ?? null) ? $tokenUsage['sectioned_free_state'] : null);

        $breadcrumbs = is_array($snapshot['breadcrumbs'] ?? null)
            ? $snapshot['breadcrumbs']
            : (is_array($tokenUsage['breadcrumbs'] ?? null) ? $tokenUsage['breadcrumbs'] : null);

        $planned = (int) ($snapshot['sections_planned']
            ?? $snapshot['steps_total']
            ?? $snapshot['sectioned_free']['steps_total']
            ?? $failure['total_sections']
            ?? 0);

        return SplitExecutionProgress::fromPersisted(
            $runState,
            $childRows,
            $failure,
            $parentStatus,
            $breadcrumbs,
            $planned,
        );
    }

    public function fromPromptResult(PromptResult $parent, array $childRows = []): SplitExecutionProgress
    {
        $snapshot = is_array($parent->input_snapshot) ? $parent->input_snapshot : [];
        $usage = is_array($parent->token_usage) ? $parent->token_usage : [];

        return $this->fromOrchestratorPayload(
            $snapshot,
            $usage,
            $childRows,
            (string) $parent->status,
        );
    }

    /**
     * @return array{state: array<string, mixed>, rerun_section_id: ?string, progress: SplitExecutionProgress}|null
     */
    public function resumeBagFromFailedParent(PromptResult $parent): ?array
    {
        $snapshot = is_array($parent->input_snapshot) ? $parent->input_snapshot : [];
        $usage = is_array($parent->token_usage) ? $parent->token_usage : [];
        $state = is_array($snapshot['sectioned_free_state'] ?? null)
            ? $snapshot['sectioned_free_state']
            : (is_array($usage['sectioned_free_state'] ?? null) ? $usage['sectioned_free_state'] : null);

        if (! is_array($state) || $state === []) {
            return null;
        }

        $progress = $this->fromPromptResult($parent);
        if (! $progress->supportsResume()) {
            return null;
        }

        $rerunId = $progress->failedSectionId;
        if ($rerunId === null || $rerunId === '') {
            $run = SectionedFreeRunState::fromArray($state);
            foreach ($run->sectionsSorted() as $row) {
                if (($row['status'] ?? '') === SectionedFreeRunState::STATUS_FAILED) {
                    $rerunId = (string) $row['section_id'];
                    break;
                }
            }
        }

        return [
            'state' => $state,
            'rerun_section_id' => $rerunId !== '' ? $rerunId : null,
            'progress' => $progress,
        ];
    }
}
