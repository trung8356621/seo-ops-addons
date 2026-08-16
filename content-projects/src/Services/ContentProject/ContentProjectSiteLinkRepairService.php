<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;

/**
 * Diagnose / repair Content Project items whose site_id or article_id
 * drifted onto another domain. Never mutates SeoArticle.site_id.
 */
final class ContentProjectSiteLinkRepairService
{
    public const DECISION_OK = 'ok';

    public const DECISION_REPAIR_TASK_SITE = 'repair_task_site';

    public const DECISION_DETACH_AND_RECONCILE = 'detach_and_reconcile';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public function __construct(
        private readonly ContentProjectExistingArticleReconciler $reconciler,
    ) {}

    /**
     * Pure policy: never move an article across sites.
     *
     * @param  array{
     *     project_site_id: int,
     *     task_site_id: int,
     *     article_id: int,
     *     article_site_id: int
     * }  $row
     * @return array{decision: string, problem: string, proposed: list<string>}
     */
    public static function decide(array $row): array
    {
        $projectSiteId = (int) ($row['project_site_id'] ?? 0);
        $taskSiteId = (int) ($row['task_site_id'] ?? 0);
        $articleId = (int) ($row['article_id'] ?? 0);
        $articleSiteId = (int) ($row['article_site_id'] ?? 0);

        $taskSiteMismatch = $projectSiteId > 0 && $taskSiteId > 0 && $taskSiteId !== $projectSiteId;
        $crossSiteArticle = $articleId > 0 && $articleSiteId > 0 && $articleSiteId !== $projectSiteId;

        if ($crossSiteArticle) {
            return [
                'decision' => self::DECISION_DETACH_AND_RECONCILE,
                'problem' => 'attached_article_wrong_site',
                'proposed' => [
                    'set task.site_id = project.site_id',
                    'detach task.article_id (do not move SeoArticle.site_id)',
                    'clear latest run-item mirror if it has the same invalid article_id',
                    'reconcile exact same-site candidate only; otherwise leave unlinked',
                ],
            ];
        }

        if ($taskSiteMismatch && $articleId > 0 && $articleSiteId === $projectSiteId) {
            return [
                'decision' => self::DECISION_REPAIR_TASK_SITE,
                'problem' => 'task_site_mismatch_article_ok',
                'proposed' => ['set task.site_id = project.site_id'],
            ];
        }

        if ($taskSiteMismatch && $articleId <= 0) {
            return [
                'decision' => self::DECISION_REPAIR_TASK_SITE,
                'problem' => 'task_site_mismatch_no_article',
                'proposed' => ['set task.site_id = project.site_id'],
            ];
        }

        if ($projectSiteId <= 0) {
            return [
                'decision' => self::STATUS_NEEDS_ATTENTION,
                'problem' => 'project_site_missing',
                'proposed' => ['manual: set SeoProject.site_id before repair'],
            ];
        }

        return [
            'decision' => self::DECISION_OK,
            'problem' => '',
            'proposed' => [],
        ];
    }

