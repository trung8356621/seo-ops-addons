<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishAttemptRefs;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyKeyFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;
use Throwable;

/**
 * Canonical single-item publish execution for scheduler / retry_now / publish_now / recovery.
 * All UI + cron paths must enter here — no parallel publish logic.
 */
final class PublishDueItemService
{
    public const TRIGGER_SCHEDULER = 'scheduler';

    public const TRIGGER_RETRY_NOW = 'retry_now';

    public const TRIGGER_PUBLISH_NOW = 'publish_now';

    public const TRIGGER_RECOVERY = 'recovery';

    public function __construct(
        private readonly ContentProjectCommandBus $commandBus,
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly ContentProjectIdempotencyStore $idempotencyStore,
        private readonly PublishingProcessingMarkerClearer $markerClearer = new PublishingProcessingMarkerClearer,
        private readonly PublishingActiveProcessing $activeProcessing = new PublishingActiveProcessing,
    ) {}

    public function execute(int $itemId, string $trigger): PublishDueItemOutcome
    {
        $trigger = $this->normalizeTrigger($trigger);

        try {
            $task = SeoProjectTask::query()->with(['article', 'project'])->find($itemId);
            if (! $task instanceof SeoProjectTask) {
                return $this->finish(new PublishDueItemOutcome(
                    itemId: $itemId,
                    trigger: $trigger,
                    outcome: PublishDueItemOutcome::ERROR,
                    reason: DispatchClaimResult::NOT_FOUND,
                    finalStatus: '',
                ));
            }

            if ($this->activeProcessing->isActivelyPublishing($task)) {
                return $this->finish(new PublishDueItemOutcome(
                    itemId: $itemId,
                    trigger: $trigger,
                    outcome: PublishDueItemOutcome::SKIPPED,
                    reason: DispatchClaimResult::ACTIVE_PUBLISH,
                    claimCode: DispatchClaimResult::ACTIVE_PUBLISH,
                    finalStatus: (string) ($task->publish_queue_status ?? ''),
                ));
            }

            $project = $task->project;
            $projectId = $project instanceof SeoProject ? (int) $project->getKey() : (int) ($task->project_id ?? 0);

            if (in_array($trigger, [self::TRIGGER_RETRY_NOW, self::TRIGGER_PUBLISH_NOW], true)
                && $project instanceof SeoProject
            ) {
                $this->queue->{$trigger === self::TRIGGER_RETRY_NOW ? 'retry' : 'publishNow'}($project, [$itemId]);
                $task = $task->fresh(['article', 'project']) ?? $task;
            }

            // Break sticky CommandBus success on stable publish_operation_key (production root cause).
            $this->releaseStickyIdempotency($task);

            $attemptRef = PublishAttemptRefs::newAttemptRef();
            $idemKey = $this->buildAttemptScopedBusKey($task, $trigger, $attemptRef);
            $siteId = (int) ($task->site_id ?? $project?->site_id ?? 0);

            $actor = new ActorContext(
                actorType: 'queue',
                actorId: null,
                siteId: $siteId > 0 ? $siteId : null,
                idempotencyKey: $idemKey,
                correlationId: 'cp-publish-'.$itemId.'-'.$trigger.'-'.Str::lower(Str::random(6)),
            );

            $result = $this->commandBus->dispatch(
                new ProcessScheduledProjectItemPublishCommand(
                    itemRef: $itemId,
                    projectRef: $projectId > 0 ? $projectId : null,
                    attemptRef: $attemptRef,
                ),
                $actor,
            );

            // Sticky replay with stale success must not count as progress.
            if ($result->code === ContentProjectActionCodes::IDEMPOTENT_REPLAY) {
                $this->releaseStickyIdempotency($task);
                $idemKey = $this->buildAttemptScopedBusKey($task, $trigger, $attemptRef.'-rereun');
                $actor = new ActorContext(
                    actorType: 'queue',
                    actorId: null,
                    siteId: $siteId > 0 ? $siteId : null,
                    idempotencyKey: $idemKey,
                    correlationId: 'cp-publish-'.$itemId.'-'.$trigger.'-rerun',
                );
                $result = $this->commandBus->dispatch(
                    new ProcessScheduledProjectItemPublishCommand(
                        itemRef: $itemId,
                        projectRef: $projectId > 0 ? $projectId : null,
                        attemptRef: $attemptRef,
                    ),
                    $actor,
                );
            }

            return $this->finish($this->mapResult($itemId, $trigger, $result, $task->fresh() ?? $task));
        } catch (Throwable $e) {
            RuntimeLogger::warning('publishing.due_item_exception', [
                'item_id' => $itemId,
                'trigger' => $trigger,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->finish(new PublishDueItemOutcome(
                itemId: $itemId,
                trigger: $trigger,
                outcome: PublishDueItemOutcome::ERROR,
                reason: 'exception',
                exceptionClass: $e::class,
                exceptionMessage: $e->getMessage(),
            ));
        }
    }

    /**
     * @param  list<int>  $itemIds
     * @return list<PublishDueItemOutcome>
     */
    public function executeMany(array $itemIds, string $trigger): array
    {
        $out = [];
        foreach ($itemIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $out[] = $this->execute($id, $trigger);
        }

        return $out;
    }

    private function mapResult(
        int $itemId,
        string $trigger,
        ContentProjectActionResult $result,
        SeoProjectTask $fresh,
    ): PublishDueItemOutcome {
        $status = (string) ($fresh->publish_queue_status ?? '');
        $claimCode = (string) ($result->metadata['claim_code'] ?? '');
        $publisherInvoked = $result->success
            || in_array($status, [
                ContentProjectPublishQueueStatus::QueuedForDelivery->value,
                ContentProjectPublishQueueStatus::Processing->value,
                ContentProjectPublishQueueStatus::Published->value,
                ContentProjectPublishQueueStatus::Retrying->value,
            ], true);

        if ($result->success) {
            if ($status === ContentProjectPublishQueueStatus::Published->value
                || ($result->metadata['already_published'] ?? false) === true
            ) {
                return new PublishDueItemOutcome(
                    itemId: $itemId,
                    trigger: $trigger,
                    outcome: PublishDueItemOutcome::PUBLISHED,
                    reason: 'published',
                    publisherInvoked: true,
                    claimSuccess: true,
                    claimCode: DispatchClaimResult::CLAIMED,
                    finalStatus: $status,
                    meta: $result->metadata,
                );
            }

            return new PublishDueItemOutcome(
                itemId: $itemId,
                trigger: $trigger,
                outcome: PublishDueItemOutcome::AWAITING_DELIVERY,
                reason: 'delivery_requested',
                publisherInvoked: true,
                claimSuccess: true,
                claimCode: DispatchClaimResult::CLAIMED,
                finalStatus: $status !== '' ? $status : ContentProjectPublishQueueStatus::QueuedForDelivery->value,
                meta: $result->metadata,
            );
        }

        if ($claimCode !== '') {
            $outcome = match ($claimCode) {
                DispatchClaimResult::ACTIVE_PUBLISH,
                DispatchClaimResult::AWAITING_WORKER,
                DispatchClaimResult::STALE_OPERATION,
                DispatchClaimResult::LOCK_BUSY => PublishDueItemOutcome::SKIPPED,
                DispatchClaimResult::ATTEMPTS_EXHAUSTED => PublishDueItemOutcome::FAILED,
                default => PublishDueItemOutcome::SKIPPED,
            };

            return new PublishDueItemOutcome(
                itemId: $itemId,
                trigger: $trigger,
                outcome: $outcome,
                reason: $claimCode,
                publisherInvoked: false,
                claimSuccess: false,
                claimCode: $claimCode,
                finalStatus: $status,
                meta: $result->metadata,
            );
        }

        if ($status === ContentProjectPublishQueueStatus::Retrying->value) {
            return new PublishDueItemOutcome(
                itemId: $itemId,
                trigger: $trigger,
                outcome: PublishDueItemOutcome::RETRY_WAIT,
                reason: 'publisher_failed_retryable',
                publisherInvoked: true,
                claimSuccess: true,
                claimCode: DispatchClaimResult::CLAIMED,
                finalStatus: $status,
                meta: $result->metadata,
            );
        }

        if ($status === ContentProjectPublishQueueStatus::Failed->value) {
            return new PublishDueItemOutcome(
                itemId: $itemId,
                trigger: $trigger,
                outcome: PublishDueItemOutcome::FAILED,
                reason: 'publisher_failed',
                publisherInvoked: true,
                claimSuccess: true,
                claimCode: DispatchClaimResult::CLAIMED,
                finalStatus: $status,
                meta: $result->metadata,
            );
        }

        return new PublishDueItemOutcome(
            itemId: $itemId,
            trigger: $trigger,
            outcome: PublishDueItemOutcome::ERROR,
            reason: $result->code,
            publisherInvoked: $publisherInvoked,
            claimSuccess: false,
            claimCode: $claimCode,
            finalStatus: $status,
            meta: array_merge($result->metadata, ['message' => $result->message]),
        );
    }

    private function releaseStickyIdempotency(SeoProjectTask $task): void
    {
        $this->markerClearer->applySideEffects($task, 'due_item_release_sticky_idempotency');

        $opKey = trim((string) ($task->publish_operation_key ?? ''));
        if ($opKey === '') {
            return;
        }

        $siteId = (int) ($task->site_id ?? $task->project?->site_id ?? 0);
        $action = 'content_project.process_scheduled_publish';
        foreach (['site:'.$siteId.':queue', 'site:'.$siteId.':actor:queue'] as $tenant) {
            try {
                $this->idempotencyStore->releasePublishOperation($tenant, $action, $opKey);
            } catch (Throwable) {
                // non-fatal
            }
        }
    }

    private function buildAttemptScopedBusKey(SeoProjectTask $task, string $trigger, string $attemptRef): string
    {
        $nextAttempt = max(1, (int) ($task->publish_attempt_count ?? 0) + 1);
        $base = trim((string) ($task->publish_operation_key ?? ''));
        if ($base === '' && $task->scheduled_publish_at !== null) {
            $base = ContentProjectIdempotencyKeyFactory::scheduler(
                (int) $task->getKey(),
                $task->scheduled_publish_at->toIso8601String(),
            );
        }
        if ($base === '') {
            $base = 'item:'.(int) $task->getKey();
        }

        // Unique per dispatch — never reuse stable publish_operation_key alone on CommandBus.
        return sprintf(
            '%s:due:%s:attempt:%d:ref:%s',
            $base,
            $trigger,
            $nextAttempt,
            substr(hash('sha256', $attemptRef), 0, 12),
        );
    }

    private function normalizeTrigger(string $trigger): string
    {
        $trigger = strtolower(trim($trigger));

        return match ($trigger) {
            self::TRIGGER_RETRY_NOW,
            'retry',
            'retry_selected' => self::TRIGGER_RETRY_NOW,
            self::TRIGGER_PUBLISH_NOW,
            'publish',
            'publish_now' => self::TRIGGER_PUBLISH_NOW,
            self::TRIGGER_RECOVERY,
            'recover' => self::TRIGGER_RECOVERY,
            default => self::TRIGGER_SCHEDULER,
        };
    }

    private function finish(PublishDueItemOutcome $outcome): PublishDueItemOutcome
    {
        RuntimeLogger::info('publishing.due_item_outcome', $outcome->toLogArray());

        return $outcome;
    }
}
