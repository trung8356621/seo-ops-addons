<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only provenance dump for a Content Project task.
 */
final class ContentProjectTaskHistoryForensicService
{
    /**
     * @return array<string, mixed>
     */
    public function diagnose(int $taskId): array
    {
        $task = SeoProjectTask::withTrashed()->with(['project.site'])->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return ['ok' => false, 'error' => 'task_not_found', 'task_id' => $taskId];
        }

        $project = $task->project;
        $projectSiteId = $project instanceof SeoProject ? (int) ($project->site_id ?? 0) : 0;
        $projectDomain = (string) ($project?->site?->domain ?? '');

        $taskArticleId = (int) ($task->article_id ?? 0);
        $currentArticle = $this->loadArticle($taskArticleId);
        $currentLink = $this->wordpressLink($taskArticleId);

        $events = $this->taskEvents($taskId);
        $runItems = $this->runItems($taskId);
        $runs = $this->runsFromItems($runItems);
        $promptLinks = $this->promptLinks($taskId, $taskArticleId);
        $promptResults = $this->promptResults($promptLinks, $taskId);
        $articleMetaForTask = $this->articleMetaForTask($taskId);
        $articleMetaForCurrent = $this->articleMetaForArticle($taskArticleId);
        $originArticles = $this->articlesByAutomationOrigin($taskId, $projectSiteId);

        $independent = $this->independentProvenanceToCurrent(
            $taskId,
            $taskArticleId,
            $currentArticle,
            $projectSiteId,
            $promptLinks,
            $articleMetaForCurrent,
            $originArticles,
            $runItems,
            $events,
        );

        $semantic = $this->semanticMatch(
            (string) ($task->keyword ?? ''),
            (string) ($task->title ?? ''),
            (string) ($currentArticle['title'] ?? ''),
        );

        $promptKeyword = $this->extractHistoricalPromptKeyword($promptResults, $runItems);
        $generatedTitle = $this->extractGeneratedTitle($promptResults, $runItems, $events);
        $promptClass = ContentProjectLegacyTaskClassifier::classifyPromptKeyword(
            (string) ($task->keyword ?? ''),
            $promptKeyword['value'],
        );
        if (
            $promptClass === ContentProjectLegacyTaskClassifier::PROMPT_KEYWORD_OK
            && ! $this->generatedTitleReflectsKeyword((string) ($task->keyword ?? ''), $generatedTitle)
        ) {
            $promptClass = ContentProjectLegacyTaskClassifier::PROMPT_KEYWORD_MISSING;
        }

        $firstPromptAt = $promptKeyword['at'] ?? $this->firstTimestamp($promptResults, 'started_at');
        $articleCreatedAt = $currentArticle['created_at'] ?? null;
        $articleBoundAt = $this->firstArticleBindAt($events, $runItems);
        $orderClass = ContentProjectLegacyTaskClassifier::classifyCreationOrder(
            $firstPromptAt,
            $articleCreatedAt,
            $articleBoundAt,
        );
        if ($taskArticleId > 0 && $currentArticle === null) {
            $orderClass = ContentProjectLegacyTaskClassifier::ORDER_NEVER_BOUND;
        }

        $collision = ContentProjectLegacyTaskClassifier::classifyCurrentArticle([
            'task_article_id' => $taskArticleId,
            'current_article_id' => $taskArticleId,
            'current_article_site_id' => (int) ($currentArticle['site_id'] ?? 0),
            'project_site_id' => $projectSiteId,
            'independent_provenance' => $independent,
            'semantic_match' => $semantic,
            'article_row_exists' => $currentArticle !== null,
        ]);

        $timeline = $this->buildTimeline(
            $task,
            $runs,
            $runItems,
            $events,
            $promptResults,
            $currentArticle,
        );

        $currentConsistent = $independent
            && $semantic
            && (int) ($currentArticle['site_id'] ?? 0) === $projectSiteId;

