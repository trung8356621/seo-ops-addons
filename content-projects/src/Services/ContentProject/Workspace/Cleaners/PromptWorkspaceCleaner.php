<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
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
                $query->orWhereIn('article_id', $context->articleIds());
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

        $deletedResults = SeoPromptResult::query()
            ->whereIn('id', $orphanIds)
            ->delete();
        $context->bumpStat('prompt_results_deleted', (int) $deletedResults);
    }
}
