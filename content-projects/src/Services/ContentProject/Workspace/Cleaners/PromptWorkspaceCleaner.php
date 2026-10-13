<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn Prompt History / Prompt Result gắn project.
 */
final class PromptWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'prompt';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        $promptResultIds = [];

        $linkQuery = SeoPromptResultLink::query();
        $linkQuery->where(function ($query) use ($context): void {
            $hasFilter = false;

            if ($context->hasRuns()) {
                $query->orWhereIn('project_run_id', $context->runIds());
                $hasFilter = true;
            }

            if ($context->hasTasks()) {
                $query->orWhereIn('project_task_id', $context->taskIds());
                $hasFilter = true;
            }

            if ($context->hasArticles()) {
                $query->orWhere(function ($articleQuery) use ($context): void {
                    $articleQuery->whereIn('article_id', $context->articleIds());
                    if ($context->hasTasks()) {
                        $articleQuery->where(function ($taskQuery) use ($context): void {
                            $taskQuery->whereNull('project_task_id')
                                ->orWhereIn('project_task_id', $context->taskIds());
                        });
                    }
                });
                $hasFilter = true;
            }

            if (! $hasFilter) {
                $query->whereRaw('0 = 1');
            }
        });

        $links = $linkQuery->get(['id', 'prompt_result_id']);
        foreach ($links as $link) {
            $promptResultId = (int) ($link->prompt_result_id ?? 0);
            if ($promptResultId > 0) {
                $promptResultIds[] = $promptResultId;
            }
        }

        if ($links->isNotEmpty()) {
            $deletedLinks = SeoPromptResultLink::query()
                ->whereIn('id', $links->pluck('id')->all())
                ->delete();
            $context->bumpStat('prompt_result_links_deleted', (int) $deletedLinks);
        }

        if ($context->hasArticles() && Schema::connection('omi_seo_ai')->hasColumn('articles', 'prompt_result_id')) {
            $legacyIds = SeoArticle::query()
                ->whereIn('id', $context->articleIds())
                ->whereNotNull('prompt_result_id')
                ->pluck('prompt_result_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->all();
            $promptResultIds = array_merge($promptResultIds, $legacyIds);

            $cleared = SeoArticle::query()
                ->whereIn('id', $context->articleIds())
                ->update([
                    'prompt_result_id' => null,
                    'last_ai_content_at' => null,
                ]);
            $context->bumpStat('articles_prompt_cleared', (int) $cleared);
        }

        $promptResultIds = array_merge(
            $promptResultIds,
            $this->projectScopedPromptResultIds($context->projectId()),
        );

        $promptResultIds = array_values(array_unique(array_filter(
            $promptResultIds,
            static fn (int $id): bool => $id > 0,
        )));

        if ($promptResultIds === []) {
            return;
        }

        $stillLinked = SeoPromptResultLink::query()
            ->whereIn('prompt_result_id', $promptResultIds)
            ->pluck('prompt_result_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $orphanIds = array_values(array_diff($promptResultIds, $stillLinked));
        if ($orphanIds === []) {
            return;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('prompt_results')) {
            return;
        }

        $deletedResults = DB::connection('omi_seo_ai')
            ->table('prompt_results')
            ->whereIn('id', $orphanIds)
            ->delete();
        $context->bumpStat('prompt_results_deleted', (int) $deletedResults);
    }

    /**
     * Prompt results stamped with this content project, even when the link row is already gone.
     *
     * @return list<int>
     */
    private function projectScopedPromptResultIds(int $projectId): array
    {
        if ($projectId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('prompt_results')) {
            return [];
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('prompt_results', 'content_project_id')) {
            return [];
        }

        return DB::connection('omi_seo_ai')
            ->table('prompt_results')
            ->where('content_project_id', $projectId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }
}
