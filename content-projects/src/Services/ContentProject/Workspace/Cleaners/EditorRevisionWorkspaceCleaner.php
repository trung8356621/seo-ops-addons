<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;

/**
 * Dọn Editor History (SaaS). Sau sync/publish, WordPress Revision là nguồn lịch sử.
 */
final class EditorRevisionWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'editor_revision';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $deleted = SeoArticleRevision::query()
            ->whereIn('article_id', $context->articleIds())
            ->delete();
        $context->bumpStat('editor_revisions_deleted', (int) $deleted);
    }
}
