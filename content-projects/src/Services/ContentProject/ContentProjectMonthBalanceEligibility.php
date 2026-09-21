<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGeneratorDoneClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemState;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;

/**
 * Balance Months eligibility (pre-generation domain only).
 *
 * Ownership contract:
 * - Balance Months owns NOT-generated workload redistribution across months.
 * - Compact done articles owns generator_done compaction.
 * - generator_done tasks are OUTSIDE the Balance domain (not “fixed load”).
 */
final class ContentProjectMonthBalanceEligibility
{
    public const REASON_GENERATED = 'generated';

    public const REASON_PUBLISHED = 'published';

    public const REASON_SCHEDULED = 'scheduled';

    public const REASON_WAITING_PUBLISH = 'waiting_publish';

    public const REASON_ACTIVE_QUEUE = 'active_publish_queue';

    public const REASON_ACTIVE_RUNNING = 'active_running';

    public const REASON_ARCHIVED = 'archived';

    public const REASON_CANCELLED = 'cancelled';

    public const REASON_PROJECT_ARCHIVED = 'project_archived';

    public const REASON_AMBIGUOUS = 'ambiguous_near_publish';

    public function __construct(
        private readonly ContentProjectCompactSuccessSafetyGuard $safety = new ContentProjectCompactSuccessSafetyGuard,
        private readonly PublishingActiveProcessing $publishActive = new PublishingActiveProcessing,
        private readonly ContentProjectGeneratorDoneClassifier $generatorDone = new ContentProjectGeneratorDoneClassifier,
    ) {}

    /**
     * @return array{
     *     in_domain: bool,
     *     movable: bool,
     *     fixed: bool,
     *     reason: string|null
     * }
     */
    public function classify(
        SeoProjectTask $task,
        ?SeoProject $project,
        ?ContentProjectItemState $state = null,
        ?SeoArticle $article = null,
    ): array {
        if ($task->archived_at !== null) {
            return $this->fixed(self::REASON_ARCHIVED);
        }

        $status = strtolower(trim((string) ($task->status ?? '')));
        if ($status === SeoProjectTask::STATUS_CANCELLED || $status === 'cancelled') {
            return $this->fixed(self::REASON_CANCELLED);
        }

        if ($project instanceof SeoProject) {
            if ($project->isArchive() || $project->isProjectArchived()) {
                return $this->fixed(self::REASON_PROJECT_ARCHIVED);
            }
        }

        // Hard boundary: Compact's generator_done domain is excluded from Balance entirely.
        if ($state instanceof ContentProjectItemState && $this->isGeneratorDone($task, $state, $article)) {
            return $this->excluded(self::REASON_GENERATED);
        }

        if ($task->publish_published_at !== null) {
            return $this->fixed(self::REASON_PUBLISHED);
        }

        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'))
            ?? ContentProjectPublishQueueStatus::None;

        if ($queue === ContentProjectPublishQueueStatus::Published) {
            return $this->fixed(self::REASON_PUBLISHED);
        }

        if ($queue->isActiveQueue()) {
            return $this->fixed(self::REASON_ACTIVE_QUEUE);
        }

        if ($state instanceof ContentProjectItemState) {
            if ($state->hasPublishedRevision
                || $state->lifecycleState === ContentProjectLifecyclePhase::Published
                || $state->publishState === ContentProjectItemPublishState::Published
            ) {
                return $this->fixed(self::REASON_PUBLISHED);
            }

            if ($state->publishState === ContentProjectItemPublishState::Scheduled
                || $state->lifecycleState === ContentProjectLifecyclePhase::WaitingPublish
            ) {
                return $this->fixed(self::REASON_WAITING_PUBLISH);
            }

            if ($state->publishState === ContentProjectItemPublishState::Queued) {
                return $this->fixed(self::REASON_ACTIVE_QUEUE);
            }

            if ($state->lifecycleState === ContentProjectLifecyclePhase::Generating
                || $state->executionState === ContentProjectItemExecutionState::Running
            ) {
                return $this->fixed(self::REASON_ACTIVE_RUNNING);
            }

            if ($state->lifecycleState === ContentProjectLifecyclePhase::Archived) {
                return $this->fixed(self::REASON_ARCHIVED);
            }

            if ($state->lifecycleState === ContentProjectLifecyclePhase::Approved
                && $state->publishState !== ContentProjectItemPublishState::None
                && $state->publishState !== ContentProjectItemPublishState::Skipped
                && $state->publishState !== ContentProjectItemPublishState::Cancelled
            ) {
                return $this->fixed(self::REASON_AMBIGUOUS);
            }
        }

        if (in_array($status, [
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_PROCESSING,
            'writing',
            'processing',
        ], true)) {
            return $this->fixed(self::REASON_ACTIVE_RUNNING);
        }

        if ($this->publishActive->isActivelyPublishing($task)) {
            return $this->fixed(self::REASON_ACTIVE_RUNNING);
        }

        $itemGate = $this->safety->assessItem($task, $project);
        if (! $itemGate['movable']) {
            $reason = (string) ($itemGate['reason'] ?? self::REASON_ACTIVE_RUNNING);
            if ($reason === 'scheduled_published_unsafe') {
                return $this->fixed(self::REASON_ACTIVE_QUEUE);
            }

            return $this->fixed($reason !== '' ? $reason : self::REASON_ACTIVE_RUNNING);
        }

        if ($project instanceof SeoProject) {
            $projectGate = $this->safety->assessProject($project);
            if (! $projectGate['ok']) {
                $reason = (string) ($projectGate['reason'] ?? self::REASON_ACTIVE_RUNNING);

                return $this->fixed($reason !== '' ? $reason : self::REASON_ACTIVE_RUNNING);
            }
        }

        return ['in_domain' => true, 'movable' => true, 'fixed' => false, 'reason' => null];
    }

