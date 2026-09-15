<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGeneratorDoneClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemState;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;

/**
 * Safety + classification for auto-partition compact (preview + JIT execute).
 */
final class ContentProjectCompactSuccessSafetyGuard
{
    public function __construct(
        private readonly ContentProjectActiveGenerationRunDetector $bulkDetector = new ContentProjectActiveGenerationRunDetector,
        private readonly PublishingActiveProcessing $publishActive = new PublishingActiveProcessing,
        private readonly ContentProjectGeneratorDoneClassifier $generatorDone = new ContentProjectGeneratorDoneClassifier,
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
            return ['movable' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING];
        }

        if ($this->publishActive->isActivelyPublishing($task)) {
            return ['movable' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING];
        }

        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? 'none'));
        if ($queue === ContentProjectPublishQueueStatus::Processing
            || $queue === ContentProjectPublishQueueStatus::Waiting
            || $queue === ContentProjectPublishQueueStatus::Retrying
        ) {
            return ['movable' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_SCHEDULED_PUBLISHED];
        }

        if ($this->hasActiveDispatch($task, $project instanceof SeoProject ? $project : null)) {
            return ['movable' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING];
        }

        if ($this->hasActiveEditorSession($task)) {
            return ['movable' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING];
        }

        return ['movable' => true, 'reason' => null];
    }

    /**
     * @return array{
     *     kind: string,
     *     generator_done: bool,
     *     movable: bool,
     *     skip_reason: string|null
     * }
     */
    public function classifyItem(
        SeoProjectTask $task,
        ContentProjectItemState $state,
        ?SeoArticle $article,
        ?SeoProject $project,
        int $scopeSiteId,
    ): array {
        $taskSiteId = (int) ($task->site_id ?? 0);
        if ($scopeSiteId > 0 && $taskSiteId !== $scopeSiteId) {
            return [
                'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                'generator_done' => false,
                'movable' => false,
                'skip_reason' => ContentProjectCompactSuccessPlanner::SKIP_WRONG_DOMAIN_MONTH,
            ];
        }

        $hasContent = $this->generatorDone->articleHasGeneratedContent($article);
        $isGeneratorDone = $this->generatorDone->isGeneratorDone($state, $hasContent);

        if (! $isGeneratorDone && $this->looksLikeFalseSuccess($state, $hasContent)) {
            return [
                'kind' => ContentProjectCompactSuccessPlanner::KIND_NOT_DONE,
                'generator_done' => false,
                'movable' => false,
                'skip_reason' => ContentProjectCompactSuccessPlanner::SKIP_MISSING_CONTENT,
            ];
        }

        if (! $isGeneratorDone) {
            $projectGate = $project instanceof SeoProject
                ? $this->assessProject($project)
                : ['ok' => true, 'reason' => null];

            if ($this->isActivelyGenerating($state)
                || ! $projectGate['ok']
            ) {
                return [
                    'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                    'generator_done' => false,
                    'movable' => false,
                    'skip_reason' => ! $projectGate['ok']
                        ? (string) ($projectGate['reason'] ?? ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING)
                        : ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING,
                ];
            }

            $itemGate = $this->assessItem($task, $project);
            if (! $itemGate['movable']) {
                return [
                    'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                    'generator_done' => false,
                    'movable' => false,
                    'skip_reason' => (string) ($itemGate['reason'] ?? ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING),
                ];
            }

            return [
                'kind' => ContentProjectCompactSuccessPlanner::KIND_NOT_DONE,
                'generator_done' => false,
                'movable' => true,
                'skip_reason' => $this->notDoneReason($state),
            ];
        }

        // generator_done — still may be unsafe to move
        $projectGate = $project instanceof SeoProject
            ? $this->assessProject($project)
            : ['ok' => true, 'reason' => null];

        if (! $projectGate['ok']) {
            return [
                'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                'generator_done' => true,
                'movable' => false,
                'skip_reason' => (string) ($projectGate['reason'] ?? ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING),
            ];
        }

        if ($this->isActivelyGenerating($state)) {
            return [
                'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                'generator_done' => true,
                'movable' => false,
                'skip_reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING,
            ];
        }

        $itemGate = $this->assessItem($task, $project);
        if (! $itemGate['movable']) {
            return [
                'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                'generator_done' => true,
                'movable' => false,
                'skip_reason' => (string) ($itemGate['reason'] ?? ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING),
            ];
        }

        return [
            'kind' => ContentProjectCompactSuccessPlanner::KIND_GENERATOR_DONE,
            'generator_done' => true,
            'movable' => true,
            'skip_reason' => null,
        ];
    }

    /**
     * Lightweight project scan: any generator_done item present?
     */
    public function projectHasGeneratorDoneItems(SeoProject $project): bool
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->active()
            ->with(['article'])
            ->get(['id', 'status', 'article_id', 'site_id', 'archived_at', 'publish_queue_status']);

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $article = $task->article instanceof SeoArticle ? $task->article : null;
            if (! $this->generatorDone->articleHasGeneratedContent($article)) {
                continue;
            }
            $status = strtolower(trim((string) ($task->status ?? '')));
            if (in_array($status, [
                SeoProjectTask::STATUS_COMPLETED,
                SeoProjectTask::STATUS_REVIEWING,
                'completed',
                'reviewing',
                'approved',
                'published',
            ], true)) {
                return true;
            }
            if ($article instanceof SeoArticle && $article->last_ai_content_at !== null) {
                return true;
            }
        }

        return false;
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
            return ['ok' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING];
        }

        $aiRunning = SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->notConsolidated()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->whereNull('finished_at')
            ->exists();

        if ($aiRunning) {
            return ['ok' => false, 'reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING];
        }

        return ['ok' => true, 'reason' => null];
    }

    private function looksLikeFalseSuccess(ContentProjectItemState $state, bool $hasContent): bool
    {
        if ($hasContent) {
            return false;
        }

        return in_array($state->lifecycleState, [
            ContentProjectLifecyclePhase::Review,
            ContentProjectLifecyclePhase::Approved,
            ContentProjectLifecyclePhase::WaitingPublish,
            ContentProjectLifecyclePhase::Published,
        ], true)
            || $state->generationState === ContentProjectItemGenerationState::Completed;
    }

    private function isActivelyGenerating(ContentProjectItemState $state): bool
    {
        return $state->lifecycleState === ContentProjectLifecyclePhase::Generating
            || $state->generationState === ContentProjectItemGenerationState::Writing
            || $state->generationState === ContentProjectItemGenerationState::Processing
            || $state->executionState === ContentProjectItemExecutionState::Running;
    }

    private function notDoneReason(ContentProjectItemState $state): string
    {
        if ($state->lifecycleState === ContentProjectLifecyclePhase::Failed
            || $state->generationState === ContentProjectItemGenerationState::Failed
        ) {
            return ContentProjectCompactSuccessPlanner::SKIP_FAILED;
        }

        if ($this->isActivelyGenerating($state)) {
            return ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING;
        }

        return ContentProjectCompactSuccessPlanner::SKIP_PENDING;
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
