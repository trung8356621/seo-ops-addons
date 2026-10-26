<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Services\Users\SeoOpsSystemUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Services\WriterMonthlyCapacityGate;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;

/**
 * Reassign execution project writer (seo_projects.user_id) without lifecycle resets.
 *
 * Syncs linked Article Laravel owner via {@see SeoProjectArticleOwnerSyncService}
 * (access/assignment owner — not WordPress post author).
 */
final class ChangeContentProjectWriterService
{
    public function __construct(
        private readonly WriterMonthlyCapacityGate $capacityGate,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
    ) {}

    /**
     * @return array{
     *     can_change: bool,
     *     blocked_reason: string|null,
     *     ai_running: bool,
     *     queue_processing: bool,
     * }
     */
    public function changeWriterGate(SeoProject $project): array
    {
        $projectId = (int) $project->getKey();

        if ($project->isProjectArchived() || $project->archived_at !== null) {
            return [
                'can_change' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.change_writer_blocked_archived'),
                'ai_running' => false,
                'queue_processing' => false,
            ];
        }

        if ($project->isDraftPlanning() || (string) ($project->status ?? '') === SeoProject::STATUS_DRAFT) {
            return [
                'can_change' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.change_writer_blocked_draft'),
                'ai_running' => false,
                'queue_processing' => false,
            ];
        }

        $aiRunning = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->notConsolidated()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->exists();

        $queueProcessing = false;
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $queueProcessing = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active()
                ->where('publish_queue_status', ContentProjectPublishQueueStatus::Processing->value)
                ->exists();
        }

        $blockedReason = null;
        if ($aiRunning) {
            $blockedReason = (string) __('seo-content-ai::filament.projects.change_writer_blocked_ai_running');
        } elseif ($queueProcessing) {
            $blockedReason = (string) __('seo-content-ai::filament.projects.change_writer_blocked_queue_processing');
        }

        return [
            'can_change' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
            'ai_running' => $aiRunning,
            'queue_processing' => $queueProcessing,
        ];
    }

    /**
     * @return array{
     *     ok: true,
     *     project_id: int,
     *     from_user_id: int,
     *     to_user_id: int,
     *     articles_synced: int,
     * }|array{
     *     ok: false,
     *     code: string,
     *     reason: string,
     *     project_id: int,
     *     metadata?: array<string, mixed>,
     * }
     */
    public function changeWriter(SeoProject $project, int $newWriterId): array
    {
        $projectId = (int) $project->getKey();
        $gate = $this->changeWriterGate($project);
        if (! $gate['can_change']) {
            return [
                'ok' => false,
                'code' => ContentProjectActionCodes::LIFECYCLE_INVALID,
                'reason' => (string) ($gate['blocked_reason'] ?? ContentProjectActionCodes::LIFECYCLE_INVALID),
                'project_id' => $projectId,
                'metadata' => ['gate' => $gate],
            ];
        }

        if ($newWriterId <= 0 || SeoOpsSystemUser::isSystemUserId($newWriterId)) {
            return [
                'ok' => false,
                'code' => ContentProjectActionCodes::SYSTEM_USER_REJECTED,
                'reason' => ContentProjectActionCodes::SYSTEM_USER_REJECTED,
                'project_id' => $projectId,
                'metadata' => ['user_id' => $newWriterId],
            ];
        }

        $fromUserId = (int) ($project->user_id ?? 0);
        if ($fromUserId === $newWriterId) {
            return [
                'ok' => false,
                'code' => ContentProjectActionCodes::VALIDATION_FAILED,
                'reason' => (string) __('seo-content-ai::filament.projects.change_writer_same_writer'),
                'project_id' => $projectId,
            ];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($project, $projectId, $newWriterId, $fromUserId): array {
            /** @var SeoProject|null $locked */
            $locked = SeoProject::query()
                ->whereKey($projectId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProject) {
                return [
                    'ok' => false,
                    'code' => ContentProjectActionCodes::PROJECT_NOT_FOUND,
                    'reason' => ContentProjectActionCodes::PROJECT_NOT_FOUND,
                    'project_id' => $projectId,
                ];
            }

            $liveGate = $this->changeWriterGate($locked);
            if (! $liveGate['can_change']) {
                return [
                    'ok' => false,
                    'code' => ContentProjectActionCodes::LIFECYCLE_INVALID,
                    'reason' => (string) ($liveGate['blocked_reason'] ?? ContentProjectActionCodes::LIFECYCLE_INVALID),
                    'project_id' => $projectId,
                    'metadata' => ['gate' => $liveGate],
                ];
            }

            $liveFrom = (int) ($locked->user_id ?? 0);
            if ($liveFrom === $newWriterId) {
                return [
                    'ok' => false,
                    'code' => ContentProjectActionCodes::VALIDATION_FAILED,
                    'reason' => (string) __('seo-content-ai::filament.projects.change_writer_same_writer'),
                    'project_id' => $projectId,
                ];
            }

            $incoming = $locked->registeredTaskCount();
            $month = $locked->month ?? ContentProjectMonthContext::current();
            $this->lockWriterMonthProjects($newWriterId, $month);

            $capacity = $this->capacityGate->assertCanAccept($newWriterId, $month, $incoming);
            if (($capacity['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'code' => (string) ($capacity['code'] ?? ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED),
                    'reason' => (string) ($capacity['code'] ?? ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED),
                    'project_id' => $projectId,
                    'metadata' => [
                        'remaining' => (int) ($capacity['remaining'] ?? 0),
                        'incoming' => $incoming,
                        'user_id' => $newWriterId,
                    ],
                ];
            }

            $locked->user_id = $newWriterId;
            $locked->save();

            $articlesSynced = 0;
            if (ContentProjectWriterAssignment::hasRealWriter($locked)) {
                $articlesSynced = $this->articleOwnerSync->syncProjectArticles($locked->fresh() ?? $locked);
            }

            return [
                'ok' => true,
                'project_id' => $projectId,
                'from_user_id' => $liveFrom > 0 ? $liveFrom : $fromUserId,
                'to_user_id' => $newWriterId,
                'articles_synced' => $articlesSynced,
            ];
        });
    }

    private function lockWriterMonthProjects(int $userId, mixed $month): void
    {
        if ($userId <= 0) {
            return;
        }

        $monthDate = ContentProjectMonthContext::toDateString($month);

        SeoProject::query()
            ->where('user_id', $userId)
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->whereDate('month', $monthDate)
            ->lockForUpdate()
            ->get(['id']);
    }
}
