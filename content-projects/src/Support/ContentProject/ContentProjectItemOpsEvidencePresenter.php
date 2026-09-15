<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleRuntimeStatusResolver;

/**
 * Ops generation presentation from partitioned run-item evidence.
 *
 * Keeps historical execution separate from lazy-bulk membership / live dispatch.
 */
final class ContentProjectItemOpsEvidencePresenter
{
    /**
     * @param  array<string, mixed>|null  $latestExecution
     * @param  array<string, mixed>|null  $currentMembership
     * @param  array<string, mixed>  $runtimeContext
     * @return array{
     *     generation_status: string,
     *     execution_status: string|null,
     *     runtime: ContentProjectArticleRuntimeStatus,
     *     runtime_status: array<string, mixed>,
     *     runtime_state: string,
     *     runtime_label: string,
     *     is_genuinely_running: bool,
     *     generation_key: string,
     *     generation_badge: array{key: string, label: string, classes: string, icon: string},
     *     runtime_item: array<string, mixed>|null,
     * }
     */
    public static function present(
        string $taskStatus,
        ?array $latestExecution,
        ?array $currentMembership,
        array $runtimeContext,
        bool $isStaleGeneration = false,
        ?ContentProjectArticleRuntimeStatusResolver $runtimeStatus = null,
        string $type = SeoProjectTask::TYPE_CREATE,
        int $articleId = 0,
        bool $isImprove = false,
    ): array {
        $runtimeStatus ??= new ContentProjectArticleRuntimeStatusResolver;
        $exec = $latestExecution;
        $runtimeItem = ContentProjectRunItemEvidenceIndex::runtimeRunItem(
            $latestExecution,
            $currentMembership,
            $runtimeContext,
        );

        $currentRunPrecedence = ContentProjectRunItemEvidenceIndex::currentRunTakesPresentationPrecedence($runtimeContext)
            && $currentMembership !== null
            && ContentProjectRunItemEvidenceIndex::isCurrentBatchMembership($currentMembership, $runtimeContext);

        $execStatusEarly = strtolower((string) ($exec['status'] ?? ''));
        $dispatch = is_array($runtimeContext['active_dispatch'] ?? null)
            ? $runtimeContext['active_dispatch']
            : null;
        $isCurrentDispatchedItem = $runtimeItem !== null
            && ContentProjectRunItemEvidenceIndex::matchesActiveDispatch($runtimeItem, $dispatch);
        $runtimeStatusEarly = strtolower((string) ($runtimeItem['status'] ?? ''));
        $latestAttemptQueued = $isCurrentDispatchedItem
            && in_array($runtimeStatusEarly, ['pending', 'processing'], true);

        $genStatus = (string) ($taskStatus !== '' ? $taskStatus : SeoProjectTask::STATUS_PENDING);
        if ($currentRunPrecedence && in_array($runtimeStatusEarly, ['pending', 'processing'], true)) {
            // Current live/recoverable membership owns CURRENT UI — hide historical Failed.
            $genStatus = SeoProjectTask::STATUS_PENDING;
        } elseif (in_array($execStatusEarly, ['failed', 'error', 'cancelled', 'stopped', 'timeout'], true)) {
            $genStatus = SeoProjectTask::STATUS_FAILED;
        } elseif ($latestAttemptQueued && $genStatus === SeoProjectTask::STATUS_FAILED) {
            $genStatus = SeoProjectTask::STATUS_PENDING;
        } elseif (in_array($execStatusEarly, ['success', 'completed'], true)
            && in_array($genStatus, [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_REVIEWING, 'completed', 'reviewing'], true)
        ) {
            $genStatus = SeoProjectTask::STATUS_COMPLETED;
        }

        $runtime = $runtimeStatus->resolve($runtimeContext + [
            'run_item' => $runtimeItem,
            'task_status' => $currentRunPrecedence ? SeoProjectTask::STATUS_PENDING : $taskStatus,
            'is_generation_stale' => $isStaleGeneration,
            'current_run_batch_waiting' => $currentRunPrecedence
                && $runtimeStatusEarly === 'pending'
                && ! $isCurrentDispatchedItem,
        ]);
        if ($runtime->isActive) {
            $genStatus = SeoProjectTask::STATUS_WRITING;
        }

        $displayGenStatus = $isStaleGeneration ? SeoProjectTask::STATUS_FAILED : $genStatus;
        $rowBase = [
            'generation_status' => $displayGenStatus,
            // Prefer current-run membership status for CURRENT execution_status when live.
            'execution_status' => $currentRunPrecedence
                ? ($runtimeItem['status'] ?? null)
                : ($exec['status'] ?? null),
            'runtime_status' => $runtime->toArray(),
            'is_genuinely_running' => $runtime->isActive,
            'is_generation_stale' => $isStaleGeneration,
            'type' => $type,
            'article_id' => $articleId,
            'is_improve' => $isImprove || $type === SeoProjectTask::TYPE_IMPROVE,
            'queue_status' => 'none',
        ];
        $classified = ContentProjectOpsStateClassifier::classify($rowBase);
        $generationKey = $classified['generation_key'];
        $genBadge = match ($generationKey) {
            'running' => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_ACTIVELY_PROCESSING,
            ),
            'queued' => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_QUEUED,
            ),
            'batch_waiting' => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_BATCH_WAITING,
            ),
            'waiting_ai' => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_WAITING_AI_RETRY,
            ),
            'stale' => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING,
            ),
            'failed' => ContentProjectStatusBadgePresenter::generation('failed', 'failed'),
            'generated' => ContentProjectStatusBadgePresenter::generation('completed', 'success'),
            'not_started' => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION,
            ),
            default => ContentProjectStatusBadgePresenter::runtime(
                ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION,
            ),
        };

        return [
            'generation_status' => $displayGenStatus,
            'execution_status' => $rowBase['execution_status'],
            'runtime' => $runtime,
            'runtime_status' => $runtime->toArray(),
            'runtime_state' => $runtime->state,
            'runtime_label' => $runtime->label,
            'is_genuinely_running' => $runtime->isActive,
            'generation_key' => $generationKey,
            'generation_badge' => $genBadge,
            'runtime_item' => $runtimeItem,
        ];
    }
}
