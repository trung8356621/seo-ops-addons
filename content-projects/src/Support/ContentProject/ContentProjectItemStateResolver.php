<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;

/**
 * Single canonical Content Project item state resolver (Batch D).
 *
 * Precedence for lifecycle_state:
 * 1. content archive
 * 2. published revision (stays published during rerun running/failed)
 * 3. active publish queue / scheduled
 * 4. publish failed (only when never published)
 * 5. active generation
 * 6. generation failed (only when never published)
 * 7. approved review
 * 8. pending review / completed awaiting review
 * 9. draft
 */
final class ContentProjectItemStateResolver
{
    private readonly ContentProjectItemActionGuard $actionGuard;

    private readonly PublishingActiveProcessing $activeProcessing;

    public function __construct(
        ?ContentProjectItemActionGuard $actionGuard = null,
        ?PublishingActiveProcessing $activeProcessing = null,
    ) {
        $this->actionGuard = $actionGuard ?? new ContentProjectItemActionGuard;
        $this->activeProcessing = $activeProcessing ?? new PublishingActiveProcessing;
    }

    /**
     * @param  array{
     *     run_item_status?: string|null,
     *     run_item_error?: string|null,
     *     execution_running?: bool,
     *     stale_generation?: bool
     * }  $hints
     */
    public function resolve(SeoProjectTask $task, ?SeoArticle $article = null, array $hints = []): ContentProjectItemState
    {
        $article ??= $task->relationLoaded('article') ? $task->article : null;
        $project = $task->relationLoaded('project') ? $task->project : null;

        $archive = $this->resolveArchive($task, $project instanceof SeoProject ? $project : null);
        $review = $this->resolveReview($article);
        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'))
            ?? ContentProjectPublishQueueStatus::None;
        $latestPublishAttemptFailed = $queue === ContentProjectPublishQueueStatus::Failed;
        $hasPublished = ContentProjectPublishedEvidence::fromTaskAndArticle(
            $task,
            $article instanceof SeoArticle ? $article : null,
            $hints,
        );
        $publish = $this->resolvePublish(
            $task,
            $article instanceof SeoArticle ? $article : null,
            $hasPublished,
        );

        $generation = $this->resolveGeneration($task, $hints);
        $execution = $this->resolveExecution(
            $task,
            $publish,
            $generation,
            $hints,
            $latestPublishAttemptFailed,
            $hasPublished,
        );
        [$error, $errorSource] = $this->resolveError(
            $task,
            $publish,
            $generation,
            $hasPublished,
            $hints,
            $latestPublishAttemptFailed,
        );

        $lifecycle = $this->resolveLifecycle(
            $archive,
            $hasPublished,
            $publish,
            $generation,
            $review,
        );

        $displayPublish = $hasPublished ? ContentProjectItemPublishState::Published : $publish;
        $isActivelyPublishing = $this->activeProcessing->isActivelyPublishing($task);

        $actions = $this->actionGuard->availableActions(
            $lifecycle,
            $archive,
            $displayPublish,
            $generation,
            $review,
            $hasPublished,
            $latestPublishAttemptFailed,
            $queue,
            $isActivelyPublishing,
        );

        $blocking = $this->blockingReason($archive, $lifecycle, $generation, $isActivelyPublishing);

        return new ContentProjectItemState(
            lifecycleState: $lifecycle,
            generationState: $generation,
            reviewState: $review,
            publishState: $displayPublish,
            executionState: $execution,
            archiveState: $archive,
            availableActions: $actions,
            blockingReason: $blocking,
            currentError: $error,
            currentErrorSource: $errorSource,
            hasPublishedRevision: $hasPublished,
            latestPublishAttemptFailed: $latestPublishAttemptFailed,
        );
    }

    public function resolvePhase(SeoProjectTask $task, ?SeoArticle $article = null, array $hints = []): ContentProjectLifecyclePhase
    {
        return $this->resolve($task, $article, $hints)->lifecycleState;
    }

    private function resolveArchive(SeoProjectTask $task, ?SeoProject $project): ContentProjectItemArchiveState
    {
        if ($project instanceof SeoProject && $this->hasFilledAttr($project, 'archived_at')) {
            return ContentProjectItemArchiveState::ContentArchived;
        }

        if ($this->hasFilledAttr($task, 'archived_at')) {
            return ContentProjectItemArchiveState::ContentArchived;
        }

        $status = ContentProjectTaskStatusNormalizer::tryNormalize((string) ($task->status ?? ''));
        if ($status === SeoProjectTaskStatus::Archived) {
            return ContentProjectItemArchiveState::ContentArchived;
        }

        return ContentProjectItemArchiveState::None;
    }