    /**
     * @return array{
     *     project: array<string, mixed>,
     *     rows: list<array<string, mixed>>,
     *     mismatches: list<array<string, mixed>>,
     *     apply: bool
     * }
     */
    public function repair(SeoProject $project, bool $apply = false): array
    {
        $project->loadMissing('site');
        $projectSiteId = (int) ($project->site_id ?? 0);
        $projectDomain = (string) ($project->site?->domain ?? '');

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNull('archived_at')
            ->orderBy('target_date')
            ->orderBy('id')
            ->get();

        $articleIds = $tasks
            ->pluck('article_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $articles = $articleIds === []
            ? collect()
            : SeoArticle::query()
                ->with(['site', 'wordpressLink'])
                ->whereIn('id', $articleIds)
                ->get()
                ->keyBy('id');

        $taskIds = $tasks->map(static fn (SeoProjectTask $task): int => (int) $task->id)->all();
        $latestRunItems = $taskIds === []
            ? collect()
            : SeoProjectRunItem::query()
                ->whereIn('task_id', $taskIds)
                ->orderByDesc('id')
                ->get()
                ->unique('task_id')
                ->keyBy('task_id');

        $rows = [];
        $mismatches = [];

        foreach ($tasks as $task) {
            $taskId = (int) $task->id;
            $articleId = (int) ($task->article_id ?? 0);
            /** @var SeoArticle|null $article */
            $article = $articleId > 0 ? ($articles->get($articleId) ?? null) : null;
            $articleSiteId = $article instanceof SeoArticle ? (int) ($article->site_id ?? 0) : 0;
            /** @var SeoProjectRunItem|null $latest */
            $latest = $latestRunItems->get($taskId);
            $latestArticleId = $latest instanceof SeoProjectRunItem ? (int) ($latest->article_id ?? 0) : 0;

            $decision = self::decide([
                'project_site_id' => $projectSiteId,
                'task_site_id' => (int) ($task->site_id ?? 0),
                'article_id' => $articleId,
                'article_site_id' => $articleSiteId,
            ]);

            $permalink = $this->articlePermalink($article);

            $row = [
                'project_id' => (int) $project->getKey(),
                'project_site_id' => $projectSiteId,
                'project_domain' => $projectDomain,
                'task_id' => $taskId,
                'target_date' => $task->target_date?->format('Y-m-d'),
                'type' => SeoProjectTask::normalizeType($task->type),
                'status' => (string) ($task->status ?? ''),
                'task_site_id' => (int) ($task->site_id ?? 0),
                'article_id' => $articleId > 0 ? $articleId : null,
                'article_site_id' => $articleSiteId > 0 ? $articleSiteId : null,
                'article_domain' => $article instanceof SeoArticle ? (string) ($article->site?->domain ?? '') : '',
                'article_title' => $article instanceof SeoArticle ? (string) ($article->title ?? '') : '',
                'wp_post_id' => $this->articleWpPostId($article),
                'wp_permalink' => $permalink,
                'latest_run_item_id' => $latest instanceof SeoProjectRunItem ? (int) $latest->id : null,
                'latest_run_item_article_id' => $latestArticleId > 0 ? $latestArticleId : null,
                'decision' => $decision['decision'],
                'problem' => $decision['problem'],
                'proposed' => $decision['proposed'],
                'applied' => [],
                'relinked_article_id' => null,
                'unresolved' => false,
            ];

            if ($decision['decision'] !== self::DECISION_OK) {
                if ($apply) {
                    $row = $this->applyDecision($project, $task, $latest, $decision['decision'], $row);
                } elseif ($decision['decision'] === self::DECISION_DETACH_AND_RECONCILE) {
                    $row = $this->previewDetachAndReconcile($task, $projectSiteId, $row);
                }
                $mismatches[] = $row;
            }

            $rows[] = $row;
        }

        return [
            'project' => [
                'id' => (int) $project->getKey(),
                'site_id' => $projectSiteId,
                'domain' => $projectDomain,
                'name' => (string) ($project->name ?? ''),
            ],
            'rows' => $rows,
            'mismatches' => $mismatches,
            'apply' => $apply,
        ];
    }

    /**
     * Dry-run only: ask the reconciler whether an unambiguous same-site article
     * would be relinked after detaching the invalid association. No DB writes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function previewDetachAndReconcile(SeoProjectTask $task, int $projectSiteId, array $row): array
    {
        $preview = $task->replicate();
        $preview->id = (int) $task->id;
        $preview->exists = true;
        $preview->article_id = null;
        $preview->site_id = $projectSiteId;
        if ($task->relationLoaded('project')) {
            $preview->setRelation('project', $task->project);
        }

        $result = $this->reconciler->reconcileTask($preview, $projectSiteId, persist: false);
        if ($result->articleId !== null && $result->articleId > 0
            && in_array($result->status, [
                ContentProjectExistingArticleReconcileResult::STATUS_REPAIRED,
                ContentProjectExistingArticleReconcileResult::STATUS_RESOLVED,
            ], true)
        ) {
            $row['relinked_article_id'] = $result->articleId;
            $row['proposed'][] = 'would relink article_id='.$result->articleId.' via '.$result->matchedBy;
        } else {
            $row['unresolved'] = true;
            $row['proposed'][] = 'would leave unlinked:'.($result->reason ?? $result->status);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyDecision(
        SeoProject $project,
        SeoProjectTask $task,
        ?SeoProjectRunItem $latest,
        string $decision,
        array $row,
    ): array {
        $projectSiteId = (int) $project->site_id;
        $applied = [];

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $task,
            $latest,
            $decision,
            $row,
            $projectSiteId,
            &$applied,
        ): array {
            $locked = SeoProjectTask::query()->whereKey((int) $task->id)->lockForUpdate()->first();
            if (! $locked instanceof SeoProjectTask) {
                $row['unresolved'] = true;
                $row['applied'] = ['task_missing'];

                return $row;
            }

            if ((int) ($locked->site_id ?? 0) !== $projectSiteId) {
                $locked->site_id = $projectSiteId;
                $locked->save();
                $applied[] = 'task.site_id='.$projectSiteId;
            }

            if ($decision === self::DECISION_REPAIR_TASK_SITE) {
                $row['applied'] = $applied;
                $row['task_site_id'] = $projectSiteId;

                return $row;
            }

            $invalidArticleId = (int) ($locked->article_id ?? 0);
            if ($invalidArticleId > 0) {
                $locked->article_id = null;
                $locked->save();
                $applied[] = 'detached article_id='.$invalidArticleId;
            }

            if ($latest instanceof SeoProjectRunItem) {
                $latestLocked = SeoProjectRunItem::query()->whereKey((int) $latest->id)->lockForUpdate()->first();
                if ($latestLocked instanceof SeoProjectRunItem
                    && (int) ($latestLocked->article_id ?? 0) === $invalidArticleId
                    && $invalidArticleId > 0
                ) {
                    $latestLocked->article_id = null;
                    $latestLocked->save();
                    $applied[] = 'cleared latest run_item.article_id='.$invalidArticleId;
                    $row['latest_run_item_article_id'] = null;
                }
            }

            $locked->refresh();
            $result = $this->reconciler->reconcileTask($locked, $projectSiteId, persist: true);
            if ($result->articleId !== null && $result->articleId > 0
                && in_array($result->status, [
                    ContentProjectExistingArticleReconcileResult::STATUS_REPAIRED,
                    ContentProjectExistingArticleReconcileResult::STATUS_RESOLVED,
                ], true)
            ) {
                $row['relinked_article_id'] = $result->articleId;
                $applied[] = 'relinked article_id='.$result->articleId.' via '.$result->matchedBy;
            } else {
                $row['unresolved'] = true;
                $row['decision'] = self::STATUS_NEEDS_ATTENTION;
                $applied[] = 'left_unlinked:'.($result->reason ?? $result->status);
            }

            $row['article_id'] = (int) ($locked->fresh()?->article_id ?? 0) ?: null;
            $row['task_site_id'] = $projectSiteId;
            $row['applied'] = $applied;

            return $row;
        });
    }

    private function articleWpPostId(?SeoArticle $article): ?int
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $fromLink = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($fromLink > 0) {
            return $fromLink;
        }

        $fromColumn = (int) ($article->getAttribute('wp_post_id') ?? 0);

        return $fromColumn > 0 ? $fromColumn : null;
    }

    private function articlePermalink(?SeoArticle $article): string
    {
        if (! $article instanceof SeoArticle) {
            return '';
        }

        $meta = $article->articleMetas?->firstWhere('meta_key', 'wp_permalink');
        $fromMeta = trim((string) ($meta?->meta_value ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        try {
            if (! $article->relationLoaded('articleMetas')) {
                $article->loadMissing('articleMetas');
                $meta = $article->articleMetas?->firstWhere('meta_key', 'wp_permalink');
                $fromMeta = trim((string) ($meta?->meta_value ?? ''));
                if ($fromMeta !== '') {
                    return $fromMeta;
                }
            }
        } catch (\Throwable) {
            // optional relation
        }

        return '';
    }
}
