<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Post-publish editorial UPDATE of an existing WordPress post.
 * Distinct from ProcessScheduledProjectItemPublish / PublishNow / Retry / local Save.
 */
final class SyncPublishedArticleToWordPressCommand implements ContentProjectCommand
{
    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     */
    public function __construct(
        public readonly int $articleId,
        public readonly ?int $projectRef = null,
        public readonly ?int $itemRef = null,
        public readonly ?array $seoOverride = null,
        public readonly ?string $contentHash = null,
        public readonly string $initiatedFrom = 'article_editor.post_publish_sync',
    ) {}

    public function name(): string
    {
        return 'publishing.sync_published_article_to_wordpress';
    }
}