    /**
     * @param  array{
     *     archived?: bool,
     *     cancelled?: bool,
     *     project_archived?: bool,
     *     generator_done?: bool,
     *     published_at?: bool,
     *     queue?: string|null,
     *     lifecycle?: string|null,
     *     publish_state?: string|null,
     *     execution_state?: string|null,
     *     has_published_revision?: bool,
     *     raw_status?: string|null,
     *     actively_publishing?: bool,
     *     safety_movable?: bool,
     *     safety_reason?: string|null,
     *     project_ok?: bool,
     *     project_reason?: string|null
     * }  $facts
     * @return array{in_domain: bool, movable: bool, fixed: bool, reason: string|null}
     */
    public function classifyFromFacts(array $facts): array
    {
        if (! empty($facts['archived'])) {
            return $this->fixed(self::REASON_ARCHIVED);
        }
        if (! empty($facts['cancelled'])) {
            return $this->fixed(self::REASON_CANCELLED);
        }
        if (! empty($facts['project_archived'])) {
            return $this->fixed(self::REASON_PROJECT_ARCHIVED);
        }
        if (! empty($facts['generator_done'])) {
            return $this->excluded(self::REASON_GENERATED);
        }
        if (! empty($facts['published_at']) || ! empty($facts['has_published_revision'])) {
            return $this->fixed(self::REASON_PUBLISHED);
        }

        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($facts['queue'] ?? 'none'))
            ?? ContentProjectPublishQueueStatus::None;
        if ($queue === ContentProjectPublishQueueStatus::Published) {
            return $this->fixed(self::REASON_PUBLISHED);
        }
        if ($queue->isActiveQueue()) {
            return $this->fixed(self::REASON_ACTIVE_QUEUE);
        }

        $lifecycle = ContentProjectLifecyclePhase::tryFrom((string) ($facts['lifecycle'] ?? ''));
        $publishState = ContentProjectItemPublishState::tryFrom((string) ($facts['publish_state'] ?? ''));
        $execution = ContentProjectItemExecutionState::tryFrom((string) ($facts['execution_state'] ?? ''));

        if ($lifecycle === ContentProjectLifecyclePhase::Published
            || $publishState === ContentProjectItemPublishState::Published
        ) {
            return $this->fixed(self::REASON_PUBLISHED);
        }
        if ($publishState === ContentProjectItemPublishState::Scheduled
            || $lifecycle === ContentProjectLifecyclePhase::WaitingPublish
        ) {
            return $this->fixed(self::REASON_WAITING_PUBLISH);
        }
        if ($publishState === ContentProjectItemPublishState::Queued) {
            return $this->fixed(self::REASON_ACTIVE_QUEUE);
        }
        if ($lifecycle === ContentProjectLifecyclePhase::Generating
            || $execution === ContentProjectItemExecutionState::Running
        ) {
            return $this->fixed(self::REASON_ACTIVE_RUNNING);
        }
        if ($lifecycle === ContentProjectLifecyclePhase::Archived) {
            return $this->fixed(self::REASON_ARCHIVED);
        }
        if ($lifecycle === ContentProjectLifecyclePhase::Approved
            && $publishState !== null
            && $publishState !== ContentProjectItemPublishState::None
            && $publishState !== ContentProjectItemPublishState::Skipped
            && $publishState !== ContentProjectItemPublishState::Cancelled
        ) {
            return $this->fixed(self::REASON_AMBIGUOUS);
        }

        $raw = strtolower(trim((string) ($facts['raw_status'] ?? '')));
        if (in_array($raw, [SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_PROCESSING, 'writing', 'processing'], true)) {
            return $this->fixed(self::REASON_ACTIVE_RUNNING);
        }
        if (! empty($facts['actively_publishing'])) {
            return $this->fixed(self::REASON_ACTIVE_RUNNING);
        }
        if (array_key_exists('safety_movable', $facts) && $facts['safety_movable'] === false) {
            return $this->fixed((string) ($facts['safety_reason'] ?? self::REASON_ACTIVE_RUNNING));
        }
        if (array_key_exists('project_ok', $facts) && $facts['project_ok'] === false) {
            return $this->fixed((string) ($facts['project_reason'] ?? self::REASON_ACTIVE_RUNNING));
        }

        return ['in_domain' => true, 'movable' => true, 'fixed' => false, 'reason' => null];
    }

    private function isGeneratorDone(
        SeoProjectTask $task,
        ContentProjectItemState $state,
        ?SeoArticle $article,
    ): bool {
        $resolved = $article;
        if (! $resolved instanceof SeoArticle && $task->relationLoaded('article')) {
            $resolved = $task->article instanceof SeoArticle ? $task->article : null;
        }

        $hasContent = $this->generatorDone->articleHasGeneratedContent(
            $resolved instanceof SeoArticle ? $resolved : null,
        );

        return $this->generatorDone->isGeneratorDone($state, $hasContent);
    }

    /**
     * @return array{in_domain: bool, movable: bool, fixed: bool, reason: string|null}
     */
    private function excluded(string $reason): array
    {
        return ['in_domain' => false, 'movable' => false, 'fixed' => false, 'reason' => $reason];
    }

    /**
     * @return array{in_domain: bool, movable: bool, fixed: bool, reason: string|null}
     */
    private function fixed(string $reason): array
    {
        return ['in_domain' => true, 'movable' => false, 'fixed' => true, 'reason' => $reason];
    }
}
