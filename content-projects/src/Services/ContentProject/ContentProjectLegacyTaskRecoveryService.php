<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Illuminate\Support\Facades\DB;

/**
 * Targeted legacy-task recovery. Never mutates foreign SeoArticle.site_id.
 * Never deletes the foreign article. Apply is explicit.
 * Detach-only: no auto-reconcile, no new article, no WP import.
 */
final class ContentProjectLegacyTaskRecoveryService
{
    public const PLAN_B_CLEAN_RESTART = 'PLAN_B_DETACH_UNLINKED_MANUAL';

    public function __construct(
        private readonly ContentProjectTaskHistoryForensicService $forensic,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(int $taskId, bool $searchWp = false): array
    {
        unset($searchWp);
        $report = $this->forensic->diagnose($taskId);
        if (($report['ok'] ?? false) !== true) {
            return $report;
        }

        $current = is_array($report['current_article'] ?? null) ? $report['current_article'] : [];
        $task = is_array($report['task'] ?? null) ? $report['task'] : [];
        $project = is_array($report['project'] ?? null) ? $report['project'] : [];
        $currentArticleId = (int) ($task['article_id'] ?? $current['id'] ?? 0);
        $articleSiteId = (int) ($current['site_id'] ?? 0);
        $articleDomain = (string) ($current['domain'] ?? $current['site_domain'] ?? '');
        $latest = $this->latestRunItemMirror((int) $taskId);
        $poisonedLatest = $currentArticleId > 0
            && (int) ($latest['article_id'] ?? 0) === $currentArticleId;

        return [
            'ok' => true,
            'apply' => false,
            'task_id' => $taskId,
            'project_id' => (int) ($project['id'] ?? 0),
            'project_site_id' => (int) ($project['site_id'] ?? 0),
            'project_domain' => (string) ($project['domain'] ?? ''),
            'keyword' => (string) ($task['keyword'] ?? ''),
            'current' => [
                'article_id' => $currentArticleId > 0 ? $currentArticleId : null,
                'article_site' => $articleSiteId > 0 ? $articleSiteId : null,
                'domain' => $articleDomain,
                'latest_run_item_id' => $latest['id'],
                'latest_run_item_article_id' => $latest['article_id'],
            ],
            'plan' => self::PLAN_B_CLEAN_RESTART,
            'action' => 'detach only',
            'auto_reconcile' => false,
            'create_article' => false,
            'manual_action_required' => true,
            'proposed' => [
                'task.article_id => NULL',
                $poisonedLatest
                    ? 'current run mirror => NULL if poisoned'
                    : 'current run mirror => leave (not the poisoned article_id)',
                'preserve Article #'.($currentArticleId > 0 ? (string) $currentArticleId : 'n/a'),
                'auto_reconcile => NO',
                'create_article => NO',
                'manual_action_required => YES',
            ],
            'forbidden' => [
                'never_change_foreign_SeoArticle.site_id',
                'never_delete_foreign_article_'.$currentArticleId,
                'never_auto_reconcile',
                'never_create_article',
            ],
            'forensic' => $report,
        ];
    }

    /**
     * Apply is explicit. Default callers must pass false.
     *
     * @return array<string, mixed>
     */
    public function recover(int $taskId, bool $apply, bool $searchWp = false): array
    {
        $plan = $this->plan($taskId, $searchWp);
        $plan['apply'] = $apply;
        if (! $apply || ($plan['ok'] ?? false) !== true) {
            return $plan;
        }

        $plan['applied'] = $this->applyDetach($taskId);

        return $plan;
    }

    /**
     * @return list<string>
     */
    private function applyDetach(int $taskId): array
    {
        $applied = [];

        return DB::connection('omi_seo_ai')->transaction(function () use ($taskId, &$applied): array {
            $task = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
            if (! $task instanceof SeoProjectTask) {
                return ['task_missing'];
            }
            $task->loadMissing('project');
            $project = $task->project;
            $projectSiteId = $project instanceof SeoProject ? (int) ($project->site_id ?? 0) : 0;
            $invalidId = (int) ($task->article_id ?? 0);
            $foreign = false;
            $articleTitle = '';
            if ($invalidId > 0) {
                $article = SeoArticle::query()->find($invalidId);
                if ($article instanceof SeoArticle) {
                    $articleTitle = trim((string) ($article->title ?? ''));
                    $foreign = $projectSiteId > 0 && (int) ($article->site_id ?? 0) !== $projectSiteId;
                }
            }

            if ($invalidId > 0) {
                $task->article_id = null;
                $applied[] = 'detached article_id='.$invalidId;
            }
            if ($projectSiteId > 0 && (int) ($task->site_id ?? 0) !== $projectSiteId) {
                $task->site_id = $projectSiteId;
                $applied[] = 'task.site_id='.$projectSiteId;
            }
            if ($articleTitle !== '' && trim((string) ($task->title ?? '')) === $articleTitle) {
                $task->title = null;
                $applied[] = 'cleared task.title copied from foreign article';
            }
            $status = strtolower(trim((string) ($task->status ?? '')));
            if (in_array($status, [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_REVIEWING, 'completed', 'reviewing'], true)) {
                $task->status = SeoProjectTask::STATUS_PENDING;
                $applied[] = 'task.status=pending';
            }
            $task->save();

            $latest = SeoProjectRunItem::query()
                ->where('task_id', $taskId)
                ->orderByDesc('id')
                ->first();
            if ($latest instanceof SeoProjectRunItem
                && $invalidId > 0
                && (int) ($latest->article_id ?? 0) === $invalidId
            ) {
                $latest->article_id = null;
                $latest->save();
                $applied[] = 'cleared latest run_item.article_id='.$invalidId;
            }

            ContentProjectManualArticleResolution::mark($taskId, [
                'detached_article_id' => $invalidId > 0 ? $invalidId : null,
                'foreign_article_preserved' => $foreign,
                'plan' => self::PLAN_B_CLEAN_RESTART,
            ]);
            SeoProjectTaskEvent::query()->create([
                'task_id' => $taskId,
                'event' => 'legacy.recovery.detach',
                'payload' => [
                    'reason' => ContentProjectManualArticleResolution::REASON,
                    'detached_article_id' => $invalidId > 0 ? $invalidId : null,
                    'foreign_article_preserved' => $foreign,
                    'plan' => self::PLAN_B_CLEAN_RESTART,
                    'auto_reconcile' => false,
                    'create_article' => false,
                ],
                'created_at' => now(),
            ]);
            $applied[] = 'recorded '.ContentProjectManualArticleResolution::EVENT;
            $applied[] = 'auto_reconcile=NO';
            $applied[] = 'create_article=NO';

            return $applied;
        });
    }

    /**
     * @return array{id: ?int, article_id: ?int}
     */
    private function latestRunItemMirror(int $taskId): array
    {
        $latest = SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->first();
        if (! $latest instanceof SeoProjectRunItem) {
            return ['id' => null, 'article_id' => null];
        }

        $articleId = (int) ($latest->article_id ?? 0);

        return [
            'id' => (int) $latest->id,
            'article_id' => $articleId > 0 ? $articleId : null,
        ];
    }
}
