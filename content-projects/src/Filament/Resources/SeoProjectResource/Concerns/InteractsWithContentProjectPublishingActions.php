<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use App\Support\RuntimeLogger;
use Throwable;

/**
 * Publishing bulk/item actions — shared by Publishing Queue hub / CP ops.
 *
 * Host component MUST declare:
 * - public bool $bulkRunning
 * - public array $pendingTaskIds
 * - public ?string $pendingOp
 * - public ?string $pendingPhase  updating|accepted|null
 * - public ?string $pendingOperationId
 * - public string $autoMode, $autoStartAt, $autoDayStart, $autoDayEnd
 * - public int $autoIntervalMinutes, $autoPerDay
 */
trait InteractsWithContentProjectPublishingActions
{
    abstract protected function requireProject(): SeoProject;

    /** @return list<int> */
    abstract protected function selectedItemIds(): array;

    abstract public function clearSelection(): void;

    public function isTaskPending(int $taskId): bool
    {
        return in_array($taskId, $this->pendingTaskIds ?? [], true);
    }

    public function bulkSchedule(?string $at = null): void
    {
        $when = $at !== null && $at !== ''
            ? SystemDateTime::parseSystemInputToUtc($at)
            : SystemDateTime::currentSystemTime()->addHour()->utc();

        $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            $when,
        ), 'schedule');
    }

    public function bulkUnschedule(): void
    {
        $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'unschedule');
    }

    public function bulkPublishNow(): void
    {
        $this->dispatchPublishingCommand(new PublishProjectItemsNowCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'publish_now');
    }

    public function bulkRetryPublish(): void
    {
        $this->dispatchPublishingCommand(new RetryProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'retry');
    }

    public function bulkResyncPublishedWordPress(): void
    {
        $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BulkResyncPublishedArticlesToWordPressCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'bulk_resync_wordpress');
    }

    public function resyncPublishedItemWordPress(int $taskId): void
    {
        $this->dispatchPublishingCommand(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BulkResyncPublishedArticlesToWordPressCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'resync_wordpress');
    }

    public function bulkMoveTime(string $at): void
    {
        $this->dispatchPublishingCommand(new MoveProjectItemScheduleCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            SystemDateTime::parseSystemInputToUtc($at),
        ), 'move_time');
    }

    public function bulkClearSchedule(): void
    {
        $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'clear_schedule');
    }

    public function bulkSkipPublish(): void
    {
        $this->dispatchPublishingCommand(new SkipProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'skip');
    }

    public function bulkCancelPublish(): void
    {
        $this->dispatchPublishingCommand(new CancelProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'cancel');
    }

    public function retryPublishOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new RetryProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'retry');
    }

    public function skipPublishOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new SkipProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'skip');
    }

    public function cancelPublishOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new CancelProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'cancel');
    }

    public function sendToPublishingQueueOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new SendToPublishingQueueCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'send_to_publishing_queue');
    }

    public function bulkSendToPublishingQueue(): void
    {
        $this->dispatchPublishingCommand(new SendToPublishingQueueCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'send_to_publishing_queue');
    }

    public function scheduleOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
            SystemDateTime::currentSystemTime()->addHour()->utc(),
        ), 'schedule');
    }

    public function unscheduleOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'unschedule');
    }

    public function publishOneNow(int $taskId): void
    {
        $this->dispatchPublishingCommand(new PublishProjectItemsNowCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'publish_now');
    }

    public function runAutoSchedule(): void
    {
        $this->dispatchPublishingCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            [
                'mode' => $this->autoMode,
                'start_at' => $this->autoStartAt,
                'interval_minutes' => $this->autoIntervalMinutes,
                'per_day' => $this->autoPerDay,
                'day_start' => $this->autoDayStart,
                'day_end' => $this->autoDayEnd,
            ],
        ), 'auto_schedule');
    }

    private function dispatchPublishingCommand(object $command, string $op): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        if (($this->bulkRunning ?? false) === true) {
            return;
        }

        $embedded = property_exists($command, 'itemRefs') && is_array($command->itemRefs)
            ? array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                $command->itemRefs,
            ), static fn (int $id): bool => $id > 0))
            : [];

        $allowsEmptySelection = in_array($op, ['auto_schedule'], true);

        if ($embedded === [] && ! $allowsEmptySelection) {
            app(ContentProjectActionResultNotifier::class)->send(
                ContentProjectActionResult::fail(
                    'validation.failed',
                    (string) __('seo-content-ai::filament.projects.queue_select_required'),
                    (int) $this->requireProject()->getKey(),
                ),
            );

            return;
        }

        $operationId = bin2hex(random_bytes(8));
        $this->pendingOperationId = $operationId;
        $this->pendingOp = $op;
        $this->pendingPhase = 'updating';
        $this->pendingTaskIds = $embedded;

        // Auto/Quick: resolve eligible ids for row-level pending before dispatch.
        if ($embedded === [] && $allowsEmptySelection && $op === 'auto_schedule') {
            try {
                $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService::class)
                    ->preview(
                        $this->requireProject(),
                        [],
                        property_exists($command, 'options') && is_array($command->options) ? $command->options : [],
                    );
                $this->pendingTaskIds = array_values(array_map('intval', $preview['eligible_ids'] ?? []));
            } catch (Throwable) {
                $this->pendingTaskIds = [];
            }
        }

        $this->bulkRunning = true;

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                $command,
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($this->requireProject()->site_id ?? 0) ?: null,
                ),
            );

            if ($this->pendingOperationId !== $operationId) {
                return;
            }

            // Không success toast — chỉ danger/warning khi fail / confirm.
            if (! $result->success) {
                app(ContentProjectActionResultNotifier::class)->send($result);
                $this->clearPendingPresentation();

                return;
            }

            // Publish Now / Retry: command accepted ≠ WordPress Published.
            if (in_array($op, ['publish_now', 'retry'], true)) {
                $this->pendingPhase = 'accepted';
            }

            $this->clearSelection();
            if (method_exists($this, 'afterPublishingCommandSuccess')) {
                $this->afterPublishingCommandSuccess($op, $embedded, $result);
            }

            // Keep brief accepted flash for publish_now; otherwise clear pending so
            // canonical state from re-render takes over.
            if (! in_array($op, ['publish_now', 'retry'], true)) {
                $this->clearPendingPresentation();
            } else {
                $this->bulkRunning = false;
                // Leave pendingPhase=accepted until next refresh paints Publishing.
                $this->js('setTimeout(() => $wire.clearPendingPresentation(), 1200)');
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.items.publishing.'.$op,
                'project_id' => (int) $this->requireProject()->getKey(),
            ]);
            app(ContentProjectActionResultNotifier::class)->send(
                ContentProjectActionResult::fail(
                    'failed',
                    $e->getMessage(),
                    (int) $this->requireProject()->getKey(),
                ),
            );
            $this->clearPendingPresentation();
        } finally {
            if (($this->pendingPhase ?? null) !== 'accepted') {
                $this->bulkRunning = false;
            }
        }
    }

    public function clearPendingPresentation(): void
    {
        $this->pendingTaskIds = [];
        $this->pendingOp = null;
        $this->pendingPhase = null;
        $this->pendingOperationId = null;
        $this->bulkRunning = false;
    }
}
