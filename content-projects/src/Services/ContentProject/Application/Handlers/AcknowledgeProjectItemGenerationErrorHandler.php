<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AcknowledgeProjectItemGenerationErrorCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Soft-clear generation error banner / Failed overlay without regenerating content.
 */
final class AcknowledgeProjectItemGenerationErrorHandler extends AbstractPublishingHandler
{
    private const FAILURE_STATUSES = ['failed', 'error', 'cancelled', 'stopped', 'timeout'];

    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AcknowledgeProjectItemGenerationErrorCommand) {
            throw new InvalidArgumentException('Expected AcknowledgeProjectItemGenerationErrorCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            return $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($projectId, $itemIds, $command, $actor): ContentProjectActionResult {
                    if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::FAILED,
                            'Run items table unavailable.',
                            $projectId,
                        );
                    }

                    $cleared = 0;
                    $touchedTaskIds = [];

                    foreach ($itemIds as $taskId) {
                        $task = SeoProjectTask::query()->find((int) $taskId);
                        if (! $task instanceof SeoProjectTask) {
                            continue;
                        }

                        $latest = SeoProjectRunItem::query()
                            ->where('task_id', (int) $taskId)
                            ->orderByDesc('id')
                            ->first();

                        if (! $latest instanceof SeoProjectRunItem) {
                            continue;
                        }

                        $status = strtolower(trim((string) ($latest->status ?? '')));
                        $error = trim((string) ($latest->error_message ?? ''));
                        $hasFailureSignal = in_array($status, self::FAILURE_STATUSES, true) || $error !== '';
                        if (! $hasFailureSignal) {
                            continue;
                        }

                        $snapshot = is_array($latest->output_snapshot) ? $latest->output_snapshot : [];
                        $snapshot['acknowledged_error'] = [
                            'at' => now()->toIso8601String(),
                            'previous_status' => $status,
                            'previous_error' => mb_substr($error, 0, 1000),
                            'note' => $command->note,
                            'actor_type' => $actor->actorType,
                            'by' => 'content_project.acknowledge_generation_error',
                        ];

                        $latest->status = SeoProjectRunItemStatus::Success->value;
                        $latest->error_message = null;
                        $latest->output_snapshot = $snapshot;
                        if ($latest->finished_at === null) {
                            $latest->finished_at = now();
                        }
                        $latest->save();

                        $taskStatus = strtolower(trim((string) ($task->status ?? '')));
                        if (
                            in_array($taskStatus, [
                                SeoProjectTask::STATUS_FAILED,
                                SeoProjectTask::STATUS_WRITING,
                                SeoProjectTask::STATUS_PROCESSING,
                            ], true)
                            && (int) ($task->article_id ?? 0) > 0
                        ) {
                            $task->status = SeoProjectTask::STATUS_COMPLETED;
                            $task->save();
                        }

                        $cleared++;
                        $touchedTaskIds[] = (int) $taskId;
                    }

                    if ($cleared === 0) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::VALIDATION_FAILED,
                            'Không có lỗi generation để bỏ qua trên item đã chọn.',
                            $projectId,
                            affectedItemIds: $itemIds,
                        );
                    }

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_GENERATION_ERROR_ACKNOWLEDGED,
                        "Đã bỏ qua lỗi generation cho {$cleared} item (giữ nội dung).",
                        $projectId,
                        $touchedTaskIds,
                        metadata: ['cleared_count' => $cleared],
                    );
                },
            );
        });
    }
}
