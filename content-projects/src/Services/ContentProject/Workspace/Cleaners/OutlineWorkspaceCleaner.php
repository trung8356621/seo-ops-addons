<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;

/**
 * Removes project-scoped outline workspace stored on the article.
 * Published body, URL, and SEO metadata keys are not in this set.
 */
final class OutlineWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'outline';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $deleted = ArticleMeta::query()
            ->whereIn('article_id', $context->articleIds())
            ->whereIn('meta_key', [
                ArticleOutlineResolver::META_KEY,
                ArticleOutlineResolver::META_KEY_PARSED,
                ArticleOutlineResolver::META_KEY_ROWS,
            ])
            ->delete();

        $context->bumpStat('outline_metas_deleted', (int) $deleted);
    }
}
