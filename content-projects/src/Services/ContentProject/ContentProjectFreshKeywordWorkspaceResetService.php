<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryPendingDraftStore;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArticlePipelineRerunService;

/**
 * Clears working generation artifacts that could contaminate a fresh keyword restart.
 * Does not delete AI history, article identity, focus keyword, or publishing metadata.
 */
final class ContentProjectFreshKeywordWorkspaceResetService
{
    /**
     * @return array{article_id: int|null, cleared_metas: int}
     */
    public function resetForTask(SeoProjectTask $task): array
    {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return ['article_id' => null, 'cleared_metas' => 0];
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return ['article_id' => null, 'cleared_metas' => 0];
        }

        $metaKeys = [
            ArticleAiHistoryPendingDraftStore::META_KEY,
            ArticlePipelineRerunService::META_KEY,
            'content_project_generation_cache',
            'content_project_prompt_cache',
        ];

        $cleared = ArticleMeta::query()
            ->where('article_id', $articleId)
            ->whereIn('meta_key', $metaKeys)
            ->delete();

        SeoArticle::query()
            ->whereKey($articleId)
            ->update(['last_ai_content_at' => null]);

        return [
            'article_id' => $articleId,
            'cleared_metas' => (int) $cleared,
        ];
    }
}