        return [
            'ok' => true,
            'task' => [
                'id' => (int) $task->id,
                'project_id' => (int) ($task->project_id ?? 0),
                'site_id' => (int) ($task->site_id ?? 0),
                'type' => SeoProjectTask::normalizeType($task->type),
                'keyword' => (string) ($task->keyword ?? ''),
                'title' => (string) ($task->title ?? ''),
                'source_content' => (string) ($task->source_content ?? ''),
                'source_key' => (string) ($task->source_key ?? ''),
                'article_id' => $taskArticleId > 0 ? $taskArticleId : null,
                'status' => (string) ($task->status ?? ''),
                'connected_at' => $this->dt($task->connected_at),
                'created_at' => $this->dt($task->created_at),
                'updated_at' => $this->dt($task->updated_at),
            ],
            'project' => [
                'id' => $project instanceof SeoProject ? (int) $project->getKey() : null,
                'site_id' => $projectSiteId,
                'domain' => $projectDomain,
                'name' => (string) ($project?->name ?? ''),
            ],
            'current_article' => $currentArticle,
            'current_wordpress_link' => $currentLink,
            'current_article_temporally_semantically_consistent' => $currentConsistent,
            'independent_provenance_to_current_article' => $independent,
            'historical_prompt' => [
                'focus_keyword' => $promptKeyword['value'],
                'keyword_variable' => $promptKeyword['keyword_variable'],
                'post_title' => $promptKeyword['post_title'],
                'source' => $promptKeyword['source'],
                'at' => $promptKeyword['at'],
                'classification' => $promptClass,
                'generated_title' => $generatedTitle,
            ],
            'creation_order' => [
                'first_prompt_at' => $firstPromptAt,
                'current_article_created_at' => $articleCreatedAt,
                'article_bound_at' => $articleBoundAt,
                'classification' => $orderClass,
            ],
            'collision' => $collision,
            'runs' => $runs,
            'run_items' => $runItems,
            'task_events' => $events,
            'prompt_result_links' => $promptLinks,
            'prompt_results' => $promptResults,
            'article_meta_task_refs' => $articleMetaForTask,
            'article_meta_current' => $articleMetaForCurrent,
            'automation_origin_articles' => $originArticles,
            'timeline' => $timeline,
            'exact_title_fallback_allowed' => ContentProjectLegacyTaskClassifier::exactTitleFallbackAllowed(
                SeoProjectTask::normalizeType($task->type),
                $promptClass,
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadArticle(int $articleId): ?array
    {
        if ($articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()->with(['site', 'wordpressLink'])->find($articleId);
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $originType = $this->metaValue($articleId, 'automation_origin_type');
        $originId = $this->metaValue($articleId, 'automation_origin_id');

        return [
            'id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'domain' => (string) ($article->site?->domain ?? ''),
            'title' => (string) ($article->title ?? ''),
            'slug' => (string) ($article->slug ?? ''),
            'created_at' => $this->dt($article->created_at),
            'updated_at' => $this->dt($article->updated_at),
            'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? $article->getAttribute('wp_post_id') ?? 0) ?: null,
            'wp_permalink' => $this->metaValue($articleId, 'wp_permalink'),
            'automation_origin_type' => $originType,
            'automation_origin_id' => $originId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function wordpressLink(int $articleId): ?array
    {
        if ($articleId <= 0 || ! $this->tableExists('wordpress_article_links')) {
            return null;
        }

        $link = WordpressArticleLink::query()->where('article_id', $articleId)->first();
        if ($link === null) {
            return null;
        }

        return [
            'article_id' => (int) $link->article_id,
            'site_id' => (int) ($link->site_id ?? 0),
            'wp_post_id' => (int) ($link->wp_post_id ?? 0) ?: null,
            'last_synced_at' => $this->dt($link->last_synced_at ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskEvents(int $taskId): array
    {
        if (! $this->tableExists('seo_project_task_events')) {
            return [];
        }

        return SeoProjectTaskEvent::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get()
            ->map(fn (SeoProjectTaskEvent $event): array => [
                'id' => (int) $event->id,
                'type' => (string) ($event->event ?? $event->type ?? $event->getAttribute('event_type') ?? ''),
                'run_id' => (int) ($event->run_id ?? 0) ?: null,
                'created_at' => $this->dt($event->created_at),
                'payload' => $this->truncateArray(is_array($event->payload) ? $event->payload : []),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runItems(int $taskId): array
    {
        if (! $this->tableExists('seo_project_run_items')) {
            return [];
        }

        return SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get()
            ->map(function (SeoProjectRunItem $item): array {
                $input = is_array($item->input_snapshot) ? $item->input_snapshot : [];
                $output = is_array($item->output_snapshot) ? $item->output_snapshot : [];

                return [
                    'id' => (int) $item->id,
                    'run_id' => (int) ($item->run_id ?? 0) ?: null,
                    'action' => (string) ($item->action ?? ''),
                    'status' => (string) ($item->status ?? ''),
                    'article_id' => (int) ($item->article_id ?? 0) ?: null,
                    'started_at' => $this->dt($item->started_at),
                    'finished_at' => $this->dt($item->finished_at),
                    'created_at' => $this->dt($item->created_at),
                    'input_focus_keyword' => $this->nestedString($input, ['focus_keyword', 'keyword', 'variables.focus_keyword', 'context.variables.focus_keyword']),
                    'input_post_title' => $this->nestedString($input, ['post_title', 'title', 'variables.post_title', 'context.variables.post_title']),
                    'input_article_id' => $this->nestedInt($input, ['article_id', 'context.article_id', 'variables.article_id']),
                    'output_article_id' => $this->nestedInt($output, ['article_id', 'result.article_id', 'context.article_id']),
                    'prompt_input_used' => $this->firstPromptInputUsed($output),
                    'prompt_generated_h1' => $this->firstPromptGeneratedH1($output),
                    'input_snapshot' => $this->truncateArray($input),
                    'output_snapshot' => $this->truncateArray($output),
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $runItems
     * @return list<array<string, mixed>>
     */
    private function runsFromItems(array $runItems): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['run_id'] ?? 0),
            $runItems,
        ))));
        if ($ids === [] || ! $this->tableExists('seo_project_runs')) {
            return [];
        }

        return SeoProjectRun::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (SeoProjectRun $run): array => [
                'id' => (int) $run->id,
                'project_id' => (int) ($run->project_id ?? 0) ?: null,
                'status' => (string) ($run->status ?? ''),
                'created_at' => $this->dt($run->created_at),
                'updated_at' => $this->dt($run->updated_at),
                'started_at' => $this->dt($run->started_at ?? null),
                'finished_at' => $this->dt($run->finished_at ?? null),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function promptLinks(int $taskId, int $articleId): array
    {
        if (! $this->tableExists('seo_prompt_result_links')) {
            return [];
        }

        $query = SeoPromptResultLink::query()
            ->where(function ($q) use ($taskId, $articleId): void {
                $q->where('project_task_id', $taskId);
                if ($articleId > 0) {
                    $q->orWhere('article_id', $articleId);
                }
            });

        return $query->orderBy('id')->get()->map(fn (SeoPromptResultLink $link): array => [
            'id' => (int) $link->id,
            'prompt_result_id' => (int) ($link->prompt_result_id ?? 0) ?: null,
            'article_id' => (int) ($link->article_id ?? 0) ?: null,
            'project_task_id' => (int) ($link->project_task_id ?? 0) ?: null,
            'project_run_id' => (int) ($link->project_run_id ?? 0) ?: null,
            'meta' => $this->truncateArray(is_array($link->meta) ? $link->meta : []),
            'created_at' => $this->dt($link->created_at),
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @return list<array<string, mixed>>
     */
    private function promptResults(array $links, int $taskId): array
    {
        if (! $this->tableExists('prompt_results')) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['prompt_result_id'] ?? 0),
            $links,
        ))));

        $byId = $ids === []
            ? collect()
            : SeoPromptResult::query()->whereIn('id', $ids)->orderBy('id')->get();

        $extra = SeoPromptResult::query()
            ->where(function ($q) use ($taskId): void {
                $q->where('input_snapshot', 'like', '%"_seo_project_task_id":"'.$taskId.'"%')
                    ->orWhere('input_snapshot', 'like', '%"_seo_project_task_id": '.$taskId.'%')
                    ->orWhere('input_snapshot', 'like', '%"project_task_id":'.$taskId.'%');
            })
            ->orderBy('id')
            ->limit(30)
            ->get();

        $merged = $byId->concat($extra)->unique('id')->sortBy('id');

        return $merged->map(function (SeoPromptResult $row): array {
            $input = is_array($row->input_snapshot) ? $row->input_snapshot : [];

            return [
                'id' => (int) $row->id,
                'prompt_id' => (int) ($row->prompt_id ?? 0) ?: null,
                'site_id' => (int) ($row->site_id ?? 0) ?: null,
                'status' => (string) ($row->status ?? ''),
                'started_at' => $this->dt($row->started_at),
                'finished_at' => $this->dt($row->finished_at),
                'created_at' => $this->dt($row->created_at),
                'focus_keyword' => $this->nestedString($input, ['focus_keyword', 'variables.focus_keyword', 'input.focus_keyword']),
                'keyword' => $this->nestedString($input, ['keyword', 'variables.keyword']),
                'post_title' => $this->nestedString($input, ['post_title', 'variables.post_title', 'title']),
                'article_id' => $this->nestedInt($input, ['article_id', 'variables.article_id', 'context.article_id']),
                'project_task_id' => $this->nestedInt($input, ['_seo_project_task_id', 'project_task_id', 'variables._seo_project_task_id']),
                'input_snapshot' => $this->truncateArray($input),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articleMetaForTask(int $taskId): array
    {
        if (! $this->tableExists('article_meta')) {
            return [];
        }

        return ArticleMeta::query()
            ->whereIn('meta_key', ['content_project_run', 'automation_origin_id', 'seo_focus_keyword'])
            ->where(function ($q) use ($taskId): void {
                $q->where('meta_value', (string) $taskId)
                    ->orWhere('meta_value', 'like', '%"task_id":'.$taskId.'%')
                    ->orWhere('meta_value', 'like', '%"task_id": '.$taskId.'%');
            })
            ->limit(80)
            ->get(['id', 'article_id', 'meta_key', 'meta_value'])
            ->map(fn (ArticleMeta $meta): array => [
                'id' => (int) $meta->id,
                'article_id' => (int) $meta->article_id,
                'meta_key' => (string) $meta->meta_key,
                'meta_value' => mb_substr((string) $meta->meta_value, 0, 500),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articleMetaForArticle(int $articleId): array
    {
        if ($articleId <= 0 || ! $this->tableExists('article_meta')) {
            return [];
        }

        return ArticleMeta::query()
            ->where('article_id', $articleId)
            ->whereIn('meta_key', [
                'automation_origin_type',
                'automation_origin_id',
                'content_project_run',
                'seo_focus_keyword',
                'wp_permalink',
                'wp_post_id',
            ])
            ->get(['meta_key', 'meta_value'])
            ->map(fn (ArticleMeta $meta): array => [
                'meta_key' => (string) $meta->meta_key,
                'meta_value' => mb_substr((string) $meta->meta_value, 0, 500),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articlesByAutomationOrigin(int $taskId, int $siteId): array
    {
        if (! $this->tableExists('article_meta')) {
            return [];
        }

        $ids = SeoArticle::query()
            ->when($siteId > 0, static fn ($q) => $q->where('site_id', $siteId))
            ->whereHas('articleMetas', static function ($query): void {
                $query->where('meta_key', 'automation_origin_type')
                    ->where('meta_value', 'seo_project_task');
            })
            ->whereHas('articleMetas', static function ($query) use ($taskId): void {
                $query->where('meta_key', 'automation_origin_id')
                    ->where('meta_value', (string) $taskId);
            })
            ->limit(10)
            ->get(['id', 'site_id', 'title', 'created_at']);

        return $ids->map(fn (SeoArticle $article): array => [
            'id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'title' => (string) ($article->title ?? ''),
            'created_at' => $this->dt($article->created_at),
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $promptLinks
     * @param  list<array<string, mixed>>  $articleMeta
     * @param  list<array<string, mixed>>  $originArticles
     * @param  list<array<string, mixed>>  $runItems
     * @param  list<array<string, mixed>>  $events
     */
    private function independentProvenanceToCurrent(
        int $taskId,
        int $articleId,
        ?array $currentArticle,
        int $projectSiteId,
        array $promptLinks,
        array $articleMeta,
        array $originArticles,
        array $runItems,
        array $events,
    ): bool {
        if ($articleId <= 0 || $currentArticle === null) {
            return false;
        }
        if ((int) ($currentArticle['site_id'] ?? 0) !== $projectSiteId) {
            return false;
        }

        foreach ($originArticles as $row) {
            if ((int) ($row['id'] ?? 0) === $articleId) {
                return true;
            }
        }

        foreach ($articleMeta as $meta) {
            if ((string) ($meta['meta_key'] ?? '') === 'automation_origin_id'
                && (string) ($meta['meta_value'] ?? '') === (string) $taskId
            ) {
                return true;
            }
            if ((string) ($meta['meta_key'] ?? '') === 'content_project_run'
                && str_contains((string) ($meta['meta_value'] ?? ''), '"task_id":'.$taskId)
            ) {
                return true;
            }
        }

        foreach ($promptLinks as $link) {
            if ((int) ($link['project_task_id'] ?? 0) === $taskId
                && (int) ($link['article_id'] ?? 0) === $articleId
            ) {
                return true;
            }
        }

        foreach ($events as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $eventArticle = (int) ($payload['article_id'] ?? 0);
            if ($eventArticle === $articleId && (string) ($event['type'] ?? '') !== '') {
                return true;
            }
        }

        foreach ($runItems as $item) {
            // Run-item numeric id matching current article is not independent if it
            // can be the same stale pointer copied from task.article_id.
            unset($item);
        }

        return false;
    }

    private function semanticMatch(string $keyword, string $plannedTitle, string $articleTitle): bool
    {
        $kw = mb_strtolower(ContentProjectItemIdentity::normalize($keyword));
        $planned = mb_strtolower(ContentProjectItemIdentity::normalize($plannedTitle));
        $title = mb_strtolower(ContentProjectItemIdentity::normalize($articleTitle));
        if ($title === '') {
            return false;
        }
        if ($planned !== '' && $planned === $title) {
            return true;
        }
        if ($kw !== '' && str_contains($title, $kw)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $promptResults
     * @param  list<array<string, mixed>>  $runItems
     * @return array{value: ?string, keyword_variable: ?string, post_title: ?string, source: string, at: ?string}
     */
    private function extractHistoricalPromptKeyword(array $promptResults, array $runItems): array
    {
        foreach ($promptResults as $row) {
            $focus = trim((string) ($row['focus_keyword'] ?? ''));
            $keyword = trim((string) ($row['keyword'] ?? ''));
            if ($focus !== '' || $keyword !== '') {
                return [
                    'value' => $focus !== '' ? $focus : $keyword,
                    'keyword_variable' => $keyword !== '' ? $keyword : $focus,
                    'post_title' => trim((string) ($row['post_title'] ?? '')) ?: null,
                    'source' => 'prompt_results#'.(int) $row['id'],
                    'at' => $row['started_at'] ?? $row['created_at'] ?? null,
                ];
            }
            if (array_key_exists('focus_keyword', $row) && ($row['focus_keyword'] === '' || $row['focus_keyword'] === null)
                && ($row['input_snapshot'] ?? []) !== []
            ) {
                return [
                    'value' => '',
                    'keyword_variable' => $keyword !== '' ? $keyword : '',
                    'post_title' => trim((string) ($row['post_title'] ?? '')) ?: null,
                    'source' => 'prompt_results#'.(int) $row['id'],
                    'at' => $row['started_at'] ?? $row['created_at'] ?? null,
                ];
            }
        }

        foreach ($runItems as $item) {
            $used = trim((string) ($item['prompt_input_used'] ?? ''));
            $focus = trim((string) ($item['input_focus_keyword'] ?? ''));
            $keyword = $used !== '' ? $used : $focus;
            if ($keyword !== '') {
                return [
                    'value' => $keyword,
                    'keyword_variable' => $focus !== '' ? $focus : $used,
                    'post_title' => trim((string) ($item['input_post_title'] ?? '')) ?: null,
                    'source' => 'run_item#'.(int) $item['id'].($used !== '' ? '.steps.input_used' : '.input_snapshot.keyword'),
                    'at' => $item['started_at'] ?? $item['created_at'] ?? null,
                ];
            }
        }

        return [
            'value' => null,
            'keyword_variable' => null,
            'post_title' => null,
            'source' => 'none',
            'at' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $promptResults
     * @param  list<array<string, mixed>>  $runItems
     * @param  list<array<string, mixed>>  $events
     */
    private function extractGeneratedTitle(array $promptResults, array $runItems, array $events): ?string
    {
        foreach ($runItems as $item) {
            $title = trim((string) ($item['prompt_generated_h1'] ?? $item['output_title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
        }
        foreach ($events as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            foreach (['title', 'post_title', 'h1_title', 'generated_title'] as $key) {
                $title = trim((string) ($payload[$key] ?? ''));
                if ($title !== '') {
                    return $title;
                }
            }
        }
        foreach ($promptResults as $row) {
            $title = trim((string) ($row['post_title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $runItems
     */
    private function firstArticleBindAt(array $events, array $runItems): ?string
    {
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            if (str_contains($type, 'article') && ($event['created_at'] ?? null)) {
                return (string) $event['created_at'];
            }
        }
        foreach ($runItems as $item) {
            if ((int) ($item['article_id'] ?? 0) > 0) {
                return (string) ($item['started_at'] ?? $item['created_at'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function firstTimestamp(array $rows, string $key): ?string
    {
        foreach ($rows as $row) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  list<array<string, mixed>>  $runItems
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $promptResults
     * @return list<array{at: ?string, event: string, detail: string}>
     */
    private function buildTimeline(
        SeoProjectTask $task,
        array $runs,
        array $runItems,
        array $events,
        array $promptResults,
        ?array $currentArticle,
    ): array {
        $rows = [];
        $rows[] = ['at' => $this->dt($task->created_at), 'event' => 'task_created', 'detail' => 'keyword='.(string) ($task->keyword ?? '')];
        foreach ($runs as $run) {
            $rows[] = ['at' => $run['created_at'] ?? null, 'event' => 'run_created', 'detail' => 'run_id='.(int) $run['id']];
        }
        foreach ($promptResults as $row) {
            $rows[] = [
                'at' => $row['started_at'] ?? $row['created_at'] ?? null,
                'event' => 'prompt_called',
                'detail' => 'prompt_result='.(int) $row['id']
                    .' focus_keyword='.(string) ($row['focus_keyword'] ?? '')
                    .' article_id='.(string) ($row['article_id'] ?? ''),
            ];
        }
        foreach ($runItems as $item) {
            $rows[] = [
                'at' => $item['started_at'] ?? $item['created_at'] ?? null,
                'event' => 'run_item',
                'detail' => 'id='.(int) $item['id']
                    .' action='.(string) $item['action']
                    .' article_id='.(string) ($item['article_id'] ?? '')
                    .' input_kw='.(string) ($item['input_focus_keyword'] ?? ''),
            ];
        }
        foreach ($events as $event) {
            $rows[] = [
                'at' => $event['created_at'] ?? null,
                'event' => 'task_event:'.(string) $event['type'],
                'detail' => mb_substr(json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 180),
            ];
        }
        if ($currentArticle !== null) {
            $rows[] = [
                'at' => $currentArticle['created_at'] ?? null,
                'event' => 'current_article_row_created',
                'detail' => 'article_id='.(int) $currentArticle['id']
                    .' site='.(string) ($currentArticle['domain'] ?? '')
                    .' title='.(string) ($currentArticle['title'] ?? ''),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function firstPromptInputUsed(array $output): ?string
    {
        $steps = is_array($output['steps'] ?? null) ? $output['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $used = trim((string) ($step['input_used'] ?? ''));
            if ($used !== '') {
                return $used;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function firstPromptGeneratedH1(array $output): ?string
    {
        $steps = is_array($output['steps'] ?? null) ? $output['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $raw = (string) ($step['output'] ?? '');
            if (preg_match('/^#\s+H1:\s*(.+)$/mu', $raw, $match) === 1) {
                return trim($match[1]);
            }
            if (preg_match('/^#\s+(.+)$/mu', $raw, $match) === 1) {
                return trim($match[1]);
            }
        }

        return null;
    }

    private function generatedTitleReflectsKeyword(string $keyword, ?string $generatedTitle): bool
    {
        $kw = mb_strtolower(ContentProjectItemIdentity::normalize($keyword));
        $title = mb_strtolower(ContentProjectItemIdentity::normalize($generatedTitle));
        if ($kw === '' || $title === '') {
            return false;
        }

        return str_contains($title, $kw);
    }

    private function metaValue(int $articleId, string $key): ?string
    {
        if ($articleId <= 0 || ! $this->tableExists('article_meta')) {
            return null;
        }
        $value = ArticleMeta::query()
            ->where('article_id', $articleId)
            ->where('meta_key', $key)
            ->value('meta_value');
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function dt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $paths
     */
    private function nestedString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $paths
     */
    private function nestedInt(array $data, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = (int) data_get($data, $path);
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function truncateArray(array $data): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (! is_string($json) || strlen($json) <= 4000) {
            return $data;
        }

        return ['_truncated' => true, '_preview' => mb_substr($json, 0, 4000)];
    }
}
