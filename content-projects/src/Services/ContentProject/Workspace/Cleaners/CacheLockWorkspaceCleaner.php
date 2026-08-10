<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;

/**
 * Thu thập Cache / Lock keys để release sau commit.
 */
final class CacheLockWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'cache_lock';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        foreach ($context->articleIds() as $articleId) {
            $context->queueCacheLockKey('seo-wp-publish-article-'.$articleId);
            $context->queueCacheLockKey('manual-wp-sync:'.$articleId);
        }

        foreach ($context->runIds() as $runId) {
            $context->queueCacheLockKey('content-project-run-bulk-sync:'.$runId);
        }
    }
}
