<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Active Content Project ownership for Article restrictions (editor / WP sync / workspace).
 *
 * Archived project association is historical/reporting only — never active ownership.
 */
final class ContentProjectArticleMembership
{
    public function activeTaskForArticle(SeoArticle|int $article): ?SeoProjectTask
    {
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : $article;
        if ($articleId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()
            ->active()
            ->where('article_id', $articleId)
            ->whereHas('project', static function ($query): void {
                $query->whereNull('archived_at');
            })
            ->orderByDesc('id')
            ->first();

        return $task instanceof SeoProjectTask ? $task : null;
    }

    public function belongsToActiveContentProject(SeoArticle|int $article): bool
    {
        return $this->activeTaskForArticle($article) instanceof SeoProjectTask;
    }

    /**
     * Active Content Project task ownership for editor/sync restrictions.
     * Archived project / item-archived tasks do not count.
     */
    public function assignedTaskForArticle(SeoArticle|int $article): ?SeoProjectTask
    {
        return $this->activeTaskForArticle($article);
    }

    /**
     * Any task row still pointing at the article (including archived project leftovers).
     * Historical/reporting only — do not use for editor deny or CP sync gates.
     */
    public function historicalAssignedTaskForArticle(SeoArticle|int $article): ?SeoProjectTask
    {
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : $article;
        if ($articleId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->orderByDesc('id')
            ->first();

        return $task instanceof SeoProjectTask ? $task : null;
    }

    /**
     * Restriction gate: only ACTIVE Content Project membership.
     * Archived association ⇒ standalone Article.
     */
    public function belongsToContentProject(SeoArticle|int $article): bool
    {
        return $this->belongsToActiveContentProject($article);
    }

    public function activeProjectForArticle(SeoArticle|int $article): ?SeoProject
    {
        $task = $this->activeTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return null;
        }

        $project = $task->project;
        if ($project instanceof SeoProject && $project->archived_at === null) {
            return $project;
        }

        return null;
    }
}
