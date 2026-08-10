<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Group 1: project.task.attach_article + project.task.mark_completed.
 */
final class ProjectTaskCallerBridge
{
    public function __construct(
        private readonly AutomationCallerMigrator $migrator,
        private readonly ActionRunner $actionRunner,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
        private readonly ParitySnapshotNormalizer $parityNormalizer,
    ) {}

    public function attachArticle(SeoProjectTask $task, int $articleId, ?int $actorId = null, ?int $siteId = null): void
    {
        $taskId = (int) $task->id;
        $alreadyAttached = (int) ($task->article_id ?? 0) === $articleId && $articleId > 0;
        $correlationId = Str::uuid()->toString();
        $normalizer = $this->parityNormalizer;

        $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_ARTICLE_ATTACH,
            legacyWrite: function () use ($task, $taskId, $articleId, $alreadyAttached): array {
                if (! $alreadyAttached) {
                    DB::connection($task->getConnectionName())->transaction(function () use ($taskId, $articleId): void {
                        SeoProjectTask::query()
                            ->where('article_id', $articleId)
                            ->whereKeyNot($taskId)
                            ->update(['article_id' => null]);

                        $payload = ['article_id' => $articleId];
                        $fresh = SeoProjectTask::query()->find($taskId);
                        if ($fresh instanceof SeoProjectTask && $fresh->connected_at === null) {
                            $payload['connected_at'] = now();
                        }
                        SeoProjectTask::query()->whereKey($taskId)->update($payload);
                    });
                }

                return [
                    'task_id' => $taskId,
                    'article_id' => $articleId,
                    'already_attached' => $alreadyAttached,
                ];
            },
            actionWrite: fn (): ActionResult => $this->actionRunner->run(
                'project.task.attach_article',
                ActionContext::fromArray([
                    'origin' => 'migration.project_article_attach',
                    'actor_id' => $actorId,
                    'site_id' => $siteId,
                    'correlation_id' => $correlationId,
                ]),
                ['task_id' => $taskId, 'article_id' => $articleId],
            ),
            parityExpected: static fn (): array => [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'already_attached' => $alreadyAttached,
            ],
            normalizeLegacy: static fn (mixed $v): array => $normalizer->attach(
                is_array($v) ? $v : [],
                (bool) ($v['already_attached'] ?? $alreadyAttached),
                $siteId,
            ),
            normalizeExpected: static fn (array $v): array => $normalizer->attach(
                $v,
                (bool) ($v['already_attached'] ?? $alreadyAttached),
                $siteId,
            ),
            actionKey: 'project.task.attach_article',
            correlationId: $correlationId,
        );

        $task->refresh();
    }

    public function markCompleted(SeoProjectTask $task, int $articleId, ?int $actorId = null, ?int $siteId = null, string $origin = 'migration.project_task_complete'): void
    {
        $taskId = (int) $task->id;
        $alreadyCompleted = (string) ($task->status ?? '') === SeoProjectTask::STATUS_COMPLETED
            && (int) ($task->article_id ?? 0) === ($articleId > 0 ? $articleId : (int) ($task->article_id ?? 0));
        $correlationId = Str::uuid()->toString();
        $normalizer = $this->parityNormalizer;

        $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_TASK_COMPLETE,
            legacyWrite: function () use ($task, $taskId, $articleId, $alreadyCompleted): array {
                if (! $alreadyCompleted) {
                    DB::connection($task->getConnectionName())->transaction(function () use ($task, $taskId, $articleId): void {
                        if ($articleId > 0) {
                            SeoProjectTask::query()
                                ->where('article_id', $articleId)
                                ->whereKeyNot($taskId)
                                ->update(['article_id' => null]);
                        }

                        $payload = [
                            'status' => SeoProjectTask::STATUS_COMPLETED,
                            'article_id' => $articleId > 0 ? $articleId : null,
                        ];
                        if ($articleId > 0 && $task->connected_at === null) {
                            $payload['connected_at'] = now();
                        }
                        if ($task->completed_at === null) {
                            $payload['completed_at'] = now();
                        }
                        SeoProjectTask::query()->whereKey($taskId)->update($payload);

                        if ($articleId > 0) {
                            $task->loadMissing('project');
                            if ($task->project instanceof SeoProject) {
                                $this->articleOwnerSync->assignWriterToArticle($task->project, $articleId);
                            }
                        }
                    });
                }

                return [
                    'task_id' => $taskId,
                    'article_id' => $articleId > 0 ? $articleId : null,
                    'status' => SeoProjectTask::STATUS_COMPLETED,
                    'already_completed' => $alreadyCompleted,
                ];
            },
            actionWrite: fn (): ActionResult => $this->actionRunner->run(
                'project.task.mark_completed',
                ActionContext::fromArray([
                    'origin' => $origin,
                    'actor_id' => $actorId,
                    'site_id' => $siteId,
                    'correlation_id' => $correlationId,
                    'suppress_article_completed_bridge' => $origin === 'content_project_run',
                ]),
                [
                    'task_id' => $taskId,
                    'article_id' => $articleId > 0 ? $articleId : null,
                ],
            ),
            parityExpected: static fn (): array => [
                'task_id' => $taskId,
                'article_id' => $articleId > 0 ? $articleId : null,
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'already_completed' => $alreadyCompleted,
            ],
            normalizeLegacy: static fn (mixed $v): array => $normalizer->markCompleted(
                is_array($v) ? $v : [],
                (bool) ($v['already_completed'] ?? $alreadyCompleted),
                $siteId,
            ),
            normalizeExpected: static fn (array $v): array => $normalizer->markCompleted(
                $v,
                (bool) ($v['already_completed'] ?? $alreadyCompleted),
                $siteId,
            ),
            actionKey: 'project.task.mark_completed',
            correlationId: $correlationId,
        );

        $task->refresh();
    }
}
