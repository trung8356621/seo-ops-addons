<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Editor adapter — delegates orchestration to CommandBus step rerun (no direct job).
 */
final class ArticlePipelineRerunService
{
    public const FROM_OUTLINE = 'outline';

    public const FROM_ARTICLE = 'article';

    public const META_KEY = 'content_project_rerun';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const BLOCK_NO_PROJECT = 'Bài viết phải được gắn vào Content Project trước khi chạy lại quy trình.';

    public function __construct(
        private readonly ContentProjectCommandBus $commandBus,
        private readonly ArticlePipelineRerunStartStepResolver $startStepResolver,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     blocked?: bool,
     *     busy?: bool,
     *     message: string,
     *     run_id?: int|null,
     *     run_url?: string|null,
     *     status?: string|null
     * }
     */
    public function queue(SeoArticle $article, string $fromStep, ?int $userId = null): array
    {
        $fromStep = $this->normalizeFromStep($fromStep);
        $step = ContentProjectRerunFromStep::fromMixed($fromStep);

        if (! SeoAccessControl::canAccessManagerFeatures()) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bạn không có quyền chạy lại quy trình bài viết.',
            ];
        }

        if (! SeoAccessControl::canAccessArticle($article)) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bạn không có quyền truy cập bài viết này.',
            ];
        }

        if ($article->trashed()) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Không thể chạy lại quy trình cho bài viết đã xóa.',
            ];
        }

        $task = $this->resolveProjectTask($article);
        if (! $task instanceof SeoProjectTask) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => self::BLOCK_NO_PROJECT,
            ];
        }

        $project = $task->project ?? SeoProject::query()->find((int) $task->project_id);
        if (! $project instanceof SeoProject) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => self::BLOCK_NO_PROJECT,
            ];
        }

        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bạn không có quyền chạy Content Project của bài viết này.',
            ];
        }

        // Pre-check workflow start node still resolvable (editor UX message).
        $resolved = $this->startStepResolver->resolve($task, $fromStep);
        if (! ($resolved['ok'] ?? false) || ($resolved['resolved_node_id'] ?? null) === null) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => (string) ($resolved['message']
                    ?? 'Workflow của bài viết đã thay đổi và không còn bước tương ứng. Vui lòng chọn lại bước bắt đầu.'),
            ];
        }

        $lockKey = $this->lockKey((int) $article->id, $fromStep);
        $lock = Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            return [
                'success' => false,
                'busy' => true,
                'message' => 'Yêu cầu chạy lại đang được xử lý. Vui lòng đợi.',
            ];
        }

        try {
            $this->writeRerunMeta($article, [
                'project_id' => (int) $project->id,
                'task_id' => (int) $task->id,
                'from' => $fromStep,
                'status' => self::STATUS_RUNNING,
                'queued_at' => now()->toIso8601String(),
                'started_at' => now()->toIso8601String(),
                'message' => null,
            ]);

            @set_time_limit(0);

            // Editor outline = from outline node + downstream; article = content only.
            $result = $this->commandBus->dispatch(
                new RerunProjectItemStepCommand(
                    projectRef: (int) $project->id,
                    itemRefs: [(int) $task->id],
                    fromStep: $step,
                    includeDownstream: $step === ContentProjectRerunFromStep::Outline,
                    sourceArticleId: (int) $article->id,
                    mode: SeoProjectRun::MODE_FULL,
                    syncExecution: true,
                ),
                ActorContext::user(
                    $userId,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );

            $executionRef = is_string($result->metadata['execution_ref'] ?? null)
                ? (string) $result->metadata['execution_ref']
                : '';
            $runId = $executionRef !== '' ? ContentProjectPublicRef::decodeExecution($executionRef) : 0;

            $ok = $result->success;
            $this->writeRerunMeta($article, [
                'run_id' => $runId > 0 ? $runId : null,
                'project_id' => (int) $project->id,
                'task_id' => (int) $task->id,
                'from' => $fromStep,
                'status' => $ok ? self::STATUS_COMPLETED : self::STATUS_FAILED,
                'finished_at' => now()->toIso8601String(),
                'message' => $result->message,
            ]);

            RuntimeLogger::info('seo.article_rerun.command_bus', [
                'article_id' => (int) $article->id,
                'project_id' => (int) $project->id,
                'task_id' => (int) $task->id,
                'from_step' => $fromStep,
                'success' => $ok,
                'run_id' => $runId,
            ]);

            return [
                'success' => $ok,
                'message' => $result->message,
                'run_id' => $runId > 0 ? $runId : null,
                'run_url' => SeoProjectResource::getProjectWorkspaceUrl($project),
                'status' => $ok ? self::STATUS_COMPLETED : self::STATUS_FAILED,
                'blocked' => ! $ok,
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{status: string|null, run_id: int|null, run_url: string|null, from: string|null, message: string|null, busy: bool}
     */
    public function statusPayload(SeoArticle $article): array
    {
        $meta = $this->readRerunMeta($article);
        $runId = (int) ($meta['run_id'] ?? 0);
        $status = isset($meta['status']) ? (string) $meta['status'] : null;
        $busy = in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);

        $runUrl = null;
        if ($runId > 0) {
            $run = SeoProjectRun::query()->with('project')->find($runId);
            $project = $run?->project;
            if ($project instanceof SeoProject) {
                $runUrl = SeoProjectResource::getProjectWorkspaceUrl($project);
            }
        }

        return [
            'status' => $status,
            'run_id' => $runId > 0 ? $runId : null,
            'run_url' => $runUrl,
            'from' => isset($meta['from']) ? (string) $meta['from'] : null,
            'message' => isset($meta['message']) ? (string) $meta['message'] : null,
            'busy' => $busy,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeRerunMeta(SeoArticle $article, array $payload): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readRerunMeta(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_KEY)?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeFromStep(string $fromStep): string
    {
        $normalized = strtolower(trim($fromStep));

        return $normalized === self::FROM_ARTICLE ? self::FROM_ARTICLE : self::FROM_OUTLINE;
    }

    private function lockKey(int $articleId, string $fromStep): string
    {
        return 'seo:article-pipeline-rerun:'.$articleId.':'.$fromStep;
    }

    private function resolveProjectTask(SeoArticle $article): ?SeoProjectTask
    {
        $task = SeoProjectTask::query()
            ->where('article_id', (int) $article->id)
            ->orderByDesc('id')
            ->first();

        return $task instanceof SeoProjectTask ? $task : null;
    }
}
