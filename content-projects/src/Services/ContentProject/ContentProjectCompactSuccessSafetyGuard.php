<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;

/**
 * Safety gates for compact-success move (preview + JIT execute).
 */
final class ContentProjectCompactSuccessSafetyGuard
{
    public function __construct(
        private readonly ContentProjectActiveGenerationRunDetector $bulkDetector = new ContentProjectActiveGenerationRunDetector,
        private readonly PublishingActiveProcessing $publishActive = new PublishingActiveProcessing,
        private readonly ?ArticleEditorSessionService $editorSessions = null,
    ) {}

    /**
     * @return array{movable: bool, reason: string|null}
     */
    public function assessItem(SeoProjectTask $task, ?SeoProject $project = null): array
    {
        $project ??= $task->relationLoaded('project') ? $task->project : null;

        if ($project instanceof SeoProject) {
            if ($project->isArchive() || $project->isProjectArchived()) {
                return ['movable' => false, 'reason' => 'project_archived'];
            }
            if ($project->isDraftPlanning()) {
                return ['movable' => false, 'reason' => 'project_draft'];
            }
        }

        if ($task->archived_at !== null) {
            return ['movable' => false, 'reason' => 'item_archived'];
        }

        $status = strtolower(trim((string) ($task->status ?? '')));
        if (in_array($status, [
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_PROCESSING,
            'writing',
            'processing',
        ], true)) {
            return ['movable' => false, 'reason' => 'ai_running'];
        }

        if ($this->publishActive->isActivelyPublishing($task)) {
            return ['movable' => false, 'reason' => 'publish_queue_processing'];
        }

        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'));
        if ($queue === ContentProjectPublishQueueStatus::Processing) {
            return ['movable' => false, 'reason' => 'publish_queue_processing'];
        }

        if ($this->hasActiveDispatch($task, $project instanceof SeoProject ? $project : null)) {
            return ['movable' => false, 'reason' => 'active_dispatch'];
        }

        if ($this->hasActiveEditorSession($task)) {
            return ['movable' => false, 'reason' => 'active_editor_session'];
        }

        return ['movable' => true, 'reason' => null];
    }

    public function projectHasActiveBulkGeneration(SeoProject $project): bool
    {
        return $this->bulkDetector->hasActiveBulkGeneration((int) $project->getKey());
    }

    /**
     * @return array{ok: bool, reason: string|null}
     */
    public function assessProject(SeoProject $project): array
    {
        if ($project->isArchive() || $project->isProjectArchived()) {
            return ['ok' => false, 'reason' => 'project_archived'];
        }
        if ($project->isDraftPlanning()) {
            return ['ok' => false, 'reason' => 'project_draft'];
        }
        if ($this->projectHasActiveBulkGeneration($project)) {
            return ['ok' => false, 'reason' => 'bulk_generation_active'];
        }

        $aiRunning = SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->notConsolidated()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->whereNull('finished_at')
            ->exists();

        if ($aiRunning) {
            return ['ok' => false, 'reason' => 'project_ai_running'];
        }

        return ['ok' => true, 'reason' => null];
    }

    private function hasActiveDispatch(SeoProjectTask $task, ?SeoProject $project): bool
    {
        $projectId = $project instanceof SeoProject
            ? (int) $project->getKey()
            : (int) ($task->project_id ?? 0);
        if ($projectId <= 0) {
            return false;
        }

        $taskId = (int) $task->getKey();
        $runs = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->notConsolidated()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'settings']);

        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }
            $settings = is_array($run->settings) ? $run->settings : [];
            $engine = is_array($settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY]
                : [];
            $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            if ($active === null) {
                continue;
            }
            if ((int) ($active['task_id'] ?? 0) === $taskId) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveEditorSession(SeoProjectTask $task): bool
    {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return false;
        }

        $sessions = $this->editorSessions;
        if (! $sessions instanceof ArticleEditorSessionService) {
            if (! class_exists(ArticleEditorSessionService::class)) {
                return false;
            }
            try {
                $sessions = app(ArticleEditorSessionService::class);
            } catch (\Throwable) {
                return false;
            }
        }

        $article = $task->relationLoaded('article') && $task->article instanceof SeoArticle
            ? $task->article
            : SeoArticle::query()->find($articleId);

        if (! $article instanceof SeoArticle) {
            return false;
        }

        try {
            return $sessions->findActiveSession($article) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
