<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;

final class SeoProjectRunPreflightService
{
    /**
     * @return list<array{
     *     task_id: int,
     *     keyword: string,
     *     articles: list<array{id: int, title: string, edit_url: string}>
     * }>
     */
    public function findKeywordTitleConflicts(SeoProject $project, ?int $limit = null): array
    {
        $tasks = $this->pendingTasksQuery($project, $limit)->get();
        $conflicts = [];

        foreach ($tasks as $task) {
            /** @var SeoProjectTask $task */
            if (! SeoProjectTask::isNewArticleType($task->type)) {
                continue;
            }

            $keyword = trim((string) ($task->keyword ?? $task->source_content ?? ''));
            if ($keyword === '') {
                $keyword = trim((string) ($task->title ?? ''));
            }
            if ($keyword === '') {
                continue;
            }

            $siteId = (int) ($task->site_id ?? $project->site_id ?? 0);
            $matches = $this->articlesWithTitleContaining($keyword, $siteId);

            if ($matches === []) {
                continue;
            }

            $conflicts[] = [
                'task_id' => (int) $task->id,
                'keyword' => $keyword,
                'articles' => $matches,
            ];
        }

        return $conflicts;
    }

    public function formatWarningsForModal(SeoProject $project, ?int $limit = null): HtmlString
    {
        $conflicts = $this->findKeywordTitleConflicts($project, $limit);

        if ($conflicts === []) {
            return new HtmlString('');
        }

        $lines = ['<div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-900 dark:border-warning-600 dark:bg-warning-500/10 dark:text-warning-200">'];
        $lines[] = '<p class="font-semibold">'.e(__('seo-content-ai::filament.projects.run_preflight_heading')).'</p>';
        $lines[] = '<ul class="mt-2 list-disc space-y-2 pl-5">';

        foreach ($conflicts as $conflict) {
            $articleBits = [];

            foreach ($conflict['articles'] as $article) {
                $title = e((string) $article['title']);
                $url = e((string) $article['edit_url']);
                $id = (int) $article['id'];
                $articleBits[] = '<a href="'.$url.'" target="_blank" rel="noopener" class="underline">#'.$id.' — '.$title.'</a>';
            }

            $lines[] = '<li>'.__('seo-content-ai::filament.projects.run_preflight_item', [
                'keyword' => e((string) $conflict['keyword']),
                'articles' => implode(', ', $articleBits),
            ]).'</li>';
        }

        $lines[] = '</ul></div>';

        return new HtmlString(implode('', $lines));
    }

    /**
     * @return HasMany<SeoProjectTask, SeoProject>
     */
    private function pendingTasksQuery(SeoProject $project, ?int $limit): HasMany
    {
        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * @return list<array{id: int, title: string, edit_url: string}>
     */
    private function articlesWithTitleContaining(string $keyword, int $siteId): array
    {
        $needle = mb_strtolower($keyword);
        if ($needle === '') {
            return [];
        }

        $query = SeoArticle::query()
            ->whereRaw('LOWER(title) LIKE ?', ['%'.$this->escapeLike($needle).'%'])
            ->orderByDesc('id');

        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        return $query
            ->limit(5)
            ->get(['id', 'title'])
            ->map(fn (SeoArticle $article): array => [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
                'edit_url' => ArticleResource::getUrl('edit', ['record' => $article->id]),
            ])
            ->all();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