    private function resolveReview(?SeoArticle $article): ContentProjectItemReviewState
    {
        if (! $article instanceof SeoArticle) {
            return ContentProjectItemReviewState::None;
        }

        $stored = ArticleReviewStatus::tryFromString(
            is_string($article->review_status ?? null) ? (string) $article->review_status : null,
        );

        return match ($stored) {
            ArticleReviewStatus::Approved => ContentProjectItemReviewState::Approved,
            ArticleReviewStatus::Archived => ContentProjectItemReviewState::ReviewArchived,
            ArticleReviewStatus::PendingReview => ContentProjectItemReviewState::PendingReview,
            ArticleReviewStatus::Draft => ContentProjectItemReviewState::Draft,
            default => ContentProjectItemReviewState::Draft,
        };
    }

    private function resolvePublish(
        SeoProjectTask $task,
        ?SeoArticle $article,
        bool $hasPublished,
    ): ContentProjectItemPublishState {
        unset($article);
        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'))
            ?? ContentProjectPublishQueueStatus::None;

        if ($hasPublished) {
            return ContentProjectItemPublishState::Published;
        }

        if ($queue->isActiveQueue()) {
            return ContentProjectItemPublishState::Queued;
        }

        if ($queue === ContentProjectPublishQueueStatus::Failed) {
            return ContentProjectItemPublishState::PublishFailed;
        }

        // scheduled_publish_at wins over cancelled/skipped stamp (dashboard SQL / dirty cancel rows).
        if ($this->hasFilledAttr($task, 'scheduled_publish_at')) {
            return ContentProjectItemPublishState::Scheduled;
        }

        if ($queue === ContentProjectPublishQueueStatus::Skipped) {
            return ContentProjectItemPublishState::Skipped;
        }

        if ($queue === ContentProjectPublishQueueStatus::Cancelled) {
            return ContentProjectItemPublishState::Cancelled;
        }

        return ContentProjectItemPublishState::None;
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    private function resolveGeneration(SeoProjectTask $task, array $hints): ContentProjectItemGenerationState
    {
        if (! empty($hints['stale_generation'])) {
            return ContentProjectItemGenerationState::Failed;
        }

        $status = ContentProjectTaskStatusNormalizer::tryNormalize((string) ($task->status ?? ''));

        return match ($status) {
            SeoProjectTaskStatus::Writing => ContentProjectItemGenerationState::Writing,
            SeoProjectTaskStatus::Processing => ContentProjectItemGenerationState::Processing,
            SeoProjectTaskStatus::Pending => ContentProjectItemGenerationState::Pending,
            SeoProjectTaskStatus::Completed, SeoProjectTaskStatus::Reviewing => ContentProjectItemGenerationState::Completed,
            SeoProjectTaskStatus::Failed => ContentProjectItemGenerationState::Failed,
            SeoProjectTaskStatus::Cancelled => ContentProjectItemGenerationState::Cancelled,
            SeoProjectTaskStatus::Draft => ContentProjectItemGenerationState::Idle,
            default => ContentProjectItemGenerationState::Idle,
        };
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    private function resolveExecution(
        SeoProjectTask $task,
        ContentProjectItemPublishState $publish,
        ContentProjectItemGenerationState $generation,
        array $hints,
        bool $latestPublishAttemptFailed = false,
        bool $hasPublished = false,
    ): ContentProjectItemExecutionState {
        if (! empty($hints['execution_running'])
            || $generation === ContentProjectItemGenerationState::Writing
            || $generation === ContentProjectItemGenerationState::Processing
            || $publish === ContentProjectItemPublishState::Queued
        ) {
            return ContentProjectItemExecutionState::Running;
        }

        $runStatus = strtolower(trim((string) ($hints['run_item_status'] ?? '')));
        $latestAttempt = strtolower(trim((string) ($hints['latest_attempt_source'] ?? '')));

        if ($latestAttempt === 'generation'
            || $generation === ContentProjectItemGenerationState::Failed
            || ! empty($hints['stale_generation'])
            || in_array($runStatus, ['failed', 'cancelled', 'stopped', 'timeout'], true)
        ) {
            if ($latestAttempt !== 'publish') {
                return ContentProjectItemExecutionState::Failed;
            }
        }

        if ($latestAttempt === 'publish' || $latestPublishAttemptFailed || $publish === ContentProjectItemPublishState::PublishFailed) {
            return ContentProjectItemExecutionState::Failed;
        }

        if (in_array($runStatus, ['success', 'completed'], true)) {
            return ContentProjectItemExecutionState::Succeeded;
        }

        if ($generation === ContentProjectItemGenerationState::Completed
            || ($hasPublished && ! $latestPublishAttemptFailed)
            || $publish === ContentProjectItemPublishState::Published
        ) {
            return ContentProjectItemExecutionState::Succeeded;
        }

        return ContentProjectItemExecutionState::Idle;
    }

    /**
     * Latest relevant attempt only. Hints.latest_attempt_source overrides when set.
     *
     * @param  array<string, mixed>  $hints
     * @return array{0: ?string, 1: ContentProjectItemErrorSource}
     */
    private function resolveError(
        SeoProjectTask $task,
        ContentProjectItemPublishState $publish,
        ContentProjectItemGenerationState $generation,
        bool $hasPublished,
        array $hints,
        bool $latestPublishAttemptFailed = false,
    ): array {
        $runError = trim((string) ($hints['run_item_error'] ?? ''));
        $publishError = trim((string) ($task->getAttributes()['last_publish_error'] ?? $task->last_publish_error ?? ''));
        $latestAttempt = strtolower(trim((string) ($hints['latest_attempt_source'] ?? '')));

        // Explicit latest attempt from caller (ops/read model).
        if ($latestAttempt === 'publish') {
            return [
                $publishError !== '' ? $publishError : ($runError !== '' ? $runError : null),
                ContentProjectItemErrorSource::Publish,
            ];
        }
        if ($latestAttempt === 'generation') {
            $msg = $runError !== '' ? $runError : null;
            if ($msg === null && ! empty($hints['stale_generation'])) {
                $msg = 'Stale generation recovered.';
            }

            return [$msg, ContentProjectItemErrorSource::Generation];
        }

        // Generation failure / stale — never treat last_publish_error as generation message when published.
        if ($generation === ContentProjectItemGenerationState::Failed
            || ! empty($hints['stale_generation'])
        ) {
            $msg = $runError !== '' ? $runError : null;
            if ($msg === null && ! empty($hints['stale_generation'])) {
                $msg = 'Stale generation recovered.';
            }
            if ($msg === null && ! $hasPublished && $publishError !== '') {
                // Legacy mis-write only when never published.
                $msg = $publishError;
            }

            return [$msg, ContentProjectItemErrorSource::Generation];
        }

        // Current publish attempt failed (including retry after published revision).
        if ($latestPublishAttemptFailed || $publish === ContentProjectItemPublishState::PublishFailed) {
            return [
                $publishError !== '' ? $publishError : null,
                ContentProjectItemErrorSource::Publish,
            ];
        }

        if ($runError !== '') {
            return [$runError, ContentProjectItemErrorSource::Execution];
        }

        return [null, ContentProjectItemErrorSource::None];
    }

    private function resolveLifecycle(
        ContentProjectItemArchiveState $archive,
        bool $hasPublished,
        ContentProjectItemPublishState $publish,
        ContentProjectItemGenerationState $generation,
        ContentProjectItemReviewState $review,
    ): ContentProjectLifecyclePhase {
        if ($archive === ContentProjectItemArchiveState::ContentArchived) {
            return ContentProjectLifecyclePhase::Archived;
        }

        // Published revision always wins over generation/rerun failure.
        if ($hasPublished || $publish === ContentProjectItemPublishState::Published) {
            return ContentProjectLifecyclePhase::Published;
        }

        if ($publish === ContentProjectItemPublishState::Queued
            || $publish === ContentProjectItemPublishState::Scheduled
        ) {
            return ContentProjectLifecyclePhase::WaitingPublish;
        }

        if ($publish === ContentProjectItemPublishState::PublishFailed) {
            return ContentProjectLifecyclePhase::Failed;
        }

        if ($generation === ContentProjectItemGenerationState::Writing
            || $generation === ContentProjectItemGenerationState::Processing
        ) {
            return ContentProjectLifecyclePhase::Generating;
        }

        if ($generation === ContentProjectItemGenerationState::Failed) {
            return ContentProjectLifecyclePhase::Failed;
        }

        if ($review === ContentProjectItemReviewState::Approved) {
            return ContentProjectLifecyclePhase::Approved;
        }

        if ($review === ContentProjectItemReviewState::PendingReview
            || $generation === ContentProjectItemGenerationState::Completed
            || $review === ContentProjectItemReviewState::ReviewArchived
        ) {
            // review_archived = hoàn tất duyệt — not publish-ready → still Review bucket for CP.
            return ContentProjectLifecyclePhase::Review;
        }

        return ContentProjectLifecyclePhase::Draft;
    }

    private function blockingReason(
        ContentProjectItemArchiveState $archive,
        ContentProjectLifecyclePhase $lifecycle,
        ContentProjectItemGenerationState $generation,
        bool $isActivelyPublishing,
    ): ?string {
        if ($archive === ContentProjectItemArchiveState::ContentArchived) {
            return 'Item or project is content-archived.';
        }
        if ($lifecycle === ContentProjectLifecyclePhase::Generating
            || $generation === ContentProjectItemGenerationState::Writing
        ) {
            return 'Generation is running.';
        }
        // Only canonical active processing blocks Retry / Publish Now.
        // retry_wait / scheduled waiting map to Queued for display but are NOT active.
        if ($isActivelyPublishing) {
            return 'Publish queue is active.';
        }

        return null;
    }

    /**
     * Read raw attribute — avoid Eloquent datetime cast (needs DB connection in pure PHPUnit).
     */
    private function hasFilledAttr(\Illuminate\Database\Eloquent\Model $model, string $key): bool
    {
        $raw = $model->getAttributes()[$key] ?? null;

        return $raw !== null && $raw !== '';
    }
}
